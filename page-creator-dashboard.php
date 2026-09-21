<?php
/**
 * Template Name: Creator Dashboard
 * File: page-creator-dashboard.php
 * Path: /wp-content/themes/astra/page-templates/page-creator-dashboard.php
 * 
 * PURPOSE:
 * Dashboard for NFT creators to manage their collections and listings.
 * NO admin privileges required — wallet ownership IS the authorization.
 * 
 * FEATURES:
 * - Overview stats (total collections, listings, minted, revenue)
 * - Collection cards with click-through to collection detail
 * - Listing management (pause/resume/cancel, edit price)
 * - Purchase history per listing
 * - Tier breakdown visualization
 * 
 * @version 1.0.0 (v64)
 */

get_header();

// Get logged-in wallet from cookie
$xrpl_account = function_exists('imc_session_wallet') ? imc_session_wallet() : '';
$nonce = wp_create_nonce('xrpl_marketplace_nonce');

// D-A (13 Sep 2026): artist-profile prefill REMOVED. Profile editing now lives
// solely on the public profile page (page-user.php at /user/<account>), which
// owns display_name, bio, socials
// and the profile image against this same artist-profile-handler record.
// Two editors for one record is how they drift apart.

// Endpoints for JS
$endpoints = [
    'listingsHandler' => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/listings-handler.php',
    'mintOnDemand'    => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/mint-on-demand-handler.php',
    'collectionsApi'  => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/collections-api-handler.php',
    'uploadHandler'   => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/upload-handler.php', // v506 Phase C: cover re-upload
    'allowlistHandler'=> get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/allowlist-handler.php',
    // D-A: 'artistProfile' removed - no caller remains on this page.
    // D-B2: a NEW read-only file, deliberately NOT an action on
    // mint-on-demand-handler.php (the stabilised Phase 4e
    // minting handler). Reporting must not require redeploying the mint path.
    'creatorAnalytics'=> get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/creator-analytics-handler.php',
];
?>

<link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/xrpl-nft-marketplace/frontend/creator-dashboard.css?v=<?php echo time(); ?>">

<style>
/* ═══════════════════════════════════════════════════════════════════════════
   Creator Dashboard — Core Styles (v66 - Background Fix)
   ═══════════════════════════════════════════════════════════════════════════ */

:root {
    /* R-2a (14 Sep 2026) - the surface goes from navy to the near-black the
       collection page uses, so the two surfaces read as one product. */
    --cd-bg-primary: #0a0a14;
    --cd-bg-secondary: #12121f;
    --cd-bg-card: #08080a;              /* was rgba(26,26,46,0.8) - the navy */
    --cd-bg-hover: #131318;             /* was #222238 */
    --cd-gold: #d4af37;
    --cd-gold-light: #f0d060;
    --cd-gold-dark: #a68b2a;
    --cd-text: #e8e8e8;
    --cd-text-muted: #888;
    --cd-border: rgba(212, 175, 55, 0.2);
    --cd-success: #10b981;
    --cd-warning: #f59e0b;
    --cd-danger: #ef4444;
    --cd-info: #3b82f6;

    /* R-2a - THESE THREE WERE USED BUT NEVER DEFINED. With no fallback they
       resolved to nothing, which is a live defect rather than a style choice:
       --cd-accent is the Edit Pricing modal's XRP preview colour, the border on
       its callout, and the "Dynamic Pricing = XRP Only" heading - so that callout
       has had NO border colour. --cd-bg-elevated is the .cd-xrp-preview
       background, so it has had none either. Rarely seen because dynamic pricing
       is rarely used; broken all the same. */
    --cd-accent: #d4af37;
    --cd-bg-elevated: #101014;
    --cd-bg: #08080a;

    /* R-2a - the gold gradient border, mirroring #nft-collection-page .imc-panel.
       --imu-gold-rgb lives on :root in custom.css, so it is reachable here. */
    --cd-panel-edge: linear-gradient(135deg,
        rgba(var(--imu-gold-rgb, 214,186,102), .55) 0%,
        rgba(var(--imu-gold-rgb, 214,186,102), .10) 45%,
        rgba(var(--imu-gold-rgb, 214,186,102), .30) 100%);
    --cd-panel-edge-hover: linear-gradient(135deg,
        rgba(var(--imu-gold-rgb, 214,186,102), .95) 0%,
        rgba(var(--imu-gold-rgb, 214,186,102), .45) 45%,
        rgba(var(--imu-gold-rgb, 214,186,102), .75) 100%);
}

/* R-2a - THE PANEL SURFACE. A flat colour token cannot express this: it is a
   two-layer background (opaque fill via padding-box, gradient via border-box)
   behind a TRANSPARENT border. Ported from #nft-collection-page .imc-panel.
   Applied by adding .cd-surface alongside the existing class, so every rule that
   already targets those classes keeps working untouched. */
/* R-2b - the icon system, ported from mint.css L5787 and rescoped from
   .mint-wizard-container to this page. `stroke: currentColor` is the important
   part: icons inherit text colour, so they survive the custom.css colour nuke
   without needing !important armour. */
#creator-dashboard-page .imc-ic {
    width: 18px; height: 18px;
    stroke: currentColor; fill: none;
    stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round;
    display: inline-block; vertical-align: -3px; flex: none;
}
#creator-dashboard-page h1 .imc-ic,
#creator-dashboard-page h2 .imc-ic,
#creator-dashboard-page h3 .imc-ic,
#creator-dashboard-page h4 .imc-ic { color: var(--cd-gold); }

.cd-surface {
    border: 1px solid transparent !important;
    background:
        linear-gradient(var(--cd-bg-card), var(--cd-bg-card)) padding-box,
        var(--cd-panel-edge) border-box !important;
}
/* R-2a FIX (same build): the !important above DEFEATS the existing
   `.cd-stat-card:hover` and `.cd-collection-card:hover` rules, which set
   `border-color: var(--cd-gold)`. Their transform and box-shadow still fire, so
   the loss is silent - the card lifts but the edge stays dull. Restoring the
   feedback in the new language: the same gradient, brightened. Only the two
   INTERACTIVE surfaces get this; .cd-topbar, .cd-an-kpi and
   the rest are not hoverable and must not react. */
/* ⚠ R-11 FIX: the R-11 dead-CSS sweep removed `.cd-collection-card.cd-surface:hover`
   from this TWO-SELECTOR rule and left the trailing comma behind, so the selector
   became `.cd-stat-card.cd-surface:hover, #creator-dashboard-page { ... }` - and
   the next rule's `min-height: 100vh` was applied TO THE HOVERED STAT CARD. With
   `align-items: stretch` on the grid, all four hero cards grew to full viewport
   height together. That is the "cards expand and go long" glitch.
   The sweep regex matched selectors line by line; a rule whose selector list
   spans lines needs the whole list considered. Noted for next time. */
.cd-stat-card.cd-surface:hover {
    background:
        linear-gradient(var(--cd-bg-card), var(--cd-bg-card)) padding-box,
        var(--cd-panel-edge-hover) border-box !important;
}

/* ═══ Page Background - Match Trading Hub ═══ */
#creator-dashboard-page {
    position: relative;
    min-height: 100vh;
    background: var(--imu-black, #0a0b0e);
    width: 100% !important;
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    box-sizing: border-box;
}

#creator-dashboard-page::before {
    content: "";
    position: fixed;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    background: 
        radial-gradient(ellipse at 20% 0%, rgba(var(--imu-gold-rgb), 0.15) 0%, transparent 50%),
        radial-gradient(ellipse at 80% 100%, rgba(212, 175, 55, 0.10) 0%, transparent 50%),
        radial-gradient(ellipse at 50% 50%, rgba(60, 60, 80, 0.40) 0%, transparent 70%),
        linear-gradient(180deg, #0a0b0e 0%, #12131a 50%, #0a0b0e 100%);
    background-size: 100% 100%, 100% 100%, 100% 100%, 100% 100%;
}

#creator-dashboard-page::after {
    content: "";
    position: fixed;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    background: rgba(0, 0, 0, 0.2);
}

.cd-container {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 1200px;
    margin-left: auto !important;
    margin-right: auto !important;
    padding: 2rem 1.5rem;
    min-height: 80vh;
    box-sizing: border-box;
}

/* ═══ Not Connected State ═══ */
.cd-not-connected {
    text-align: center;
    padding: 4rem 2rem;
    background: var(--cd-bg-card);
    backdrop-filter: blur(10px);
    border-radius: 16px;
    border: 1px solid var(--cd-border);
    margin-top: 2rem;
}

.cd-not-connected-icon {
    font-size: 4rem;
    margin-bottom: 1.5rem;
}

.cd-not-connected h2 {
    font-family: 'Cinzel Decorative', 'Cinzel', 'Times New Roman', serif;
    font-size: 1.8rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    background: linear-gradient(180deg, #ffe066 0%, var(--cd-gold-light, #f0d060) 40%, var(--cd-gold-dark, #a68b2a) 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    filter: drop-shadow(0 0 16px rgba(212, 175, 55, 0.35));
    margin-bottom: 1rem;
}

.cd-not-connected p {
    color: var(--cd-text-muted);
    font-size: 1.1rem;
    margin-bottom: 2rem;
}

.cd-connect-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 1rem 2rem;
    background: linear-gradient(135deg, var(--cd-gold), var(--cd-gold-dark));
    color: #000;
    font-weight: 600;
    border-radius: 8px;
    text-decoration: none;
    transition: all 0.3s ease;
}

.cd-connect-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 20px rgba(212, 175, 55, 0.4);
}

/* ═══ Page Header ═══ */
.cd-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    flex-wrap: wrap;
    gap: 1rem;
}

.cd-header h1 {
    font-family: 'Cinzel Decorative', 'Cinzel', 'Times New Roman', serif;
    font-size: 2rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    background: linear-gradient(180deg, #ffe066 0%, var(--cd-gold-light, #f0d060) 40%, var(--cd-gold-dark, #a68b2a) 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    filter: drop-shadow(0 0 18px rgba(212, 175, 55, 0.35));
    margin: 0;
}

.cd-header-actions {
    display: flex;
    gap: 1rem;
}

.cd-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.25rem;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.95rem;
    cursor: pointer;
    transition: all 0.2s ease;
    border: none;
    text-decoration: none;
}

.cd-btn-primary {
    background: linear-gradient(135deg, var(--cd-gold), var(--cd-gold-dark));
    color: #000;
}

.cd-btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(212, 175, 55, 0.3);
}

.cd-btn-secondary {
    background: var(--cd-bg-card);
    color: var(--cd-text);
    border: 1px solid var(--cd-border);
}

.cd-btn-secondary:hover {
    background: var(--cd-bg-hover);
    border-color: var(--cd-gold);
}

.cd-btn-danger {
    background: rgba(239, 68, 68, 0.2);
    color: var(--cd-danger);
    border: 1px solid rgba(239, 68, 68, 0.3);
}

.cd-btn-danger:hover {
    background: rgba(239, 68, 68, 0.3);
}

/* ═══ Stats Overview ═══ */
.cd-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.25rem;
    margin-bottom: 2.5rem;
}

.cd-stat-card {
    background: var(--cd-bg-card);
    backdrop-filter: blur(10px);
    border: 1px solid var(--cd-border);
    border-radius: 12px;
    padding: 1.5rem;
    transition: all 0.3s ease;
    text-align: center;
}

.cd-stat-card:hover {
    border-color: var(--cd-gold);
    transform: translateY(-2px);
}

.cd-stat-label {
    font-size: 0.85rem;
    color: var(--cd-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
}

.cd-stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--cd-gold);
}

.cd-stat-sub {
    font-size: 0.85rem;
    color: var(--cd-text-muted);
    margin-top: 0.25rem;
}

/* ═══ Section Tabs ═══ */
/* ── B-c (13 Sep 2026) · TOP-LEVEL NAV ──────────────────────────────
   DELIBERATELY NOT named .cd-tabs / .cd-tab. Those already exist below as the
   STATUS FILTER (All / Active / Scheduled / ...), and their click handler is
   bound once at boot over every `.cd-tab` in the document:

       document.querySelectorAll('.cd-tab').forEach(tab => { ... })

   Any element carrying the `cd-tab` token would inherit that handler, set
   currentFilter to undefined and blank the collections grid. Class matching is
   per-token, so `class="cd-tab cd-nav-tab"` WOULD be caught - never combine.

   Level 1 = these nav tabs (persist across drill-down).
   Level 2 = the existing .cd-view / .cd-listing-view / .cd-detail-view swap,
             which is untouched and continues to work inside the panel. */
.cd-navtabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    border-bottom: 1px solid var(--cd-border);
    padding-bottom: 0.5rem;
    flex-wrap: wrap;
}
.cd-nav-tab {
    padding: 0.75rem 1.5rem;
    background: transparent;
    color: var(--cd-text-muted);
    border: none;
    border-radius: 8px 8px 0 0;
    cursor: pointer;
    font-weight: 600;
    font-size: 1rem;
    transition: all 0.2s ease;
}
.cd-nav-tab:hover { color: var(--cd-text); background: var(--cd-bg-card); }
.cd-nav-tab.active {
    color: var(--cd-gold);
    background: var(--cd-bg-card);
    border-bottom: 2px solid var(--cd-gold);
}
/* v669 taught this the hard way: the JS toggled .active on #cd-collections-view
   for a long time with NO matching CSS rule, so the grid never hid and stacked
   on top of the detail view. The panel rule and its switcher ship together. */
.cd-panel { display: none; }
.cd-panel.active { display: block; }

/* Overview · attention items. Everything here is derived from allListings,
   already in memory - no endpoint, no new request. */
.cd-ov-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; }
/* R-6: the .cd-ov-* rules went with the Overview panel they styled. */
.cd-feed-filter { display:flex; align-items:center; gap:.6rem; flex-wrap:wrap; margin:0 0 1rem; }
.cd-feed-filter label {
    font-size:.7rem; letter-spacing:.08em; text-transform:uppercase;
    color:var(--cd-text-muted); font-weight:700;
}
.cd-feed-filter select {
    background:var(--cd-bg-elevated) !important; color:var(--cd-text);
    border:1px solid var(--cd-border); border-radius:10px;
    padding:.5rem .7rem; font-size:.88rem; min-height:42px; cursor:pointer; max-width:22rem;
}
.cd-feed-row {
    display:flex; align-items:center; gap:.85rem;
    padding:.7rem 0; border-top:1px solid var(--cd-border);
}
.cd-feed-row:first-child { border-top:none; }
.cd-feed-img { width:40px; height:40px; border-radius:8px; object-fit:cover; flex:none; background:var(--cd-bg-elevated); }
.cd-feed-main { min-width:0; flex:1; }
.cd-feed-main b { display:block; font-size:.9rem; color:var(--cd-text); font-weight:600;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.cd-feed-main span { font-size:.78rem; color:var(--cd-text-muted); }
.cd-feed-right { text-align:right; flex:none; }
.cd-feed-right b { display:block; font-size:.88rem; color:var(--cd-gold); font-weight:600; }
.cd-feed-right span { font-size:.72rem; color:var(--cd-text-muted); }
.cd-feed-empty { padding:2.5rem 1rem; text-align:center; color:var(--cd-text-muted); font-size:.92rem; }
@media (max-width: 768px) { .cd-feed-filter select { width:100%; max-width:100%; } }


/* D-D · analytics. Window pills are their OWN class - not .cd-tab (the status
   filter, bound at boot over every .cd-tab) and not .cd-nav-tab (level-1 nav).
   Three disjoint sets by design. */
/* ── D-E · ON-LEDGER (STORE) PANEL ────────────────────────────────
   Everything shipped so far is PRIMARY-MINT data from WordPress MySQL. This is
   the XRPL side: who holds the NFTs now, and what the floor is. Its own class
   family - .cd-store-* - kept clear of the four existing tab families
   (cd-tab / cd-nav-tab / cd-an-win / cd-hdr-win). */
.cd-store-sel { display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; margin:0 0 1rem; }
.cd-store-sel label {
    font-size:.7rem; letter-spacing:.08em; text-transform:uppercase;
    color:var(--cd-text-muted); font-weight:700;
}
.cd-store-sel select {
    background:var(--cd-bg-card); color:var(--cd-text); border:1px solid var(--cd-border);
    border-radius:8px; padding:.45rem .7rem; font-size:.88rem; min-height:38px; max-width:22rem;
}
.cd-store-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:1rem; }
.cd-store-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:.75rem; margin-bottom:1rem; }
.cd-store-kpi { background:var(--cd-bg-card); border:1px solid var(--cd-border); border-radius:12px; padding:.8rem .9rem; }
.cd-store-kpi .v { font-size:1.5rem; font-weight:700; color:var(--cd-gold); line-height:1.15; }
.cd-store-kpi .l { font-size:.7rem; letter-spacing:.07em; text-transform:uppercase; color:var(--cd-text-muted); font-weight:700; }
.cd-store-note { font-size:.78rem; color:var(--cd-text-muted); margin:.5rem 0 0; line-height:1.5; }
.cd-store-warn { color:#e0a33a; }
@media (max-width: 768px) { .cd-store-sel select { max-width:100%; width:100%; } }

/* D-B2 · header activity window, R-3 form. The six pills became a <select> in
   the header row; cdHdrWin and everything reading it are unchanged. */
.cd-stat-period { color:var(--cd-gold); opacity:.85; }

/* R-3 · the FILTER. Secondary weight on purpose - it scopes the stats, it is
   not an action. */
.cd-hdr-sel { display:inline-flex; align-items:center; gap:.5rem; }
.cd-hdr-sel > span {
    font-size:.7rem; letter-spacing:.08em; text-transform:uppercase;
    color:var(--cd-text-muted); font-weight:700;
}
.cd-hdr-sel select {
    background:var(--cd-bg-elevated); color:var(--cd-text);
    border:1px solid var(--cd-border); border-radius:10px;
    padding:.5rem .7rem; font-size:.88rem; min-height:42px; cursor:pointer;
}
.cd-hdr-sel select:hover { border-color:var(--cd-gold); }

/* R-3 · the LINK. Icon only, muted, furthest right - it is a destination, not
   a thing that happens. */
.cd-gear {
    display:inline-flex; align-items:center; justify-content:center;
    width:42px; height:42px; flex:none;
    border:1px solid var(--cd-border); border-radius:10px;
    color:var(--cd-text-muted) !important; text-decoration:none;
    transition:color .15s ease, border-color .15s ease;
}
.cd-gear:hover { color:var(--cd-gold) !important; border-color:var(--cd-gold); }

@media (max-width: 768px) {
    .cd-header-actions { width:100%; flex-wrap:wrap; }
    .cd-hdr-sel { flex:1 1 auto; }
    .cd-hdr-sel select { width:100%; }
}

/* ── B-d · PERSISTENT ORIENTATION BAR ────────────────────────────
   DELIBERATELY THIN. The agreed design said "persistent header with the four
   stats", but the stat grid is ~130px and the page header another ~60px; making
   that sticky eats a third of a phone viewport. The complaint this solves is
   "you cannot tell where you are", which is orientation, not numbers. So the bar
   carries the breadcrumb and a scoped detail line; the four stat cards stay
   where they are. The design round can decide whether to fold them in.

   ⚠ STICKY RISK. base.css:11 and custom.css:10/88 set
   `html, body { overflow-x: hidden !important }`. That is the exact condition
   that can break position:sticky - this codebase has already hit it once
   (mint wizard B-b4: .trading-hub-container's overflow-x broke a sticky bar and
   it had to become position:fixed). If the bar does not stick in testing, the
   fallback is fixed + a top offset; the markup does not change either way.

   z-index 12: above cards (1) and the page, BELOW the open Actions menu (60) so
   a menu still clears it, and far below modals (1000000) and the theme nav
   (99999). Offset uses --imp-header-height (70px in header.php, 75px in
   custom.css) rather than a magic number. */
.cd-topbar {
    /* R-3: hidden by DEFAULT. cdSetCrumbs() decides visibility, but it only runs
       once listings land - so before R-3 this rendered as a visible, empty,
       bordered bar on every page load. Starting hidden removes that flash; the
       JS sets 'flex' explicitly when there is depth to show. */
    display: none;
    position: sticky;
    top: calc(var(--imp-header-height, 70px) + 4px);
    z-index: 12;
    display: flex; align-items: center; gap: .75rem; flex-wrap: wrap;
    background: var(--cd-bg-card);
    border: 1px solid var(--cd-border);
    border-radius: 12px;
    padding: .6rem .9rem;
    margin: 0 0 1.25rem;
    backdrop-filter: blur(10px);
}
.cd-breadcrumb { display:flex; align-items:center; gap:.4rem; flex-wrap:wrap; min-width:0; font-size:.9rem; }
.cd-crumb {
    background:none; border:0; padding:0; cursor:pointer; font:inherit;
    color:var(--cd-text-muted); max-width:22ch; overflow:hidden;
    text-overflow:ellipsis; white-space:nowrap;
}
.cd-crumb:hover { color:var(--cd-gold); }
.cd-crumb.is-current { color:var(--cd-text); cursor:default; font-weight:600; }
.cd-crumb-sep { color:var(--cd-text-muted); opacity:.5; flex:0 0 auto; }
/* B-5 ruling: scoped detail is a MUTED INLINE ROW, not another stat block.
   Rendering it as cards would just move the "seven numbers, two designs"
   problem rather than solve it. */
.cd-scope { margin-left:auto; font-size:.8rem; color:var(--cd-text-muted); white-space:nowrap; }
.cd-scope b { color:var(--cd-text); font-weight:600; }
@media (max-width: 768px) {
    .cd-topbar { top: calc(var(--imp-header-height, 70px) + 2px); padding:.5rem .7rem; }
    .cd-scope { margin-left:0; flex-basis:100%; white-space:normal; }
    .cd-crumb { max-width:14ch; }
}

/* R-5d · the inline create-allowlist section. [hidden] is honoured explicitly
   because the panel rules set display on .cd-lm-panel children and a bare
   [hidden] attribute loses to any display declaration. */
.cd-al-create { border:1px solid var(--cd-border); border-radius:14px; padding:1rem 1.25rem; margin:0 0 1.25rem; }
/* AG · the holder-list generator */
.cd-gen { border:1px solid var(--cd-border); border-radius:14px; padding:1rem 1.25rem; margin:0 0 1.25rem; background:var(--cd-bg-elevated); }
.cd-gen[hidden] { display:none !important; }
.cd-gen h4 { margin:0 0 .35rem; }
.cd-gen-src { display:grid; grid-template-columns:minmax(0,2fr) minmax(0,1fr) auto; gap:.5rem; align-items:start; }
.cd-gen-src input { background:var(--cd-bg-card) !important; color:var(--cd-text); border:1px solid var(--cd-border); border-radius:8px; padding:.5rem .6rem; font-size:.85rem; min-height:40px; width:100%; box-sizing:border-box; }
.cd-gen-list { list-style:none; margin:.75rem 0 0; padding:0; }
.cd-gen-list li { display:flex; align-items:center; gap:.6rem; padding:.5rem 0; border-top:1px solid var(--cd-border); font-size:.85rem; flex-wrap:wrap; }
.cd-gen-list li:first-child { border-top:none; }
.cd-gen-name { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.cd-gen-count { color:var(--cd-text-muted); white-space:nowrap; }
.cd-gen-count b { color:var(--cd-gold); }
.cd-gen-drift { color:#f0a020; }
.cd-gen-x { background:transparent !important; border:0; color:var(--cd-text-muted) !important; cursor:pointer; font-size:1rem; padding:0 .25rem; }
.cd-gen-opts { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:.75rem; margin:.9rem 0 0; }
/* The requirement row is its own question - which wallets qualify - so it sits
   apart from the row that decides how much each one gets. */
.cd-gen-opts-req { grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); }
#cd-gen-full { max-height:16rem; overflow:auto; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.75rem; line-height:1.65; }
.cd-gen-opts label { display:block; font-size:.7rem; letter-spacing:.07em; text-transform:uppercase; color:var(--cd-text-muted); font-weight:700; margin-bottom:.25rem; }
.cd-gen-opts select, .cd-gen-opts input { background:var(--cd-bg-card) !important; color:var(--cd-text); border:1px solid var(--cd-border); border-radius:8px; padding:.45rem .55rem; font-size:.85rem; width:100%; min-height:38px; box-sizing:border-box; }
.cd-gen-prev { margin:.9rem 0 0; padding:.75rem .9rem; border:1px solid var(--cd-border); border-radius:10px; background:var(--cd-bg-card); font-size:.82rem; line-height:1.6; }
.cd-gen-prev b { color:var(--cd-gold); }
.cd-gen-warn { color:#f0a020; }
.cd-gen-i { cursor:help; color:var(--cd-text-muted); border-bottom:1px dotted var(--cd-text-muted); }
@media (max-width:768px){ .cd-gen-src { grid-template-columns:minmax(0,1fr); } }
.cd-al-create[hidden] { display:none !important; }

/* ⚠⚠ R-11d: THE SELECTOR WAS WRONG IN BOTH PREVIOUS ATTEMPTS.
   I wrote `#cd-listing-detail.cd-view.active`, having assumed the three sibling
   views share a class. They do not - each carries its own:
       #cd-collections-view   .cd-view
       #cd-collection-detail  .cd-detail-view
       #cd-listing-detail     .cd-listing-view      <- this one
   The rule therefore matched NOTHING, twice, and no amount of adjusting the
   colours inside it was ever going to make it appear. Read the markup; do not
   infer a class from a sibling element.

   ⚠ R-11c: the R-11b version of this used `--cd-bg` for the fill and the
   `--cd-panel-edge` gradient for the border. Both were technically applied and
   BOTH WERE INVISIBLE: --cd-bg is #08080a, the same near-black as the page
   behind it, and a 1px gradient edge at 20% gold against that reads as nothing.
   A border that is present in the cascade but indistinguishable on screen is
   the same as no border, so this uses a lifted fill and a solid edge instead.

   The manager is now a real panel: its own surface, a visible edge, and the
   section tabs sitting on that surface rather than floating on the page. */
#cd-listing-detail.cd-listing-view.active {
    background: var(--cd-bg-elevated);
    border: 1px solid var(--cd-border);
    border-radius: 18px;
    padding: 1.75rem;
    margin-top: .75rem;
    box-shadow: 0 1px 0 rgba(255,255,255,.03) inset, 0 12px 28px rgba(0,0,0,.35);
}

/* The back button belongs to the page, not to the panel - it leaves it. */
#cd-listing-detail.cd-listing-view.active > .cd-back-btn { margin-bottom: 1.25rem; }

/* The header sits ON the panel, so its own cards need to read one level up
   from the panel rather than one level up from the page. */
#cd-listing-detail.cd-listing-view.active .cd-stat-mini {
    background: var(--cd-bg-card) !important;
}

/* A rule the section tabs can sit on: the nav's underline now spans the panel
   rather than stopping at the content width. */
#cd-listing-detail.cd-listing-view.active .cd-lm-nav {
    margin-left: -1.75rem;
    margin-right: -1.75rem;
    padding-left: 1.75rem;
    padding-right: 1.75rem;
}

@media (max-width: 768px) {
    #cd-listing-detail.cd-listing-view.active { padding: 1.1rem; border-radius: 14px; }
    #cd-listing-detail.cd-listing-view.active .cd-lm-nav {
        margin-left: -1.1rem; margin-right: -1.1rem;
        padding-left: 1.1rem; padding-right: 1.1rem;
    }
}

/* ── R-5a · LISTINGS MANAGER SHELL ──────────────────────────────
   The listing view was 20 lines - a back button, a header, and the allowlists
   section. Allowlists were not a sibling of anything; they were the entire body.
   So this is not a reorganisation, it is the page that should have existed.

   FIFTH tab family. The four that exist are cd-tab (status filter), cd-nav-tab
   (level-1 nav), cd-an-win (analytics window) and cd-hdr-win (retired at R-3).
   Class matching is per-token, so these MUST stay disjoint - D-B2 already shipped
   a bug from an unscoped listener reusing .cd-an-win. Hence cd-lm-*, and a
   container-scoped listener. */
.cd-lm-nav {
    display:flex; gap:.25rem; flex-wrap:wrap; align-items:flex-end;
    border-bottom:1px solid var(--cd-border); margin:0 0 1.25rem;
}
.cd-lm-tab {
    appearance:none; background:transparent; border:0; border-bottom:2px solid transparent;
    border-radius:0; padding:.6rem 1rem; font:inherit; font-size:.92rem; font-weight:600;
    color:var(--cd-text-muted) !important; cursor:pointer;
    transition:color .15s ease, border-color .15s ease;
}
.cd-lm-tab:hover { color:var(--cd-text) !important; }
.cd-lm-tab.is-active { color:var(--cd-gold) !important; border-bottom-color:var(--cd-gold); }
.cd-lm-tab:focus-visible { outline:2px solid var(--cd-gold); outline-offset:2px; }
.cd-lm-panel { display:none; }
.cd-lm-panel.is-active { display:block; }
.cd-lm-row {
    display:flex; align-items:center; justify-content:space-between; gap:1rem;
    flex-wrap:wrap; padding:.85rem 0; border-top:1px solid var(--cd-border);
}
.cd-lm-row:first-child { border-top:none; }
/* The explainer must be free to wrap to a second line WITHOUT pushing the button
   onto a line of its own - flex-wrap alone did that because .cd-lm-what had no
   flex basis to shrink from. text-align is stated explicitly: the theme centres
   inherited text, which left these rows reading as centred headings. */
.cd-lm-row .cd-lm-what { min-width:0; flex:1 1 20rem; text-align:left; }
.cd-lm-row h5, .cd-lm-row p { text-align:left; }
.cd-lm-row > div:last-child { flex:0 0 auto; }
.cd-lm-row h5 { margin:0 0 .15rem; font-size:.95rem; color:var(--cd-text); font-weight:600; }
.cd-lm-row p  { margin:0; font-size:.8rem; color:var(--cd-text-muted); line-height:1.45; }

/* DANGER: its own block, not a row in a dropdown. cdResumeListing mutates with
   NO confirmation, and it used to sit two items from Preview. Stating the
   consequence next to the control is the point of this phase. */
.cd-lm-danger { border:1px solid rgba(239,68,68,.35); border-radius:14px; padding:.25rem 1.1rem; margin-top:.5rem; }
.cd-lm-danger .cd-lm-row { border-top-color:rgba(239,68,68,.2); }
.cd-lm-danger h4 {
    margin:1rem 0 .25rem; font-size:.7rem; letter-spacing:.08em; text-transform:uppercase;
    color:var(--cd-danger); font-weight:700;
}
@media (max-width: 768px) {
    .cd-lm-nav { overflow-x:auto; flex-wrap:nowrap; }
    .cd-lm-tab { flex:0 0 auto; }
    .cd-lm-row { align-items:flex-start; }
}

/* R-4: the 10 card-expand rules (.cd-card-toggle / .cd-card-more / .cd-cur-rows
   / .cd-cur-note) went with the markup they styled. The breakdown now lives in
   the collection detail as .cd-detail-revenue / .cd-det-cur.
   B-e's note is worth keeping as history: the chevron sat inside a card whose
   whole div carries an onclick, so its handler had to call stopPropagation just
   to stop it navigating instead of expanding. Needing that was the signal it was
   in the wrong place. */

.cd-an-windows { display:flex; gap:.4rem; flex-wrap:wrap; margin-bottom:1.25rem; }
/* R-7 */
.cd-an-scope { display:flex; align-items:center; gap:.6rem; flex-wrap:wrap; margin:0 0 .9rem; }
.cd-an-scope label {
    font-size:.7rem; letter-spacing:.08em; text-transform:uppercase;
    color:var(--cd-text-muted); font-weight:700;
}
.cd-an-scope select {
    background:var(--cd-bg-elevated) !important; color:var(--cd-text);
    border:1px solid var(--cd-border); border-radius:10px;
    padding:.5rem .7rem; font-size:.88rem; min-height:42px; cursor:pointer; max-width:24rem;
}
.cd-hold-acts { display:flex; gap:.5rem; flex-wrap:wrap; margin-top:.75rem; }
/* A3 */
.cd-an-cardhead { display:flex; align-items:center; justify-content:space-between; gap:.75rem; flex-wrap:wrap; }
.cd-an-cardhead h4 { margin:0; }
.cd-an-cursel {
    background:var(--cd-bg-elevated) !important; color:var(--cd-text);
    border:1px solid var(--cd-border); border-radius:8px;
    padding:.3rem .5rem; font-size:.8rem; cursor:pointer;
}
.cd-dist-row { display:flex; align-items:center; gap:.6rem; padding:.35rem 0; font-size:.85rem; }
.cd-dist-row span:first-child { min-width:5.5rem; color:var(--cd-text-muted); }
.cd-dist-bar { flex:1; height:8px; border-radius:99px; background:rgba(255,255,255,.06); overflow:hidden; }
.cd-dist-bar i { display:block; height:100%; background:var(--cd-gold); border-radius:99px; }
.cd-dist-row b { min-width:3rem; text-align:right; color:var(--cd-text); font-weight:600; }
@media (max-width: 768px) { .cd-an-scope select { width:100%; max-width:100%; } }
.cd-an-win {
    padding:.45rem .9rem; border-radius:999px; cursor:pointer; font-size:.85rem; font-weight:600;
    background:transparent; color:var(--cd-text-muted); border:1px solid var(--cd-border);
    transition:all .2s ease;
}
.cd-an-win:hover { color:var(--cd-text); border-color:var(--cd-gold); }
.cd-an-win.active { color:var(--cd-gold); border-color:var(--cd-gold); background:var(--cd-bg-card); }

.cd-an-grid {
    /* ⚠ SIX COLUMNS, NOT auto-fit. auto-fit gave every card the same width, so a
       90-day date axis got the same ~330px as a 10-row list - the time series were
       unreadable and the lists were half empty. Six columns lets each card ask for
       what its CONTENT needs: 6 for a dense series, 3 for a paired chart, 2 for a
       list. Explicit, so a card cannot silently land in the wrong size. */
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 1rem;
}
.cd-an-card {
    background:var(--cd-bg-card); border:1px solid var(--cd-border);
    border-radius:14px; padding:1.1rem 1.25rem; min-width:0;
}
/* ⚠ SPECIFICITY, NOT SOURCE ORDER, DECIDED THIS - and it was written wrong.
   `.cd-an-card.cd-an-card` is (0,2,0); `.cd-an-half` and `.cd-an-wide` are
   (0,1,0). The doubled default therefore beat BOTH, and every card rendered at
   span 2 - three per row, the exact opposite of the intent.
   All three now carry the SAME specificity (0,2,0) via the parent, so source
   order decides, and the more specific intent is written last. */
.cd-an-grid > .cd-an-card { grid-column: span 2; }   /* default: a third  - lists, text  */
.cd-an-grid > .cd-an-half { grid-column: span 3; }   /* a half            - paired charts */
.cd-an-grid > .cd-an-wide { grid-column: 1 / -1; }   /* full width        - dense series  */

/* ⚠ minmax(0,1fr) above matters: a grid item's default min-width is auto, so a
   long label or a wide canvas would push its column past its share and overflow
   the row even with the span pinned. */

/* FINAL PHASE - the ragged holes. Two causes: wide cards break each row, and any
   hidden card leaves its slot empty. After the taxon merge only ONE card can hide
   (floor, and it now explains rather than vanishing), so what remains is making
   the short cards fill the row they land in rather than leaving a gap beside a
   taller neighbour. grid-auto-rows + align-content does that without pinning any
   card to a position, which would break at every breakpoint. */
.cd-an-grid { align-content:start; grid-auto-flow:row dense; }
.cd-an-card { display:flex; flex-direction:column; }
.cd-an-card > :last-child { margin-top:auto; }
.cd-an-canvas-wrap { flex:1; min-height:220px; }
.cd-an-card h4 {
    margin:0 0 .2rem; font-size:.78rem; letter-spacing:.08em; text-transform:uppercase;
    color:var(--cd-text-muted); font-weight:700;
}
.cd-an-note { font-size:.78rem; color:var(--cd-text-muted); margin:.45rem 0 0; }
.cd-an-canvas-wrap { position:relative; height:240px; margin-top:.7rem; }
.cd-an-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:1rem; margin-bottom:1rem; }
.cd-an-kpi { background:var(--cd-bg-card); border:1px solid var(--cd-border); border-radius:14px; padding:1rem 1.1rem; }
.cd-an-kpi .v { font-size:1.8rem; font-weight:700; color:var(--cd-gold); line-height:1.15; }
.cd-an-kpi .l { font-size:.74rem; letter-spacing:.07em; text-transform:uppercase; color:var(--cd-text-muted); font-weight:700; }
.cd-an-cur { display:flex; justify-content:space-between; padding:.45rem 0; border-top:1px solid var(--cd-border); font-size:.9rem; }
.cd-an-cur:first-child { border-top:none; }
.cd-an-cur b { color:var(--cd-gold); }
.cd-an-rows { margin:.5rem 0 0; padding:0; list-style:none; }
.cd-an-rows li {
    display:flex; justify-content:space-between; gap:.75rem; font-size:.86rem;
    padding:.35rem 0; border-top:1px solid var(--cd-border);
}
.cd-an-rows li span:first-child { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.cd-an-rows li span:last-child { color:var(--cd-text-muted); flex:0 0 auto; }

.cd-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    border-bottom: 1px solid var(--cd-border);
    padding-bottom: 0.5rem;
    flex-wrap: wrap;
}

.cd-tab {
    padding: 0.75rem 1.5rem;
    background: transparent;
    color: var(--cd-text-muted);
    border: none;
    border-radius: 8px 8px 0 0;
    cursor: pointer;
    font-weight: 500;
    transition: all 0.2s ease;
}

.cd-tab:hover {
    color: var(--cd-text);
    background: var(--cd-bg-card);
}

.cd-tab.active {
    color: var(--cd-gold);
    background: var(--cd-bg-card);
    border-bottom: 2px solid var(--cd-gold);
}

/* ═══ Collection Cards Grid — 2-4 columns on desktop ═══ */
.cd-collections-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.5rem;
}

@media (max-width: 1200px) {
    .cd-collections-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 992px) {
    .cd-collections-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

.cd-collection-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.cd-badge-active {
    background: rgba(16, 185, 129, 0.9);
    color: #fff;
}

.cd-badge-draft {
    background: rgba(107, 114, 128, 0.9);
    color: #fff;
}

.cd-badge-paused {
    background: rgba(245, 158, 11, 0.9);
    color: #000;
}

.cd-badge-sold-out {
    background: rgba(239, 68, 68, 0.9);
    color: #fff;
}

.cd-collection-info {
    padding: 1.25rem;
}

/* ═══ Collections Grid View ═══ */
/* v669: The JS has always toggled .active on #cd-collections-view (see renderCollectionDetail:
   "Hide collections view, show detail view"), but no .cd-view rule ever existed -- so the grid
   never hid and stacked above the detail, leaving it ambiguous which collection was being
   edited. This restores the intended view swap. Sole user: #cd-collections-view. */
.cd-view {
    display: none;
}

.cd-view.active {
    display: block;
}

/* ═══ Wallet Rows (v678) ═══ */
/* One row per wallet with its own amount box -- holder allocations are per wallet, so
   "address, number" text was doing the work a proper field should. */
.cd-wallet-rows {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    margin-bottom: 0.5rem;
    max-height: 260px;
    overflow-y: auto;
}

.cd-wallet-row {
    display: flex;
    gap: 0.4rem;
    align-items: center;
}

/* v685: these inputs sit in .cd-wallet-row / #inline-qty-wrap, not .cd-form-group, so they
   missed the shared input styling and fell back to the browser default (white on white).
   Mirrors the .cd-form-group input rule, including the autofill text-fill fix. */
.cd-wallet-row input,
#inline-qty-wrap input {
    padding: 0.55rem 0.75rem;
    background: var(--cd-bg-elevated) !important;
    background-color: var(--cd-bg-elevated) !important;
    border: 1px solid var(--cd-border);
    border-radius: 8px;
    color: #e8e8e8 !important;
    font-size: 0.95rem;
    -webkit-text-fill-color: #e8e8e8 !important;
}

.cd-wallet-row input:focus,
#inline-qty-wrap input:focus {
    outline: none;
    border-color: var(--cd-gold);
}

.cd-wallet-row input::placeholder,
#inline-qty-wrap input::placeholder {
    color: rgba(232, 232, 232, 0.4);
}

.cd-wallet-row input:-webkit-autofill,
.cd-wallet-row input:-webkit-autofill:hover,
.cd-wallet-row input:-webkit-autofill:focus,
#inline-qty-wrap input:-webkit-autofill,
#inline-qty-wrap input:-webkit-autofill:hover,
#inline-qty-wrap input:-webkit-autofill:focus {
    -webkit-box-shadow: 0 0 0 1000px var(--cd-bg-elevated) inset !important;
    -webkit-text-fill-color: #e8e8e8 !important;
}

.cd-wallet-row input.cd-wallet-addr {
    flex: 1;
    min-width: 0;
}

.cd-wallet-row input.cd-wallet-qty {
    width: 84px;
    flex: 0 0 auto;
}

.cd-wallet-row .cd-wallet-remove {
    flex: 0 0 auto;
    background: none;
    border: 1px solid var(--cd-border);
    color: var(--cd-text-muted);
    border-radius: 6px;
    width: 30px;
    height: 30px;
    cursor: pointer;
    line-height: 1;
}

.cd-wallet-row .cd-wallet-remove:hover {
    color: #ff6b6b;
    border-color: #ff6b6b;
}

.cd-wallet-rows-head {
    display: flex;
    gap: 0.4rem;
    font-size: 0.75rem;
    color: var(--cd-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 0.25rem;
}

.cd-wallet-rows-head .h-addr { flex: 1; }
.cd-wallet-rows-head .h-qty  { width: 84px; flex: 0 0 auto; }
.cd-wallet-rows-head .h-sp   { width: 30px; flex: 0 0 auto; }

/* ═══ Listings Card Grid (v672) ═══ */
/* Replaces the listings table. Cards make each listing a distinct object to act on,
   which matters because pricing, schedules, mint limits and allowlists are all per-listing. */
.cd-listings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}


/* R-8: v673's note went with the rule it described - the card and its dropdown
   are both gone. A comment explaining a rule that no longer exists is the residue
   R-3 and R-4 had to clean up afterwards. */

@media (max-width: 600px) {
    .cd-listings-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
}

/* ═══ Listing Detail View (v671) ═══ */
/* Third level: collections grid -> collection detail (listings) -> listing detail.
   Allowlists live here because they are per-LISTING in the database, so opening them
   from a specific listing makes their scope unambiguous. */
.cd-listing-view {
    display: none;
}

.cd-listing-view.active {
    display: block;
}

/* ═══ Collection Detail View ═══ */
.cd-detail-view {
    display: none;
}

.cd-detail-view.active {
    display: block;
}

/* R-4: was a bare clickable div. It had hover and transitions, so it LOOKED
   like a control - but a div is not focusable, not keyboard-reachable and has no
   button semantics, which is why it read as text. Now a real button element with
   the bordered treatment R-3 gave the gear, so it looks like the control it is.
   (No literal tags in this comment: prose markup has thrown a false positive in
   three separate gates now - CP-T2, R-2b and here.)
   `button` sits in the custom.css colour nuke (color:#fff !important), hence the
   armour on colour only - the same tax the collection page pays. */
.cd-back-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    background: transparent;
    border: 1px solid var(--cd-border);
    border-radius: 10px;
    color: var(--cd-text-muted) !important;
    font: inherit;
    font-size: 0.95rem;
    cursor: pointer;
    margin-bottom: 1.5rem;
    padding: 0.5rem 0.9rem;
    transition: color 0.2s ease, border-color 0.2s ease;
}
.cd-back-btn:focus-visible { outline: 2px solid var(--cd-gold); outline-offset: 2px; }

.cd-back-btn:hover {
    color: var(--cd-gold) !important;
    border-color: var(--cd-gold);
}

.cd-detail-header {
    display: flex;
    gap: 2rem;
    margin-bottom: 2rem;
    align-items: flex-start;
}

.cd-detail-cover {
    width: 200px;
    height: 200px;
    border-radius: 12px;
    overflow: hidden;
    flex-shrink: 0;
}

.cd-detail-cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.cd-detail-info {
    flex: 1;
}

.cd-detail-title {
    font-size: 1.8rem;
    font-weight: 700;
    color: var(--cd-text);
    margin-bottom: 1rem;
}

/* v69: Styled stat boxes for collection detail */
.cd-detail-stats {
    /* R-4: was repeat(3,1fr) / max-width 500px. A fourth box under those values
       gave three across and one orphaned beneath at 166px each. auto-fit with a
       floor reflows 4 / 2 / 1 by width with no extra breakpoint. */
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 1rem;
    margin-bottom: 1.25rem;
    max-width: 680px;
}

/* R-4: the per-currency breakdown, relocated from the collection CARD. */
.cd-detail-revenue { border-radius:14px; padding:1rem 1.25rem; margin:0 0 1.25rem; max-width:680px; }
.cd-detail-revenue h5 {
    margin:0 0 .5rem; font-size:.7rem; letter-spacing:.08em; text-transform:uppercase;
    color:var(--cd-text-muted); font-weight:700;
}
.cd-det-cur { margin:0 0 .6rem; padding:0; list-style:none; }
.cd-det-cur li {
    display:flex; justify-content:space-between; gap:.75rem; font-size:.88rem;
    padding:.4rem 0; border-top:1px solid var(--cd-border);
}
.cd-det-cur li:first-child { border-top:none; }
.cd-det-cur li b { color:var(--cd-gold); font-weight:600; }
.cd-det-note { font-size:.75rem; color:var(--cd-text-muted); margin:0; line-height:1.5; }

.cd-stat-mini {
    padding: 1rem 1.25rem !important;
}

.cd-stat-mini .cd-stat-label {
    font-size: 0.7rem;
    margin-bottom: 0.25rem;
}

.cd-stat-mini .cd-stat-value {
    font-size: 1.5rem;
}

.cd-stat-mini .cd-stat-sub {
    font-size: 0.75rem;
    margin-left: 0.25rem;
}

.cd-detail-meta {
    display: flex;
    gap: 2rem;
    margin-bottom: 1rem;
    color: var(--cd-text-muted);
}

.cd-detail-actions {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

/* R-9d - the collection page's on-ledger strip. After R-9c a single-listing
   collection skips this page entirely, so it now only renders when there is more
   than one listing - which is exactly when it is worth filling in. */
.cd-cl-ledger { margin: 0 0 1.25rem; max-width: 680px; }
.cd-cl-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:.75rem; }
.cd-cl-kpi { background:var(--cd-bg-card); border:1px solid var(--cd-border); border-radius:12px; padding:.75rem .85rem; }
.cd-cl-kpi .v { font-size:1.25rem; font-weight:700; color:var(--cd-gold); line-height:1.15; }
.cd-cl-kpi .l { font-size:.65rem; letter-spacing:.07em; text-transform:uppercase; color:var(--cd-text-muted); font-weight:700; }
.cd-cl-note { font-size:.75rem; color:var(--cd-text-muted); margin:.6rem 0 0; line-height:1.5; }

/* R-11: the .cd-collection-card family went with the grid it styled. Fourth
   time this residue has come up (R-3, R-4, R-8, now) - removing markup without
   its CSS leaves rules describing elements that no longer exist.
   ⚠ The `.cd-collection-card.cd-surface:hover` specificity note at the top of
   this file is kept: it documents WHY .cd-surface needs the extra specificity,
   and .cd-stat-card still relies on it. */

/* R-8: the .cd-listing-card and .cd-actions-* rules went with the markup they
   styled - and .cd-progress-bar with them, checked first: the listing card was its
   only consumer. */
.cd-listing-row { cursor: pointer; transition: background .15s ease; }
.cd-listing-row:focus-visible { outline: 2px solid var(--cd-gold); outline-offset: -2px; }
.cd-lrow-cover { width: 56px; }
.cd-lrow-cover img { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; display: block; background: var(--cd-bg-elevated); }
.cd-lrow-name b { display: block; font-weight: 600; color: var(--cd-text); }
.cd-lrow-sub { display: block; font-size: .75rem; color: var(--cd-text-muted); margin-top: .15rem; }
.cd-lrow-num { white-space: nowrap; }
/* R-11 */
.cd-lrow-coll { max-width: 14rem; }
.cd-lrow-link {
    background: transparent !important; border: 0; padding: 0; font: inherit;
    color: var(--cd-text-muted) !important; cursor: pointer; text-align: left;
    text-decoration: underline; text-underline-offset: 3px;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 100%;
}
.cd-lrow-link:hover { color: var(--cd-gold) !important; }
.cd-lrow-link:focus-visible { outline: 2px solid var(--cd-gold); outline-offset: 2px; }
.cd-lrow-muted { color: var(--cd-text-muted); }

/* ═══ Listings Table ═══ */
.cd-listings-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 1.5rem;
}

.cd-listings-table th,
.cd-listings-table td {
    padding: 1rem;
    text-align: left;
    border-bottom: 1px solid var(--cd-border);
}

.cd-listings-table th {
    color: var(--cd-text-muted);
    font-weight: 500;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.cd-listings-table td {
    color: var(--cd-text);
}

.cd-listing-row:hover {
    background: var(--cd-bg-hover);
}

.cd-listing-name {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.cd-listing-thumb {
    width: 50px;
    height: 50px;
    border-radius: 8px;
    object-fit: cover;
}

.cd-listing-title {
    font-weight: 600;
}

.cd-listing-type {
    font-size: 0.8rem;
    color: var(--cd-text-muted);
}

.cd-status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 600;
}

.cd-status-active { background: rgba(16, 185, 129, 0.2); color: var(--cd-success); }
.cd-status-draft { background: rgba(107, 114, 128, 0.2); color: #9ca3af; }
.cd-status-paused { background: rgba(245, 158, 11, 0.2); color: var(--cd-warning); }
.cd-status-sold_out { background: rgba(239, 68, 68, 0.2); color: var(--cd-danger); }
.cd-status-cancelled { background: rgba(107, 114, 128, 0.2); color: #6b7280; }
/* v233 */
.cd-status-scheduled { background: rgba(99, 102, 241, 0.2); color: #818cf8; }
.cd-launch-info { font-size: 0.7rem; color: var(--cd-text-muted); margin-top: 0.25rem; }

/* ═══ Listing Actions Dropdown ═══ */

/* ═══ Edit Price Modal ═══ */
.cd-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.8);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000000; /* v678: theme header/footer use up to 100000 -- modal must clear them */
    backdrop-filter: blur(4px);
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
    overflow-y: auto; /* v84: Allow overlay scroll if needed */
    padding: 2rem 1rem; /* v84: Add padding for small screens */
}

.cd-modal-overlay.active {
    opacity: 1;
    visibility: visible;
}

.cd-modal {
    background: var(--cd-bg-card);
    border: 1px solid var(--cd-border);
    border-radius: 16px;
    padding: 2rem;
    max-width: 500px; /* v85: Wider for better pricing UI */
    width: 90%;
    max-height: 85vh; /* v84: Prevent exceeding viewport */
    overflow-y: auto; /* v84: Make modal content scrollable */
    transform: scale(0.9);
    transition: transform 0.3s ease;
    margin: auto; /* v84: Center when scrolling */
}

.cd-modal-overlay.active .cd-modal {
    transform: scale(1);
}

.cd-modal h3 {
    font-family: 'Cinzel Decorative', 'Cinzel', 'Times New Roman', serif;
    font-size: 1.3rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    background: linear-gradient(180deg, #ffe066 0%, var(--cd-gold-light, #f0d060) 40%, var(--cd-gold-dark, #a68b2a) 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    filter: drop-shadow(0 0 14px rgba(212, 175, 55, 0.3));
    margin-bottom: 1.5rem;
}

.cd-form-group {
    margin-bottom: 1.5rem;
}

.cd-form-group label {
    display: block;
    color: var(--cd-text-muted);
    font-size: 0.9rem;
    margin-bottom: 0.5rem;
}

.cd-form-group input,
.cd-form-group input[type="text"],
.cd-form-group input[type="number"] {
    width: 100%;
    padding: 0.75rem 1rem;
    background: var(--cd-bg-elevated) !important;
    background-color: var(--cd-bg-elevated) !important;
    border: 1px solid var(--cd-border);
    border-radius: 8px;
    color: #e8e8e8 !important;
    font-size: 1rem;
    -webkit-text-fill-color: #e8e8e8 !important;
}

/* Fix for browser autofill */
.cd-form-group input:-webkit-autofill,
.cd-form-group input:-webkit-autofill:hover,
.cd-form-group input:-webkit-autofill:focus {
    -webkit-box-shadow: 0 0 0 1000px var(--cd-bg-elevated) inset !important;
    -webkit-text-fill-color: #e8e8e8 !important;
    background-color: var(--cd-bg-elevated) !important;
    caret-color: #e8e8e8 !important;
}

.cd-form-group input:focus {
    outline: none;
    border-color: var(--cd-gold);
}

.cd-modal-actions {
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
}

/* ═══ Purchase History Section ═══ */
.cd-purchase-history {
    margin-top: 2rem;
}

.cd-purchase-history h3 {
    font-family: 'Cinzel', 'Times New Roman', serif;
    font-size: 1.2rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    background: linear-gradient(180deg, #ffe066 0%, var(--cd-gold-light, #f0d060) 40%, var(--cd-gold-dark, #a68b2a) 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    filter: drop-shadow(0 0 10px rgba(212, 175, 55, 0.25));
    margin-bottom: 1rem;
}

.cd-purchase-table {
    width: 100%;
    border-collapse: collapse;
}

.cd-purchase-table th,
.cd-purchase-table td {
    padding: 0.75rem 1rem;
    text-align: left;
    border-bottom: 1px solid var(--cd-border);
    font-size: 0.9rem;
}

.cd-purchase-table th {
    color: var(--cd-text-muted);
    font-weight: 500;
}

.cd-purchase-table td {
    color: var(--cd-text);
}

.cd-delivery-status {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.cd-delivery-status.delivered { color: var(--cd-success); }
.cd-delivery-status.pending { color: var(--cd-warning); }

/* ═══ Empty State ═══ */
.cd-empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: var(--cd-text-muted);
}

.cd-empty-state-icon {
    font-size: 3rem;
    margin-bottom: 1rem;
}

.cd-empty-state h3 {
    color: var(--cd-text);
    margin-bottom: 0.5rem;
}

.cd-empty-state p {
    margin-bottom: 1.5rem;
}

/* ═══ Loading State ═══ */
.cd-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 4rem;
}

.cd-spinner {
    width: 50px;
    height: 50px;
    border: 3px solid var(--cd-border);
    border-top-color: var(--cd-gold);
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-bottom: 1rem;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* ═══ Toast Notifications ═══ */
.cd-toast-container {
    position: fixed;
    bottom: 2rem;
    right: 2rem;
    z-index: 10001;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.cd-toast {
    padding: 1rem 1.5rem;
    border-radius: 8px;
    color: #fff;
    font-weight: 500;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

.cd-toast-success { background: var(--cd-success); }
.cd-toast-error { background: var(--cd-danger); }
.cd-toast-warning { background: var(--cd-warning); color: #000; }

/* ═══ Allowlists Section ═══ */
.cd-allowlists-section {
    margin-top: 2.5rem;
    padding-top: 2rem;
    border-top: 1px solid var(--cd-border);
}

.cd-allowlists-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}

.cd-allowlists-header h3 {
    font-family: 'Cinzel', 'Times New Roman', serif;
    font-size: 1.2rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    background: linear-gradient(180deg, #ffe066 0%, var(--cd-gold-light, #f0d060) 40%, var(--cd-gold-dark, #a68b2a) 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    filter: drop-shadow(0 0 10px rgba(212, 175, 55, 0.25));
    margin: 0;
}

.cd-allowlists-description {
    color: var(--cd-text-muted);
    font-size: 0.9rem;
    margin-bottom: 1.5rem;
}

.cd-btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.85rem;
}

.cd-allowlists-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1rem;
}

.cd-allowlist-card {
    background: var(--cd-bg-secondary);
    border: 1px solid var(--cd-border);
    border-radius: 12px;
    padding: 1.25rem;
    transition: all 0.2s ease;
}

.cd-allowlist-card:hover {
    border-color: var(--cd-gold);
}

.cd-allowlist-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.75rem;
}

.cd-allowlist-name {
    font-weight: 600;
    color: var(--cd-text);
    font-size: 1rem;
}

.cd-allowlist-type {
    font-size: 0.75rem;
    padding: 3px 8px;
    border-radius: 12px;
    font-weight: 500;
}

.cd-type-early_access { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }
.cd-type-discount { background: rgba(16, 185, 129, 0.2); color: #34d399; }
.cd-type-exclusive { background: rgba(139, 92, 246, 0.2); color: #a78bfa; }
.cd-type-limit_override { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
.cd-type-discount_limited { background: rgba(236, 72, 153, 0.2); color: #f472b6; }

.cd-allowlist-meta {
    display: flex;
    gap: 1rem;
    margin-bottom: 1rem;
    font-size: 0.85rem;
    color: var(--cd-text-muted);
}

.cd-allowlist-actions {
    display: flex;
    gap: 0.5rem;
}

.cd-allowlist-actions button {
    flex: 1;
    padding: 0.5rem;
    font-size: 0.8rem;
    border-radius: 6px;
    cursor: pointer;
    border: 1px solid var(--cd-border);
    background: var(--cd-bg-card);
    color: var(--cd-text);
    transition: all 0.2s ease;
}

.cd-allowlist-actions button:hover {
    border-color: var(--cd-gold);
    color: var(--cd-gold);
}

.cd-allowlist-inactive {
    opacity: 0.6;
}

/* ═══ Modal Variations ═══ */
.cd-modal-wide {
    width: 480px;
    max-width: 95vw;
}

.cd-modal-large {
    width: 600px;
    max-width: 95vw;
    max-height: 80vh;
    overflow-y: auto;
}

.cd-form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.cd-form-group textarea {
    width: 100%;
    padding: 0.75rem 1rem;
    background: var(--cd-bg-secondary);
    border: 1px solid var(--cd-border);
    border-radius: 8px;
    color: var(--cd-text);
    font-size: 0.95rem;
    resize: vertical;
}

/* v597 FIX: #inline-wallets (allowlist-create wallet box) sits outside .cd-form-group,
   so it missed the textarea styling above and rendered white text on a default white
   background (unreadable). Mirror the readable form-group textarea style. */
#inline-wallets {
    width: 100%;
    padding: 0.75rem 1rem;
    background: var(--cd-bg-secondary);
    border: 1px solid var(--cd-border);
    border-radius: 8px;
    color: var(--cd-text);
    font-size: 0.95rem;
    resize: vertical;
}

.cd-form-group select {
    width: 100%;
    padding: 0.75rem 1rem;
    background: var(--cd-bg-secondary);
    border: 1px solid var(--cd-border);
    border-radius: 8px;
    color: var(--cd-text);
    font-size: 0.95rem;
}

/* ═══ Entries Modal ═══ */
.cd-entries-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    border-bottom: 1px solid var(--cd-border);
    padding-bottom: 0.5rem;
}

.cd-entries-tab {
    padding: 0.5rem 1rem;
    background: transparent;
    border: none;
    color: var(--cd-text-muted);
    cursor: pointer;
    font-size: 0.9rem;
    border-radius: 6px 6px 0 0;
    transition: all 0.2s ease;
}

.cd-entries-tab:hover {
    color: var(--cd-text);
}

.cd-entries-tab.active {
    color: var(--cd-gold);
    background: var(--cd-bg-card);
}

.cd-entries-list {
    max-height: 300px;
    overflow-y: auto;
}

.cd-entry-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem;
    border-bottom: 1px solid var(--cd-border);
}

.cd-entry-wallet {
    font-family: monospace;
    font-size: 0.85rem;
    color: var(--cd-text);
}

.cd-entry-label {
    font-size: 0.8rem;
    color: var(--cd-text-muted);
    margin-left: 0.5rem;
}

.cd-entry-remove {
    background: transparent;
    border: none;
    color: var(--cd-danger);
    cursor: pointer;
    padding: 0.25rem 0.5rem;
    font-size: 0.8rem;
}

.cd-csv-help {
    font-size: 0.85rem;
    color: var(--cd-text-muted);
    margin-bottom: 0.5rem;
}

.cd-csv-help code {
    background: var(--cd-bg-secondary);
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.8rem;
}

.cd-no-allowlists {
    text-align: center;
    padding: 2rem;
    color: var(--cd-text-muted);
}

/* ═══ Responsive ═══ */
@media (max-width: 768px) {
    .cd-container {
        padding: 1rem;
    }
    
    .cd-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .cd-header h1 {
        font-size: 1.5rem;
    }
    
    .cd-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .cd-collections-grid {
        grid-template-columns: 1fr;
    }
    
    .cd-detail-header {
        flex-direction: column;
    }
    
    .cd-detail-cover {
        width: 100%;
        height: 200px;
    }
    
    .cd-listings-table {
        display: block;
        overflow-x: auto;
    }
    
    /* B-c: same treatment as the filter row below, or three tabs wrap badly. */
    .cd-navtabs {
        overflow-x: auto;
        flex-wrap: nowrap;
        -webkit-overflow-scrolling: touch;
    }
    .cd-nav-tab { flex-shrink: 0; padding: 0.55rem 1rem; font-size: 0.92rem; }
    .cd-an-windows { overflow-x: auto; flex-wrap: nowrap; -webkit-overflow-scrolling: touch; }
    .cd-an-win { flex-shrink: 0; }
    .cd-an-canvas-wrap { height: 200px; }

    .cd-tabs {
        overflow-x: auto;
        flex-wrap: nowrap;
        -webkit-overflow-scrolling: touch;
    }
    
    .cd-tab {
        flex-shrink: 0;
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
    }
}

@media (max-width: 480px) {
    .cd-stats-grid {
        grid-template-columns: 1fr;
    }
    
    .cd-stat-value {
        font-size: 1.5rem;
    }
}
/* v732 (A4b polish): modal selects/inputs were clipping their text */
.cd-modal select, .cd-modal input[type="number"], .cd-modal input[type="text"] {
    min-height: 42px; line-height: 1.35; padding: 8px 10px; box-sizing: border-box;
}
.cd-matrix-row .cd-mx-value { min-width: 130px; }

/* ══════════════════════════════════════════════════════════════════════════
   R-10m · MOBILE LAYOUT
   ══════════════════════════════════════════════════════════════════════════
   THIRTEEN grid/flex blocks had no mobile rule at all. Every media query in
   this file targeted the HEADER stats, the container, the detail header and
   the listings table - the collection-detail BODY was never given one, which
   is why the stat row ran off screen, the revenue card clipped mid-word and
   "Preview" was cut in half.

   The rule applied: TWO per row where the content is a short figure, ONE per
   row where it is a sentence, a chart or a price pair.

   ⚠ WHY LOWERING THE FLOORS WOULD NOT HAVE WORKED. These grids are all
   `repeat(auto-fit, minmax(Npx, 1fr))`. auto-fit cannot place a column
   narrower than its floor, so a 330px floor on a 360px viewport overflows
   however many columns it chooses. Pinning the COUNT is the fix; lowering
   the floor only moves the breakpoint at which it breaks.

   The listings TABLE keeps its own horizontal scroll (R-8). Six columns is
   genuinely wide and scrolling it is the honest answer.

   Placed at the END of the stylesheet deliberately - these override earlier
   top-level rules of equal specificity, so source order is what decides. */
@media (max-width: 768px) {
    /* TWO PER ROW - short figures */
    .cd-detail-stats,
    .cd-cl-kpis,
    .cd-store-kpis,
    .cd-an-kpis {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    /* ONE PER ROW - each holds a sentence, a chart or a price pair */
    .cd-ov-grid,
    .cd-store-grid,
    .cd-allowlists-list {
        grid-template-columns: minmax(0, 1fr);
    }
    /* One column on a phone: every span collapses to the full width. */
    .cd-an-grid { grid-template-columns: minmax(0, 1fr); }
    .cd-an-grid > .cd-an-card,
    .cd-an-grid > .cd-an-half,
    .cd-an-grid > .cd-an-wide { grid-column: 1 / -1; }

    /* ⚠ minmax(0,1fr), never plain 1fr. A grid item's default min-width is
       auto, so one long unbroken string - a wallet address, a collection
       name - pushes its column past its share and the row overflows even
       with the count pinned. This is the part that is easy to miss. */
    .cd-detail-revenue,
    .cd-cl-ledger,
    .cd-detail-stats,
    .cd-cl-kpis { max-width: 100%; }

    /* The revenue rows are label + amount. Let the amount drop beneath the
       label rather than clip - the amounts are the point of the card. */
    .cd-det-cur li,
    .cd-cur-rows li {
        flex-wrap: wrap;
        gap: .15rem .6rem;
    }
    .cd-det-cur li span,
    .cd-det-cur li b { min-width: 0; overflow-wrap: anywhere; }

    /* Three buttons at natural width do not fit side by side. */
    .cd-detail-actions { flex-direction: column; }
    .cd-detail-actions > * { width: 100%; justify-content: center; }
}

/* Between phone and desktop: two columns. A third becomes a half, and anything
   already half or wide takes the full row rather than being squeezed. */
@media (min-width: 769px) and (max-width: 1100px) {
    .cd-an-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .cd-an-grid > .cd-an-card { grid-column: span 1; }
    .cd-an-grid > .cd-an-half,
    .cd-an-grid > .cd-an-wide { grid-column: 1 / -1; }
}

@media (max-width: 420px) {
    /* Below ~420px, two columns of a four-figure stat start truncating the
       numbers themselves - worse than a taller page. */
    .cd-detail-stats,
    .cd-cl-kpis,
    .cd-store-kpis,
    .cd-an-kpis {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>

<div id="creator-dashboard-page">
<?php /* R-5e: ic-gear was a circle with eight radiating lines - a SUN, not a cog.
   Mis-named in the sprite this was copied from, and used at five sites here
   (header settings, Actions button, Manage listing, Edit Allowlist title, the
   allowlist Edit button) - every one of which wanted a cog. Replaced the SYMBOL
   rather than the five references, so all five are fixed at once and
   page-mint.php stays untouched. */ ?>
<?php /* R-2b: the icon sprite, copied verbatim from page-mint.php (L415-454).
   A use-href reference resolves ONLY against symbols present in the SAME
   document, so this must be emitted here. Copied rather than shared:
   page-mint.php is stable and high-value. */ ?>
<svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true">
<defs>
<symbol id="ic-book" viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></symbol>
<symbol id="ic-warning" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></symbol>
<symbol id="ic-check-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M8 12.5l2.5 2.5L16 9.5"/></symbol>
<symbol id="ic-x-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></symbol>
<symbol id="ic-lock" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></symbol>
<symbol id="ic-shield" viewBox="0 0 24 24"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z"/></symbol>
<symbol id="ic-music" viewBox="0 0 24 24"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></symbol>
<symbol id="ic-clapper" viewBox="0 0 24 24"><rect x="2" y="8" width="20" height="13" rx="2"/><path d="M2 8l3-5.5 5 1-3 5M12 3.5l5 1-3 5"/></symbol>
<symbol id="ic-video" viewBox="0 0 24 24"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2"/></symbol>
<symbol id="ic-disc" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></symbol>
<symbol id="ic-image" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></symbol>
<symbol id="ic-palette" viewBox="0 0 24 24"><path d="M12 2a10 10 0 0 0 0 20c1.5 0 2.2-.9 2.2-2 0-1.2 1-2 2.2-2h1.8a3.8 3.8 0 0 0 3.8-3.8C22 7.6 17.5 2 12 2z"/><circle cx="7.5" cy="10.5" r="1"/><circle cx="12" cy="7.5" r="1"/><circle cx="16.5" cy="10.5" r="1"/></symbol>
<symbol id="ic-scroll" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/></symbol>
<symbol id="ic-bot" viewBox="0 0 24 24"><rect x="4" y="8" width="16" height="12" rx="2"/><path d="M12 8V4"/><circle cx="12" cy="3" r="1"/><circle cx="9" cy="13" r="1"/><circle cx="15" cy="13" r="1"/><path d="M9 17h6"/></symbol>
<symbol id="ic-coins" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v12"/><path d="M15.5 9.5c0-1.2-1.6-2-3.5-2s-3.5.8-3.5 2 1.2 1.7 3.5 2.2 3.5 1 3.5 2.2-1.6 2-3.5 2-3.5-.8-3.5-2"/></symbol>
<symbol id="ic-clipboard" viewBox="0 0 24 24"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></symbol>
<symbol id="ic-folder" viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></symbol>
<symbol id="ic-pencil" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></symbol>
<symbol id="ic-refresh" viewBox="0 0 24 24"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></symbol>
<symbol id="ic-bolt" viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></symbol>
<symbol id="ic-bulb" viewBox="0 0 24 24"><path d="M9 18h6M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.4 1 2.3h6c0-.9.4-1.8 1-2.3A7 7 0 0 0 12 2z"/></symbol>
<symbol id="ic-dice" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.2"/><circle cx="15.5" cy="15.5" r="1.2"/><circle cx="15.5" cy="8.5" r="1.2"/><circle cx="8.5" cy="15.5" r="1.2"/></symbol>
<symbol id="ic-calendar" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></symbol>
<symbol id="ic-sparkle" viewBox="0 0 24 24"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 3l.7 1.8L21.5 5.5l-1.8.7L19 8l-.7-1.8-1.8-.7 1.8-.7z"/></symbol>
<symbol id="ic-gift" viewBox="0 0 24 24"><rect x="3" y="8" width="18" height="4"/><path d="M12 8v13M5 12v9h14v-9"/><path d="M12 8c-2.5 0-4.5-1.2-4.5-2.8S9 2.6 10.4 3.2 12 8 12 8zm0 0c2.5 0 4.5-1.2 4.5-2.8S15 2.6 13.6 3.2 12 8 12 8z"/></symbol>
<symbol id="ic-drop" viewBox="0 0 24 24"><path d="M12 2.7S6 10 6 14a6 6 0 0 0 12 0c0-4-6-11.3-6-11.3z"/></symbol>
<symbol id="ic-box" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4a2 2 0 0 0 1-1.7z"/><path d="M3.3 7l8.7 5 8.7-5M12 22V12"/></symbol>
<symbol id="ic-gear" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></symbol>
<symbol id="ic-link" viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></symbol>
<symbol id="ic-rocket" viewBox="0 0 24 24"><path d="M5 15c-1.5 1.3-2 5.5-2 5.5s4.2-.5 5.5-2c.8-.9.7-2.2-.1-3s-2.5-.4-3.4-.5z"/><path d="M13 15l-4-4c1-2.6 2.4-5 4.2-6.8A12.6 12.6 0 0 1 22 2c0 2.7-.9 7.6-6.2 8.8-1.8 1.8-.2 3.2-2.8 4.2z"/><path d="M9 11H4.5S5 8 6.5 7 11 7 11 7M13 15v4.5s3-.5 4-2 0-4.5 0-4.5"/></symbol>
<symbol id="ic-smile" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></symbol>
<symbol id="ic-diamond" viewBox="0 0 24 24"><path d="M6 3h12l4 6-10 12L2 9z"/><path d="M2 9h20M12 3L8 9l4 12 4-12-4-6"/></symbol>
<symbol id="ic-volume" viewBox="0 0 24 24"><path d="M11 5L6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M19 5a10 10 0 0 1 0 14"/></symbol>
<symbol id="ic-monitor" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></symbol>
<symbol id="ic-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20z"/></symbol>
<symbol id="ic-hash" viewBox="0 0 24 24"><path d="M4 9h16M4 15h16M10 3L8 21M16 3l-2 18"/></symbol>
<symbol id="ic-chart" viewBox="0 0 24 24"><path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="5" width="3" height="13"/></symbol>
<symbol id="ic-eye" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></symbol>
<symbol id="ic-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></symbol>
<symbol id="ic-help" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.83 1c0 2-3 2.1-3 3.9"/><line x1="12" y1="17.2" x2="12.01" y2="17.2"/></symbol>
</defs>
</svg>
<div class="cd-container">
    
    <?php if (empty($xrpl_account)): ?>
        <!-- Not Connected State -->
        <div class="cd-not-connected">
            <div class="cd-not-connected-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-link"></use></svg> </div>
            <h2>Connect Your Wallet</h2>
            <p>Connect your XRPL wallet to access your Creator Dashboard and manage your NFT collections.</p>
            <a href="<?php echo esc_url(home_url('/login/?redirect=' . urlencode($_SERVER['REQUEST_URI']))); ?>" class="cd-connect-btn">
                <svg class="imc-ic" aria-hidden="true"><use href="#ic-lock"></use></svg> Connect Wallet
            </a>
        </div>
    <?php else: ?>
        
        <?php /* R-3 (14 Sep 2026): the header was four stacked blocks - title,
           stat grid, a row of six activity pills, and the artist-profile row -
           about 470px before the nav tabs, which is a whole phone screen. The
           three CONTROLS now share one row with a deliberate hierarchy, because
           they are not peers: Create is an ACTION, the window is a FILTER, the
           gear is a LINK. Rendering them as three equal buttons would repeat the
           Actions-menu mistake of mixing navigation with consequence. */ ?>
        <div class="cd-header">
            <h1><svg class="imc-ic" aria-hidden="true"><use href="#ic-palette"></use></svg> Creator Dashboard</h1>
            <div class="cd-header-actions">
                <?php /* FILTER - sits next to the stats it scopes. Was six pills
                   in their own row below. */ ?>
                <label class="cd-hdr-sel" for="cd-win-select">
                    <span>Activity</span>
                    <select id="cd-win-select">
                        <option value="24h">Last 24 hours</option>
                        <option value="7d">Last 7 days</option>
                        <option value="30d" selected>Last 30 days</option>
                        <option value="90d">Last 90 days</option>
                        <option value="1y">Last year</option>
                        <option value="all">All time</option>
                    </select>
                </label>
                <?php /* ACTION - the one thing a creator comes here to do. */ ?>
                <a href="<?php echo esc_url(home_url('/mint/')); ?>" class="cd-btn cd-btn-primary">
                    Create New NFT
                </a>
                <?php /* LINK - icon only, muted, furthest right. The href is
                   carried VERBATIM from the row this replaces: D-A found it
                   pointing at a dead /my-profile/ and B-a corrected it to the
                   canonical /user/{account}. Do not retype it. */ ?>
                <a class="cd-gear" title="Edit your public artist page"
                   aria-label="Edit your public artist page"
                   href="<?php echo esc_url(home_url('/user/' . $xrpl_account)); ?>">
                    <svg class="imc-ic" aria-hidden="true"><use href="#ic-gear"></use></svg>
                </a>
            </div>
        </div>
        
        <!-- Overview Stats -->
        <div class="cd-stats-grid" id="cd-stats">
            <div class="cd-stat-card cd-surface">
                <div class="cd-stat-label">Collections</div>
                <div class="cd-stat-value" id="stat-collections">—</div>
            </div>
            <div class="cd-stat-card cd-surface">
                <div class="cd-stat-label">Total Listings</div>
                <div class="cd-stat-value" id="stat-listings">—</div>
            </div>
            <div class="cd-stat-card cd-surface">
                <div class="cd-stat-label">NFTs Minted</div>
                <div class="cd-stat-value" id="stat-minted">—</div>
                <div class="cd-stat-sub" id="stat-minted-sub"></div>
                <?php /* D-B2: the headline stays sourced from listings.minted_count -
                   the number creators have watched for months - and the PERIOD figure
                   is added BENEATH it rather than replacing it. Swapping the headline
                   to the purchase-row count would silently change a trusted number
                   (the two totals disagreed) with no explanation. */ ?>
                <div class="cd-stat-sub cd-stat-period" id="stat-minted-period"></div>
            </div>
            <div class="cd-stat-card cd-surface">
                <?php /* D-A measured that this was the XRP-ONLY subtotal and relabelled
                   it honestly. D-B2 sources the real per-currency figures from
                   wp_imc_purchases, so the label goes back to plain "Revenue".
                   Currencies are NEVER summed - price_xrp holds the amount in the row's
                   own currency, not a converted value, and
                   adding those is adding unlike units. */ ?>
                <div class="cd-stat-label">Revenue</div>
                <div class="cd-stat-value" id="stat-revenue">—</div>
                <div class="cd-stat-sub" id="stat-revenue-sub">XRP</div>
            </div>
        </div>

        <?php /* R-3: the six activity pills that stood here moved into the header
           row as a <select>. The state variable (cdHdrWin) and everything that
           reads it are unchanged - only the control changed. */ ?>
        
        <?php /* R-3: the last two .cd-profile-link rules went with the row they
           styled - it is now the gear in the header. D-A had already removed the
           other 25 when the artist-profile editor came out. Leaving CSS behind for
           an element that no longer exists is how a file accumulates rules nobody
           dares delete. */ ?>
        <!-- D-A: the Artist Profile editor lived here (54 lines). It duplicated the
             public profile page, which is now the single place a creator edits
             their identity. Left as one link so nobody is stranded.
             B-a (13 Sep): the href said /my-profile/ - a route that exists NOWHERE
             in this theme. The canonical pattern, used in header.php:1249/1285 and
             mirrored in page-user.php + trading.js, is /user/<account>. -->
        <?php /* R-3: the artist-page row is now the gear in the header. */ ?>

        <!-- Main Content Area -->
        <!-- B-c: LEVEL-1 NAV. Sits OUTSIDE #cd-main-content so it persists while
             you drill collection -> listing. The status filter stays inside
             #cd-collections-view and keeps hiding with it, as before.
             Analytics is deliberately absent until D-D has something real to
             put in it - a creator should never meet an empty tab. -->
        <?php /* B-d: orientation bar. Sits ABOVE the nav tabs so it persists across
           tab switches and drill-down alike. Populated by cdSetCrumbs(). */ ?>
        <div class="cd-topbar cd-surface" id="cd-topbar">
            <nav class="cd-breadcrumb" id="cd-breadcrumb" aria-label="Breadcrumb"></nav>
            <div class="cd-scope" id="cd-scope"></div>
        </div>

        <div class="cd-navtabs" id="cd-navtabs">
            <button type="button" class="cd-nav-tab" data-tab="activity">Activity</button>
            <button type="button" class="cd-nav-tab active" data-tab="listings">Listings</button>
            <button type="button" class="cd-nav-tab" data-tab="analytics">Analytics</button>
        </div>

        <div id="cd-main-content">

            <!-- B-c: Overview panel. Content is derived from allListings only. -->
            <?php /* R-6: was the Overview panel - four counters (drafts, scheduled,
               paused, sold out) built from allListings. The status filter row below
               does that job better: it filters instead of counting, in one click
               instead of two. Overview answered "how many?"; a creator opening the
               dashboard is asking "what happened?".

               ⚠ The listing manager has its OWN Overview section (data-lm="overview",
               #cd-lm-overview). Different namespace, same word - it is deliberately
               untouched, and every edit in this build is anchored on data-tab= /
               CD_TABS / TAB_LABEL rather than the bare string. */ ?>
            <div id="cd-panel-activity" class="cd-panel">
                <div class="cd-feed-filter">
                    <label for="cd-feed-collection">Collection</label>
                    <select id="cd-feed-collection"><option value="all">All collections</option></select>
                </div>
                <div id="cd-feed">
                    <div class="cd-loading"><div class="cd-spinner"></div><span>Loading activity...</span></div>
                </div>
            </div>

            <!-- B-c: Collections panel wraps the THREE existing views unchanged.
                 #cd-main-content was an inert wrapper (1 occurrence in the file,
                 no CSS rule, no JS reference) and there are zero direct-child
                 selectors anywhere, so this nesting level is free. -->
            <div id="cd-panel-listings" class="cd-panel active">
            
            <!-- Collections Overview (Default View) -->
            <div id="cd-collections-view" class="cd-view active">
                <div class="cd-tabs">
                    <button class="cd-tab active" data-filter="all">All</button>
                    <button class="cd-tab" data-filter="active">Active</button>
                    <button class="cd-tab" data-filter="scheduled"><svg class="imc-ic" aria-hidden="true"><use href="#ic-calendar"></use></svg> Scheduled</button>
                    <button class="cd-tab" data-filter="draft">Drafts</button>
                    <button class="cd-tab" data-filter="paused">Paused</button>
                    <button class="cd-tab" data-filter="sold_out">Sold Out</button>
                </div>
                
                <?php /* R-11: the collection-card grid is gone. It grouped by name, but
                   most collections hold exactly ONE listing - so the grid
                   showed six cards that each led to a page that led to a single
                   listing. R-9c had to skip that page and R-9d had to invent content
                   for it; two phases spent working around a layer that mostly did not
                   earn its place.
                   Listings are now flat, with the collection as a COLUMN. The
                   collection name still links to the collection page, so the
                   aggregates R-9d added remain reachable - they are just no longer
                   in the way. */ ?>
                <table class="cd-listings-table">
                    <thead>
                        <tr>
                            <th></th>
                            <th>Listing</th>
                            <th>Collection</th>
                            <th>Status</th>
                            <th>Price</th>
                            <th>Minted</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="cd-collections-grid"><!-- id kept: 6 render paths look it up --></tbody>
                </table>
            </div>
            
            <!-- Collection Detail View (Hidden Initially) -->
            <!-- v671: Listing Detail View — allowlists are per-listing, so they are managed here -->
            <div id="cd-listing-detail" class="cd-listing-view">
                <button type="button" class="cd-back-btn" onclick="cdBackToCollection()">
                    ← Back to Listings
                </button>
                <div class="cd-detail-header" id="cd-listing-detail-header">
                    <!-- Populated by JS -->
                </div>

                <?php /* R-5a: the manager shell. Allowlists keep their existing
                   markup verbatim and simply become one panel - nothing inside that
                   section changes in this phase. Pricing and Schedule arrive at
                   R-5b/R-5c; their tabs are rendered but lead to a short note rather
                   than a half-built form. */ ?>
                <nav class="cd-lm-nav" id="cd-lm-nav" aria-label="Listing sections">
                    <button type="button" class="cd-lm-tab is-active" data-lm="overview">Overview</button>
                    <button type="button" class="cd-lm-tab" data-lm="allowlists">Allowlists</button>
                    <button type="button" class="cd-lm-tab" data-lm="pricing">Pricing</button>
                    <button type="button" class="cd-lm-tab" data-lm="schedule">Schedule</button>
                    <button type="button" class="cd-lm-tab" data-lm="danger">Danger</button>
                </nav>

                <div class="cd-lm-panel is-active" data-lm-panel="overview" id="cd-lm-overview"></div>

                <div class="cd-lm-panel" data-lm-panel="pricing">
        <form id="cd-edit-price-form">
            <input type="hidden" id="edit-listing-id">
            <input type="hidden" id="edit-pricing-mode" value="static">
            
            <!-- v83: Pricing Mode Toggle - clearer descriptions -->
            <div class="cd-pricing-mode-section">
                <label class="cd-section-label">Pricing Mode</label>
                <div class="cd-pricing-mode-toggle">
                    <label class="cd-mode-option selected" data-mode="static">
                        <input type="radio" name="edit_pricing_mode" value="static" checked>
                        <span class="cd-mode-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-coins"></use></svg> </span>
                        <span class="cd-mode-text">
                            <strong>Static</strong>
                            <small>Fixed prices • Multi-token</small>
                        </span>
                    </label>
                    <label class="cd-mode-option" data-mode="dynamic">
                        <input type="radio" name="edit_pricing_mode" value="dynamic">
                        <span class="cd-mode-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-drop"></use></svg> </span>
                        <span class="cd-mode-text">
                            <strong>Dynamic</strong>
                            <small>USD → XRP live rate</small>
                        </span>
                    </label>
                    <label class="cd-mode-option" data-mode="pwyw">
                        <input type="radio" name="edit_pricing_mode" value="pwyw">
                        <span class="cd-mode-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-gift"></use></svg> </span>
                        <span class="cd-mode-text">
                            <strong>Pay What You Want</strong>
                            <small>Prices below act as minimums</small>
                        </span>
                    </label>
                    <label class="cd-mode-option" data-mode="free">
                        <input type="radio" name="edit_pricing_mode" value="free">
                        <span class="cd-mode-icon"></span>
                        <span class="cd-mode-text">
                            <strong>Free Mint</strong>
                            <small>Collectors pay nothing</small>
                        </span>
                    </label>
                </div>
            </div>
            
            <!-- Static Mode Section -->
            <div id="edit-static-section">
                <div class="cd-edit-mode-note" id="edit-pwyw-note" style="display:none;margin-bottom:14px;padding:12px 14px;border-radius:8px;background:rgba(212,175,55,0.08);border:1px solid rgba(212,175,55,0.3);font-size:0.9em;line-height:1.5;">
                    <svg class="imc-ic" aria-hidden="true"><use href="#ic-bulb"></use></svg> <strong>Pay What You Want:</strong> the prices below are the <strong>minimum</strong> accepted for each currency. Buyers enter their own amount at or above these. Set a minimum to <strong>0</strong> to allow any amount.
                </div>
                <div class="cd-edit-mode-note" id="edit-free-note" style="display:none;margin-bottom:14px;padding:12px 14px;border-radius:8px;background:rgba(80,200,120,0.08);border:1px solid rgba(80,200,120,0.35);font-size:0.9em;line-height:1.5;">
                    <strong>Free Mint:</strong> collectors mint at <strong>no charge</strong> (network fee only). Saving will clear all prices for this listing.
                </div>
                <div class="cd-form-group">
                    <!-- P5: Progressive Pricing management (freeze-and-rebase) -->
                    <div id="cd-pp-section" style="display:none;margin:14px 0;padding:14px;border:1px solid rgba(124,92,255,.45);border-radius:12px;background:rgba(124,92,255,.06);color:#fff;">
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:700;">
                            <input type="checkbox" id="cd-pp-toggle" style="width:18px;height:18px;accent-color:#7c5cff;"> <svg class="imc-ic" aria-hidden="true"><use href="#ic-chart"></use></svg> Progressive Pricing
                        </label>
                        <p id="cd-pp-status" style="font-size:.8rem;opacity:.8;margin:6px 0 0;"></p>
                        <div id="cd-pp-fields" style="margin-top:10px;"></div>
                        <button type="button" id="cd-pp-save" class="btn-primary btn-small" style="margin-top:10px;">Save Progressive Settings</button>
                        <p style="font-size:.74rem;opacity:.6;margin:8px 0 0;">Changes apply to future mints only — the price can only ever climb. Turning it off freezes the price where it is.</p>
                    </div>

                    <label for="edit-price">XRP Price (Primary)</label>
                    <input type="number" id="edit-price" step="0.000001" min="0" required>
                    <small style="color:var(--cd-text-muted);">Price per edition in XRP</small>
                </div>
                
                <!-- v77: Token Add Dropdown for Static Mode -->
                <div class="cd-add-token-section">
                    <label>Additional Payment Tokens:</label>
                    <div class="cd-token-add-row">
                        <select id="edit-static-token-selector">
                            <!-- v604: populated from the Token Manager via cdLoadSupportedTokens() -->
                            <option value="">-- Select token --</option>
                        </select>
                        <input type="number" id="edit-new-token-price" placeholder="Price" min="0" step="0.000001" style="width:100px;display:none;">
                        <button type="button" class="cd-btn cd-btn-secondary cd-btn-small" id="edit-add-token-btn" disabled>Add</button>
                    </div>
                </div>
                
                <!-- Added Tokens List -->
                <div id="edit-static-tokens-list" class="cd-tokens-list"></div>
            </div>
            
            <!-- Dynamic Mode Section (hidden by default) - v83: XRP ONLY -->
            <div id="edit-dynamic-section" style="display:none;">
                <div class="cd-form-group">
                    <label for="edit-usd-price">Base Price (USD)</label>
                    <div class="cd-price-input-wrap">
                        <span class="cd-price-prefix">$</span>
                        <input type="number" id="edit-usd-price" step="0.01" min="0.01" max="5000" value="9.99">
                        <span class="cd-price-suffix">USD</span>
                    </div>
                    <small style="color:var(--cd-text-muted);">XRP amount calculated at checkout. Max $5,000 USD.</small>
                </div>
                
                <!-- XRP Preview -->
                <div class="cd-xrp-preview" style="background:var(--cd-bg-elevated);padding:1rem;border-radius:8px;margin-top:1rem;">
                    <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem;">
                        <span style="font-size:1.5rem;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-drop"></use></svg> </span>
                        <strong>XRP Payment</strong>
                        <span style="background:#10b981;color:#fff;font-size:10px;padding:2px 6px;border-radius:4px;">LIVE RATE</span>
                    </div>
                    <div style="font-size:1.25rem;color:var(--cd-accent);" id="edit-xrp-preview">⏳ Calculating...</div>
                    <small style="color:var(--cd-text-muted);display:block;margin-top:0.5rem;">
                        Price updates automatically based on XRP/USD rate from CoinGecko
                    </small>
                </div>
                
                <!-- v83: Note about XRP-only for dynamic -->
                <div style="background:rgba(212,175,55,0.1);border:1px solid var(--cd-accent);border-radius:8px;padding:1rem;margin-top:1rem;">
                    <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem;">
                        <span><svg class="imc-ic" aria-hidden="true"><use href="#ic-help"></use></svg></span>
                        <strong style="color:var(--cd-accent);">Dynamic Pricing = XRP Only</strong>
                    </div>
                    <p style="color:var(--cd-text-muted);font-size:0.85rem;margin:0;">
                        Dynamic pricing automatically converts USD to XRP at checkout. 
                        To accept other XRPL tokens (XFT, RLUSD, etc.), use <strong>Static pricing</strong> 
                        where you set fixed token amounts.
                    </p>
                </div>
            </div>
            
            <div class="cd-modal-actions">
                <?php /* R-5b: was Cancel (which closed the modal). Inline there is
                   nothing to close, so it re-reads the listing instead - the same
                   "undo my edits" intent, done honestly. */ ?>
                <button type="button" class="cd-btn cd-btn-secondary" onclick="cdLmReloadPricing()">Reset</button>
                <button type="submit" class="cd-btn cd-btn-primary">Save Pricing</button>
            </div>
        </form>
                </div>
                <div class="cd-lm-panel" data-lm-panel="schedule">
                    <div id="cd-lm-sched-note"></div>
        <h3 id="schedule-modal-title"><svg class="imc-ic" aria-hidden="true"><use href="#ic-calendar"></use></svg> Edit Launch Schedule</h3>
        <input type="hidden" id="schedule-listing-id">
        <input type="hidden" id="schedule-resume-on-save" value="0">
        <div class="cd-form-group">
            <label for="schedule-datetime-input">New Launch Date &amp; Time <span style="color:var(--cd-text-muted);font-size:0.85em;">(your local time)</span></label>
            <input type="datetime-local" id="schedule-datetime-input" oninput="cdUpdateSchedulePreview()">
        </div>
        <div id="schedule-preview-text" style="color:var(--cd-gold);font-size:0.9rem;margin-bottom:1.25rem;min-height:1.2em;"></div>
        <div class="cd-modal-actions">
            <?php /* R-5c: was Cancel, which closed the modal. Inline there is nothing
               to close, so this re-reads the listing and resets the form - including
               the resume-on-save flag, which cdCloseScheduleModal() used to clear. */ ?>
            <button type="button" class="cd-btn cd-btn-secondary" onclick="cdLmReloadSchedule()">Reset</button>
            <button type="button" class="cd-btn cd-btn-primary" id="schedule-save-btn" onclick="cdSaveSchedule()">Save Schedule</button>
        </div>
                </div>
                <div class="cd-lm-panel" data-lm-panel="danger" id="cd-lm-danger"></div>

                <div class="cd-allowlists-section cd-lm-panel" data-lm-panel="allowlists" id="cd-allowlists-section">
                    <div class="cd-allowlists-header">
                        <h3><svg class="imc-ic" aria-hidden="true"><use href="#ic-scroll"></use></svg> Allowlists</h3>
                        <button class="cd-btn cd-btn-primary cd-btn-sm" onclick="cdShowCreateAllowlist()">+ Create Allowlist</button>
                    </div>
                    <p class="cd-allowlists-description">Manage early access, discounts, exclusive access, and per-wallet limits for <strong>this listing</strong>. Each listing has its own allowlists.</p>

                    <?php /* R-5d: collapsed by default. "Create allowlist" is an
                       occasional action, so an always-open 130-line form would be
                       the wrong trade - the button reveals it instead. */ ?>
                    <?php /* AG-2..AG-5: the holder-list GENERATOR.
                       It does not write entries itself. It builds the wallet rows the
                       existing form already owns (cdAddWalletRow), so Save & Add Wallets
                       runs the SAME cdSerialiseWalletRows -> cdAddInlineEntries path a
                       hand-typed list runs. No second write path, no second set of
                       validation rules, and the 1,000-per-request cap is handled where
                       it already was. */ ?>
                    <div class="cd-gen" id="cd-gen" hidden>
                        <h4>Generate from holders</h4>
                        <p class="cd-allowlists-description" style="margin:.15rem 0 .85rem;">
                            Reads each collection <b>live from the XRPL</b> and builds the wallet list for you.
                        </p>

                        <?php /* A <datalist> writes ONE value into ONE input - it could
                           never fill the taxon as well, which is why picking a collection
                           only ever populated the issuer. A select carries the whole
                           {issuer, taxon} pair and fills both. */ ?>
                        <div class="cd-form-group" style="margin-bottom:.6rem;">
                            <label for="cd-gen-pick">Your collections</label>
                            <select id="cd-gen-pick" onchange="cdGenPick()">
                                <option value="">Choose one, or type another creator's below&hellip;</option>
                            </select>
                        </div>
                        <div class="cd-gen-src">
                            <input type="text" id="cd-gen-issuer" placeholder="Issuer address (r...)" spellcheck="false">
                            <input type="number" id="cd-gen-taxon" placeholder="Taxon" min="0" step="1">
                            <button type="button" class="cd-btn cd-btn-secondary cd-btn-sm" onclick="cdGenAddSource()">Add</button>
                        </div>
                        <small style="color:var(--cd-text-muted);display:block;margin-top:.4rem;">
                            <span class="cd-gen-i" title="Every collection on the XRPL has its own taxon - a number that separates it from the same creator's other collections. Verifying reads that one collection straight from the ledger, so it has to know which. There is no way to ask the ledger for all of an issuer's collections at once, so add each one as its own row.">Taxon is required &#9432;</span>
                            &middot; pick one of your own collections to fill both fields automatically.
                        </small>

                        <ul class="cd-gen-list" id="cd-gen-list"></ul>

                        <?php /* Special requirement gets its own row: it changes WHICH
                           wallets qualify, while the row below changes how much each one
                           gets. Two different questions. */ ?>
                        <div class="cd-gen-opts cd-gen-opts-req">
                            <div>
                                <label for="cd-gen-req">Special requirement</label>
                                <select id="cd-gen-req" onchange="cdGenPreview()">
                                    <option value="any">Any of these collections</option>
                                    <option value="all">Must hold in EVERY collection</option>
                                </select>
                            </div>
                            <div>
                                <label for="cd-gen-each">Minimum in each collection</label>
                                <input type="number" id="cd-gen-each" value="1" min="1" step="1" oninput="cdGenPreview()" disabled>
                            </div>
                        </div>

                        <div class="cd-gen-opts">
                            <div>
                                <label for="cd-gen-merge">When a wallet appears twice</label>
                                <select id="cd-gen-merge" onchange="cdGenPreview()">
                                    <option value="sum">Add the holdings together</option>
                                    <option value="max">Use the largest single holding</option>
                                </select>
                            </div>
                            <div>
                                <label for="cd-gen-min">Minimum held (total)</label>
                                <input type="number" id="cd-gen-min" value="1" min="1" step="1" oninput="cdGenPreview()">
                            </div>
                            <?php /* ⚠ THE TYPE MATTERS MORE THAN IT LOOKS. early_access and
                               exclusive appear in NEITHER allocation branch - amounts written
                               to them are stored and never read, so a generated holder list
                               would be silently discarded. The generator defaults the form to
                               a type that actually USES the amounts. */ ?>
                            <div>
                                <label for="cd-gen-type">Allowlist type</label>
                                <?php /* All five types, matching the create form exactly.
                                   The two that USE per-wallet amounts are listed first; the
                                   three that do not are grouped and say so, because a
                                   generated holder list written to one of them keeps the
                                   wallets and discards every quantity. */ ?>
                                <select id="cd-gen-type" onchange="cdGenPreview()">
                                    <optgroup label="Uses each wallet's holding">
                                        <option value="discount_limited">Holder Discount &mdash; discount on what they hold</option>
                                        <option value="limit_override">Mint Cap &mdash; extra mints for holders</option>
                                    </optgroup>
                                    <optgroup label="Wallet list only &mdash; holdings not used">
                                        <option value="early_access">Early Access</option>
                                        <option value="discount">Unlimited Discount</option>
                                        <option value="exclusive">Exclusive Only</option>
                                    </optgroup>
                                </select>
                            </div>
                            <div>
                                <label for="cd-gen-cap">Max mints per wallet
                                    <span class="cd-gen-i" title="This is a MINT limit, not a filter. Everyone who qualifies is still added to the list with the number they actually hold - this only caps how many of those mints they can use. A wallet holding 52 stays on the list as 52 and mints up to this number.">&#9432;</span>
                                </label>
                                <input type="number" id="cd-gen-cap" placeholder="No limit" min="1" step="1">
                            </div>
                        </div>
                        <small style="color:var(--cd-text-muted);display:block;margin-top:.4rem;">
                            <b>Minimum held (total)</b> is applied <b>after</b> merging, so a wallet holding 1 in
                            each of two collections counts as 2. <b>Must hold in every collection</b> changes that
                            to an AND &mdash; only wallets present in all of them qualify, and
                            <b>Minimum in each</b> then sets the bar per collection.
                            <b>Max mints per wallet</b> is a MINT cap saved onto the allowlist &mdash; it never
                            removes anyone from the list, and entries still record the true holding.
                        </small>

                        <div class="cd-gen-prev" id="cd-gen-prev">Add a collection above to begin.</div>
                        <div class="cd-gen-prev" id="cd-gen-full" hidden></div>

                        <div class="cd-modal-actions" style="margin-top:.9rem;">
                            <button type="button" class="cd-btn cd-btn-secondary" onclick="cdGenReset()">Clear</button>
                            <button type="button" class="cd-btn cd-btn-secondary" onclick="cdGenClose()">Cancel</button>
                            <button type="button" class="cd-btn cd-btn-secondary" id="cd-gen-list-btn" onclick="cdGenShowList()" disabled>Preview list</button>
                            <button type="button" class="cd-btn cd-btn-secondary" id="cd-gen-csv" onclick="cdGenCsv()" disabled>Export CSV</button>
                            <button type="button" class="cd-btn cd-btn-primary" id="cd-gen-apply" onclick="cdGenApply()" disabled>Fill the wallet list</button>
                        </div>
                    </div>

                    <div class="cd-al-create" id="cd-al-create" hidden>
        <h3 id="allowlist-modal-title"><svg class="imc-ic" aria-hidden="true"><use href="#ic-scroll"></use></svg> Create Allowlist</h3>
        <form id="cd-allowlist-form">
            <input type="hidden" id="allowlist-id">
            <input type="hidden" id="allowlist-listing-id">

            <!-- ── Row 1: Name + Type ── -->
            <div class="cd-form-row">
                <div class="cd-form-group">
                    <label for="allowlist-name">Name *</label>
                    <input type="text" id="allowlist-name" placeholder="e.g., VIP Early Access" required>
                </div>
                <div class="cd-form-group">
                    <label for="allowlist-type">Type *</label>
                    <select id="allowlist-type" required onchange="cdUpdateAllowlistTypeFields()">
                        <option value="early_access">Early Access</option>
                        <option value="discount">Unlimited Discount</option>
                        <option value="discount_limited">Holder Discount (Quantity-Limited)</option>
                        <option value="exclusive">Exclusive Only</option>
                        <option value="limit_override">Mint Cap</option>
                    </select>
                </div>
            </div>

            <div class="cd-form-group">
                <label for="allowlist-description">Description</label>
                <textarea id="allowlist-description" rows="2" placeholder="Optional description..."></textarea>
            </div>

            <!-- Type-specific fields -->
            <div id="allowlist-early-fields" class="cd-type-fields">
                <div class="cd-form-group">
                    <label for="allowlist-early-hours">Early Access — hours before public launch</label>
                    <input type="number" id="allowlist-early-hours" value="24" min="1" max="720">
                </div>
            </div>

            <div id="allowlist-discount-fields" class="cd-type-fields" style="display:none;">
                <div class="cd-form-group">
                    <label for="allowlist-discount-mode">Discount Type</label>
                    <select id="allowlist-discount-mode" onchange="cdUpdateDiscountMode()">
                        <option value="price">Fixed price — they pay a set amount</option>
                        <option value="percent">Percentage off — a % off the listing price</option>
                    </select>
                    <small style="color:var(--cd-text-muted);font-size:0.8rem;">
                        Pick one. A fixed price always wins over a percentage, so only the chosen
                        option is saved.
                    </small>
                </div>
                <div class="cd-form-group" id="allowlist-price-group">
                    <label for="allowlist-custom-price">Custom Mint Price (XRP)</label>
                    <input type="number" id="allowlist-custom-price" placeholder="e.g. 5.00" min="0" step="0.01">
                    <small style="color:var(--cd-text-muted);font-size:0.8rem;">Leave blank to use the listing's public price · Enter <strong>0</strong> for a <strong>FREE claim — free in every currency</strong> · Token payments follow this XRP price proportionally unless a token has its own rule below.</small>
                </div>
                <div class="cd-form-group" id="allowlist-percent-group" style="display:none;">
                    <label for="allowlist-discount-percent">Discount (%)</label>
                    <input type="number" id="allowlist-discount-percent" placeholder="e.g. 20" min="1" max="100" step="1">
                    <small style="color:var(--cd-text-muted);font-size:0.8rem;">Applies to XRP and any accepted token without its own rule below.</small>
                    <small style="color:var(--cd-text-muted);font-size:0.8rem;">
                        Taken off whatever the listing costs at the time — e.g. 20 means they pay 80%.
                    </small>
                </div>
            </div>

            <?php /* AG-1: the allowlist-level CEILING. Distinct from "Max Mints Per
               Wallet" below, which is a FALLBACK - used only when an entry carries no
               amount of its own. This one CAPS whatever the entry says, and applies to
               both quantity-aware types. Blank = no ceiling, which is how every
               allowlist created before this field behaves.
               It exists because the generator writes the TRUE held count: a wallet
               holding 52 gets an entry of 52, and this decides how many of those it may
               actually use. Clamping at write time would destroy the holding figure. */ ?>
            <div id="allowlist-cap-fields" class="cd-type-fields" style="display:none;">
                <div class="cd-form-group">
                    <label for="allowlist-holder-limit">Maximum per wallet (optional)</label>
                    <input type="number" id="allowlist-holder-limit" placeholder="No limit" min="1" max="99999">
                    <small style="color:var(--cd-text-muted);display:block;margin-top:0.35rem;">
                        Caps what any one wallet can take from this allowlist, however large its
                        entry is. Leave blank for no cap.
                    </small>
                </div>
            </div>

            <div id="allowlist-limit-fields" class="cd-type-fields" style="display:none;">
                <div class="cd-form-group">
                    <label for="allowlist-max-mint" id="allowlist-max-mint-label">Max Mints Per Wallet</label>
                    <input type="number" id="allowlist-max-mint" value="5" min="1" max="1000">
                    <small style="color:var(--cd-text-muted);display:block;margin-top:0.35rem;" id="allowlist-max-mint-hint">
                        Applied to each wallet you add below. A per-wallet value in the list overrides it.
                    </small>
                </div>
            </div>

            <!-- ── Divider ── -->
            <div style="border-top:1px solid var(--cd-border);margin:1.25rem 0 1rem;"></div>

            <!-- ── Wallet Entry (always visible, part of the same form) ── -->
            <div>
                <label style="font-size:0.92rem;font-weight:600;color:var(--cd-text);display:block;margin-bottom:0.75rem;">
                    <svg class="imc-ic" aria-hidden="true"><use href="#ic-smile"></use></svg> Wallet Addresses
                    <span style="font-weight:400;color:var(--cd-text-muted);font-size:0.82rem;"> — paste addresses or upload CSV (optional, can add later)</span>
                </label>

                <div class="cd-entries-tabs" id="inline-entries-tabs">
                    <button type="button" class="cd-entries-tab active" data-tab="add" onclick="cdSwitchInlineTab('add')"><svg class="imc-ic" aria-hidden="true"><use href="#ic-pencil"></use></svg> Paste</button>
                    <button type="button" class="cd-entries-tab" data-tab="csv" onclick="cdSwitchInlineTab('csv')"><svg class="imc-ic" aria-hidden="true"><use href="#ic-clipboard"></use></svg> CSV Upload</button>
                </div>

                <div id="inline-tab-add">
                    <div id="inline-wallets-note" style="font-size:0.84rem;color:var(--cd-text-muted);margin-bottom:0.5rem;line-height:1.5;"></div>
                    <div class="cd-wallet-rows-head" id="inline-rows-head">
                        <span class="h-addr">Wallet address</span>
                        <span class="h-qty" id="inline-rows-qty-head">Amount</span>
                        <span class="h-sp"></span>
                    </div>
                    <div class="cd-wallet-rows" id="inline-wallet-rows"></div>
                    <button type="button" class="cd-btn cd-btn-secondary cd-btn-sm" onclick="cdAddWalletRow()" style="margin-bottom:0.5rem;">+ Add Wallet</button>
                    <!-- v678: rows are serialised into this hidden field on submit, so the
                         existing add_entries path stays unchanged. -->
                    <textarea id="inline-wallets" style="display:none;"></textarea>
                    <div id="inline-qty-wrap" style="display:none;margin-bottom:0.5rem;">
                        <label for="inline-quantity" style="font-size:0.85rem;color:var(--cd-text-muted);display:block;margin-bottom:0.3rem;">Default amount <span style="opacity:0.7;">(optional)</span></label>
                        <input type="number" id="inline-quantity" min="1" max="1000" placeholder="e.g. 5" style="width:140px;">
                        <small style="color:var(--cd-text-muted);display:block;margin-top:0.3rem;">
                            Only fills in wallets that have <em>no</em> number of their own — handy when everyone
                            should get the same amount. Per-wallet values always win.
                        </small>
                    </div>
                </div>

                <div id="inline-tab-csv" style="display:none;">
                    <p class="cd-csv-help">CSV or TXT file with wallet addresses. For Holder Discount and Mint Cap types, include a quantity column — per-wallet limits are set automatically.</p>
                    <input type="file" id="inline-csv-file" accept=".csv,.txt" style="margin-bottom:0.5rem;" onchange="cdPrefillFromCsv(this)">
                    <small style="color:var(--cd-text-muted);display:block;line-height:1.5;">
                        <strong>Holder snapshot format:</strong> one wallet per row, with that wallet's
                        amount in a second column — e.g.<br>
                        <code>rWallet1...,5</code> &nbsp; <code>rWallet2...,15</code> &nbsp; <code>rWallet3...,1</code><br>
                        Export straight from a holder snapshot: each wallet gets exactly what it holds.
                        A header row is ignored. The file is read here and fills the box above so you can
                        check it before saving.
                    </small>
                </div>

                <div id="inline-added-summary" style="display:none;padding:0.5rem 0.8rem;background:rgba(74,222,128,0.08);border:1px solid rgba(74,222,128,0.25);border-radius:8px;font-size:0.82rem;color:#4ade80;margin-top:0.5rem;"></div>
            </div>

            <div class="cd-modal-actions" style="margin-top:1.25rem;">
                <?php /* R-5d: still Cancel - inline it collapses the section rather
                   than closing an overlay, which is the same intent. */ ?>
                <button type="button" class="cd-btn cd-btn-secondary" onclick="cdCloseAllowlistModal()">Cancel</button>
                <button type="submit" class="cd-btn cd-btn-primary" id="allowlist-save-btn">Save &amp; Add Wallets</button>
            </div>
        </form>
                    </div>

                    <div class="cd-allowlists-list" id="cd-allowlists-list">
                        <!-- Populated by JS -->
                    </div>
                </div>
            </div>

            <div id="cd-collection-detail" class="cd-detail-view">
                <button type="button" class="cd-back-btn" onclick="cdShowCollectionsView()">
                    ← Back to Collections
                </button>
                
                <div class="cd-detail-header" id="cd-detail-header">
                    <!-- Populated by JS -->
                </div>
                
                <h3 style="color: var(--cd-gold); margin-bottom: 1rem;">Listings</h3>
                
                <?php /* R-8: a card grid rendering ONE listing was the common case -
                   measured, most creators' collections hold exactly one. Rows
                   scan; cards do not. The Actions dropdown went with the card: after
                   R-5 every item in it was navigation into the manager, and a dropdown
                   whose every item says "go to the manager" should be a link.

                   The TABLE below already existed, hidden with an inline display:none
                   and an empty #cd-listings-tbody nobody ever populated. Adopted rather
                   than replaced - its CSS (.cd-listings-table, .cd-listing-row:hover,
                   and the mobile overflow-x rule) was written for exactly this and has
                   been near-dead since. The id stays #cd-listings-grid so the eight
                   render paths that look it up keep working untouched. */ ?>
                <table class="cd-listings-table">
                    <thead>
                        <tr>
                            <th></th>
                            <th>Listing</th>
                            <th>Status</th>
                            <th>Price</th>
                            <th>Minted</th>
                            <th>Revenue</th>
                        </tr>
                    </thead>
                    <tbody id="cd-listings-grid">
                        <!-- Populated by JS -->
                    </tbody>
                </table>
                
                <!-- Purchase History -->
                <div class="cd-purchase-history" id="cd-purchase-history">
                    <h3><svg class="imc-ic" aria-hidden="true"><use href="#ic-scroll"></use></svg> Recent Purchases</h3>
                    <table class="cd-purchase-table" id="cd-purchase-table">
                        <thead>
                            <tr>
                                <th>Buyer</th>
                                <th>Edition</th>
                                <th>Tier</th>
                                <th>Amount</th>
                                <th>Date</th>
                                <th>Delivery</th>
                            </tr>
                        </thead>
                        <tbody id="cd-purchase-tbody">
                            <!-- Populated by JS -->
                        </tbody>
                    </table>
                </div>
            </div>
            
        </div>
        
    <?php endif; ?>
            </div><!-- /#cd-panel-collections -->

            <!-- D-D: analytics. The window control lives HERE rather than in a
                 persistent header because B-d is not built yet; it moves up when
                 it is. Known, accepted, small rebuild. -->
            <div id="cd-panel-analytics" class="cd-panel">
                <?php /* R-7: ONE collection selector, governing the whole tab. D-E had
                   its own inside the on-ledger card (#cd-store-pick); two controls on
                   one tab can disagree, so that one is gone and the card follows this.
                   Scoping happens SERVER-SIDE - see the handler note. */ ?>
                <div class="cd-an-scope">
                    <label for="cd-an-collection">Collection</label>
                    <select id="cd-an-collection"><option value="all">All collections</option></select>
                </div>
                <div class="cd-an-windows" id="cd-an-windows">
                    <button type="button" class="cd-an-win" data-win="24h">24h</button>
                    <button type="button" class="cd-an-win" data-win="7d">7 days</button>
                    <button type="button" class="cd-an-win active" data-win="30d">30 days</button>
                    <button type="button" class="cd-an-win" data-win="90d">90 days</button>
                    <button type="button" class="cd-an-win" data-win="1y">1 year</button>
                    <button type="button" class="cd-an-win" data-win="all">All time</button>
                </div>
                <div id="cd-an-body">
                    <div class="cd-loading"><div class="cd-spinner"></div><span>Loading analytics...</span></div>
                </div>

                <?php /* D-E: on-ledger panel. Lives on the Analytics tab rather than
                   the collection detail view so the selector has somewhere to sit and
                   the detail view stays uncluttered. Loads lazily with the tab. */ ?>
                <div class="cd-an-card cd-an-wide cd-surface" id="cd-store-card" style="margin-top:1rem;">
                    <?php /* R-7: heading renamed. The KPI inside this card stays
                       "On ledger" on purpose - it is the live XRPL count, a different
                       number from minted_count, and giving them one name would hide
                       that. */ ?>
                    <h4>NFTs minted · holders and floor</h4>
                    <?php /* R-7: the card's own selector was removed - it follows the
                       tab-wide one above. */ ?>
                    <div id="cd-store-body">
                        <p class="cd-store-note">Select a collection to see its on-ledger picture.</p>
                    </div>
                </div>
            </div>
</div>

<!-- Edit Price Modal -->
<?php /* R-5b: the Edit Pricing MODAL is gone. Its form moved verbatim into the
   listing manager's Pricing panel - same ids, same fields, same submit handler.
   Ruled 14 Sep: price, schedule and the allowlist trio go inline; payfee and
   vault stay modal (they are flows with their own state) and edit-collection is
   collection-scoped, so it belongs on the collection page rather than here. */ ?>

<!-- Cancel Confirmation Modal (Two-Step) -->
<div class="cd-modal-overlay" id="cd-cancel-modal">
    <div class="cd-modal">
        <h3><svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> Cancel Listing</h3>
        <p style="color: var(--cd-text); margin-bottom: 1rem;">
            Are you sure you want to cancel this listing?
        </p>
        <ul style="color: var(--cd-text-muted); font-size: 0.9rem; margin-bottom: 1.5rem; padding-left: 1.5rem;">
            <li>No new mints will be possible</li>
            <li>Already minted NFTs will remain with their owners</li>
            <li>The collection will still be visible if NFTs exist</li>
            <li>This action cannot be undone</li>
        </ul>
        <div class="cd-form-group">
            <label for="cancel-confirm-input">Type <strong>CANCEL</strong> to confirm:</label>
            <input type="text" id="cancel-confirm-input" placeholder="Type CANCEL" autocomplete="off">
        </div>
        <input type="hidden" id="cancel-listing-id">
        <div class="cd-modal-actions">
            <button type="button" class="cd-btn cd-btn-secondary" onclick="cdCloseCancelModal()">Go Back</button>
            <button type="button" class="cd-btn cd-btn-danger" id="cancel-confirm-btn" disabled onclick="cdConfirmCancel()">Cancel Listing</button>
        </div>
    </div>
</div>

<!-- Edit Schedule Modal (v249) -->
<?php /* R-5c: the Schedule MODAL is gone; its body moved verbatim into the
   listing manager's Schedule panel. Same ids, same save handler, same hidden
   resume-on-save flag.

   ⚠ THE FLAG IS WHY THIS NEEDED CARE. One form, two meanings:
     cdEditSchedule()    sets it to 0 - retime an existing future launch
     cdScheduleRelease() sets it to 1 - bring a PAUSED listing back at a set time
   A modal could rely on whichever function opened it. An always-visible panel
   cannot, so cdLmOpenSchedule() picks the mode from the listing's STATUS - which
   is the same thing the two callers were really expressing. */ ?>

<style>
/* v509: Edit Collection modal — start below the fixed header and scroll internally
   so the title and ✕ close are always reachable (overlay click-to-close unaffected). */
#cd-edit-collection-modal { align-items: flex-start; overflow-y: auto; padding: 96px 16px 24px; }
#cd-edit-collection-modal .cd-modal { max-height: calc(100vh - 130px); overflow-y: auto; }
</style>
<!-- v506 Phase C: Edit Collection (cover + description) Modal -->
<div class="cd-modal-overlay" id="cd-edit-collection-modal">
    <div class="cd-modal">
        <h3><svg class="imc-ic" aria-hidden="true"><use href="#ic-pencil"></use></svg> Edit Collection</h3>
        <input type="hidden" id="cd-ec-id">
        <div class="cd-form-group">
            <label for="cd-ec-name">Collection Name</label>
            <input type="text" id="cd-ec-name" maxlength="120" style="width:100%;">
            <div style="font-size:0.82rem;color:#d4af37;margin-top:0.3rem;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> Renaming changes the collection's public URL — previously shared links will no longer resolve.</div>
        </div>
        <div class="cd-form-group">
            <label>Collection Cover <span style="font-weight:400;color:#9aa;">(shown on the public collection page)</span></label>
            <div style="display:flex;align-items:center;gap:0.75rem;margin-top:0.35rem;">
                <img id="cd-ec-cover-preview" src="" alt="" style="width:72px;height:72px;border-radius:10px;object-fit:cover;border:1px solid rgba(255,255,255,0.15);background:rgba(255,255,255,0.04);">
                <button type="button" class="cd-btn cd-btn-secondary cd-btn-sm" onclick="document.getElementById('cd-ec-file').click()">Change Cover</button>
                <input type="file" id="cd-ec-file" accept="image/*" style="display:none;" onchange="cdEcUploadCover(this)">
            </div>
            <div id="cd-ec-upload-status" style="font-size:0.85rem;color:#9aa;margin-top:0.35rem;"></div>
        </div>
        <div class="cd-form-group">
            <label for="cd-ec-desc">Description</label>
            <textarea id="cd-ec-desc" rows="4" maxlength="1000" style="width:100%;"></textarea>
        </div>
        <div class="cd-modal-actions">
            <button type="button" class="cd-btn cd-btn-secondary" onclick="cdCloseEditCollection()">Cancel</button>
            <button type="button" class="cd-btn cd-btn-primary" id="cd-ec-save" onclick="cdSaveEditCollection()">Save Changes</button>
        </div>
    </div>
</div>

<!-- Create Allowlist Modal -->
<?php /* R-5d: the CREATE-ALLOWLIST modal is gone; its body moved into the
   listing manager's Allowlists panel as a collapsible section.

   ⚠ WHY ONLY THIS ONE. R5-1 originally said the whole allowlist trio goes inline.
   On inspection that was wrong for two of the three:
     cdShowCreateAllowlist()            - per LISTING. One form, one context,
                                          always applicable. Same shape as Pricing
                                          and Schedule, so it inlines cleanly.
     cdManageEntries(allowlistId, name) - per ALLOWLIST
     cdEditAllowlist(allowlistId)       - per ALLOWLIST
   The latter two act on an item picked from a list that is already on screen.
   Inlining them would mean the panel switching between list-view and item-view -
   a fifth level of navigation, and a worse shape than the modal it replaced.

   The rule the manager now follows, which is worth stating: every LISTING-level
   action is inline; every ITEM-level action is a modal. */ ?>

<!-- Manage Allowlist Entries Modal -->
<div class="cd-modal-overlay" id="cd-entries-modal">
    <div class="cd-modal cd-modal-large">
        <h3 id="entries-modal-title"><svg class="imc-ic" aria-hidden="true"><use href="#ic-smile"></use></svg> Manage Wallets</h3>
        <input type="hidden" id="entries-allowlist-id">
        
        <div class="cd-entries-tabs">
            <button class="cd-entries-tab active" data-tab="add" onclick="cdSwitchEntriesTab('add')">Add Wallets</button>
            <button class="cd-entries-tab" data-tab="list" onclick="cdSwitchEntriesTab('list')">View List (<span id="entries-count">0</span>)</button>
            <button class="cd-entries-tab" data-tab="csv" onclick="cdSwitchEntriesTab('csv')">Upload CSV</button>
        </div>
        
        <!-- Add Wallets Tab -->
        <div class="cd-entries-content" id="entries-tab-add">
            <div class="cd-form-group">
                <label>Wallet Addresses (one per line or comma-separated)</label>
                <textarea id="entries-wallets" rows="6" placeholder="rWalletAddress1...&#10;rWalletAddress2...&#10;rWalletAddress3..."></textarea>
            </div>
            <!-- v730 (A4): default amount for quantity-aware lists — shown only for
                 discount_limited / limit_override. Amounts are totals, not top-ups. -->
            <div class="cd-form-group" id="entries-default-qty-wrap" style="display:none;">
                <label>Default amount per wallet</label>
                <input type="number" id="entries-default-qty" min="1" max="99999" placeholder="e.g. 5">
                <small style="display:block;color:var(--cd-text-muted);margin-top:4px;">Used for any wallet without its own amount (rWallet...,5). Amounts are totals — re-add a wallet with its new total to grant more.</small>
            </div>
            <button class="cd-btn cd-btn-primary" onclick="cdAddEntries()">Add Wallets</button>
        </div>
        
        <!-- View List Tab -->
        <div class="cd-entries-content" id="entries-tab-list" style="display:none;">
            <div class="cd-entries-list" id="entries-list-container">
                <!-- Populated by JS -->
            </div>
        </div>
        
        <!-- CSV Upload Tab -->
        <div class="cd-entries-content" id="entries-tab-csv" style="display:none;">
            <p class="cd-csv-help">Upload a CSV or TXT file with wallet addresses (one per line).</p>
            <p class="cd-csv-help">The allowlist settings (discount, early access, limits) will apply to all uploaded wallets.</p>
            <p class="cd-csv-help" id="entries-csv-qty-help" style="display:none;">A quantity column sets each wallet's amount automatically. Re-uploading a snapshot updates existing wallets' amounts (totals, not top-ups).</p>
            <div class="cd-form-group">
                <input type="file" id="entries-csv-file" accept=".csv,.txt">
            </div>
            <div class="cd-form-group" id="entries-csv-default-qty-wrap" style="display:none;">
                <label>Default amount per wallet</label>
                <input type="number" id="entries-csv-default-qty" min="1" max="99999" placeholder="e.g. 5">
                <small style="display:block;color:var(--cd-text-muted);margin-top:4px;">Used for rows without a quantity column.</small>
            </div>
            <button class="cd-btn cd-btn-primary" onclick="cdUploadCSV()">Upload & Process</button>
        </div>
        
        <div class="cd-modal-actions" style="margin-top: 1.5rem;">
            <button type="button" class="cd-btn cd-btn-secondary" onclick="cdCloseEntriesModal()">Close</button>
        </div>
    </div>
</div>

<!-- v730 (A4): Edit Allowlist Settings modal — the backend 'update' action always
     supported these fields; this is the first UI to call it (G2). -->
<div class="cd-modal-overlay" id="cd-edit-allowlist-modal" data-matrix-enabled="<?php echo (defined('IMC_ALLOWLIST_CURRENCY_MATRIX') && IMC_ALLOWLIST_CURRENCY_MATRIX) ? '1' : '0'; ?>">
    <div class="cd-modal">
        <h3 id="edit-allowlist-title"><svg class="imc-ic" aria-hidden="true"><use href="#ic-gear"></use></svg> Edit Allowlist</h3>
        <input type="hidden" id="edit-allowlist-id">
        <div class="cd-form-group">
            <label>Name</label>
            <input type="text" id="edit-allowlist-name" maxlength="100">
        </div>
        <div class="cd-form-group">
            <label>Description</label>
            <textarea id="edit-allowlist-description" rows="2"></textarea>
        </div>
        <div class="cd-form-group" id="edit-allowlist-discount-wrap" style="display:none;">
            <label>Discount % (0 = none)</label>
            <input type="number" id="edit-allowlist-percent" min="0" max="100" step="0.01">
            <label style="margin-top:8px;">Fixed price XRP (blank = none · 0 = FREE — overrides %)</label>
            <input type="number" id="edit-allowlist-price" min="0" step="0.000001">
            <!-- v731 (A4b): per-token pricing — rules built by cdBuildMatrixUI from the
                 listing's accepted currencies. A rule REPLACES the base benefit for that
                 token. Hidden while the feature flag is off and on free-claim lists. -->
            <div id="edit-allowlist-matrix-wrap" style="display:none;margin-top:10px;">
                <label>Per-Token Pricing (optional)</label>
                <small style="display:block;color:var(--cd-text-muted);margin-bottom:4px;">A rule here <strong>replaces</strong> the discount above for wallets paying in that token — even if it's smaller. Tokens without a rule keep the base discount. Free claims are free in every currency.</small>
                <div id="edit-allowlist-matrix-rows"></div>
            </div>
        </div>
        <div class="cd-form-group" id="edit-allowlist-hours-wrap" style="display:none;">
            <label>Early access window (hours before launch)</label>
            <input type="number" id="edit-allowlist-hours" min="1" max="8760">
        </div>
        <div class="cd-form-group" id="edit-allowlist-max-wrap" style="display:none;">
            <label>Per-wallet cap (fallback when an entry has no amount)</label>
            <input type="number" id="edit-allowlist-max" min="1" max="99999">
        </div>
        <div class="cd-modal-actions">
            <button type="button" class="cd-btn cd-btn-secondary" onclick="cdCloseEditAllowlist()">Cancel</button>
            <button type="button" class="cd-btn cd-btn-primary" onclick="cdSaveEditAllowlist()">Save Changes</button>
        </div>
    </div>
</div>

<!-- Fix 4 (Aug 2026): Pay Listing Fee modal -- both wallets (Joey local sign / Xaman QR). -->
<div class="cd-modal-overlay" id="cd-payfee-modal">
    <div class="cd-modal">
        <h3><svg class="imc-ic" aria-hidden="true"><use href="#ic-coins"></use></svg> Pay Fee &amp; Publish</h3>
        <p id="cd-payfee-desc" style="color:#94a3b8;font-size:0.9rem;margin-bottom:1rem;">Pay the platform fee to publish your listing.</p>
        <div id="cd-payfee-amount" style="font-size:1.4rem;font-weight:700;margin-bottom:1rem;"></div>
        <button type="button" id="cd-payfee-btn" class="cd-btn cd-btn-primary" style="width:100%;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-coins"></use></svg> Pay Fee</button>
        <div id="cd-payfee-xumm" style="display:none;text-align:center;margin-top:1rem;">
            <p style="color:#94a3b8;font-size:0.85rem;margin-bottom:0.5rem;">Scan with Xaman or open on this device:</p>
            <img id="cd-payfee-qr" alt="Scan to pay" style="width:220px;height:220px;background:#fff;border-radius:12px;padding:8px;margin:0 auto 0.75rem;display:block;" />
            <a id="cd-payfee-deeplink" href="#" target="_blank" rel="noopener" class="cd-btn cd-btn-secondary" style="width:100%;">Open in Xaman</a>
        </div>
        <div id="cd-payfee-status" style="margin-top:1rem;font-size:0.9rem;"></div>
        <div class="cd-modal-actions" style="margin-top:1.5rem;">
            <button type="button" class="cd-btn cd-btn-secondary" onclick="cdClosePayFeeModal()">Close</button>
        </div>
    </div>
</div>

<!-- U7 (Unlockables Master): Manage Vault modal -- post-mint PAID additions.
     Upload-to-STAGING (pool, holder-invisible) -> server-priced quote ->
     pay (both wallets, mirroring the payfee modal) -> on-chain verify -> live. -->
<div class="cd-modal-overlay" id="cd-vault-modal">
    <div class="cd-modal" style="max-width:560px;">
        <h3><svg class="imc-ic" aria-hidden="true"><use href="#ic-lock"></use></svg> Manage Vault <span id="cd-vault-slots" style="font-size:0.8rem;color:#94a3b8;font-weight:400;"></span></h3>
        <p style="color:#94a3b8;font-size:0.85rem;margin:-0.25rem 0 0.9rem;">New files are <strong>charged per file by size (1–10 XRP)</strong> and go live to every holder — including past buyers — the moment payment is verified on-chain.</p>
        <div id="cd-vault-existing" style="margin-bottom:0.75rem;"></div>
        <div id="cd-vault-addzone" class="cd-btn cd-btn-secondary" style="width:100%;text-align:center;cursor:pointer;margin-bottom:0.75rem;">Add files (PDF, ZIP, audio, video, images — 1GB max each · fee per file)</div>
        <input type="file" id="cd-vault-input" multiple hidden>
        <div id="cd-vault-pool" style="display:flex;flex-direction:column;gap:8px;margin-bottom:0.75rem;"></div>
        <div id="cd-vault-quote" style="font-size:1.15rem;font-weight:700;margin-bottom:1rem;display:none;"></div>
        <button type="button" id="cd-vault-pay-btn" class="cd-btn cd-btn-primary" style="width:100%;display:none;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-coins"></use></svg> Pay &amp; Attach</button>
        <div id="cd-vault-xumm" style="display:none;text-align:center;margin-top:1rem;">
            <p style="color:#94a3b8;font-size:0.85rem;margin-bottom:0.5rem;">Scan with Xaman or open on this device:</p>
            <img id="cd-vault-qr" alt="Scan to pay" style="width:220px;height:220px;background:#fff;border-radius:12px;padding:8px;margin:0 auto 0.75rem;display:block;" />
            <a id="cd-vault-deeplink" href="#" target="_blank" rel="noopener" class="cd-btn cd-btn-secondary" style="width:100%;">Open in Xaman</a>
        </div>
        <div id="cd-vault-status" style="margin-top:0.75rem;min-height:20px;font-size:0.9rem;"></div>
        <button type="button" class="cd-btn cd-btn-secondary" style="width:100%;margin-top:0.5rem;" onclick="cdCloseVault()">Close</button>
    </div>
</div>

<!-- Toast Container -->
<div class="cd-toast-container" id="cd-toast-container"></div>

</div><!-- end #creator-dashboard-page -->

<script>
/**
 * Creator Dashboard JavaScript
 * v64 — IMCollectibles
 */
(function() {
    'use strict';
    
    // ════════════════════════════════════════════════════════════════════════
    // Configuration
    // ════════════════════════════════════════════════════════════════════════
    
    const CONFIG = {
        account: <?php echo wp_json_encode($xrpl_account); ?>,
        nonce: <?php echo wp_json_encode($nonce); ?>,
        endpoints: <?php echo wp_json_encode($endpoints); ?>
    };
    
    // State
    let allListings = [];
    let currentCollection = null;
    let currentFilter = 'all';
    
    // ════════════════════════════════════════════════════════════════════════
    // Utility Functions
    // ════════════════════════════════════════════════════════════════════════
    
    function escapeHtml(text) {
        if (!text) return '';
        // v472: textContent→innerHTML encodes <>& but NOT quotes per HTML spec.
        // Encode quotes explicitly so the output is safe in HTML attribute values
        // (e.g. alt="${escapeHtml(x)}"). For inline-JS-in-attribute contexts like
        // onclick="foo('${...}')", use escapeJsAttr() instead — HTML entity decoding
        // happens BEFORE the JS parser sees the string, so quotes inside a JS
        // string literal need JS-level escaping, not HTML encoding.
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // v472: Safe interpolation into inline JS string arguments inside HTML attributes.
    // Pattern: onclick="foo(${escapeJsAttr(name)})"  — NO outer quotes in the call site,
    // because JSON.stringify provides them. The result is:
    //   1) a valid JS string literal (proper escaping of \ and " via JSON.stringify)
    //   2) safe inside an HTML attribute value (outer " replaced with &quot;)
    function escapeJsAttr(value) {
        return JSON.stringify(String(value == null ? '' : value))
            .replace(/"/g, '&quot;');
    }
    
    function formatXRP(amount) {
        const num = parseFloat(amount) || 0;
        return num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 6 });
    }
    
    // v86: Format amount based on currency type
    function formatAmount(amount, currency = 'XRP') {
        const num = parseFloat(amount) || 0;
        if (currency === 'XRP') {
            return num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 6 });
        } else if (currency === 'RLUSD') {
            return num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        } else {
            return num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 });
        }
    }
    
    function formatDate(dateStr) {
        if (!dateStr) return '—';
        const date = new Date(dateStr);
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    
    function shortWallet(addr) {
        if (!addr) return '—';
        return addr.slice(0, 6) + '...' + addr.slice(-4);
    }
    
    function ipfsToHttp(uri) {
        if (!uri) return '/wp-content/uploads/fallback-nft.svg';
        if (uri.startsWith('ipfs://')) {
            return 'https://metadata.imcollectibles.io/img.php?url=' + encodeURIComponent(uri);
        }
        if (uri.startsWith('http')) return uri;
        return 'https://metadata.imcollectibles.io/img.php?url=' + encodeURIComponent('ipfs://' + uri);
    }
    
    function showToast(message, type = 'success') {
        const container = document.getElementById('cd-toast-container');
        const toast = document.createElement('div');
        toast.className = `cd-toast cd-toast-${type}`;
        toast.textContent = message;
        container.appendChild(toast);
        
        setTimeout(() => toast.remove(), 4000);
    }
    
    // ════════════════════════════════════════════════════════════════════════
    // API Calls
    // ════════════════════════════════════════════════════════════════════════
    
    async function fetchListings() {
        try {
            const url = `${CONFIG.endpoints.listingsHandler}?action=get_by_artist&account=${CONFIG.account}`;
            // P5: cache listings by id so the edit modal can read progressive_json +
            // minted_count without threading params through the card HTML.
            window.cdListingsById = window.cdListingsById || {};
            const resp = await fetch(url);
            const data = await resp.json();
            
            if (data.success) {
                // R-4 (14 Sep 2026): cancelled listings are hidden from the
                // dashboard. Filtered HERE rather than in listings-handler.php on
                // purpose - that endpoint serves 12 actions, and hiding rows at the
                // source is hard to undo and hard to debug. The raw rows stay in
                // cdAllListingsRaw, so "where did my listing go?" is answerable and
                // a show-cancelled toggle is a one-line addition.
                //
                // ⚠ allListings feeds the four header stats, the Overview cards, the
                // grid, the scope line AND the collection detail - so this single
                // filter moves every count at once. That is correct, and it means
                // headline numbers CHANGE on deploy for anyone with cancelled rows.
                // The analytics endpoint is unaffected: it filters on purchase
                // mint_status and never sees listing status.
                window.cdAllListingsRaw = data.data.listings || [];
                allListings = window.cdAllListingsRaw.filter(l => l.status !== 'cancelled');
                // by-id map keeps the RAW set: a cancelled listing must still
                // resolve if something holds a reference to its id.
                window.cdAllListingsRaw.forEach(l => { window.cdListingsById[String(l.id)] = l; }); // P5
                updateStats();
                renderCollections();
                // B-c: fetchListings() is re-called after every mutation (8 sites),
                // so this is the one place that keeps Overview honest without
                // touching any of those call sites.
        if (window.cdNavState && window.cdNavState.tab === 'activity'
                    && typeof cdLoadActivity === 'function') cdLoadActivity();
                // B-e: one all-time analytics call for the cards. Fire-and-forget -
                // renderCollections() has already painted, and this repaints when
                // it lands. Not awaited, so a slow or failed call never delays the grid.
                if (typeof cdLoadCollectionFacts === 'function') cdLoadCollectionFacts();
                if (typeof cdSetCrumbs === 'function') cdSetCrumbs();      // B-d

                // R-9c: DEFAULT TAB. R-6 built Activity because the question on
                // opening is "what happened?"; Collections is where you go to DO
                // something. So Activity leads - EXCEPT for a creator with no
                // listings at all, who would land on an empty feed when what they
                // need is the Collections tab's "create your first NFT" prompt.
                //
                // Decided from allListings, which is already loaded here, so there
                // is no second request and no visible tab flip.
                // ⚠ Never overrides a deep link: cdBooted is set by the D-C router
                // when a hash was present, and this runs only on the first load.
                if (!window.cdTabDefaulted) {
                    window.cdTabDefaulted = true;
                    if (!window.location.hash && allListings.length
                        && window.cdNavState.tab === 'listings'
                        && typeof cdSwitchTab === 'function') {
                        cdSwitchTab('activity');
                    }
                }
                // D-B2: fire-and-forget. updateStats() has already painted the
                // inventory pair synchronously; this fills the activity figures
                // when it lands, so a slow or failed call never blocks the header.
                if (typeof cdLoadHeaderActivity === 'function') cdLoadHeaderActivity();
                // D-C: apply the boot route ONCE. This block re-runs after every
                // mutation (8 fetchListings sites), so without the flag pausing a
                // listing would yank the view back to the URL you arrived on.
                if (!window.cdBooted) {
                    window.cdBooted = true;
                    if (window.location.hash) cdApplyHash(window.location.hash);
                }
            } else {
                throw new Error(data.error || 'Failed to load listings');
            }
        } catch (err) {
            console.error('fetchListings error:', err);
            showToast('Failed to load listings: ' + err.message, 'error');
        }
    }
    
    async function fetchPurchaseHistory(listingIds) {
        try {
            // Use mint-on-demand-handler to get purchases for these listings
            const url = `${CONFIG.endpoints.mintOnDemand}?action=get_artist_purchases&account=${CONFIG.account}&listing_ids=${listingIds.join(',')}`;
            const resp = await fetch(url);
            const data = await resp.json();
            
            if (data.success) {
                return data.data.purchases || [];
            }
            return [];
        } catch (err) {
            console.error('fetchPurchaseHistory error:', err);
            return [];
        }
    }
    
    async function updateListingStatus(listingId, newStatus) {
        try {
            const formData = new FormData();
            formData.append('action', 'update');
            formData.append('nonce', CONFIG.nonce);
            formData.append('listing_id', listingId);
            formData.append('artist_account', CONFIG.account);
            formData.append('status', newStatus);
            
            const resp = await fetch(CONFIG.endpoints.listingsHandler, {
                method: 'POST',
                body: formData
            });
            
            const data = await resp.json();
            
            if (data.success) {
                showToast(`Listing ${newStatus === 'active' ? 'resumed' : newStatus}!`, 'success');
                await fetchListings(); // Refresh
                
                // If we're in collection detail view, re-render it
                cdRefreshCurrentView();          // R-9b
            } else {
                throw new Error(data.error || 'Update failed');
            }
        } catch (err) {
            console.error('updateListingStatus error:', err);
            showToast('Failed to update: ' + err.message, 'error');
        }
    }
    
    async function updateListingPrice(listingId, newPrice, currencies = null) {
        try {
            const formData = new FormData();
            formData.append('action', 'update');
            formData.append('nonce', CONFIG.nonce);
            formData.append('listing_id', listingId);
            formData.append('artist_account', CONFIG.account);
            formData.append('price_xrp', newPrice);
            
            // v71: Include currencies if provided
            if (currencies && currencies.length > 0) {
                formData.append('accepted_currencies', JSON.stringify(currencies));
            }
            
            const resp = await fetch(CONFIG.endpoints.listingsHandler, {
                method: 'POST',
                body: formData
            });
            
            const data = await resp.json();
            
            if (data.success) {
                showToast('Pricing updated!', 'success');
                cdCloseModal();
                await fetchListings();
                
                cdRefreshCurrentView();          // R-9b
            } else {
                throw new Error(data.error || 'Update failed');
            }
        } catch (err) {
            console.error('updateListingPrice error:', err);
            showToast('Failed to update pricing: ' + err.message, 'error');
        }
    }
    
    // v77: Update listing with full pricing mode support
    async function updateListingPricing(listingId, updateData) {
        try {
            const formData = new FormData();
            formData.append('action', 'update');
            formData.append('nonce', CONFIG.nonce);
            formData.append('listing_id', listingId);
            formData.append('artist_account', CONFIG.account);
            
            // Add pricing mode
            if (updateData.pricing_mode) {
                formData.append('pricing_mode', updateData.pricing_mode);
            }
            
            // Add XRP price (for static mode)
            if (updateData.price_xrp !== undefined) {
                formData.append('price_xrp', updateData.price_xrp);
            }
            
            // Add USD price (for dynamic mode)
            if (updateData.price_usd !== undefined) {
                formData.append('price_usd', updateData.price_usd);
            }
            
            // Add accepted currencies
            if (updateData.accepted_currencies) {
                formData.append('accepted_currencies', JSON.stringify(updateData.accepted_currencies));
            }
            
            const resp = await fetch(CONFIG.endpoints.listingsHandler, {
                method: 'POST',
                body: formData
            });
            
            const data = await resp.json();
            
            if (data.success) {
                showToast('Pricing updated!', 'success');
                cdCloseModal();
                await fetchListings();
                
                cdRefreshCurrentView();          // R-9b
            } else {
                throw new Error(data.error || 'Update failed');
            }
        } catch (err) {
            console.error('updateListingPricing error:', err);
            showToast('Failed to update pricing: ' + err.message, 'error');
        }
    }
    
    // v68: Scroll to allowlists section from actions menu
    // D-A: cdArtistPfpPreview / cdAddCustomLink / cdRemoveCustomLink /
    // cdSaveArtistProfile removed with the profile editor above. The same
    // handlers live on the /user/<account> profile page; nothing else here
    // called them.

    /* cdScrollToAllowlists removed: superseded by R-5a, which made Allowlists a
       SECTION of the listing manager rather than something further down the same
       page - there is nothing to scroll to any more. Verified inert first: one
       definition, zero callers, and no reference from any other file.
       #cd-allowlists-section itself stays; it is the panel R-5a created. */
    
    window.cdPauseListing = function(listingId) {
        if (confirm('Pause this listing? Buyers won\'t be able to mint while paused.')) {
            updateListingStatus(listingId, 'paused');
        }
    };
    
    window.cdResumeListing = function(listingId) {
        updateListingStatus(listingId, 'active');
    };

    // v412: Schedule Release — resume a paused listing with a future launch date.
    // Opens the existing schedule modal with a flag so cdSaveSchedule() knows
    // to also send status=active (paused→active transition) alongside the schedule.
    window.cdScheduleRelease = function(listingId) {
        document.getElementById('schedule-listing-id').value = listingId;
        document.getElementById('schedule-resume-on-save').value = '1';
        document.getElementById('schedule-modal-title').textContent = 'Schedule Release';

        const input = document.getElementById('schedule-datetime-input');
        input.value = '';
        document.getElementById('schedule-preview-text').textContent = '';

        // Enforce minimum: at least 1 hour from now
        const minDate = new Date(Date.now() + 60 * 60 * 1000);
        const pad = n => String(n).padStart(2, '0');
        input.min = `${minDate.getFullYear()}-${pad(minDate.getMonth()+1)}-${pad(minDate.getDate())}T${pad(minDate.getHours())}:${pad(minDate.getMinutes())}`;

        // R-5c: already on screen - the panel is part of the listing manager.
    };
    
    // v233: Go live immediately (bypass scheduled launch date)
    window.cdGoLiveNow = async function(listingId) {
        if (!confirm('Go live immediately? This will make the collection available for minting right now.')) return;
        try {
            const formData = new FormData();
            formData.append('action', 'update');
            formData.append('listing_id', listingId);
            formData.append('artist_account', CONFIG.account);
            formData.append('nonce', CONFIG.nonce);
            formData.append('launch_type', 'immediate');
            formData.append('launch_at', '');
            const resp = await fetch(CONFIG.endpoints.listingsHandler, { method: 'POST', body: formData });
            const data = await resp.json();
            if (data.success) {
                showToast('Collection is now LIVE! <svg class="imc-ic" aria-hidden="true"><use href="#ic-rocket"></use></svg> ', 'success');
                await fetchListings();
            } else {
                throw new Error(data.error || 'Failed to go live');
            }
        } catch (err) {
            showToast('Error: ' + err.message, 'error');
        }
    };
    
    // v699 (Phase 4): change a tiered listing's card cover. Opens a file picker, uploads the
    // image to action=update (server pins to IPFS + sets cover_ipfs, tiered-gated). Display-only.
    window.cdChangeCover = function(listingId) {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/jpeg,image/png,image/webp,image/gif';
        input.onchange = async () => {
            const file = input.files && input.files[0];
            if (!file) return;
            if (file.size > 8 * 1024 * 1024) { showToast('Image too large (max 8MB)', 'error'); return; }
            try {
                showToast('Uploading card cover…', 'info');
                const fd = new FormData();
                fd.append('action', 'update');
                fd.append('nonce', CONFIG.nonce);
                fd.append('listing_id', listingId);
                fd.append('artist_account', CONFIG.account);
                fd.append('tier_card_cover_file', file);
                const resp = await fetch(CONFIG.endpoints.listingsHandler, { method: 'POST', body: fd });
                const data = await resp.json();
                if (data && data.success) {
                    showToast('Card cover updated', 'success');
                    await fetchListings();
                } else {
                    showToast('Update failed: ' + ((data && (data.error || data.message)) || 'unknown error'), 'error');
                }
            } catch (err) {
                console.error('cdChangeCover error:', err);
                showToast('Update failed: ' + err.message, 'error');
            }
        };
        input.click();
    };

    window.cdCancelListing = function(listingId) {
        document.getElementById('cancel-listing-id').value = listingId;
        document.getElementById('cancel-confirm-input').value = '';
        document.getElementById('cancel-confirm-btn').disabled = true;
        document.getElementById('cd-cancel-modal').classList.add('active');
    };
    
    window.cdCloseCancelModal = function() {
        document.getElementById('cd-cancel-modal').classList.remove('active');
        document.getElementById('cancel-confirm-input').value = '';
    };
    
    window.cdConfirmCancel = function() {
        const listingId = document.getElementById('cancel-listing-id').value;
        const confirmInput = document.getElementById('cancel-confirm-input').value;
        
        if (confirmInput.toUpperCase() === 'CANCEL') {
            cdCloseCancelModal();
            updateListingStatus(listingId, 'cancelled');
        }
    };
    
    document.getElementById('cancel-confirm-input').addEventListener('input', function(e) {
        const btn = document.getElementById('cancel-confirm-btn');
        btn.disabled = e.target.value.toUpperCase() !== 'CANCEL';
    });
    
    document.getElementById('cd-cancel-modal').addEventListener('click', function(e) {
        if (e.target === this) cdCloseCancelModal();
    });
    
    // Fix 4 (Aug 2026): the old cdGoToMint sent drafts to /mint/?resume=<id>, but mint.js
    // never reads ?resume= (drafts live in localStorage, wiped on cancel) -- a dead end for
    // EVERY wallet. Open an in-place pay-fee modal instead, mirroring the proven both-wallet
    // flow in mint-on-demand.js (create_fee_payment -> Joey local sign OR Xaman QR -> publish).
    window.cdGoToMint = function(listingId) { cdOpenPayFeeModal(listingId); };

    // -- Fix 4: Pay-fee modal (both wallets), mirroring mint-on-demand.js --

    const cdVault = { listingId: 0, pool: [], ticket: null, ticketExp: 0, existingCount: 0, total: 0, busy: false, poll: null };
    const CD_UL_EXTS = ['pdf','epub','txt','zip','png','jpg','jpeg','tiff','tif','psd','bmp','webp','gif','mp3','wav','flac','m4a','aac','ogg','mp4','mov','webm'];

    // ------------------------------------------------------------------------
    // NOT IN THIS REPOSITORY. Uploading a protected master to the media service,
    // and the credential that authorises it, belong to that service's trust
    // boundary. It is maintained as separate infrastructure and is not included
    // here. The call sites below are left intact so the vault flow reads end to
    // end.
    // ------------------------------------------------------------------------
    async function cdVaultTicket() {
        throw new Error('cdVaultTicket is not available in this build');
    }

    async function cdVaultUpload(file, onProgress) {
        throw new Error('cdVaultUpload is not available in this build');
    }

    function cdVaultClaims() {
        return cdVault.pool.filter(p => p.status === 'done').map(p => ({ content_hash: p.hash, label: p.label, tier_order: null }));
    }

    async function cdVaultRefresh() {
        const fd = new FormData();
        fd.append('action', 'vault_add_quote');
        fd.append('nonce', CONFIG.nonce);
        fd.append('listing_id', cdVault.listingId);
        fd.append('unlockables', JSON.stringify(cdVaultClaims()));
        const r = await fetch(CONFIG.endpoints.listingsHandler, { method: 'POST', body: fd });
        const d = await r.json();
        if (!d.success) { cdVaultNote(d.error || 'Vault error'); return null; }
        const q = d.data;
        cdVault.existingCount = q.existing_count; cdVault.total = parseFloat(q.total_xrp) || 0;
        const slots = document.getElementById('cd-vault-slots');
        if (slots) slots.textContent = q.existing_count + '/10 slots used';
        // U7-r8: per-file fees ride the quote -- merge into staged entries by hash
        // so rows show their price (server-priced; no client grid needed).
        const feeByHash = {};
        (q.items || []).forEach(it => { feeByHash[it.content_hash] = parseFloat(it.fee); });
        cdVault.pool.forEach(p => { if (p.hash && feeByHash[p.hash] !== undefined) p.fee = feeByHash[p.hash]; });
        cdVaultRenderPool();
        const ex = document.getElementById('cd-vault-existing');
        if (ex) ex.innerHTML = (q.existing || []).length
            ? '<div style="font-size:0.78rem;color:#d4af37;margin-bottom:4px;font-weight:600;">In the vault — live to holders</div>'
              + q.existing.map(e => `<div style="font-size:0.85rem;color:#94a3b8;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-lock"></use></svg> ${cdVaultEsc(e.label || 'Unlockable file')} · ${(e.file_size / 1048576).toFixed(1)} MB</div>`).join('')
            : '<div style="font-size:0.85rem;color:#64748b;">No vault files yet.</div>';
        const quote = document.getElementById('cd-vault-quote');
        const pay = document.getElementById('cd-vault-pay-btn');
        const n = cdVaultClaims().length;
        if (quote) { quote.style.display = n > 0 ? '' : 'none'; quote.textContent = 'Fee for ' + n + ' new file' + (n === 1 ? '' : 's') + ': ' + cdVault.total.toFixed(2) + ' XRP'; }
        if (pay) pay.style.display = n > 0 ? '' : 'none';
        return q;
    }

    // U7-r8: in-modal messenger -- page toasts render UNDER .cd-modal-overlay.
    function cdVaultNote(msg) {
        const s = document.getElementById('cd-vault-status');
        if (s) s.innerHTML = '<span style="color:#f8b4b4;">' + cdVaultEsc(msg) + '</span>';
    }
    function cdVaultEsc(s) { const d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }

    function cdVaultRenderPool() {
        const list = document.getElementById('cd-vault-pool');
        if (!list) return;
        const stagedHdr = cdVault.pool.length
            ? '<div style="font-size:0.78rem;color:#94a3b8;margin-bottom:2px;font-weight:600;">Staged — pay to attach</div>' : '';
        list.innerHTML = stagedHdr + cdVault.pool.map((p, i) => {
            const stat = p.status === 'uploading' ? ('Uploading' + (p.progress ? ' ' + p.progress + '%' : '…')) : p.status === 'done' ? '<svg class="imc-ic" aria-hidden="true"><use href="#ic-check-circle"></use></svg> Staged' : ('<svg class="imc-ic" aria-hidden="true"><use href="#ic-x-circle"></use></svg> ' + cdVaultEsc(p.err || 'Failed'));
            const feeTxt = (p.status === 'done' && typeof p.fee === 'number') ? ' · ' + p.fee.toFixed(2) + ' XRP' : '';
            return `<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 10px;border:1px solid rgba(255,255,255,0.1);border-radius:8px;">
                <input type="text" data-i="${i}" class="cd-vault-label" value="${cdVaultEsc(p.label)}" maxlength="120" style="flex:1;background:rgba(0,0,0,0.25);border:1px solid rgba(255,255,255,0.12);border-radius:6px;color:inherit;padding:5px 8px;font-size:0.82rem;">
                <span style="font-size:0.75rem;color:#94a3b8;white-space:nowrap;">${(p.size / 1048576).toFixed(1)} MB${feeTxt} · ${stat}</span>
                <button type="button" data-i="${i}" class="cd-vault-remove" style="background:none;border:none;color:#94a3b8;cursor:pointer;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-x-circle"></use></svg> </button>
            </div>`;
        }).join('');
        list.querySelectorAll('.cd-vault-label').forEach(el => el.addEventListener('input', e => { cdVault.pool[parseInt(e.target.dataset.i, 10)].label = e.target.value; }));
        list.querySelectorAll('.cd-vault-remove').forEach(el => el.addEventListener('click', e => { cdVault.pool.splice(parseInt(e.currentTarget.dataset.i, 10), 1); cdVaultRenderPool(); cdVaultRefresh(); }));
    }

    window.cdOpenVault = async function(listingId) {
        cdVault.listingId = listingId; cdVault.pool = []; cdVault.busy = false; cdVaultStopPoll();
        const modal = document.getElementById('cd-vault-modal');
        document.getElementById('cd-vault-xumm').style.display = 'none';
        document.getElementById('cd-vault-status').innerHTML = '';
        const pay = document.getElementById('cd-vault-pay-btn');
        pay.disabled = false; pay.textContent = 'Pay & Attach';
        pay.onclick = cdVaultPay;
        cdVaultRenderPool();
        modal.classList.add('active');
        await cdVaultRefresh();
        const zone = document.getElementById('cd-vault-addzone');
        const input = document.getElementById('cd-vault-input');
        zone.onclick = () => input.click();
        input.onchange = async (e) => {
            const files = Array.from(e.target.files || []); input.value = '';
            for (const f of files) {
                const ext = (f.name.split('.').pop() || '').toLowerCase();
                if (!CD_UL_EXTS.includes(ext)) { cdVaultNote('.' + ext + ' is not allowed for unlockable content'); continue; }
                if (f.size > 1073741824) { cdVaultNote(f.name + ' is over the 1GB limit'); continue; }
                if (cdVault.existingCount + cdVaultClaims().length >= 10) { cdVaultNote('Vault limit: 10 files per listing'); break; }
                const entry = { name: f.name, size: f.size, label: f.name.replace(/\.[^.]+$/, ''), status: 'uploading', progress: 0, hash: null };
                cdVault.pool.push(entry); cdVaultRenderPool();
                try {
                    const res = await cdVaultUpload(f, p => { entry.progress = p; cdVaultRenderPool(); });
                    entry.hash = res.content_hash || null;
                    entry.status = entry.hash ? 'done' : 'error';
                    if (!entry.hash) entry.err = 'No hash returned';
                } catch (err) { entry.status = 'error'; entry.err = err.message || 'Upload failed'; }
                cdVaultRenderPool();
            }
            await cdVaultRefresh();
        };
    };

    window.cdCloseVault = function() {
        cdVaultStopPoll();
        const modal = document.getElementById('cd-vault-modal');
        if (modal) modal.classList.remove('active');
    };

    function cdVaultStopPoll() { if (cdVault.poll) { clearInterval(cdVault.poll); cdVault.poll = null; } }

    async function cdVaultClaim(txHash) {
        const status = document.getElementById('cd-vault-status');
        status.innerHTML = '<span style="color:#94a3b8;">Verifying payment on-chain...</span>';
        const fd = new FormData();
        fd.append('action', 'vault_add_claim');
        fd.append('nonce', CONFIG.nonce);
        fd.append('listing_id', cdVault.listingId);
        fd.append('unlockables', JSON.stringify(cdVaultClaims()));
        fd.append('tx_hash', txHash);
        const r = await fetch(CONFIG.endpoints.listingsHandler, { method: 'POST', body: fd });
        const d = await r.json();
        if (!d.success) { status.innerHTML = '<span style="color:#f87171;">' + cdVaultEsc(d.error || 'Attach failed') + '</span>'; return; }
        status.innerHTML = '<span style="color:#10b981;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-check-circle"></use></svg> ' + cdVaultEsc(d.data.message || 'Attached!') + '</span>';
        showToast(d.data.message || 'Vault updated!', 'success');
        cdVault.pool = []; cdVaultRenderPool();
        await cdVaultRefresh();
    }

    async function cdVaultPay() {
        if (cdVault.busy) return; cdVault.busy = true;
        const btn = document.getElementById('cd-vault-pay-btn');
        const xumm = document.getElementById('cd-vault-xumm');
        const status = document.getElementById('cd-vault-status');
        btn.disabled = true; btn.textContent = 'Creating payment...';
        try {
            const q = await cdVaultRefresh(); // re-quote server-side just before pay
            if (!q || cdVaultClaims().length === 0) throw new Error('No staged files to attach');
            // Joey branch: local sign (mirrors cdPayFee).
            if (window.__joeySession && window.__joeySession.live && window.imuWallet) {
                if (CONFIG.account !== window.__joeySession.account) { throw new Error('Connected wallet does not match your account. Please reconnect.'); }
                const jfd = new FormData();
                jfd.append('action', 'create_vault_fee_payment');
                jfd.append('nonce', CONFIG.nonce);
                jfd.append('listing_id', cdVault.listingId);
                jfd.append('artist_account', CONFIG.account);
                jfd.append('amount_xrp', cdVault.total);
                jfd.append('wallet', 'joey');
                const jres = await fetch(CONFIG.endpoints.mintOnDemand, { method: 'POST', body: jfd });
                const jd = JSON.parse(await jres.text());
                if (!jd.success || !jd.data || !jd.data.txjson) throw new Error(jd.error || 'Failed to prepare payment');
                btn.textContent = 'Check your wallet to sign...';
                let jsigned;
                try { jsigned = await (window.imuJoeySign || window.imuWallet.sign)(jd.data.txjson); }
                catch (e) { const em = (e && e.message) || ''; showToast(/timed out|timeout/i.test(em) ? 'Signing timed out. Please try again.' : /cancel/i.test(em) ? 'Payment cancelled.' : 'Payment rejected.', 'error'); btn.disabled = false; btn.textContent = 'Pay & Attach'; cdVault.busy = false; return; }
                const jtx = jsigned && (jsigned.hash || jsigned.tx_hash);
                if (!jtx) throw new Error('No transaction hash returned from your wallet.');
                await cdVaultClaim(jtx);
                cdVault.busy = false; btn.disabled = false; btn.textContent = 'Pay & Attach';
                return;
            }
            // Xaman branch: QR + generic poll_payment.
            const fd = new FormData();
            fd.append('action', 'create_vault_fee_payment');
            fd.append('nonce', CONFIG.nonce);
            fd.append('listing_id', cdVault.listingId);
            fd.append('artist_account', CONFIG.account);
            fd.append('amount_xrp', cdVault.total);
            const res = await fetch(CONFIG.endpoints.mintOnDemand, { method: 'POST', body: fd });
            const result = JSON.parse(await res.text());
            if (!result.success) throw new Error(result.error || 'Failed to create payment');
            btn.style.display = 'none';
            xumm.style.display = 'block';
            const qrImg = document.getElementById('cd-vault-qr');
            const deeplink = document.getElementById('cd-vault-deeplink');
            if (qrImg && result.data && result.data.qr_png) qrImg.src = result.data.qr_png;
            if (deeplink && result.data && result.data.deeplink) deeplink.href = result.data.deeplink;
            if (/Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent || '') && deeplink) deeplink.setAttribute('target', '_self');
            status.innerHTML = '<span style="color:#94a3b8;">Waiting for payment...</span>';
            cdVaultStopPoll();
            cdVault.poll = setInterval(async () => {
                try {
                    const r = await fetch(`${CONFIG.endpoints.mintOnDemand}?action=poll_payment&uuid=${result.data.uuid}`);
                    const data = await r.json();
                    if (data.data && data.data.signed && data.data.dispatched_result === 'tesSUCCESS' && data.data.tx_hash) {
                        cdVaultStopPoll();
                        xumm.style.display = 'none';
                        await cdVaultClaim(data.data.tx_hash);
                        btn.style.display = 'block'; btn.disabled = false; btn.textContent = 'Pay & Attach'; cdVault.busy = false;
                    } else if (data.data && data.data.signed && data.data.dispatched_result && data.data.dispatched_result !== 'tesSUCCESS') {
                        cdVaultStopPoll();
                        status.innerHTML = '<span style="color:#f87171;">Payment failed on-ledger (' + cdVaultEsc(data.data.dispatched_result) + ')</span>';
                        xumm.style.display = 'none'; btn.style.display = 'block'; btn.disabled = false; btn.textContent = 'Pay & Attach'; cdVault.busy = false;
                    }
                } catch (e) { /* transient poll errors are fine */ }
            }, 3000);
        } catch (error) {
            showToast('Vault payment failed: ' + error.message, 'error');
            btn.disabled = false; btn.textContent = 'Pay & Attach'; btn.style.display = 'block'; cdVault.busy = false;
        }
    }

    let cdPayFeePoll = null;
    let cdPayFeeBusy = false;

    window.cdOpenPayFeeModal = function(listingId) {
        const l = (allListings || []).find(x => String(x.id) === String(listingId));
        if (!l) { showToast('Listing not found.', 'error'); return; }
        if (l.status !== 'draft') { showToast('This listing is not a draft.', 'error'); return; }
        cdPayFeeBusy = false;
        const modal = document.getElementById('cd-payfee-modal');
        const amtEl = document.getElementById('cd-payfee-amount');
        const btn   = document.getElementById('cd-payfee-btn');
        const xumm  = document.getElementById('cd-payfee-xumm');
        const status= document.getElementById('cd-payfee-status');
        const fee = parseFloat(l.platform_fee_amount || 0);
        amtEl.textContent = fee > 0 ? (fee + ' XRP') : 'Fee calculated at payment';
        btn.style.display = 'block'; btn.disabled = false; btn.textContent = 'Pay Fee';
        xumm.style.display = 'none'; status.innerHTML = '';
        btn.onclick = function() { cdStartFeePayment(l); };
        modal.classList.add('active');
    };

    window.cdClosePayFeeModal = function() {
        cdStopFeePoll();
        cdPayFeeBusy = false;
        const modal = document.getElementById('cd-payfee-modal');
        if (modal) modal.classList.remove('active');
    };

    async function cdStartFeePayment(l) {
        const btn   = document.getElementById('cd-payfee-btn');
        const xumm  = document.getElementById('cd-payfee-xumm');
        const status= document.getElementById('cd-payfee-status');
        btn.disabled = true; btn.textContent = 'Creating payment...';
        try {
            // Joey branch: local sign, then publish (mirrors mint-on-demand.js).
            if (window.__joeySession && window.__joeySession.live && window.imuWallet) {
                if (CONFIG.account !== window.__joeySession.account) {
                    showToast('Connected wallet does not match your account. Please reconnect.', 'error');
                    btn.disabled = false; btn.textContent = 'Pay Fee'; return;
                }
                const jfd = new FormData();
                jfd.append('action', 'create_fee_payment');
                jfd.append('nonce', CONFIG.nonce);
                jfd.append('listing_id', l.id);
                jfd.append('artist_account', CONFIG.account);
                jfd.append('wallet', 'joey');
                const jres = await fetch(CONFIG.endpoints.mintOnDemand, { method: 'POST', body: jfd });
                const jd = JSON.parse(await jres.text());
                if (!jd.success || !jd.data || !jd.data.txjson) throw new Error(jd.error || 'Failed to prepare fee payment');
                btn.textContent = 'Check your wallet to sign...';
                let jsigned;
                try { jsigned = await (window.imuJoeySign||window.imuWallet.sign)(jd.data.txjson); }
                catch (e) { const em=(e&&e.message)||''; showToast(/timed out|timeout/i.test(em)?'Signing timed out. Please try again.':/cancel/i.test(em)?'Payment cancelled.':'Payment rejected.', 'error'); btn.disabled=false; btn.textContent='Pay Fee'; return; }
                const jtx = jsigned && (jsigned.hash || jsigned.tx_hash);
                if (!jtx) { showToast('No transaction hash returned from your wallet.', 'error'); btn.disabled=false; btn.textContent='Pay Fee'; return; }
                status.innerHTML = '<span style="color:#10b981;">Signed! Publishing...</span>';
                await cdPublishListing(l.id, jtx);
                return;
            }

            // Xaman branch: QR + poll.
            const fd = new FormData();
            fd.append('action', 'create_fee_payment');
            fd.append('nonce', CONFIG.nonce);
            fd.append('listing_id', l.id);
            fd.append('artist_account', CONFIG.account);
            const res = await fetch(CONFIG.endpoints.mintOnDemand, { method: 'POST', body: fd });
            const txt = await res.text();
            if (!txt) throw new Error('Empty response from server');
            const result = JSON.parse(txt);
            if (!result.success) throw new Error(result.error || 'Failed to create payment');
            if (result.data && result.data.wallet === 'joey' && result.data.txjson) {
                throw new Error('Wallet still connecting - please try again in a moment.');
            }
            btn.style.display = 'none';
            xumm.style.display = 'block';
            const qrImg = document.getElementById('cd-payfee-qr');
            const deeplink = document.getElementById('cd-payfee-deeplink');
            if (qrImg && result.data && result.data.qr_png) qrImg.src = result.data.qr_png;
            if (deeplink && result.data && result.data.deeplink) deeplink.href = result.data.deeplink;
            const isMobile = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent||'');
            if (isMobile && deeplink) deeplink.setAttribute('target', '_self');
            status.innerHTML = '<span style="color:#94a3b8;">Waiting for payment...</span>';
            cdStartFeePoll(result.data.uuid, l.id);
        } catch (error) {
            console.error('Fee payment error:', error);
            showToast('Failed to create payment: ' + error.message, 'error');
            btn.disabled = false; btn.textContent = 'Pay Fee';
        }
    }

    function cdStartFeePoll(uuid, listingId) {
        cdStopFeePoll();
        const status = document.getElementById('cd-payfee-status');
        cdPayFeePoll = setInterval(async () => {
            try {
                const r = await fetch(`${CONFIG.endpoints.mintOnDemand}?action=poll_payment&uuid=${uuid}`);
                const data = await r.json();
                if (data.data && data.data.rejected) { cdStopFeePoll(); if (status) status.innerHTML = '<span style="color:#ef4444;">Payment rejected</span>'; return; }
                if (data.data && data.data.expired) { cdStopFeePoll(); if (status) status.innerHTML = '<span style="color:#ef4444;">Payment expired. Please try again.</span>'; return; }
                if (data.data && data.data.signed && !data.data.dispatched_result) { if (status) status.innerHTML = '<span style="color:#94a3b8;">Signed! Waiting for blockchain confirmation...</span>'; return; }
                if (data.data && data.data.signed && data.data.dispatched_result === 'tesSUCCESS' && data.data.tx_hash) {
                    if (cdPayFeeBusy) return; cdPayFeeBusy = true;
                    cdStopFeePoll();
                    if (status) status.innerHTML = '<span style="color:#10b981;">Payment confirmed! Publishing...</span>';
                    await cdPublishListing(listingId, data.data.tx_hash);
                    return;
                }
                if (data.data && data.data.signed && data.data.dispatched_result && data.data.dispatched_result !== 'tesSUCCESS') {
                    cdStopFeePoll();
                    if (status) status.innerHTML = '<span style="color:#ef4444;">Payment failed (' + data.data.dispatched_result + ')</span>';
                    return;
                }
            } catch (e) { console.error('Fee poll error:', e); }
        }, 3000);
        setTimeout(cdStopFeePoll, 30 * 60 * 1000);
    }

    function cdStopFeePoll() { if (cdPayFeePoll) { clearInterval(cdPayFeePoll); cdPayFeePoll = null; } }

    async function cdPublishListing(listingId, txHash) {
        try {
            const fd = new FormData();
            fd.append('action', 'publish');
            fd.append('nonce', CONFIG.nonce);
            fd.append('listing_id', listingId);
            fd.append('artist_account', CONFIG.account);
            fd.append('tx_hash', txHash);
            const r = await fetch(CONFIG.endpoints.listingsHandler, { method: 'POST', body: fd });
            const data = await r.json();
            if (!data.success) throw new Error(data.error || 'Failed to publish listing');
            showToast('Your listing is now live!', 'success');
            cdClosePayFeeModal();
            setTimeout(() => { window.location.href = '/creator-dashboard/?listing_published=1'; }, 1500);
        } catch (error) {
            console.error('Publish error:', error);
            showToast('Failed to publish: ' + error.message, 'error');
            cdPayFeeBusy = false;
        }
    }

    /**
     * v43b: Creator self-mint — mint NFTs + create sell offers.
     * After minting, NFTs appear in Pending Claims on the Trading Hub
     * where the artist claims them via Xaman (same flow as a buyer).
     */
    window.cdCreatorMint = async function(listingId, nftName) {
        // Remove any existing modal
        document.getElementById('cd-creator-mint-modal')?.remove();

        // Create styled modal
        const modal = document.createElement('div');
        modal.id = 'cd-creator-mint-modal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.8);z-index:10000;display:flex;align-items:center;justify-content:center;';
        modal.innerHTML = `
            <div style="background:var(--cd-bg-elevated);border:1px solid rgba(212,175,55,0.3);border-radius:16px;padding:30px;max-width:420px;width:90%;color:#e0e0e0;position:relative;">
                <button onclick="document.getElementById('cd-creator-mint-modal').remove()" 
                    style="position:absolute;top:12px;right:16px;background:none;border:none;color:#888;font-size:1.3rem;cursor:pointer;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-x-circle"></use></svg> </button>
                
                <div id="cm-step-qty">
                    <h3 style="color:#d4af37;margin:0 0 6px;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-gift"></use></svg> Mint to Myself</h3>
                    <p style="color:#aaa;font-size:0.85rem;margin:0 0 20px;">${nftName}</p>
                    
                    <label style="display:block;margin-bottom:6px;font-weight:600;">How many editions?</label>
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
                        <button onclick="document.getElementById('cm-qty-input').stepDown()" 
                            style="width:36px;height:36px;background:#333;border:1px solid #555;border-radius:8px;color:#fff;font-size:1.2rem;cursor:pointer;">−</button>
                        <input type="number" id="cm-qty-input" value="1" min="1" max="10" 
                            style="width:60px;text-align:center;background:#222;border:1px solid #555;border-radius:8px;padding:8px;color:#fff;font-size:1.1rem;">
                        <button onclick="document.getElementById('cm-qty-input').stepUp()" 
                            style="width:36px;height:36px;background:#333;border:1px solid #555;border-radius:8px;color:#fff;font-size:1.2rem;cursor:pointer;">+</button>
                    </div>
                    
                    <div style="background:rgba(212,175,55,0.1);border:1px solid rgba(212,175,55,0.2);border-radius:8px;padding:12px;margin-bottom:20px;font-size:0.82rem;color:#ccc;">
                        <strong style="color:#d4af37;">How it works:</strong><br>
                        NFTs will be minted and prepared for you. Once complete, visit your 
                        <strong>Trading Hub → Pending Claims</strong> to claim them to your wallet via Xaman.
                    </div>
                    
                    <button id="cm-confirm-btn" onclick="window._cdDoCreatorMint(${listingId})" 
                        style="width:100%;padding:12px;background:#d4af37;color:#000;border:none;border-radius:8px;font-weight:700;font-size:1rem;cursor:pointer;">
                        Mint Now
                    </button>
                </div>
                
                <div id="cm-step-progress" style="display:none;text-align:center;">
                    <div class="loading-spinner" style="margin:0 auto 15px;"></div>
                    <div id="cm-progress-text" style="color:#d4af37;font-weight:600;">Minting NFTs...</div>
                    <div style="color:#888;font-size:0.8rem;margin-top:8px;">Please do not close this page.</div>
                </div>
                
                <div id="cm-step-done" style="display:none;text-align:center;">
                    <div id="cm-done-icon" style="font-size:2rem;margin-bottom:10px;"></div>
                    <div id="cm-done-title" style="font-weight:600;font-size:1.1rem;margin-bottom:8px;"></div>
                    <div id="cm-done-body" style="color:#aaa;font-size:0.85rem;margin-bottom:20px;"></div>
                    <button onclick="document.getElementById('cd-creator-mint-modal').remove();cdRefreshCurrentView();" 
                        style="padding:10px 24px;background:#d4af37;color:#000;border:none;border-radius:8px;font-weight:600;cursor:pointer;">OK</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        modal.addEventListener('click', (e) => { if (e.target === modal) modal.remove(); });
    };

    // ════════════════════════════════════════════════════════════════════════
    // v893 (Defect AO): ONE REQUEST PER EDITION, not one request for all of them.
    //
    // THE BUG. This used to POST creator_mint once with the full quantity and await a
    // single response. Server-side each edition costs ~15.5s (IPFS pin + NFTokenMint +
    // NFTokenCreateOffer, each awaiting ledger validation), so qty=10 is ~155 SECONDS in
    // one HTTP request. The upstream proxy gives up around 60s and returns an HTML error
    // page; `await response.json()` then chokes on '<html>...' and the catch below painted
    // "Mint Failed".
    //
    // THE MINT HAD NOT FAILED. Measured on a live self-mint:
    // started 15:59:31, "creator_mint: DONE minted=10/10 errors=0" at 16:02:08 — the
    // server finished every edition 97 seconds AFTER the browser had already been shown a
    // failure. Nothing was lost; the creator was simply told the opposite of the truth,
    // and had no way to know their NFTs were waiting in Pending Claims.
    //
    // WHY CHUNKING RATHER THAN A LONGER TIMEOUT: the proxy limit is not ours to raise, and
    // set_time_limit is irrelevant (max_execution_time is already 0 — the server was never
    // the constraint). Ten ~16s requests each finish far inside any gateway limit.
    //
    // THREE THINGS THIS ALSO FIXES:
    //   1. REAL PROGRESS. "Minting 4 of 10..." instead of a spinner that lies at 60s.
    //   2. HONEST PARTIALS. A failure at edition 7 reports "6 of 10 minted" WITH the
    //      reason, and the 6 that succeeded are real. Previously any mid-run problem
    //      surfaced as one opaque "Mint Failed".
    //   3. LOCK FAIRNESS. creator_mint holds the global imc_mint lock for its whole run,
    //      so a qty=10 self-mint used to block EVERY buyer mint on the platform for ~2.5
    //      minutes. Per-edition calls release the lock between editions.
    //
    // NO SERVER CHANGE. creator_mint is untouched — it already accepts any quantity, and
    // re-validates supply, ownership and session on every call, so N calls of 1 is
    // strictly MORE checking than one call of N.
    // ════════════════════════════════════════════════════════════════════════
    window._cdDoCreatorMint = async function(listingId) {
        const qtyInput = document.getElementById('cm-qty-input');
        const maxQty   = parseInt(qtyInput?.max || '10');
        const qty      = parseInt(qtyInput?.value || '1');
        // Honour exactly what the creator chose; maxQty is only the input's ceiling.
        if (!qty || qty < 1 || qty > maxQty) return;

        document.getElementById('cm-step-qty').style.display = 'none';
        document.getElementById('cm-step-progress').style.display = 'block';

        const endpoint = CONFIG.endpoints?.mintOnDemand
            || '/wp-content/themes/astra/xrpl-nft-marketplace/backend/mint-on-demand-handler.php';
        const progressEl = document.getElementById('cm-progress-text');
        // The modal is closable at any time (× button, or clicking the backdrop). If it is
        // gone we STOP issuing calls — otherwise minting would continue invisibly and every
        // DOM write below would throw.
        const modalOpen = () => !!document.getElementById('cd-creator-mint-modal');

        let minted = 0;
        let failure = null;

        for (let n = 1; n <= qty; n++) {
            if (!modalOpen()) break;
            if (progressEl) {
                progressEl.textContent = qty === 1
                    ? 'Minting your NFT...'
                    : `Minting ${n} of ${qty}...`;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'creator_mint');
                formData.append('listing_id', listingId);
                formData.append('artist_account', CONFIG.account || '');
                formData.append('quantity', 1);          // ONE per request — see note above
                formData.append('nonce', CONFIG.nonce || '');

                const response = await fetch(endpoint, { method: 'POST', body: formData });

                // A proxy timeout or PHP fatal returns HTML, not JSON. Read as text first so
                // the creator sees a real message instead of "Unexpected token '<'".
                const bodyText = await response.text();
                let data;
                try {
                    data = JSON.parse(bodyText);
                } catch (parseErr) {
                    throw new Error(response.status >= 500
                        ? `Server error (HTTP ${response.status}) while minting edition ${n}. Any editions already minted are safe — check Pending Claims.`
                        : `Unexpected response while minting edition ${n}. Any editions already minted are safe — check Pending Claims.`);
                }

                if (!data.success) throw new Error(data.error || 'Mint failed');
                const got = parseInt(data.data?.minted_count ?? data.minted_count ?? 0);
                if (got < 1) {
                    const srvErr = (data.data?.errors && data.data.errors[0]) || 'the server reported no editions minted';
                    throw new Error(srvErr);
                }
                minted += got;

            } catch (err) {
                // Stop on the first failure — supply may be exhausted, a tier may be out, or
                // the session may have lapsed. Continuing would repeat the same error N times.
                failure = err.message || String(err);
                break;
            }
        }

        if (!modalOpen()) return;   // creator closed it; nothing left to render

        document.getElementById('cm-step-progress').style.display = 'none';
        document.getElementById('cm-step-done').style.display = 'block';
        const icon  = document.getElementById('cm-done-icon');
        const title = document.getElementById('cm-done-title');
        const body  = document.getElementById('cm-done-body');
        const claimLink = 'Head to your <a href="/trading-hub-dashboard/" style="color:#d4af37;text-decoration:underline;">Trading Hub → Pending Claims</a> to claim them to your wallet.';

        if (minted === qty) {
            icon.textContent = '';
            title.style.color = '#d4af37';
            title.textContent = `${minted} NFT(s) minted successfully!`;
            body.innerHTML = claimLink;
        } else if (minted > 0) {
            // PARTIAL — the successful editions are real and claimable. Say so plainly
            // rather than reporting the whole run as a failure.
            icon.textContent = '';
            title.style.color = '#d4af37';
            title.textContent = `${minted} of ${qty} NFT(s) minted`;
            body.innerHTML = `The first ${minted} minted successfully and are ready to claim.<br><br>`
                + `<span style="color:#e88;">Stopped at edition ${minted + 1}: ${failure || 'unknown error'}</span><br><br>`
                + claimLink;
        } else {
            icon.textContent = '';
            title.style.color = '#e88';
            title.textContent = 'Mint Failed';
            body.textContent = failure || 'No editions were minted.';
        }
    };
    
    // ════════════════════════════════════════════════════════════════════════
    // Stats Calculation
    // ════════════════════════════════════════════════════════════════════════
    
    function updateStats() {
        // Group by collection
        const collections = {};
        allListings.forEach(l => {
            const key = l.collection_name || 'Uncategorized';
            if (!collections[key]) collections[key] = [];
            collections[key].push(l);
        });
        
        const totalCollections = Object.keys(collections).length;
        const totalListings = allListings.length;
        let totalMinted = 0;
        let totalEditions = 0;
        let totalRevenue = 0;
        
        // D-A: an OPEN EDITION stores total_editions = 0 meaning UNLIMITED, not
        // "zero supply". Summing it put every open edition's mints in the
        // numerator and nothing in the denominator - which is how a minted count
        // came to be shown as larger than the total. The listing card at ~L3618 already
        // renders `minted / (total || 'infinity')` correctly; this is the same
        // rule applied to the two aggregate renderers that missed it.
        let openEditions = 0;
        allListings.forEach(l => {
            const minted = parseInt(l.minted_count) || 0;
            const total = parseInt(l.total_editions) || 0;
            
            totalMinted += minted;
            if (total > 0) { totalEditions += total; } else { openEditions++; }
            totalRevenue += (parseFloat(l.revenue_xrp) || 0); // v488: actual XRP received, not minted x list price
        });
        
        document.getElementById('stat-collections').textContent = totalCollections;
        document.getElementById('stat-listings').textContent = totalListings;
        document.getElementById('stat-minted').textContent = totalMinted;
        // D-A: say what is actually true. Capped supply is a real denominator;
        // open editions have none, so they are counted, not summed.
        document.getElementById('stat-minted-sub').textContent = openEditions > 0
            ? (totalEditions > 0
                ? `of ${totalEditions} capped + ${openEditions} open`
                : `across ${openEditions} open edition${openEditions === 1 ? '' : 's'}`)
            : `of ${totalEditions} total`;
        // D-B2: stat-revenue and stat-minted-period are owned by
        // cdRenderHeaderActivity(), which sources them from wp_imc_purchases for the
        // selected window. This line remains only as the FIRST paint, before that
        // call returns - it can offer nothing but the XRP-only subtotal, which is
        // exactly what D-A relabelled. The inventory figures above stay synchronous.
        if (!cdHdrData) {
            document.getElementById('stat-revenue').textContent = formatXRP(totalRevenue);
        }
    }
    
    // ════════════════════════════════════════════════════════════════════════
    // Render Functions
    // ════════════════════════════════════════════════════════════════════════
    
    function renderCollections() {
        const grid = document.getElementById('cd-collections-grid');
        if (!grid) return;

        // ── R-11 · FLAT LISTINGS ─────────────────────────────────────────────
        // Was: group allListings by collection_name, render a card per collection.
        // Now: render a row per LISTING, with the collection as a column.
        //
        // The status filter is simpler for it. It used to ask "does ANY listing in
        // this collection have this status?", which meant filtering to Paused could
        // show a collection whose other three listings were active. It now filters
        // the listings themselves, which is what the labels always claimed.
        const now = new Date();
        const isFuture = l => l.launch_type === 'scheduled' && l.launch_at
            && new Date(String(l.launch_at).includes('Z') ? l.launch_at : l.launch_at + 'Z') > now;

        let rows = (allListings || []).slice();
        if (currentFilter !== 'all') {
            rows = rows.filter(l => {
                if (currentFilter === 'scheduled') return isFuture(l);
                if (currentFilter === 'sold_out') {
                    const t = parseInt(l.total_editions) || 0;
                    return t > 0 && (parseInt(l.minted_count) || 0) >= t;
                }
                return l.status === currentFilter;
            });
        }

        // Newest first, so the row you just created is at the top.
        rows.sort((a, b) => String(b.created_at || '').localeCompare(String(a.created_at || '')));

        if (!rows.length) {
            grid.innerHTML = `<tr><td colspan="7"><div class="cd-empty-state">
                    <h3>${currentFilter === 'all' ? 'No listings yet' : `Nothing ${escapeHtml(currentFilter.replace('_', ' '))}`}</h3>
                    <p>${currentFilter === 'all'
                        ? 'Create your first NFT to get started.'
                        : 'Try a different filter, or clear it to see everything.'}</p>
                    ${currentFilter === 'all'
                        ? `<a href="${escapeHtml(window.location.origin)}/mint/" class="cd-btn cd-btn-primary">Create New NFT</a>`
                        : ''}
                </div></td></tr>`;
            return;
        }

        grid.innerHTML = rows.map(l => {
            const minted = parseInt(l.minted_count) || 0;
            const total  = parseInt(l.total_editions) || 0;
            const fut    = isFuture(l);
            const coll   = l.collection_name || 'Uncategorized';
            const icon   = l.nft_type === 'album' ? 'ic-disc' : l.nft_type === 'art' ? 'ic-palette'
                         : l.nft_type === 'film' ? 'ic-video' : l.nft_type === 'musicvideo' ? 'ic-clapper'
                         : (l.nft_type === 'ebook' || l.nft_type === 'audiobook') ? 'ic-book' : 'ic-music';   // F3: books
            const price  = Number(l.price_xrp) > 0 ? `${Number(l.price_xrp)} XRP` : 'Free';
            return `<tr class="cd-listing-row" data-id="${l.id}" onclick="cdOpenListingDetail(${l.id})"
                    tabindex="0" role="button"
                    onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();cdOpenListingDetail(${l.id});}">
                    <td class="cd-lrow-cover"><img src="${ipfsToHttp(l.cover_ipfs)}" alt="" loading="lazy"></td>
                    <td class="cd-lrow-name">
                        <b><svg class="imc-ic" aria-hidden="true"><use href="#${icon}"></use></svg> ${escapeHtml(l.nft_name || 'Untitled')}</b>
                        ${fut ? `<span class="cd-lrow-sub">${escapeHtml(String(l.launch_at || ''))}</span>` : ''}
                    </td>
                    <td class="cd-lrow-coll">
                        <?php /* R-11: the collection page is still reachable, just no
                           longer compulsory. stopPropagation because the row itself is
                           a click target - the B-e trap, handled deliberately. */ ?>
                        <button type="button" class="cd-lrow-link"
                            onclick="event.stopPropagation(); cdShowCollectionDetail(${escapeJsAttr(coll)});">${escapeHtml(coll)}</button>
                    </td>
                    <td><span class="cd-status-badge cd-status-${fut ? 'scheduled' : l.status}">${fut ? '<svg class="imc-ic" aria-hidden="true"><use href="#ic-calendar"></use></svg> Scheduled' : escapeHtml(String(l.status || '').replace('_', ' '))}</span></td>
                    <td class="cd-lrow-num">${escapeHtml(price)}</td>
                    <td class="cd-lrow-num">${minted} / ${total || '&infin;'}</td>
                    <td class="cd-lrow-num">
                        <button type="button" class="cd-btn cd-btn-secondary cd-btn-sm"
                            onclick="event.stopPropagation(); cdOpenListingDetail(${l.id});">Manage</button>
                    </td>
                </tr>`;
        }).join('');
    }
    
    async function renderCollectionDetail(collectionName) {
        currentCollection = collectionName;
        // B-d: one source of truth. The crumb renderer reads cdNavState, never
        // the DOM, so a transition that forgets to update state is visible
        // immediately rather than producing a stale trail.
        window.cdNavState.collection = collectionName;
        window.cdNavState.listingId = null;
        window.cdNavState.listingLabel = null;
        cdSetCrumbs();
        if (!cdRouting) cdSyncUrl(true);             // D-C: depth PUSHES
        
        // Hide collections view, show detail view
        document.getElementById('cd-collections-view').classList.remove('active');
        document.getElementById('cd-listing-detail').classList.remove('active'); // v671: 3-view safety
        document.getElementById('cd-collection-detail').classList.add('active');
        
        // Get listings for this collection
        const listings = allListings.filter(l => (l.collection_name || 'Uncategorized') === collectionName);

        // ⚠ R-9c FIX: the skip block used to sit HERE, and that was wrong.
        // `const listings = allListings.filter(...)` appears in BOTH this function
        // and its caller, so the anchor matched the wrong one.
        // renderCollectionDetail has no `fromCard` parameter, so reading it threw
        // ReferenceError - AFTER the crumbs rendered and the view switched, but
        // BEFORE the header, stats, revenue card, ledger strip and table rows. The
        // page went blank below the breadcrumb, with nothing visibly wrong.
        // The skip now lives in cdShowCollectionDetail, which actually receives
        // fromCard, and the scroll is recorded there too.
        
        if (listings.length === 0) {
            cdShowCollectionsView();
            return;
        }
        
        // Calculate collection stats
        let totalMinted = 0;
        let totalEditions = 0;
        let totalRevenue = 0;
        
        let openEditions = 0;                       // D-A: 0 = unlimited, not zero
        listings.forEach(l => {
            totalMinted += parseInt(l.minted_count) || 0;
            { const _te = parseInt(l.total_editions) || 0;
              if (_te > 0) { totalEditions += _te; } else { openEditions++; } }
            totalRevenue += (parseFloat(l.revenue_xrp) || 0); // v488: actual XRP received
        });
        
        // Render header
        const firstListing = listings[0];
        // R-4: per-collection facts (Holders + the revenue breakdown). Fetched by
        // B-e, all-time, and ASYNCHRONOUS - so everything reading it must tolerate
        // null on first paint and repaint when it lands.
        const detFacts = (typeof cdCollFacts !== 'undefined' && cdCollFacts) ? cdCollFacts[collectionName] : null;
        document.getElementById('cd-detail-header').innerHTML = `
            <div class="cd-detail-cover">
                <img src="${ipfsToHttp(firstListing.cover_ipfs)}" alt="${escapeHtml(collectionName)}">
            </div>
            <div class="cd-detail-info">
                <div class="cd-detail-title">${escapeHtml(collectionName)}</div>
                
                <!-- v69: Styled stat boxes like main dashboard -->
                <div class="cd-detail-stats">
                    <div class="cd-stat-card cd-stat-mini">
                        <div class="cd-stat-label">Listings</div>
                        <div class="cd-stat-value">${listings.length}</div>
                    </div>
                    <div class="cd-stat-card cd-stat-mini">
                        <div class="cd-stat-label">Minted</div>
                        <div class="cd-stat-value">${totalMinted}<span class="cd-stat-sub">/${openEditions ? '&infin;' : totalEditions}</span></div>
                    </div>
                    <div class="cd-stat-card cd-stat-mini">
                        <div class="cd-stat-label">Holders</div>
                        <div class="cd-stat-value">${detFacts ? detFacts.holders : '\u2014'}</div>
                    </div>
                    <div class="cd-stat-card cd-stat-mini">
                        <div class="cd-stat-label">Revenue</div>
                        <div class="cd-stat-value" id="cd-detail-revenue-value">${formatXRP(totalRevenue)}<span class="cd-stat-sub">XRP</span></div>
                    </div>
                </div>
                
                <?php /* R-4: the per-currency breakdown that used to hide behind a
                   chevron on the collection CARD. Same data, same rule - each currency
                   in its own unit, never summed - shown where there is room for it.
                   cdCurLine() decides how each row reads; R-1b put that in one place
                   so relocating the display needed no change to the logic. */ ?>
                ${detFacts && (detFacts.byCurrency || []).length ? `
                <div class="cd-detail-revenue cd-surface">
                    <h5>Revenue by currency</h5>
                    <ul class="cd-det-cur">${
                        detFacts.byCurrency.map(c => { const r = cdCurLine(c);
                            return `<li><span>${escapeHtml(r.label)}</span><b>${escapeHtml(r.value)}</b></li>`;
                        }).join('')
                    }</ul>
                    <p class="cd-det-note">${detFacts.holders} holder${detFacts.holders === 1 ? '' : 's'} \u00b7 ${detFacts.mints} mint${detFacts.mints === 1 ? '' : 's'} all time.
                    Currencies are shown in their own units and are never added together.</p>
                </div>` : ''}

                <?php /* R-9d: filled by cdClLedger() once the store responds. */ ?>
                <div class="cd-cl-ledger" id="cd-cl-ledger"></div>

                <div class="cd-detail-actions">
                    <a href="/collections/?taxon=${firstListing.collection_taxon}&issuer=${firstListing.artist_account}" class="cd-btn cd-btn-secondary" target="_blank">
                        <svg class="imc-ic" aria-hidden="true"><use href="#ic-eye"></use></svg> View Public Page
                    </a>
                    <a href="/collection-preview/?issuer=${firstListing.artist_account}&taxon=${firstListing.collection_taxon}" class="cd-btn cd-btn-secondary" target="_blank">
                        <svg class="imc-ic" aria-hidden="true"><use href="#ic-eye"></use></svg> Preview Collection
                    </a>
                    <button type="button" class="cd-btn cd-btn-secondary" onclick="cdOpenEditCollection()">
                        <svg class="imc-ic" aria-hidden="true"><use href="#ic-pencil"></use></svg> Edit Collection
                    </button>
                </div>
            </div>
        `;
        
        // Render listings table
        // R-9d: fire-and-forget once the page has painted - the ledger call must never
        // delay the listings table.
        cdClLedger(collectionName, listings);

        const tbody = document.getElementById('cd-listings-grid');
        tbody.innerHTML = listings.map(l => {
            const minted = parseInt(l.minted_count) || 0;
            const total = parseInt(l.total_editions) || 0;
            const progress = total > 0 ? (minted / total * 100) : 0;
            
            // v85: Calculate revenue and display price based on pricing mode
            const pricingMode = l.pricing_mode || 'static';
            const priceUsd = parseFloat(l.price_usd) || 0;
            const priceXrp = parseFloat(l.price_xrp) || 0;
            
            // v672: token-agnostic price, mirroring imc_primary_display_price() on the public
            // site -- a listing priced only in XRPL tokens showed "0 XRP" here.
            const cdFirstTok = (() => {
                let cs = l.accepted_currencies;
                try { if (typeof cs === 'string') cs = JSON.parse(cs); } catch (e) { cs = null; }
                if (!Array.isArray(cs)) return null;
                for (const c of cs) {
                    if (c.enabled === false) continue;
                    const code = String(c.currency || '').toUpperCase();
                    if (!code || code === 'XRP') continue;
                    if (parseFloat(c.price || 0) > 0) return { currency: c.currency, price: parseFloat(c.price) };
                }
                return null;
            })();
            const cdFmtTok = v => (v === Math.floor(v) ? v.toLocaleString('en-US') : String(v));

            let priceDisplay, revenueDisplay;
            if (pricingMode === 'free') {
                priceDisplay = `<strong>Free</strong>`;
                revenueDisplay = `<strong>${formatXRP(parseFloat(l.revenue_xrp) || 0)}</strong> XRP`;
            } else if (pricingMode !== 'dynamic' && priceXrp <= 0 && cdFirstTok) {
                priceDisplay = `<strong>${cdFmtTok(cdFirstTok.price)}</strong> ${escapeHtml(cdFirstTok.currency)}`;
                revenueDisplay = `<strong>${formatXRP(parseFloat(l.revenue_xrp) || 0)}</strong> XRP`;
            } else if (pricingMode === 'dynamic' && priceUsd > 0) {
                // Dynamic pricing - show USD
                priceDisplay = `<strong>$${priceUsd.toFixed(2)}</strong> <span style="color:var(--cd-text-muted);font-size:0.85em;">USD</span>`;
                revenueDisplay = `<strong>${formatXRP(parseFloat(l.revenue_xrp) || 0)}</strong> XRP`;
            } else {
                // Static pricing - show XRP
                priceDisplay = `<strong>${formatXRP(priceXrp)}</strong> XRP`;
                revenueDisplay = `<strong>${formatXRP(parseFloat(l.revenue_xrp) || 0)}</strong> XRP`;
            }
            
            // v233: Detect future-scheduled listings for special badge + actions
            const isScheduledFuture = l.launch_type === 'scheduled' && l.launch_at
                                   && new Date(l.launch_at.includes('Z') ? l.launch_at : l.launch_at + 'Z') > new Date();
            let launchLocalStr = '';
            if (isScheduledFuture) {
                const launchDate = new Date(l.launch_at.includes('Z') ? l.launch_at : l.launch_at + 'Z');
                launchLocalStr = '<svg class="imc-ic" aria-hidden="true"><use href="#ic-rocket"></use></svg> ' + launchDate.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
                               + ' at ' + launchDate.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
            }
            
            return `
<tr class="cd-listing-row" data-id="${l.id}" onclick="cdOpenListingDetail(${l.id})"
                    tabindex="0" role="button"
                    onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();cdOpenListingDetail(${l.id});}">
                    <td class="cd-lrow-cover"><img src="${ipfsToHttp(l.cover_ipfs)}" alt="" loading="lazy"></td>
                    <td class="cd-lrow-name">
                        <b>${l.nft_type === 'album' ? '<svg class="imc-ic" aria-hidden="true"><use href="#ic-disc"></use></svg> ' : l.nft_type === 'art' ? '<svg class="imc-ic" aria-hidden="true"><use href="#ic-palette"></use></svg> ' : l.nft_type === 'film' ? '<svg class="imc-ic" aria-hidden="true"><use href="#ic-video"></use></svg> ' : l.nft_type === 'musicvideo' ? '<svg class="imc-ic" aria-hidden="true"><use href="#ic-clapper"></use></svg> ' : (l.nft_type === 'ebook' || l.nft_type === 'audiobook') ? '<svg class="imc-ic" aria-hidden="true"><use href="#ic-book"></use></svg> ' : '<svg class="imc-ic" aria-hidden="true"><use href="#ic-music"></use></svg> '}${escapeHtml(l.nft_name)}</b>
                        ${isScheduledFuture ? `<span class="cd-lrow-sub">${launchLocalStr}</span>` : ''}
                    </td>
                    <td><span class="cd-status-badge cd-status-${isScheduledFuture ? 'scheduled' : l.status}">${isScheduledFuture ? '<svg class="imc-ic" aria-hidden="true"><use href="#ic-calendar"></use></svg> Scheduled' : l.status.replace('_', ' ')}</span></td>
                    <td class="cd-lrow-num">${priceDisplay}</td>
                    <td class="cd-lrow-num">${minted} / ${total || '&infin;'}</td>
                    <td class="cd-lrow-num cd-lrow-muted">${revenueDisplay}</td>
                </tr>
            `;
        }).join('');
        
        // Fetch and render purchase history
        const listingIds = listings.map(l => l.id);
        const purchases = await fetchPurchaseHistory(listingIds);
        
        // v86: Calculate multi-currency revenue totals from actual purchases
        const revenueByCurrency = {};
        purchases.forEach(p => {
            const currency = p.payment_currency || 'XRP';
            const amount = parseFloat(p.payment_amount) || parseFloat(p.total_price_xrp) || parseFloat(p.price_xrp) || 0;
            if (!revenueByCurrency[currency]) revenueByCurrency[currency] = 0;
            revenueByCurrency[currency] += amount;
        });
        
        // Update revenue display with multi-currency totals
        // R-4: was document.querySelector('.cd-detail-stats .cd-stat-card:last-child
        // .cd-stat-value') - unscoped AND positional. It matched the first
        // .cd-detail-stats in the document (there are two: collection detail and
        // listing detail) and assumed Revenue was the last card. Adding the Holders
        // box only kept working because Holders went in BEFORE Revenue. Targeting
        // by id removes both assumptions.
        const revenueEl = document.getElementById('cd-detail-revenue-value');
        if (revenueEl && Object.keys(revenueByCurrency).length > 0) {
            const revenueHtml = Object.entries(revenueByCurrency)
                .map(([curr, amt]) => `${formatAmount(amt, curr)}<span class="cd-stat-sub">${curr}</span>`)
                .join(' + ');
            revenueEl.innerHTML = revenueHtml;
        }
        
        const purchaseTbody = document.getElementById('cd-purchase-tbody');
        if (purchases.length === 0) {
            purchaseTbody.innerHTML = `
                <tr>
                    <td colspan="6" style="text-align: center; color: var(--cd-text-muted); padding: 2rem;">
                        No purchases yet
                    </td>
                </tr>
            `;
        } else {
            // v86: Show actual payment currency and amount
            purchaseTbody.innerHTML = purchases.slice(0, 20).map(p => {
                const currency = p.payment_currency || 'XRP';
                const amount = parseFloat(p.payment_amount) || parseFloat(p.total_price_xrp) || parseFloat(p.price_xrp) || 0;
                return `
                <tr>
                    <td title="${escapeHtml(p.buyer_account)}">${shortWallet(p.buyer_account)}</td>
                    <td>#${p.edition_number || '?'}</td>
                    <td>${escapeHtml(p.tier_name || '—')}</td>
                    <td>${formatAmount(amount, currency)} ${currency}</td>
                    <td>${formatDate(p.created_at)}</td>
                    <td>
                        <span class="cd-delivery-status ${p.delivered == 1 ? 'delivered' : 'pending'}">
                            ${p.delivered == 1 ? '<svg class="imc-ic" aria-hidden="true"><use href="#ic-check-circle"></use></svg> Delivered' : '⏳ Pending'}
                        </span>
                    </td>
                </tr>
            `}).join('');
        }
        
        // v671: allowlists are per-listing and now load in cdOpenListingDetail()
    }
    
    // ════════════════════════════════════════════════════════════════════════
    // UI Actions
    // ════════════════════════════════════════════════════════════════════════
    
    // v671: the listing currently being managed. Allowlists are per-listing, so every
    // allowlist read/write is scoped to this id rather than silently to the collection's
    // first listing (the previous behaviour, which hid allowlists on every other listing).
    let currentListingId = null;

    // ── R-5a · LISTINGS MANAGER ────────────────────────────────
    // FIFTH tab family (cd-lm-*), kept disjoint from cd-tab / cd-nav-tab /
    // cd-an-win, and container-scoped - D-B2 shipped a bug from an unscoped
    // listener reusing a shared class.
    (function () {
        const nav = document.getElementById('cd-lm-nav');
        if (!nav) return;
        nav.addEventListener('click', function (ev) {
            const btn = ev.target.closest('.cd-lm-tab');
            if (!btn || !nav.contains(btn)) return;
            const name = btn.dataset.lm;
            nav.querySelectorAll('.cd-lm-tab').forEach(b => b.classList.toggle('is-active', b === btn));
            document.querySelectorAll('#cd-listing-detail .cd-lm-panel').forEach(p => {
                p.classList.toggle('is-active', p.dataset.lmPanel === name);
            });
        });
    })();

    // R-5c: the Schedule panel is always on screen, so it has to decide its own
    // mode. The two old callers were really expressing the listing's STATUS:
    //   scheduled for the future -> retime it          (cdEditSchedule,    flag 0)
    //   paused                   -> bring it back at a time (cdScheduleRelease, flag 1)
    //   anything else            -> there is nothing to schedule
    // Both are called with their ORIGINAL signatures, so the date handling, the
    // one-hour minimum and the UTC conversion are untouched.
    function cdLmOpenSchedule(l) {
        const note = document.getElementById('cd-lm-sched-note');
        const form = document.getElementById('schedule-datetime-input');
        const acts = document.getElementById('schedule-save-btn');
        const isFuture = !!(l.launch_at && new Date(String(l.launch_at).replace(' ', 'T') + 'Z') > new Date());
        const usable = isFuture || l.status === 'paused';

        [form, acts].forEach(el => { if (el) el.closest('div').style.display = usable ? '' : 'none'; });
        const hid = document.getElementById('schedule-preview-text');
        if (hid) hid.style.display = usable ? '' : 'none';

        if (!usable) {
            if (note) note.innerHTML = '<p class="cd-allowlists-description">This listing is not scheduled. Pause it first if you want to bring it back at a set time, or use Go live now on Overview.</p>';
            return;
        }
        if (note) note.innerHTML = '';
        if (isFuture)  { cdEditSchedule(l.id, l.launch_at || ''); }
        else           { cdScheduleRelease(l.id); }
    }

    // R-5b: fills the inline pricing form for a listing. Calls the SAME
    // cdEditPrice() the Actions menu used - its signature and every argument are
    // unchanged, so the populate logic (4 modes, token rows, progressive pricing)
    // is untouched. Only "then open a modal" became "it is already on screen".
    function cdLmOpenPricing(l) {
        if (typeof cdEditPrice !== 'function') return;
        cdEditPrice(
            l.id,
            l.price_xrp,
            JSON.parse(JSON.stringify(l.accepted_currencies || [])),
            l.pricing_mode || 'static',
            l.price_usd || null
        );
    }

    // Rebuilt on every open: the listing's status decides which controls exist.
    function cdLmRender(l) {
        const minted = parseInt(l.minted_count) || 0;
        const total  = parseInt(l.total_editions) || 0;
        const isFuture = !!(l.launch_at && new Date(String(l.launch_at).replace(' ', 'T') + 'Z') > new Date());
        const row = (h, p, a) =>
            `<div class="cd-lm-row"><div class="cd-lm-what"><h5>${h}</h5><p>${p}</p></div><div>${a}</div></div>`;

        const ov = document.getElementById('cd-lm-overview');
        if (ov) {
            const r = [];
            if (l.status === 'draft') r.push(row('Publish this listing',
                'It stays a draft until the listing fee is paid. Nothing is minted and nobody can buy it.',
                `<button type="button" class="cd-btn cd-btn-primary cd-btn-sm" onclick="cdGoToMint(${l.id})">Pay fee &amp; publish</button>`));
            if (isFuture) r.push(row('Go live now',
                'Releases immediately instead of waiting for the scheduled time.',
                `<button type="button" class="cd-btn cd-btn-secondary cd-btn-sm" onclick="cdGoLiveNow(${l.id})">Go live now</button>`));
            if (l.status === 'active' && (total === 0 || minted < total)) r.push(row('Mint to yourself',
                'Mints an edition to your own wallet. It counts towards the supply like any other mint.',
                `<button type="button" class="cd-btn cd-btn-secondary cd-btn-sm" onclick="cdCreatorMint(${l.id})">Mint to myself</button>`));
            // AG: the generator lives on OVERVIEW, not buried in the Allowlists
            // header. It is a tool a creator reaches for deliberately - "build me a
            // list from who holds what" - rather than a variant of the Create button,
            // and Overview is where the listing's deliberate actions already are.
            r.push(row('Allowlist generator',
                'Build a wallet list from who holds your collections, read live from the XRPL. Merge several collections, set a minimum held, then review before saving.',
                `<button type="button" class="cd-btn cd-btn-secondary cd-btn-sm" onclick="cdGenFromOverview()">Open generator</button>`));

            // R-9a: MANAGE VAULT, finally reachable. cdOpenVault() and its modal -
            // upload zone, per-file quote, XUMM payment, QR, polling - have existed
            // and worked this whole time with ZERO callers. Checked back to v972,
            // the earliest bundle held, which predates this programme: nothing has
            // ever opened it. A paid feature that cannot be reached is worse than
            // one that does not exist, because the code implies it works.
            //
            // Item-level scope (it takes a listingId), so per R-5's rule it stays a
            // MODAL and is opened from the manager rather than inlined.
            r.push(row('Unlockable content',
                'Attach files only holders of this listing can open. Each file is paid for at upload.',
                `<button type="button" class="cd-btn cd-btn-secondary cd-btn-sm" onclick="cdOpenVault(${l.id})">Manage vault</button>`));

            // R-8: Preview moved here from the listing card's Actions dropdown. The
            // row that replaces that card has exactly ONE action - open the manager -
            // because a container click plus an inner link is the same fight B-e hit
            // with the card chevron, which needed stopPropagation to survive it.
            r.push(row('Public page',
                'See exactly what a buyer sees before they mint.',
                `<button type="button" class="cd-btn cd-btn-secondary cd-btn-sm" onclick="window.open('/collection-preview/?issuer=${escapeHtml(l.artist_account)}&taxon=${escapeHtml(String(l.collection_taxon))}','_blank')">Preview</button>`));
            if (l.has_tiers) r.push(row('Cover image',
                'Change the artwork shown on the mint page and in listings.',
                `<button type="button" class="cd-btn cd-btn-secondary cd-btn-sm" onclick="cdChangeCover(${l.id})">Change cover</button>`));
            ov.innerHTML = r.length ? r.join('')
                : '<p class="cd-allowlists-description">Nothing to do here for a listing in this state.</p>';
        }

        const dz = document.getElementById('cd-lm-danger');
        if (dz) {
            const r = [];
            if (l.status === 'active' && !isFuture) r.push(row('Pause minting',
                'Buyers can no longer mint. Editions already minted are unaffected, and you can resume at any time.',
                `<button type="button" class="cd-btn cd-btn-secondary cd-btn-sm" onclick="cdPauseListing(${l.id})">Pause</button>`));
            if (l.status === 'paused') {
                // This one mutates with NO confirmation, and it used to sit two items
                // below Preview in the same dropdown. Saying what it does, next to
                // the control, is the whole reason this section exists.
                r.push(row('Resume minting',
                    'Reopens minting immediately \u2014 this takes effect the moment you click it.',
                    `<button type="button" class="cd-btn cd-btn-secondary cd-btn-sm" onclick="cdResumeListing(${l.id})">Resume</button>`));
                // R-5c: moved to the Schedule panel, which now handles the paused
                // case directly. Keeping a second entry point here would mean two
                // controls setting the same hidden flag from different places.
                r.push(row('Schedule a release',
                    'Reopen minting at a chosen date and time instead of right now.',
                    `<button type="button" class="cd-btn cd-btn-secondary cd-btn-sm" onclick="cdOpenListingDetail(${l.id}, 'schedule')">Schedule</button>`));
            }
            if (['active', 'paused', 'draft'].includes(l.status)) r.push(row('Cancel this listing',
                'Permanent. It stops accepting mints and is removed from your dashboard. Editions already minted stay with their owners.',
                `<button type="button" class="cd-btn cd-btn-danger cd-btn-sm" onclick="cdCancelListing(${l.id})">Cancel listing</button>`));
            dz.innerHTML = r.length
                ? `<div class="cd-lm-danger"><h4>Danger zone</h4>${r.join('')}</div>`
                : '<p class="cd-allowlists-description">Nothing here for a listing in this state.</p>';
        }
    }

    // R-5b: optional `section` so a caller can land straight on a panel. The
    // Actions menu's Edit Pricing now uses it instead of opening a modal.
    // ── R-9d · THE COLLECTION PAGE EARNS ITS PLACE ────────────────────────
    // It used to show a hero, four stats already on screen and a listings table.
    // R-9c removed the single-listing case entirely, so what remains is the
    // multi-listing collection - and for those, the ledger view is the thing the
    // page can say that nothing else does.
    //
    // action=holders gives live holders, supply and concentration for the taxon.
    // holders_history gives FLOOR and LISTED COUNT, which the codebase has been
    // recording since 9 Sep and has never drawn anywhere.
    //
    // ⚠ A NULL floor means nothing was listed that day - not a floor of zero. The
    // indexer says so in its own comment, and the latest non-null point is used
    // rather than the last point, so an unlisted day does not read as a crash.
    const cdClCache = {};

    async function cdClLedger(collectionName, listings) {
        const box = document.getElementById('cd-cl-ledger');
        if (!box || !listings || !listings.length) return;
        const first = listings[0];
        const taxon = first.collection_taxon, issuer = first.artist_account;
        if (taxon === null || taxon === undefined || taxon === '' || !issuer) return;

        // Only render when the whole collection is one taxon. Mixed taxons would
        // make this describe a slice while claiming to describe the collection -
        // the CP-T2 lesson, in a different place.
        const taxons = new Set(listings.map(l => String(l.collection_taxon)));
        if (taxons.size !== 1) {
            box.innerHTML = '<p class="cd-cl-note">This collection spans more than one on-ledger collection, so a single ledger view would be misleading. See the public page for each.</p>';
            return;
        }

        const key = issuer + ':' + taxon;
        if (cdClCache[key]) { cdClRender(cdClCache[key]); return; }
        box.innerHTML = '<p class="cd-cl-note">Reading the ledger index\u2026</p>';
        try {
            const [h, hist] = await Promise.all([
                fetch(CD_STORE_API + '?action=holders&issuer=' + encodeURIComponent(issuer)
                    + '&taxon=' + encodeURIComponent(taxon) + '&limit=1&offset=0').then(r => r.json()).catch(() => null),
                fetch(CD_STORE_API + '?action=holders_history&issuer=' + encodeURIComponent(issuer)
                    + '&taxon=' + encodeURIComponent(taxon) + '&days=90').then(r => r.json()).catch(() => null)
            ]);
            const payload = { h: h, hist: hist };
            cdClCache[key] = payload;
            cdClRender(payload);
        } catch (e) {
            box.innerHTML = '<p class="cd-cl-note">The ledger index could not be reached just now. The figures above are unaffected.</p>';
        }
    }

    function cdClRender(p) {
        const box = document.getElementById('cd-cl-ledger');
        if (!box) return;
        const h = p.h, pts = (p.hist && p.hist.success && Array.isArray(p.hist.points)) ? p.hist.points : [];
        if (!h || !h.success) {
            box.innerHTML = '<p class="cd-cl-note">No on-ledger data for this collection yet.</p>';
            return;
        }
        // Latest point that actually HAS a floor - not simply the latest point.
        const withFloor = pts.filter(x => x.floor_xrp !== null && x.floor_xrp !== undefined);
        const lastFloor = withFloor.length ? withFloor[withFloor.length - 1] : null;
        const listed = pts.length ? pts[pts.length - 1].listed_count : null;

        const kpi = (v, l) => `<div class="cd-cl-kpi"><div class="v">${v}</div><div class="l">${l}</div></div>`;
        box.innerHTML = `<div class="cd-cl-kpis">
                ${kpi(h.distinct_holders ?? '\u2014', 'Holders on ledger')}
                ${kpi(h.total_nfts ?? '\u2014', 'NFTs on ledger')}
                ${kpi((h.top10_share_pct ?? '\u2014') + '%', 'Top 10 hold')}
                ${kpi(lastFloor ? lastFloor.floor_xrp + ' XRP' : '\u2014', 'Floor')}
            </div>
            <p class="cd-cl-note">${
                lastFloor
                    ? `Floor as at ${escapeHtml(String(lastFloor.date))}${listed ? ` \u00b7 ${listed} listed` : ''}.`
                    : 'No floor recorded \u2014 nothing from this collection is currently listed for sale.'
            }${pts.length ? ` ${pts.length} daily reading${pts.length === 1 ? '' : 's'} held.` : ' Daily readings start from the first snapshot.'}${
                h.truncated ? ' \u26a0 This reading was incomplete when taken.' : ''
            }</p>`;
    }

    // ── R-9b · REFRESH WHERE YOU ARE ──────────────────────────────────────
    // renderCollectionDetail() opens by removing 'active' from #cd-listing-detail,
    // and FIVE paths called it after a change: updateListingStatus,
    // updateListingPrice, updateListingPricing, cdCreatorMint and cdSaveSchedule.
    // So every save inside the listing manager closed the listing manager - pause,
    // resume, cancel, save pricing, save schedule, mint to self.
    //
    // ⚠ NOTHING IN THAT CODE CHANGED. Before R-5 those actions lived in a dropdown
    // ON the collection page, so the re-render looked like a refresh. R-5 moved
    // them INTO a page the re-render then destroys. The overhaul created this.
    //
    // cdNavState already records where the user is, so this refreshes the view
    // that is actually open, at the section that is actually open.
    let cdLmRefreshing = false;

    window.cdRefreshCurrentView = function () {
        const st = window.cdNavState;

        if (st.listingId) {
            // ⚠ The listing may no longer exist - cancelling removes it from
            // allListings (R-4). Refreshing into it would fire "Listing not found"
            // at someone who just cancelled on purpose. Fall back instead.
            const still = allListings.some(x => String(x.id) === String(st.listingId));
            if (still) {
                const nav = document.getElementById('cd-lm-nav');
                const act = nav ? nav.querySelector('.cd-lm-tab.is-active') : null;
                const sec = act ? act.dataset.lm : 'overview';
                cdLmRefreshing = true;
                try { cdOpenListingDetail(st.listingId, sec); }
                finally { cdLmRefreshing = false; }
                return;
            }
            st.listingId = null;
            st.listingLabel = null;
        }

        // The collection can vanish too - cancel its last listing and it is gone
        // from the grid entirely. renderCollectionDetail already falls back to the
        // grid on an empty list, so this is safe either way.
        cdRefreshCurrentView();              // R-9b
    };

    window.cdOpenListingDetail = function(listingId, section) {
        const l = allListings.find(x => String(x.id) === String(listingId));
        if (!l) { showToast('Listing not found', 'error'); return; }
        currentListingId = l.id;
        window.cdNavState.listingId = l.id;                               // D-C identifier
        window.cdNavState.listingLabel = l.nft_name || ('Listing #' + l.id);
        cdSetCrumbs();
        if (!cdRouting) cdSyncUrl(true);             // D-C: depth PUSHES

        document.getElementById('cd-collection-detail').classList.remove('active');
        document.getElementById('cd-listing-detail').classList.add('active');

        const minted = parseInt(l.minted_count) || 0;
        const total  = parseInt(l.total_editions) || 0;
        const icon = l.nft_type === 'album' ? '<svg class="imc-ic" aria-hidden="true"><use href="#ic-disc"></use></svg> ' : l.nft_type === 'art' ? '<svg class="imc-ic" aria-hidden="true"><use href="#ic-palette"></use></svg> '
                   : l.nft_type === 'film' ? '<svg class="imc-ic" aria-hidden="true"><use href="#ic-video"></use></svg> ' : l.nft_type === 'musicvideo' ? '<svg class="imc-ic" aria-hidden="true"><use href="#ic-clapper"></use></svg> ' : (l.nft_type === 'ebook' || l.nft_type === 'audiobook') ? '<svg class="imc-ic" aria-hidden="true"><use href="#ic-book"></use></svg> ' : '<svg class="imc-ic" aria-hidden="true"><use href="#ic-music"></use></svg> ';
        document.getElementById('cd-listing-detail-header').innerHTML = `
            <div class="cd-detail-cover">
                <img src="${ipfsToHttp(l.cover_ipfs)}" alt="${escapeHtml(l.nft_name)}">
            </div>
            <div class="cd-detail-info">
                <div class="cd-detail-title">${escapeHtml(l.nft_name)}</div>
                <div class="cd-detail-stats">
                    <div class="cd-stat-card cd-stat-mini">
                        <div class="cd-stat-label">Minted</div>
                        <div class="cd-stat-value">${minted}<span class="cd-stat-sub">/${total || '∞'}</span></div>
                    </div>
                    <div class="cd-stat-card cd-stat-mini">
                        <div class="cd-stat-label">Status</div>
                        <div class="cd-stat-value">${escapeHtml(l.status || '')}</div>
                    </div>
                </div>
            </div>`;

        // R-5a: rebuild for THIS listing and always land on Overview, or opening a
        // second listing inherits the first one's section.
        cdLmRender(l);
        cdLmOpenPricing(l);                 // R-5b: the panel is live, so fill it now
        cdLmOpenSchedule(l);                // R-5c
        // R-5b: land on `section` when asked, Overview otherwise. Unknown values
        // fall back rather than leaving every panel hidden.
        const lmNav = document.getElementById('cd-lm-nav');
        const want = (section && lmNav && lmNav.querySelector(`.cd-lm-tab[data-lm="${section}"]`)) ? section : 'overview';
        if (lmNav) {
            lmNav.querySelectorAll('.cd-lm-tab').forEach(b => b.classList.toggle('is-active', b.dataset.lm === want));
            document.querySelectorAll('#cd-listing-detail .cd-lm-panel').forEach(p => {
                p.classList.toggle('is-active', p.dataset.lmPanel === want);
            });
        }

        // R-9b: a refresh-in-place must NOT scroll. You pressed Pause in the Danger
        // section; being thrown to the top of the page is only marginally better
        // than being thrown out of the page.
        //
        // R-11b: and when it IS a navigation, scroll to the MANAGER rather than to
        // the top. Opening a listing from row 7 of the table used to send you to the
        // page header, leaving you to scroll back down to the thing you just opened.
        if (!cdLmRefreshing) {
            const lv = document.getElementById('cd-listing-detail');
            if (lv) lv.scrollIntoView({ behavior: 'smooth', block: 'start' });
            else window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        fetchAllowlists();
    };

    window.cdBackToCollection = function() {
        window.cdNavState.listingId = null;                               // B-d/D-C
        window.cdNavState.listingLabel = null;
        cdSetCrumbs();
        if (!cdRouting) cdSyncUrl(true);             // D-C
        currentListingId = null;
        document.getElementById('cd-listing-detail').classList.remove('active');
        document.getElementById('cd-collection-detail').classList.add('active');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    // R-9c: three navigation paths call scrollTo({top:0}) unconditionally, so
    // drilling into the fourth collection of a long grid and coming back put you at
    // the top every time. Recorded on the way out, restored on the way in.
    let cdGridScroll = 0;

    window.cdShowCollectionsView = function() {
        currentCollection = null;
        window.cdNavState.collection = null;                              // B-d/D-C
        window.cdNavState.listingId = null;
        window.cdNavState.listingLabel = null;
        cdSetCrumbs();
        if (!cdRouting) cdSyncUrl(true);             // D-C
        currentListingId = null;
        document.getElementById('cd-collection-detail').classList.remove('active');
        document.getElementById('cd-listing-detail').classList.remove('active'); // v671: 3-view safety
        // R-9c: restore after the views swap, on the next frame - the grid has no
        // height until it is 'active', so scrolling before that lands at 0.
        if (cdGridScroll > 0) {
            const y = cdGridScroll;
            requestAnimationFrame(() => window.scrollTo({ top: y, behavior: 'auto' }));
        }
        document.getElementById('cd-collections-view').classList.add('active');
        // v669: now that the grid genuinely hides, a creator returning from a long detail view
        // would otherwise land scrolled past the (shorter) grid. Return them to the top.
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };
    
    // R-9c: `fromCard` is passed ONLY by the collection card. The D-C router must
    // never skip - a deep link to #c=<collection> would bounce past the page the
    // link names, and the URL would no longer describe the view.
    window.cdShowCollectionDetail = async function(collectionName, fromCard) {
        // R-9c: remember where the grid was, so coming back restores the scroll.
        cdGridScroll = window.scrollY || 0;

        // R-11: the R-9c skip is REMOVED, not disabled. It existed because the
        // grid made you pass through a collection page to reach a single listing.
        // Listings are now flat, so you arrive here only by clicking a collection
        // NAME - a deliberate act that must not be skipped past. It was also the
        // source of the jump-to-top you reported: the skip called
        // cdOpenListingDetail, which scrolls.
        await renderCollectionDetail(collectionName);
        // v68: bring the detail into view. R-11: still wanted here - you clicked a
        // collection name and the page below changed, so the view should follow.
        setTimeout(() => {
            const detailSection = document.getElementById('cd-collection-detail');
            if (detailSection) {
                detailSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }, 100);
    };
    
    // R-8: toggleActionsMenu removed with the dropdown it opened. Its
    // document-level close handler went too - leaving a listener querying a class
    // that no longer exists is the dead-code residue R-3 and R-4 had to clean up
    // afterwards, so both go together.
    
    // R-8: the outside-click closer went with the dropdown. It queried two classes
    // that no longer exist anywhere in the markup.
    
    // v81: State for edit modal - GLOBAL for inline handlers
    window.editStaticTokens = [];
    window.editDynamicTokens = [];
    window.editPricingMode = 'static';
    let editPriceOracleCache = null;
    
    // ═══ P5: Progressive Pricing management (freeze-and-rebase) ═══════════════
    function cdPpRender(listing) {
        const sec = document.getElementById('cd-pp-section');
        if (!sec) return;
        let cfg = listing && listing.progressive_json;
        if (typeof cfg === 'string') { try { cfg = JSON.parse(cfg); } catch (e) { cfg = null; } }
        window.cdPpCfg = cfg || null;
        window.cdPpListing = listing || null;
        sec.style.display = listing ? 'block' : 'none';
        if (!listing) return;
        const t = document.getElementById('cd-pp-toggle');
        t.checked = !!(cfg && cfg.enabled);
        const minted = parseInt(listing.minted_count || 0);
        document.getElementById('cd-pp-status').textContent = (cfg && cfg.enabled)
            ? `Active — changes apply forward from the current price${minted > 0 ? ' (minting has started)' : ''}.`
            : (cfg ? 'Frozen — turn on to resume climbing from the current price.' : 'Off — turn on to make the price climb with every mint.');
        // rows: USD form for dynamic, per-currency for static
        const mode = (listing.pricing_mode || 'static');
        let acc = listing.accepted_currencies;
        if (typeof acc === 'string') { try { acc = JSON.parse(acc); } catch (e) { acc = []; } }
        const rows = [];
        const inc = (cfg && cfg.increments) || {};
        const cap = (cfg && cfg.cap) || {};
        const inputCss = 'flex:1;min-width:120px;padding:8px 10px;border-radius:7px;background:rgba(255,255,255,.06);color:#fff;border:1px solid rgba(255,255,255,.18);';
        if (mode === 'dynamic') {
            const cur = parseFloat(listing.price_usd || 0);
            rows.push({ cur: 'USD', label: 'USD', now: cur, inc: inc['USD'] ?? '', cap: cap['USD'] ?? '' });
        } else {
            const seen = {};
            (Array.isArray(acc) ? acc : []).forEach(en => {
                if (!en || !en.currency) return;
                const c = String(en.currency).toUpperCase(); if (seen[c]) return; seen[c] = 1;
                rows.push({ cur: c, label: c, now: parseFloat(en.price || 0), inc: inc[c] ?? '', cap: cap[c] ?? '' });
            });
            if (!rows.length && parseFloat(listing.price_xrp || 0) > 0) {
                rows.push({ cur: 'XRP', label: 'XRP', now: parseFloat(listing.price_xrp), inc: inc['XRP'] ?? '', cap: cap['XRP'] ?? '' });
            }
        }
        document.getElementById('cd-pp-fields').innerHTML = rows.map(r => `
            <div style="display:flex;gap:8px;align-items:center;margin-bottom:7px;flex-wrap:wrap;">
                <span style="min-width:52px;font-weight:600;">${r.label}</span>
                <span style="font-size:.78rem;opacity:.7;min-width:86px;">now ${r.cur === 'USD' ? '$' + r.now.toFixed(2) : r.now}</span>
                <input type="number" min="0" step="any" class="cd-pp-inc" data-cur="${r.cur}" placeholder="Increment per mint" value="${r.inc}" style="${inputCss}">
                <input type="number" min="${r.now}" step="any" class="cd-pp-cap" data-cur="${r.cur}" data-now="${r.now}" placeholder="Cap (optional, ≥ current)" value="${r.cap}" style="${inputCss}">
            </div>`).join('');
    }
    async function cdPpSave() {
        const listing = window.cdPpListing;
        if (!listing) return;
        const t = document.getElementById('cd-pp-toggle');
        const wasOn = !!(window.cdPpCfg && window.cdPpCfg.enabled);
        if (!t.checked && wasOn) {
            if (!confirm('<svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> Turn off Progressive Pricing? The ladder stops and the price freezes where it is now. You can turn it back on later to resume climbing.')) return;
        }
        let payload;
        if (!t.checked) {
            payload = { enabled: false };
        } else {
            const incs = {}, caps = {};
            let bad = null;
            document.querySelectorAll('.cd-pp-inc').forEach(i => {
                const v = parseFloat(i.value); if (v > 0) incs[i.dataset.cur] = v;
            });
            document.querySelectorAll('.cd-pp-cap').forEach(i => {
                const v = parseFloat(i.value);
                if (v > 0) {
                    if (v < parseFloat(i.dataset.now || 0)) bad = `The ${i.dataset.cur} cap cannot be below the current price — prices only ever climb.`;
                    caps[i.dataset.cur] = v;
                }
            });
            if (bad) { showToast(bad, 'error'); return; }
            if (!Object.keys(incs).length) { showToast('Set an increment for at least one currency (or turn the toggle off).', 'error'); return; }
            payload = { enabled: true, scope: (window.cdPpCfg && window.cdPpCfg.scope) || 'together', increments: incs };
            if (Object.keys(caps).length) payload.cap = caps;
        }
        try {
            const fd = new FormData();
            fd.append('action', 'update');
            fd.append('nonce', CONFIG.nonce);
            fd.append('listing_id', listing.id);
            fd.append('artist_account', CONFIG.account);
            fd.append('progressive', JSON.stringify(payload));
            const resp = await fetch(CONFIG.endpoints.listingsHandler, { method: 'POST', body: fd });
            const data = await resp.json();
            if (data.success) {
                showToast(payload.enabled ? 'Progressive settings saved — applies to future mints.' : 'Progressive pricing frozen at the current price.', 'success');
                if (typeof loadListings === 'function') loadListings();
            } else {
                showToast(data.error || 'Failed to save progressive settings', 'error');
            }
        } catch (err) {
            showToast('Failed to save: ' + err.message, 'error');
        }
    }
    document.getElementById('cd-pp-save')?.addEventListener('click', cdPpSave);
    // G3: switching pricing mode disarms the ladder -- confirming notice
    document.querySelectorAll('input[name="edit_pricing_mode"]').forEach(r => {
        r.addEventListener('change', () => {
            const l = window.cdPpListing; const c = window.cdPpCfg;
            if (l && c && c.enabled && r.value !== (l.pricing_mode || 'static')) {
                if (!confirm('<svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> Switching pricing mode disarms Progressive Pricing on this listing — the ladder stops and prices go flat. Continue?')) {
                    const orig = document.querySelector(`input[name="edit_pricing_mode"][value="${l.pricing_mode || 'static'}"]`);
                    if (orig) { orig.checked = true; orig.dispatchEvent(new Event('change')); }
                }
            }
        });
    });

    window.cdEditPrice = async function(listingId, currentPrice, acceptedCurrencies, pricingMode, priceUsd) {
        document.getElementById('edit-listing-id').value = listingId;
        cdPpRender((window.cdListingsById || {})[String(listingId)] || null); // P5
        document.getElementById('edit-price').value = currentPrice;
        document.getElementById('edit-pricing-mode').value = pricingMode || 'static';
        
        // Reset state
        window.editStaticTokens = [];
        window.editDynamicTokens = [];
        window.editPricingMode = pricingMode || 'static';
        
        // Update pricing mode UI
        document.querySelectorAll('.cd-pricing-mode-toggle .cd-mode-option').forEach(opt => {
            opt.classList.toggle('selected', opt.dataset.mode === window.editPricingMode);
            opt.querySelector('input').checked = opt.dataset.mode === window.editPricingMode;
        });
        
        // Show/hide sections based on mode
        window.cdApplyEditPricingMode();
        
        if (window.editPricingMode === 'dynamic') {
            document.getElementById('edit-usd-price').value = priceUsd || 9.99;
            window.cdUpdateDynamicPreview();
        }
        
        // Parse accepted currencies and populate tokens
        if (acceptedCurrencies && Array.isArray(acceptedCurrencies)) {
            acceptedCurrencies.forEach(curr => {
                if (curr.currency === 'XRP') return;
                
                // v670: PWYW stores its floors in the same shape as static prices, so its tokens
                // must load here too -- otherwise saving a PWYW listing would drop every floor.
                if (window.editPricingMode === 'static' || window.editPricingMode === 'pwyw') {
                    window.editStaticTokens.push({
                        currency: curr.currency,
                        issuer: curr.issuer || '',
                        price: curr.price || 0,
                        enabled: curr.enabled !== false
                    });
                } else {
                    window.editDynamicTokens.push({
                        currency: curr.currency,
                        issuer: curr.issuer || '',
                        discount_pct: curr.discount_pct || curr.discount_percent || 0,
                        enabled: curr.enabled !== false
                    });
                }
            });
        }
        
        // Render token lists
        window.cdRenderEditStaticTokens();
        window.cdRenderEditDynamicTokens();
        
        // R-5b: nothing to open - the form is already in the Pricing panel.
    };
    
    // Pricing mode toggle handlers
    document.querySelectorAll('.cd-pricing-mode-toggle .cd-mode-option').forEach(opt => {
        opt.addEventListener('click', function() {
            window.editPricingMode = this.dataset.mode;
            document.getElementById('edit-pricing-mode').value = window.editPricingMode;
            
            document.querySelectorAll('.cd-pricing-mode-toggle .cd-mode-option').forEach(o => {
                o.classList.toggle('selected', o.dataset.mode === window.editPricingMode);
                o.querySelector('input').checked = o.dataset.mode === window.editPricingMode;
            });
            
            window.cdApplyEditPricingMode();
            
            if (window.editPricingMode === 'dynamic') {
                window.cdUpdateDynamicPreview();
            }
        });
    });

    // v670: single source of truth for edit-modal section visibility, used by both the
    // populate path (cdEditPrice) and the mode-toggle click handler.
    //   static / pwyw -> the price section (PWYW prices are minimums)
    //   free          -> the section stays, but every price control is hidden
    //   dynamic       -> the USD section
    window.cdApplyEditPricingMode = function() {
        const m = window.editPricingMode || 'static';
        const staticSec = document.getElementById('edit-static-section');
        const dynSec    = document.getElementById('edit-dynamic-section');
        if (staticSec) staticSec.style.display = (m === 'dynamic') ? 'none' : 'block';
        if (dynSec)    dynSec.style.display    = (m === 'dynamic') ? 'block' : 'none';
        if (staticSec) {
            staticSec.querySelectorAll('.cd-form-group, .cd-add-token-section, #edit-static-tokens-list')
                .forEach(el => { el.style.display = (m === 'free') ? 'none' : ''; });
        }
        const pwywNote = document.getElementById('edit-pwyw-note');
        if (pwywNote) pwywNote.style.display = (m === 'pwyw') ? 'block' : 'none';
        const freeNote = document.getElementById('edit-free-note');
        if (freeNote) freeNote.style.display = (m === 'free') ? 'block' : 'none';
    };
    
    // v604: populate the static-token selector from the Token Manager (single source of
    // truth, same as the create flow in mint.js). Falls back to the placeholder on error.
    async function cdLoadSupportedTokens() {
        try {
            const resp = await fetch('/wp-admin/admin-ajax.php?action=imc_get_tokens&context=mint');
            const data = await resp.json();
            if (!data || !data.success || !data.data || !data.data.tokens) return;
            const sel = document.getElementById('edit-static-token-selector');
            if (!sel) return;
            let html = '<option value="">-- Select token --</option>';
            data.data.tokens.filter(function(t){ return !t.is_native; }).forEach(function(t){
                const name = (t.name && t.name !== t.ticker) ? (' - ' + t.name) : '';
                const ic = t.icon ? (t.icon + ' ') : '';
                html += '<option value="' + t.ticker + '" data-issuer="' + (t.issuer || '') + '">' + ic + t.ticker + name + '</option>';
            });
            sel.innerHTML = html;
        } catch (e) { /* keep placeholder; non-fatal */ }
    }
    cdLoadSupportedTokens();

    // Static token selector
    document.getElementById('edit-static-token-selector')?.addEventListener('change', function() {
        const priceInput = document.getElementById('edit-new-token-price');
        const addBtn = document.getElementById('edit-add-token-btn');
        if (this.value) {
            priceInput.style.display = 'inline-block';
            addBtn.disabled = false;
        } else {
            priceInput.style.display = 'none';
            addBtn.disabled = true;
        }
    });
    
    document.getElementById('edit-add-token-btn')?.addEventListener('click', function() {
        const selector = document.getElementById('edit-static-token-selector');
        const priceInput = document.getElementById('edit-new-token-price');
        
        if (!selector.value || !priceInput.value) return;
        
        const selected = selector.options[selector.selectedIndex];
        if (window.editStaticTokens.some(t => t.currency === selected.value)) {
            alert('Token already added');
            return;
        }
        
        window.editStaticTokens.push({
            currency: selected.value,
            issuer: selected.dataset.issuer || '',
            price: parseFloat(priceInput.value) || 0,
            enabled: true
        });
        
        window.cdRenderEditStaticTokens();
        selector.selectedIndex = 0;
        priceInput.value = '';
        priceInput.style.display = 'none';
        this.disabled = true;
    });
    
    // Dynamic token selector
    document.getElementById('edit-dynamic-token-selector')?.addEventListener('change', function() {
        const discountInput = document.getElementById('edit-new-token-discount');
        const suffix = document.getElementById('edit-discount-suffix');
        const addBtn = document.getElementById('edit-add-dynamic-btn');
        if (this.value) {
            // v81: Default to 0% - creator defines their own discount
            discountInput.value = 0;
            discountInput.style.display = 'inline-block';
            suffix.style.display = 'inline';
            addBtn.disabled = false;
        } else {
            discountInput.style.display = 'none';
            suffix.style.display = 'none';
            addBtn.disabled = true;
        }
    });
    
    document.getElementById('edit-add-dynamic-btn')?.addEventListener('click', function() {
        const selector = document.getElementById('edit-dynamic-token-selector');
        const discountInput = document.getElementById('edit-new-token-discount');
        
        if (!selector.value) return;
        
        if (window.editDynamicTokens.some(t => t.currency === selector.value)) {
            alert('Token already added');
            return;
        }
        
        window.editDynamicTokens.push({
            currency: selector.value,
            discount_pct: parseInt(discountInput.value) || 0,
            enabled: true
        });
        
        window.cdRenderEditDynamicTokens();
        window.cdUpdateDynamicPreview();
        selector.selectedIndex = 0;
        discountInput.style.display = 'none';
        document.getElementById('edit-discount-suffix').style.display = 'none';
        this.disabled = true;
    });
    
    // USD price change handler
    document.getElementById('edit-usd-price')?.addEventListener('input', function() { window.cdUpdateDynamicPreview(); });
    
    // v81: Make render functions global for inline handlers
    window.cdRenderEditStaticTokens = function() {
        const list = document.getElementById('edit-static-tokens-list');
        if (!list) return;
        
        list.innerHTML = window.editStaticTokens.map((t, idx) => `
            <div class="cd-token-row">
                <span class="cd-token-name">${t.currency}</span>
                <input type="number" value="${t.price}" min="0" step="0.000001" 
                       onchange="window.editStaticTokens[${idx}].price = parseFloat(this.value) || 0">
                <span>${t.currency}</span>
                <button type="button" class="cd-btn-remove" onclick="window.editStaticTokens.splice(${idx}, 1); window.cdRenderEditStaticTokens();">×</button>
            </div>
        `).join('');
    };
    
    window.cdRenderEditDynamicTokens = function() {
        const list = document.getElementById('edit-dynamic-tokens-list');
        if (!list) return;
        
        list.innerHTML = window.editDynamicTokens.map((t, idx) => `
            <div class="cd-token-row">
                <span class="cd-token-name">${t.currency}</span>
                <input type="number" value="${t.discount_pct}" min="0" max="50" step="1"
                       onchange="window.editDynamicTokens[${idx}].discount_pct = parseInt(this.value) || 0; window.cdUpdateDynamicPreview();">
                <span>% off</span>
                <button type="button" class="cd-btn-remove" onclick="window.editDynamicTokens.splice(${idx}, 1); window.cdRenderEditDynamicTokens(); window.cdUpdateDynamicPreview();">×</button>
            </div>
        `).join('');
    };
    
    window.cdUpdateDynamicPreview = async function() {
        const usdInput = document.getElementById('edit-usd-price');
        const usdPrice = parseFloat(usdInput?.value) || 0;
        const preview = document.getElementById('edit-xrp-preview');
        
        // v193: Live enforcement of $5,000 USD cap
        if (usdPrice > 5000) {
            if (preview) preview.innerHTML = '<span style="color:#ef4444;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-x-circle"></use></svg> Maximum price is $5,000 USD</span>';
            if (usdInput) usdInput.style.borderColor = '#ef4444';
            return;
        } else if (usdInput) {
            usdInput.style.borderColor = '';
        }
        
        if (!preview || usdPrice <= 0) return;
        
        // v84: Show calculating state first
        preview.innerHTML = '<span style="opacity:0.7;">⏳ Fetching live rate...</span>';
        
        // Fetch XRP price
        try {
            // v84: Always fetch fresh (don't use stale cache)
            const resp = await fetch('/wp-json/imc-price/v1/prices');
            if (!resp.ok) throw new Error('Failed to fetch prices');
            editPriceOracleCache = await resp.json();
            
            const xrpPrice = editPriceOracleCache?.prices?.XRP?.usd;
            if (!xrpPrice || xrpPrice <= 0) {
                preview.innerHTML = '<svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> Unable to fetch XRP price';
                return;
            }
            const xrpAmount = (usdPrice / xrpPrice).toFixed(4);
            preview.innerHTML = `≈ <strong>${xrpAmount}</strong> XRP <span style="opacity:0.7;">(@$${xrpPrice.toFixed(4)})</span>`;
        } catch (err) {
            console.error('Price fetch error:', err);
            preview.innerHTML = '<svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> Price oracle unavailable';
        }
    };
    
    // R-5b: the pricing modal no longer exists. Kept as a NO-OP rather than
    // deleted, because two post-save paths call it and a third-party bookmarklet
    // or cached page could too - silently doing nothing is safer than throwing.
    // It is removed once R-5c and R-5d retire the last modal that used it.
    window.cdCloseModal = function() { /* R-5b: pricing is inline; nothing to close */ };

    // Re-reads the listing and repaints the inline pricing form. Replaces the
    // modal's Cancel button, which used to discard edits by closing.
    window.cdLmReloadPricing = function () {
        const l = allListings.find(x => String(x.id) === String(currentListingId));
        if (!l) return;
        cdLmOpenPricing(l);
        showToast('Pricing reset to saved values', 'success');
    };
    
    // Edit price form submission
    document.getElementById('cd-edit-price-form').addEventListener('submit', function(e) {
        e.preventDefault();
        const listingId = document.getElementById('edit-listing-id').value;
        const pricingMode = document.getElementById('edit-pricing-mode').value;
        
        let updateData = {
            pricing_mode: pricingMode
        };
        
        if (pricingMode === 'free') {
            // v670: Free Mint clears every price so nothing can be charged. Previously PWYW and
            // Free both fell into the dynamic branch below, which overwrote accepted_currencies
            // with an XRP-only entry and destroyed the listing's token prices.
            updateData.price_xrp = 0;
            updateData.price_usd = 0;
            updateData.accepted_currencies = [];
        } else if (pricingMode === 'static' || pricingMode === 'pwyw') {
            // PWYW uses the identical shape -- the amounts are minimums rather than fixed prices.
            const newPrice = parseFloat(document.getElementById('edit-price').value) || 0;
            updateData.price_xrp = newPrice;
            
            // Build currencies array
            const currencies = [
                { currency: 'XRP', price: newPrice, enabled: true }
            ];
            window.editStaticTokens.forEach(t => {
                currencies.push({
                    currency: t.currency,
                    issuer: t.issuer,
                    price: t.price,
                    enabled: true
                });
            });
            updateData.accepted_currencies = currencies;
        } else {
            // Dynamic. v83: XRP ONLY (calculated from USD at checkout)
            updateData.price_usd = parseFloat(document.getElementById('edit-usd-price').value) || 0;
            
            // v193: Enforce $5,000 USD cap
            if (updateData.price_usd > 5000) {
                alert('Maximum dynamic price is $5,000 USD for compliance reasons.');
                return;
            }
            
            // Only XRP is accepted for dynamic pricing
            updateData.accepted_currencies = [
                { currency: 'XRP', enabled: true }
            ];
        }
        
        console.log('Saving pricing update:', updateData);
        updateListingPricing(listingId, updateData);
    });
    
    // ── B-c · LEVEL-1 NAV ──────────────────────────────────────
    // Its OWN listener over .cd-nav-tab. The filter listener below binds once at
    // boot over .cd-tab and would otherwise capture these buttons, blanking the
    // grid. The two class sets are disjoint by design - see the CSS note.
    //
    // cdNavState is the seed of the object D-C will serialise into the URL. It
    // holds only { tab } today; B-d adds collection/listing, D-C adds pushState.
    // B-c seeded this with { tab }. B-d adds the drill-down depth, which is the
    // shape D-C will serialise into the URL - collection and listing are exactly
    // what ?view=collection&c=... needs. Kept as plain values, not DOM lookups,
    // so the router can read state without touching the page.
    // D-C: `listing` split into an IDENTIFIER and a LABEL. B-d stored the display
    // name in `listing`, but cdOpenListingDetail() takes an ID - so a URL carrying
    // the name could never reopen the listing. Names are not unique or stable.
    // `collection` stays the NAME on purpose: the dashboard groups by
    // `l.collection_name || 'Uncategorized'` at three sites and
    // cdShowCollectionDetail() takes exactly that. Keying on taxon would repeat the
    // CP-T2 mistake of keying on something the client does not group by.
    window.cdNavState = { tab: 'listings', collection: null, listingId: null, listingLabel: null };

    // Renders the bar from cdNavState alone. Called by every view transition;
    // no transition computes its own crumbs, so they cannot drift apart.
    // ── D-C · URL STATE ────────────────────────────────────────
    // HASH, not query string: trading.js's CP-C2 router already uses a hash for the
    // collection page, it needs no rewrite rule, and ?listing_published=1 is already
    // a real query param on this page (post-publish redirect) - mixing our state
    // into the query risks colliding with it.
    //
    // pushState for DEPTH, replaceState for TABS (ruled 13 Sep). The CP-C2
    // precedent uses replaceState throughout with the reason "the hash stays
    // shareable without turning every tab click into a back-button step" - correct
    // there, because that page has one level. This page has three, and Back walking
    // listing -> collection -> grid is the whole point of D-C. Tab switches still
    // replace, so they never pollute the history stack.
    // R-6: 'overview' stays in the list as an ALIAS. D-C has been live since v985
    // and the hash is shareable by design, so bookmarked #overview links exist.
    // cdNormTab() maps it to 'activity' at every entry point.
    // R-11: 'collections' joins 'overview' as an ALIAS. Both were live routing
    // values with shareable hashes, so old links must keep resolving.
    const CD_TABS = ['activity', 'overview', 'listings', 'collections', 'analytics'];
    function cdNormTab(t) { return (t === 'overview') ? 'activity' : (t === 'collections' ? 'listings' : t); }

    function cdBuildHash() {
        const st = window.cdNavState;
        // R-11: the canonical value is now 'listings'; 'collections' still
        // RESOLVES (CD_TABS + cdNormTab) but is never written into a new URL.
        if (st.tab !== 'listings') return '#' + st.tab;
        if (!st.collection) return '';
        let h = '#c=' + encodeURIComponent(st.collection);
        if (st.listingId) h += '&l=' + encodeURIComponent(st.listingId);
        return h;
    }

    function cdSyncUrl(push) {
        const h = cdBuildHash();
        const url = window.location.pathname + window.location.search + h;
        try {
            if (push) history.pushState(null, '', url);
            else      history.replaceState(null, '', url);
        } catch (e) { /* never let a history failure break navigation */ }
    }
    window.cdSyncUrl = cdSyncUrl;

    // Applies a hash to the view. Returns silently on anything it does not own, so
    // an in-page anchor from elsewhere is left alone (the CP-C2 rule).
    let cdRouting = false;              // guards against sync-while-applying
    async function cdApplyHash(raw) {
        const h = String(raw || '').replace(/^#/, '');
        cdRouting = true;
        try {
            if (!h) { cdShowCollectionsView(); return; }
            if (CD_TABS.indexOf(h) !== -1) { cdSwitchTab(cdNormTab(h)); return; }

            const p = new URLSearchParams(h);
            const cName = p.get('c');
            const lId   = p.get('l');
            // Only act on a hash we own. An unrecognised one is left ALONE rather
            // than reset to the grid - the CP-C2 rule, so an in-page anchor from
            // anywhere else never hijacks the view. An EMPTY hash is different and
            // is handled above: that genuinely means "the grid".
            if (!cName) return;

            // The collection must still exist. A renamed or deleted one falls back
            // to the grid rather than rendering an empty detail view.
            const exists = allListings.some(l => (l.collection_name || 'Uncategorized') === cName);
            if (!exists) { cdShowCollectionsView(); return; }

            if (window.cdNavState.tab !== 'listings') cdSwitchTab('listings');
            await cdShowCollectionDetail(cName);

            if (lId) {
                // Check BEFORE calling: cdOpenListingDetail toasts "Listing not
                // found" on a miss, and a stale URL must not raise an error at the
                // user. Silent fallback to the collection is the honest behaviour.
                const hit = allListings.find(x => String(x.id) === String(lId)
                            && (x.collection_name || 'Uncategorized') === cName);
                if (hit) cdOpenListingDetail(hit.id);
            }
        } finally {
            cdRouting = false;
        }
    }
    window.cdApplyHash = cdApplyHash;

    window.addEventListener('popstate', function () {
        // Back/Forward. Apply without syncing, or we would rewrite the entry we
        // just navigated to.
        cdApplyHash(window.location.hash);
    });

    window.cdSetCrumbs = function () {
        const bc = document.getElementById('cd-breadcrumb');
        const sc = document.getElementById('cd-scope');
        if (!bc) return;
        const st = window.cdNavState;
        const parts = [];

        const TAB_LABEL = { activity: 'Activity', listings: 'Listings', analytics: 'Analytics' };
        parts.push({ label: TAB_LABEL[st.tab] || 'Dashboard',
                     go: st.tab === 'listings' && st.collection ? 'home' : null });

        if (st.tab === 'listings' && st.collection) {
            parts.push({ label: st.collection, go: st.listingId ? 'collection' : null });
            if (st.listingId) parts.push({ label: st.listingLabel || ('Listing #' + st.listingId), go: null });
        }

        // R-3: on a flat tab `parts` has length 1, so the bar would be a
        // full-width panel displaying one word. Hide it; it returns by itself on
        // drill-down. display:none, NOT visibility:hidden - the bar is
        // position:sticky and hidden-but-present would leave a gap at the top of
        // the scroll. Revisit at R-5: once the listings manager exists the tree
        // is four deep and the trail earns its place on more screens.
        const bar = document.getElementById('cd-topbar');
        // 'flex' explicitly, not '': the stylesheet default is now none, so an
        // empty string would fall back to hidden and the bar would never appear.
        if (bar) bar.style.display = (parts.length <= 1) ? 'none' : 'flex';

        bc.innerHTML = parts.map((p, i) => {
            const cur = (i === parts.length - 1);
            const cls = 'cd-crumb' + (cur ? ' is-current' : '');
            const btn = cur || !p.go
                ? `<span class="${cls}" title="${escapeHtml(p.label)}">${escapeHtml(p.label)}</span>`
                : `<button type="button" class="${cls}" title="${escapeHtml(p.label)}" onclick="cdCrumbGo('${p.go}')">${escapeHtml(p.label)}</button>`;
            return (i ? '<span class="cd-crumb-sep">\u203a</span>' : '') + btn;
        }).join('');

        // Scope line - B-5 ruling: muted, inline, subordinate to the breadcrumb.
        if (sc) {
            let txt = '';
            if (st.tab === 'listings' && st.collection && !st.listingId) {
                const ls = allListings.filter(l => (l.collection_name || 'Uncategorized') === st.collection);
                let minted = 0, capped = 0, open = 0;
                ls.forEach(l => {
                    minted += parseInt(l.minted_count) || 0;
                    const t = parseInt(l.total_editions) || 0;
                    if (t > 0) capped += t; else open++;
                });
                txt = `${ls.length} listing${ls.length === 1 ? '' : 's'} \u00b7 ${minted} minted`
                    + (open ? ' \u00b7 open edition' + (open === 1 ? '' : 's') : ` of ${capped}`);
            } else if (st.tab === 'listings' && !st.collection) {
                txt = `<b>${Object.keys(allListings.reduce((a, l) => (a[l.collection_name || 'Uncategorized'] = 1, a), {})).length}</b> collections \u00b7 <b>${allListings.length}</b> listings`;
            }
            sc.innerHTML = txt;
        }
    };

    window.cdCrumbGo = function (where) {
        if (where === 'home') { cdShowCollectionsView(); }
        else if (where === 'collection') { cdBackToCollection(); }
    };

    window.cdSwitchTab = function(name) {
        if (!name || name === window.cdNavState.tab) return;
        const panel = document.getElementById('cd-panel-' + name);
        if (!panel) return;                       // unknown tab: do nothing
        document.querySelectorAll('.cd-panel').forEach(p => p.classList.remove('active'));
        panel.classList.add('active');
        document.querySelectorAll('.cd-nav-tab').forEach(t =>
            t.classList.toggle('active', t.dataset.tab === name));
        window.cdNavState.tab = name;
        cdSetCrumbs();                               // B-d
        if (!cdRouting) cdSyncUrl(false);            // D-C: tabs REPLACE, never push
        if (name === 'activity')  cdLoadActivity();
        if (name === 'analytics' && typeof cdLoadAnalytics === 'function') cdLoadAnalytics(false);
        // D-E: lazy - the store panel only loads when Analytics is actually opened.
        if (name === 'analytics' && typeof cdStoreFillPicker === 'function') {
            cdStoreFillPicker();
            cdLoadStore(cdAnScope().taxon);
        }
    };

    document.querySelectorAll('.cd-nav-tab').forEach(t => {
        t.addEventListener('click', function () { cdSwitchTab(this.dataset.tab); });
    });

    // Overview · what needs the creator's attention. Reads allListings only.
    // ── R-6 · ACTIVITY FEED ────────────────────────────────────────────────
    // Primary mints only, deliberately. Secondary sales come from the store, with
    // a different row shape, different currencies and different latency; putting
    // both in one list is a second design problem and the mint feed already
    // answers "what happened".
    //
    // No new endpoint: get_artist_purchases takes listing_ids as OPTIONAL, so
    // omitting it is account-wide, newest first, with nft_name and cover_ipfs
    // already joined. endpoints.* is unchanged at 38.
    // ⚠ R-6b FIX (14 Sep 2026): the filter was CLIENT-SIDE over one page of rows.
    // The loader fetched the 100 newest purchases account-wide and then filtered
    // those in the browser - but measured on live data, ALL 100 of the newest
    // belong to a single actively-minting collection. Every other collection came
    // back empty and the panel said "No mints recorded for this collection yet",
    // which was simply false: mint counts vary widely per listing.
    //
    // A paginated feed cannot be filtered after the fact. get_artist_purchases
    // already takes listing_ids and filters in SQL, so the collection choice now
    // goes to the server and each collection gets its own newest 100.
    // Cached per collection, because switching back should not refetch.
    let cdFeedCache = {};                 // { [collection]: rows[] }

    function cdFeedFillPicker() {
        const sel = document.getElementById('cd-feed-collection');
        if (!sel) return;
        const names = Object.keys((allListings || []).reduce((a, l) => {
            a[l.collection_name || 'Uncategorized'] = 1; return a;
        }, {})).sort();
        const cur = sel.value || 'all';
        sel.innerHTML = '<option value="all">All collections</option>'
            + names.map(nm => `<option value="${escapeHtml(nm)}">${escapeHtml(nm)}</option>`).join('');
        sel.value = names.indexOf(cur) !== -1 ? cur : 'all';
    }

    window.cdLoadActivity = async function () {
        const box = document.getElementById('cd-feed');
        if (!box) return;
        cdFeedFillPicker();
        const want = (document.getElementById('cd-feed-collection') || {}).value || 'all';
        if (cdFeedCache[want]) { cdRenderActivity(cdFeedCache[want]); return; }

        box.innerHTML = '<div class="cd-loading"><div class="cd-spinner"></div><span>Loading activity...</span></div>';

        // The ids of the listings in this collection. R-4 filters cancelled
        // listings out of allListings, so their mints stay out of the feed too -
        // consistent with every other count on the page.
        let idsParam = '';
        if (want !== 'all') {
            const ids = (allListings || [])
                .filter(l => (l.collection_name || 'Uncategorized') === want)
                .map(l => l.id);
            if (!ids.length) { cdRenderActivity([]); return; }
            idsParam = '&listing_ids=' + encodeURIComponent(ids.join(','));
        }

        try {
            const url = `${CONFIG.endpoints.mintOnDemand}?action=get_artist_purchases`
                      + `&account=${encodeURIComponent(CONFIG.account)}`
                      + idsParam + `&limit=100&offset=0&t=${Date.now()}`;
            const res = await fetch(url, { credentials: 'include' });
            const data = await res.json();
            const rows = (data && data.success && data.data && Array.isArray(data.data.purchases))
                ? data.data.purchases
                : (data && Array.isArray(data.purchases) ? data.purchases : []);
            cdFeedCache[want] = rows;
            cdRenderActivity(rows);
        } catch (err) {
            console.warn('activity unavailable:', err);
            box.innerHTML = '<p class="cd-feed-empty">Activity could not be loaded just now. Your figures above are unaffected.</p>';
        }
    };

    function cdRenderActivity(rows) {
        const box = document.getElementById('cd-feed');
        if (!box) return;
        const want = (document.getElementById('cd-feed-collection') || {}).value || 'all';
        const byId = {};
        (allListings || []).forEach(l => { byId[String(l.id)] = l; });

        if (!rows || !rows.length) {
            box.innerHTML = '<p class="cd-feed-empty">No mints recorded'
                + (want === 'all' ? ' yet.' : ' for this collection yet.') + '</p>';
            return;
        }

        box.innerHTML = rows.map(p => {
            const l = byId[String(p.listing_id)] || {};
            const cur = p.price_currency || 'XRP';
            // Only XRP carries a trustworthy amount on the purchase row - R-1b. A
            // token mint shows its code rather than an XRP number under a token label.
            const paid = Number(p.price_xrp) > 0;
            const amt = !paid ? 'Free' : (cur === 'XRP' ? `${Number(p.price_xrp)} XRP` : cur);
            const who = p.buyer_account ? (p.buyer_account.slice(0, 8) + '\u2026' + p.buyer_account.slice(-4)) : '\u2014';
            return `<div class="cd-feed-row">
                <img class="cd-feed-img" src="${ipfsToHttp(l.cover_ipfs || p.cover_ipfs || '')}" alt=""
                     onerror="this.src='/wp-content/uploads/fallback-nft.svg'">
                <div class="cd-feed-main">
                    <b>${escapeHtml(p.nft_name || l.nft_name || 'Listing #' + p.listing_id)}</b>
                    <span>${p.edition_number ? '#' + escapeHtml(String(p.edition_number)) + ' \u00b7 ' : ''}${escapeHtml(who)}</span>
                </div>
                <div class="cd-feed-right">
                    <b>${escapeHtml(amt)}</b>
                    <span>${escapeHtml(cdAgo(p.created_at))}</span>
                </div>
            </div>`;
        }).join('');
    }

    document.addEventListener('change', function (e) {
        // Refetch, not re-filter: each collection needs its OWN newest 100.
        if (e.target && e.target.id === 'cd-feed-collection') cdLoadActivity();
    });

    // A3: a currency switch redraws from the payload already held - no refetch.
    document.addEventListener('change', function (e) {
        if (!e.target || e.target.id !== 'cd-an-sales-cur') return;
        cdAnSalesCur = e.target.value;
        try { cdAn.charts.sales && cdAn.charts.sales.destroy(); } catch (err) {}
        delete cdAn.charts.sales;
        const sc = (typeof cdAnScope === 'function') ? cdAnScope() : null;
        // ⚠ CACHE KEY. cdLoadStore stores under String(which) - the TAXON alone,
        // or 'all'. These replays looked up `issuer + ':' + taxon`, which never
        // matched, so `cached` was always null and Floor and Listings drew
        // nothing on a specific collection. A silent miss: a null payload
        // renders as an empty card rather than an error.
        // (cdClLedger keeps its own issuer:taxon cache - a different object,
        // and correct there because that one is fetched per issuer.)
        const key = sc ? String(sc.taxon) : null;
        const cached = (key && cdStore.cache && cdStore.cache[key]) ? cdStore.cache[key] : null;
        if (cached) cdAnDrawSales(cached.history || cached.hist || null);
    });

    // ── D-E · ON-LEDGER (STORE) PANEL ───────────────────────────────────────
    // Data comes from the VPS store at metadata.imcollectibles.io, called
    // DIRECTLY from the browser - it is public data, the same way trading.js
    // reads it for the collection page. No WordPress proxy, no nonce.
    //
    // "ALL COLLECTIONS" IS A TRUE UNION, NOT A SUM. A wallet holding from two of
    // your collections is ONE holder. The endpoint was widened (13 Sep) so an
    // omitted taxon aggregates with GROUP BY owner across the whole issuer -
    // correct in SQLite rather than reassembled here, which would double-count.
    //
    // ⚠ HISTORY CANNOT BE UNIONED. holders_history reads holders_cache, keyed
    // (issuer, taxon, date). Supply sums; distinct_holders does not. So the "All"
    // view shows the LIVE union only, and per-collection history stays per taxon.
    const CD_STORE_API = 'https://metadata.imcollectibles.io/';
    const cdStore = { cache: {}, charts: {}, token: 0 };

    /* ⚠ AG FIX: this block previously sat INSIDE cdCloseAllowlistModal - the
       anchor comment used to place it lived in that function's body. Every
       window.cdGen* assignment therefore only ran when that function was called,
       so the "Generate from holders" button invoked an undefined function and
       did nothing at all, silently. Moved to true IIFE scope, alongside the
       other helpers. A function defined inside another function is a scope bug
       that neither php -l nor node --check can see. */

    // ══ AG · HOLDER-LIST GENERATOR ═══════════════════════════════════════
    // Reads each source LIVE from the ledger (mode=verify -> Clio), merges, then
    // fills the wallet rows the create form already owns. It never writes entries
    // itself: Save & Add Wallets runs the same path a hand-typed list runs.
    //
    // ⚠ WHY LIVE AND NOT THE INDEX. the reconciliation job states the rule - "the
    // LEDGER (Clio) is the authority for owner + burn; our stream is an
    // accelerator". The stream re-subscribes without backfill after a WebSocket
    // reconnect, so trades landing during a disconnect are missing until the
    // reconciler runs. A chart self-heals on the next read; an allowlist is
    // written once and then governs money, so it pays the live cost.
    const cdGen = { sources: [], busy: false };

    // From Overview: switch to the Allowlists section first, then open. Without
    // the switch the panel would unhide inside a section that is not on screen.
    window.cdGenFromOverview = function () {
        const nav = document.getElementById('cd-lm-nav');
        if (nav) {
            const tab = nav.querySelector('.cd-lm-tab[data-lm="allowlists"]');
            if (tab) tab.click();
        }
        setTimeout(cdGenOpen, 60);
    };

    window.cdGenOpen = function () {
        const box = document.getElementById('cd-gen');
        const create = document.getElementById('cd-al-create');
        if (create) create.hidden = true;
        if (!box) return;
        // Own collections fill both fields from one pick - typing is only for
        // other creators' collections.
        const pick = document.getElementById('cd-gen-pick');
        if (pick) pick.innerHTML = `<option value="">Choose one, or type another creator's below\u2026</option>`
            + (typeof cdStoreTaxons === 'function' ? cdStoreTaxons() : [])
                .map(r => `<option value="${escapeHtml(r.issuer)}|${escapeHtml(String(r.taxon))}">${escapeHtml(r.name)}</option>`).join('');
        box.hidden = false;
        box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };

    // Clear empties the generator AND anything it already filled into the form.
    // Without the second half, "Clear" would leave the pending rows in the create
    // form and only look like it had cleared.
    window.cdGenReset = function () {
        cdGen.sources = [];
        cdGenRenderList();
        ['cd-gen-issuer', 'cd-gen-taxon', 'cd-gen-cap'].forEach(id => {
            const el = document.getElementById(id); if (el) el.value = '';
        });
        const pk = document.getElementById('cd-gen-pick'); if (pk) pk.value = '';
        const mn = document.getElementById('cd-gen-min');  if (mn) mn.value = '1';
        const ea = document.getElementById('cd-gen-each'); if (ea) ea.value = '1';
        const mg = document.getElementById('cd-gen-merge'); if (mg) mg.value = 'sum';
        const rq = document.getElementById('cd-gen-req');   if (rq) rq.value = 'any';
        const ty = document.getElementById('cd-gen-type');  if (ty) ty.value = 'discount_limited';
        const full = document.getElementById('cd-gen-full'); if (full) { full.hidden = true; full.innerHTML = ''; }

        // the rows this generator put into the create form
        const wrap = document.getElementById('inline-wallet-rows');
        if (wrap) wrap.innerHTML = '';
        if (typeof cdAddWalletRow === 'function') cdAddWalletRow('', '');
        if (typeof cdSerialiseWalletRows === 'function') cdSerialiseWalletRows();
        const capOut = document.getElementById('allowlist-holder-limit'); if (capOut) capOut.value = '';

        cdGenSyncReq();
        cdGenPreview();
        showToast('Generator cleared', 'success');
    };

    window.cdGenClose = function () {
        const box = document.getElementById('cd-gen');
        if (box) box.hidden = true;
        cdGen.sources = [];
        cdGenRenderList();
    };

    // Carries the whole {issuer, taxon} pair, so one choice fills both fields.
    window.cdGenPick = function () {
        const v = (document.getElementById('cd-gen-pick') || {}).value || '';
        if (!v) return;
        const [iss, tx] = v.split('|');
        document.getElementById('cd-gen-issuer').value = iss || '';
        document.getElementById('cd-gen-taxon').value  = tx  || '';
    };

    window.cdGenAddSource = function () {
        const iss = (document.getElementById('cd-gen-issuer').value || '').trim();
        const tx  = (document.getElementById('cd-gen-taxon').value || '').trim();
        if (!/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/.test(iss)) { showToast('That does not look like an XRPL address', 'error'); return; }
        // ⚠ Taxon is REQUIRED, not defaulted. mode=verify rejects an issuer-wide
        // request outright, so a blank here would fail at the endpoint with a
        // message the creator cannot act on.
        if (tx === '' || isNaN(parseInt(tx, 10)) || parseInt(tx, 10) < 0) {
            showToast('Taxon is required \u2014 the ledger cannot return all of an issuer\u2019s collections at once', 'error'); return;
        }
        const taxon = parseInt(tx, 10);
        if (cdGen.sources.some(x => x.issuer === iss && x.taxon === taxon)) { showToast('That collection is already in the list', 'error'); return; }

        const known = (typeof cdStoreTaxons === 'function' ? cdStoreTaxons() : [])
            .find(r => r.issuer === iss && String(r.taxon) === String(taxon));
        const src = { issuer: iss, taxon: taxon, name: known ? known.name : (iss.slice(0, 8) + '\u2026 \u00b7 ' + taxon),
                      indexed: null, verified: null, rows: null, state: 'queued', note: '' };
        cdGen.sources.push(src);
        document.getElementById('cd-gen-issuer').value = '';
        document.getElementById('cd-gen-taxon').value = '';
        const pk = document.getElementById('cd-gen-pick'); if (pk) pk.value = '';
        cdGenRenderList();
        cdGenLoad(src);
    };

    window.cdGenRemove = function (i) {
        cdGen.sources.splice(i, 1);
        cdGenRenderList();
        cdGenPreview();
    };

    // Merge, filter, preview. All client-side on data already fetched.
    function cdGenMerge() {
        const rule = (document.getElementById('cd-gen-merge') || {}).value || 'sum';
        const min  = Math.max(1, parseInt((document.getElementById('cd-gen-min') || {}).value, 10) || 1);
        const req  = (document.getElementById('cd-gen-req') || {}).value || 'any';
        const each = Math.max(1, parseInt((document.getElementById('cd-gen-each') || {}).value, 10) || 1);

        const ready = cdGen.sources.filter(s => s.state === 'done' && Array.isArray(s.rows));
        const acc = {};
        let seen = 0;
        ready.forEach(s => {
            s.rows.forEach(w => {
                const addr = w.account || w.wallet;
                const held = Number(w.held) || 0;
                if (!addr || held <= 0) return;
                seen++;
                // per-source holdings kept alongside the merged total: the AND rule
                // and the per-collection minimum both need the breakdown, and it is
                // gone once the amounts are added together.
                if (!acc[addr]) acc[addr] = { held: held, sources: 1, per: [held] };
                else {
                    acc[addr].sources++;
                    acc[addr].per.push(held);
                    // SUM is the default because it is what "how much of mine do
                    // they hold" usually means, and it cannot surprise anyone
                    // downward. MAX rewards depth in one collection instead.
                    acc[addr].held = (rule === 'max') ? Math.max(acc[addr].held, held)
                                                      : acc[addr].held + held;
                }
            });
        });

        const all = Object.keys(acc).map(a => ({ addr: a, held: acc[a].held, sources: acc[a].sources, per: acc[a].per }));
        let pool = all;
        let failedReq = 0;
        if (req === 'all' && ready.length > 1) {
            // ⚠ AND, not OR. "Must hold in every collection" is an INTERSECTION -
            // a wallet missing from even one source drops out however much it
            // holds elsewhere. And the per-collection minimum is checked against
            // the SMALLEST holding, not the total, or "at least 2 in each" would
            // pass on 5+0.
            pool = all.filter(x => {
                if (x.sources < ready.length) return false;
                return Math.min.apply(null, x.per) >= each;
            });
            failedReq = all.length - pool.length;
        }
        // ⚠ Minimum applies AFTER the merge. Per-source and post-merge give
        // different lists, and the creator must not have to guess which.
        const kept = pool.filter(x => x.held >= min).sort((a, b) => b.held - a.held);
        return { kept: kept, excluded: pool.length - kept.length, failedReq: failedReq,
                 overlap: all.filter(x => x.sources > 1).length, rows: seen, sources: ready.length };
    }

    // "Minimum in each" only means anything under the AND rule.
    window.cdGenSyncReq = function () {
        const req = (document.getElementById('cd-gen-req') || {}).value || 'any';
        const each = document.getElementById('cd-gen-each');
        if (each) each.disabled = (req !== 'all');
    };

    window.cdGenPreview = function () {
        const box = document.getElementById('cd-gen-prev');
        const btn = document.getElementById('cd-gen-apply');
        if (!box) return;
        const ready = cdGen.sources.filter(s => s.state === 'done').length;
        if (!ready) {
            box.textContent = cdGen.sources.length ? 'Reading the ledger\u2026' : 'Add a collection above to begin.';
            if (btn) btn.disabled = true; cdGenCsvBtn(true);
            return;
        }
        cdGenSyncReq();
        const m = cdGenMerge();
        if (!m.kept.length) {
            box.innerHTML = 'No wallets meet that minimum.';
            if (btn) btn.disabled = true; cdGenCsvBtn(true);
            return;
        }
        // Show what the cap actually DOES rather than only explaining it. The entry
        // keeps the true holding; the number in brackets is what they can mint.
        // The cap only bites on the two types that read an amount, so it is not
        // applied to the preview of a type that ignores them.
        const genType = (document.getElementById('cd-gen-type') || {}).value;
        const capApplies = (genType === 'discount_limited' || genType === 'limit_override');
        const cap = capApplies ? (parseInt((document.getElementById('cd-gen-cap') || {}).value, 10) || 0) : 0;
        const capped = cap > 0 ? m.kept.filter(x => x.held > cap).length : 0;
        const top = m.kept.slice(0, 5).map(x => {
            const eff = (cap > 0 && x.held > cap) ? ` <span class="cd-gen-warn">\u2192 mints ${cap}</span>` : '';
            return `${escapeHtml(x.addr.slice(0, 8))}\u2026${escapeHtml(x.addr.slice(-4))} <b>${x.held}</b>${eff}`;
        }).join(' &middot; ');
        const lo = m.kept[m.kept.length - 1].held, hi = m.kept[0].held;
        box.innerHTML = `<b>${m.kept.length}</b> wallet${m.kept.length === 1 ? '' : 's'}`
            + ` &middot; amounts ${lo}\u2013${hi}`
            + (m.overlap ? ` &middot; ${m.overlap} held in more than one` : '')
            + (m.excluded ? ` &middot; ${m.excluded} below the minimum` : '')
            + (m.failedReq ? ` &middot; ${m.failedReq} not in every collection` : '')
            + (capped ? ` &middot; <span class="cd-gen-warn">${capped} hold more than the ${cap}-mint cap</span>` : '')
            + `<br>${top}${m.kept.length > 5 ? ' &hellip;' : ''}`
            // ⚠ add_entries uses ON DUPLICATE KEY UPDATE, so a second run REPLACES
            // an existing amount rather than adding to it. Saying so here is the
            // difference between a re-run being safe and being a surprise.
            + `<br><span class="cd-gen-warn">Saving replaces any amount these wallets already have on this allowlist \u2014 it does not add to it.</span>`
            + (function () {
                // ⚠ THREE types ignore amounts, not one. $uses_quantity in
                // allowlist-handler.php is ['limit_override','discount_limited'] -
                // everything else stores custom_max_mint and never reads it.
                const t = (document.getElementById('cd-gen-type') || {}).value;
                const LBL = { early_access: 'Early Access', discount: 'Unlimited Discount', exclusive: 'Exclusive Only' };
                return LBL[t]
                    ? `<br><span class="cd-gen-warn">${LBL[t]} uses the wallet list but not the holdings \u2014 every wallet above is added, and the amounts are ignored.</span>`
                    : '';
            })()
            + (cdGen.sources.some(s => s.state === 'error') ? `<br><span class="cd-gen-warn">Some collections could not be read and are not included.</span>` : '');
        if (btn) btn.disabled = false; cdGenCsvBtn(false);
    };

    // Fills the rows the create form already owns. Save & Add Wallets then runs
    // the SAME path as a hand-typed list - one write path, one set of rules.
    function cdGenCsvBtn(disabled) {
        ['cd-gen-csv', 'cd-gen-list-btn'].forEach(id => {
            const b = document.getElementById(id);
            if (b) b.disabled = !!disabled;
        });
        if (disabled) { const f = document.getElementById('cd-gen-full'); if (f) f.hidden = true; }
    }

    // The full list, on screen, before anything is written. "Fill the wallet list"
    // was doing nothing visible when no source had finished verifying - this makes
    // the state legible rather than leaving a dead button.
    window.cdGenShowList = function () {
        const box = document.getElementById('cd-gen-full');
        if (!box) return;
        if (!box.hidden) { box.hidden = true; return; }
        const m = cdGenMerge();
        if (!m.kept.length) { box.hidden = true; return; }
        box.innerHTML = `<b>${m.kept.length}</b> wallet${m.kept.length === 1 ? '' : 's'} \u2014 this is exactly what will be written:<br>`
            + m.kept.map(x => `${escapeHtml(x.addr)},${x.held}`).join('<br>');
        box.hidden = false;
        box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };

    // A RECORD of the snapshot, not a route into creation - "Fill the wallet list"
    // is that route, and it keeps one write path. This is for proving a snapshot
    // was fair, reusing the same cohort later, or anything outside IMC. It carries
    // the ledger each source was read at, because a holder list without the ledger
    // it came from cannot be checked afterwards.
    window.cdGenCsv = function () {
        const m = cdGenMerge();
        if (!m.kept.length) return;
        const esc = v => `"${String(v == null ? '' : v).replace(/"/g, '""')}"`;
        // ⚠ VERIFIED AGAINST THE REAL IMPORTER. upload_csv finds the wallet column
        // by address regex, then expands outward for the nearest number - so
        // "wallet",held,sources parses to the right amount. The header and the
        // # provenance lines carry no valid address and are skipped.
        // The one rough edge is that skipped lines are COUNTED as invalid and
        // reported back, so a clean round-trip would say "6 invalid rows". The
        // provenance therefore goes in a LEADING block, and the note below tells
        // the creator what to expect if they re-import this file.
        const lines = [];
        const rule = (document.getElementById('cd-gen-merge') || {}).value || 'sum';
        const req  = (document.getElementById('cd-gen-req') || {}).value || 'any';
        lines.push('# Allowlist snapshot \u2014 read live from the XRPL');
        cdGen.sources.filter(s => s.state === 'done').forEach(s => {
            lines.push(`# source,${esc(s.name)},issuer=${s.issuer},taxon=${s.taxon},holders=${s.verified}`);
        });
        lines.push(`# generated,${new Date().toISOString()}`);
        lines.push(`# merge,${rule},requirement,${req}`);
        lines.push('# Re-importing this file is safe \u2014 these # lines and the header are');
        lines.push('# skipped, and may be reported as skipped rows.');
        lines.push('wallet,held,collections_held_in');
        m.kept.forEach(x => lines.push([esc(x.addr), x.held, x.sources].join(',')));

        const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'allowlist-snapshot-' + new Date().toISOString().slice(0, 10) + '.csv';
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(url), 1000);
        showToast(`Exported ${m.kept.length} wallet${m.kept.length === 1 ? '' : 's'}`, 'success');
    };

    window.cdGenApply = function () {
        const m = cdGenMerge();
        if (!m.kept.length) return;
        const wrap = document.getElementById('inline-wallet-rows');
        if (wrap) wrap.innerHTML = '';
        m.kept.forEach(x => cdAddWalletRow(x.addr, x.held));
        if (typeof cdSerialiseWalletRows === 'function') cdSerialiseWalletRows();
        // The cap belongs to the ALLOWLIST, not to the generated rows, so it is
        // handed to the create form's own field rather than clamped into amounts.
        // Entries still record the true holding - that was the whole reason
        // allowlist_holder_limit exists.
        // ⚠ SET THE TYPE FIRST. The form defaults to Early Access, which appears in
        // neither allocation branch - it never reads custom_max_mint. Filling a
        // holder list into it wrote every amount and then ignored all of them.
        const typeIn = document.getElementById('cd-gen-type');
        const typeOut = document.getElementById('allowlist-type');
        if (typeIn && typeOut) {
            typeOut.value = typeIn.value;
            if (typeof cdUpdateAllowlistTypeFields === 'function') cdUpdateAllowlistTypeFields();
        }

        const capIn = document.getElementById('cd-gen-cap');
        const capOut = document.getElementById('allowlist-holder-limit');
        if (capIn && capOut && capIn.value !== '') capOut.value = capIn.value;
        cdGenClose();
        const create = document.getElementById('cd-al-create');
        if (create) { create.hidden = false; create.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
        showToast(`${m.kept.length} wallet${m.kept.length === 1 ? '' : 's'} added \u2014 review, then Save & Add Wallets`, 'success');
    };

    // Indexed first (instant), then the live walk. Both are shown.
    async function cdGenLoad(src) {
        const base = CD_STORE_API + '?action=holders&issuer=' + encodeURIComponent(src.issuer)
                   + '&taxon=' + encodeURIComponent(src.taxon);
        try {
            const r = await fetch(base + '&limit=1&offset=0');
            const d = await r.json();
            if (d && d.success) { src.indexed = d.distinct_holders ?? null; src.state = 'indexed'; cdGenRenderList(); }
        } catch (e) { /* the verified read is what matters */ }
        cdGenVerify(src);
    }

    // ⚠ PACED. The endpoint allows 6 verifications per 60s per IP and answers a
    // 7th with HTTP 429. A cache HIT does not consume the budget, so re-running
    // the same sources is nearly free - only genuinely new walks are counted.
    // A 429 is a WAIT, not a failure: the sources already read are never discarded.
    let cdGenBudget = [];
    async function cdGenVerify(src) {
        while (cdGen.busy) { await new Promise(r => setTimeout(r, 250)); }
        cdGen.busy = true;
        try {
            for (let attempt = 0; attempt < 12; attempt++) {
                cdGenBudget = cdGenBudget.filter(t => t > Date.now() - 60000);
                if (cdGenBudget.length >= 6) {
                    const waitMs = 60000 - (Date.now() - cdGenBudget[0]) + 500;
                    src.note = 'waiting for the ledger rate limit\u2026';
                    cdGenRenderList();
                    await new Promise(r => setTimeout(r, Math.max(1000, waitMs)));
                    continue;
                }
                const url = CD_STORE_API + '?action=holders&issuer=' + encodeURIComponent(src.issuer)
                          + '&taxon=' + encodeURIComponent(src.taxon) + '&mode=verify&limit=500&offset=0';
                const r = await fetch(url);
                if (r.status === 429) { await new Promise(x => setTimeout(x, 10000)); continue; }
                const d = await r.json();
                if (!d || !d.success) { src.state = 'error'; src.note = (d && d.error) || 'could not read'; break; }

                // A cache hit costs nothing; only a real walk joins the budget.
                if (d.source !== 'cache') cdGenBudget.push(Date.now());

                let rows = Array.isArray(d.holders) ? d.holders.slice() : [];
                const total = Number(d.distinct_holders) || rows.length;
                // 500 per page - page the rest at the SAME verified ledger.
                let off = rows.length, guard = 0;
                while (rows.length < total && off > 0 && guard++ < 20) {
                    const rp = await fetch(url.replace('&offset=0', '&offset=' + off));
                    const dp = await rp.json();
                    if (!dp || !dp.success || !Array.isArray(dp.holders) || !dp.holders.length) break;
                    rows = rows.concat(dp.holders);
                    off += dp.holders.length;
                }
                src.rows = rows;
                src.verified = total;
                src.state = 'done';
                src.note = d.truncated ? '\u26a0 the ledger read was incomplete' : (d.source === 'cache' ? 'from a recent read' : '');
                break;
            }
            if (src.state !== 'done' && src.state !== 'error') { src.state = 'error'; src.note = 'timed out'; }
        } catch (e) {
            src.state = 'error'; src.note = 'could not reach the ledger index';
        } finally {
            cdGen.busy = false;
            cdGenRenderList();
            cdGenPreview();
        }
    }

    function cdGenRenderList() {
        const ul = document.getElementById('cd-gen-list');
        if (!ul) return;
        ul.innerHTML = cdGen.sources.map((s, i) => {
            let right;
            if (s.state === 'queued')        right = '<span class="cd-gen-count">waiting\u2026</span>';
            else if (s.state === 'indexed')  right = `<span class="cd-gen-count">${s.indexed} indexed \u00b7 verifying\u2026</span>`;
            else if (s.state === 'done') {
                // Showing BOTH is the point: a difference is stream drift made
                // visible, and it tells the creator the list is ledger-built.
                const drift = (s.indexed !== null && s.indexed !== s.verified);
                right = `<span class="cd-gen-count"><b>${s.verified}</b> on ledger`
                      + (drift ? ` <span class="cd-gen-drift" title="Our index said ${s.indexed}. The ledger is the authority.">(index said ${s.indexed})</span>` : '')
                      + (s.note ? ` \u00b7 ${escapeHtml(s.note)}` : '') + '</span>';
            }
            else right = `<span class="cd-gen-count cd-gen-warn">${escapeHtml(s.note || 'could not read')}</span>`;
            return `<li><span class="cd-gen-name">${escapeHtml(s.name)}</span>${right}
                <button type="button" class="cd-gen-x" onclick="cdGenRemove(${i})" title="Remove">\u2715</button></li>`;
        }).join('');
    }

    // Presents the SAME shape a single-taxon response does - points, sale_points,
    // currencies, sales_since, sale_points_truncated - so cdStoreRender,
    // cdAnDrawHistory and cdAnDrawSales all keep working untouched. Diverging here
    // would mean editing three consumers instead of one loader.
    function cdHistMerge(list) {
        const ok = (list || []).filter(d => d && d.success);
        if (!ok.length) return null;

        // ⚠ LISTINGS: build the DATE UNION first, then sum what exists. A taxon with
        // no snapshot on a day must contribute 0, never a gap - taxons are walked
        // independently, so an un-walked day would otherwise DIP the total and read
        // as listings being withdrawn when nothing changed.
        const dates = [...new Set(ok.flatMap(d => (d.points || []).map(p => p.date)))].sort();
        const byDate = {};
        ok.forEach(d => (d.points || []).forEach(p => {
            if (!byDate[p.date]) byDate[p.date] = { listed: 0, holders: 0, nfts: 0 };
            byDate[p.date].listed  += Number(p.listed_count) || 0;
            byDate[p.date].holders += Number(p.distinct_holders) || 0;
            byDate[p.date].nfts    += Number(p.total_nfts) || 0;
        }));
        const points = dates.map(dt => ({
            date: dt,
            listed_count: byDate[dt].listed,
            distinct_holders: byDate[dt].holders,
            total_nfts: byDate[dt].nfts,
            // ⚠ floor is DELIBERATELY null on a merged series. There is no floor
            // across unrelated collections - MIN would report the cheapest thing
            // the creator sells and call it their floor. The card explains instead.
            floor_xrp: null,
            top10_share_pct: null
        }));

        // SALES: a plain union. Each point carries its own `cur`, so the currency
        // selector keeps working; the counts are summed per currency.
        const sale_points = ok.flatMap(d => d.sale_points || [])
            .sort((a, b) => String(a.t).localeCompare(String(b.t)));
        const currencies = {};
        ok.forEach(d => Object.keys(d.currencies || {}).forEach(c => {
            currencies[c] = (currencies[c] || 0) + Number(d.currencies[c] || 0);
        }));

        // ⚠ EARLIEST since-date, and truncated if ANY source was: a partial read in
        // one collection makes the pooled chart partial.
        const sinces = ok.map(d => d.sales_since).filter(Boolean).sort();
        return {
            success: true,
            merged: true,
            points: points,
            count: points.length,
            sale_points: sale_points,
            sale_points_truncated: ok.some(d => !!d.sale_points_truncated),
            currencies: currencies,
            sales_since: sinces.length ? sinces[0] : null,
            truncated: ok.some(d => !!d.truncated)
        };
    }

    function cdStoreTaxons() {
        // One entry per (issuer, taxon). Built from allListings, so it reflects
        // exactly what this creator has - no separate lookup.
        const seen = {};
        (allListings || []).forEach(l => {
            const t = l.collection_taxon, iss = l.artist_account;
            if (t === null || t === undefined || t === '' || !iss) return;
            const k = iss + ':' + t;
            if (!seen[k]) seen[k] = { issuer: iss, taxon: t, name: l.collection_name || 'Uncategorized' };
        });
        return Object.keys(seen).map(k => seen[k]);
    }

    // R-7: one selector for the whole tab. It carries the collection NAME as its
    // value (that is what the analytics handler narrows on) and the matching taxon
    // in a data attribute (that is what the store endpoints need). One control,
    // two consumers, no chance of them disagreeing.
    function cdStoreFillPicker() {
        const sel = document.getElementById('cd-an-collection');
        if (!sel) return;
        const rows = cdStoreTaxons();
        const iss = rows.length ? rows[0].issuer : '';
        const cur = sel.value || 'all';
        sel.innerHTML = `<option value="all" data-taxon="all">All collections</option>`
            + rows.map(r => `<option value="${escapeHtml(r.name)}" data-taxon="${escapeHtml(String(r.taxon))}">${escapeHtml(r.name)}</option>`).join('');
        sel.dataset.issuer = iss;
        const names = rows.map(r => r.name);
        sel.value = names.indexOf(cur) !== -1 ? cur : 'all';
    }

    function cdAnScope() {
        const sel = document.getElementById('cd-an-collection');
        if (!sel) return { name: 'all', taxon: 'all', issuer: '' };
        const opt = sel.options[sel.selectedIndex];
        return { name: sel.value || 'all',
                 taxon: (opt && opt.dataset.taxon) || 'all',
                 issuer: sel.dataset.issuer || '' };
    }

    function cdStoreKill() {
        Object.keys(cdStore.charts).forEach(k => {
            try { cdStore.charts[k].destroy(); } catch (e) {}
            delete cdStore.charts[k];
        });
    }

    async function cdLoadStore(which) {
        const body = document.getElementById('cd-store-body');
        const sel  = document.getElementById('cd-an-collection');
        if (!body || !sel) return;
        const issuer = sel.dataset.issuer;
        if (!issuer) { body.innerHTML = '<p class="cd-store-note">No on-ledger collections found for this account.</p>'; return; }

        // Guard against a late response painting under the wrong selection: open
        // A then B quickly and A's reply must be discarded. B-e's card facts were
        // creator-wide so ordering never mattered; these are per-collection.
        const myToken = ++cdStore.token;
        const key = String(which);

        if (cdStore.cache[key]) { cdStoreRender(cdStore.cache[key], key); return; }
        body.innerHTML = '<div class="cd-loading"><div class="cd-spinner"></div><span>Reading the ledger index...</span></div>';

        try {
            const isAll = (key === 'all');
            const hUrl = CD_STORE_API + '?action=holders&issuer=' + encodeURIComponent(issuer)
                       + (isAll ? '' : '&taxon=' + encodeURIComponent(key)) + '&limit=10&offset=0';
            // ── FINAL PHASE · MERGE ACROSS TAXONS ─────────────────────────
            // All collections used to skip history entirely, so Sale prices, Floor
            // and Listings all vanished - three empty slots and a tab that read as
            // half-finished. holders_history is SQLite-only (holders_cache +
            // nft_sales): no Clio, no rate limiter, so one call per taxon is cheap.
            // ⚠ NOT the same cost profile as action=holders&mode=verify, which IS a
            // live Clio walk behind 6/min. The two look alike and are not.
            const hist1 = t => fetch(CD_STORE_API + '?action=holders_history&issuer=' + encodeURIComponent(issuer)
                + '&taxon=' + encodeURIComponent(t) + '&days=90').then(r => r.json()).catch(() => null);

            let histPromise;
            if (isAll) {
                const taxons = (typeof cdStoreTaxons === 'function' ? cdStoreTaxons() : [])
                    .filter(r => r.issuer === issuer).map(r => r.taxon);
                histPromise = taxons.length
                    ? Promise.all(taxons.map(hist1)).then(cdHistMerge)
                    : Promise.resolve(null);
            } else {
                histPromise = hist1(key);
            }
            const [h, hist] = await Promise.all([
                fetch(hUrl).then(r => r.json()).catch(() => null),
                histPromise
            ]);
            if (myToken !== cdStore.token) return;            // superseded
            const payload = { holders: h, history: hist || null };
            cdStore.cache[key] = payload;
            cdStoreRender(payload, key);
        } catch (err) {
            if (myToken !== cdStore.token) return;
            console.warn('store panel unavailable:', err);
            body.innerHTML = '<p class="cd-store-note">The on-ledger index could not be reached just now. Your listing figures above are unaffected.</p>';
        }
    }

    // R-7: holder distribution, from the `distribution` field action=holders already
    // returns. Bars rather than Chart.js - five buckets do not need a chart library,
    // and this degrades to readable text if it is unavailable (the B-f lesson).
    // ⚠ R-7b FIX: the distribution card lives INSIDE #cd-an-body, and cdAnRender()
    // rebuilds that whole block. So whichever call finished last won: the store
    // filled the panel, then the analytics response landed and wiped it back to
    // the placeholder - which is why it sat on "Reading the ledger index".
    //
    // Two independent async sources writing one container is a race, not a
    // sequence. The holders payload is now cached and REPLAYED after every
    // rebuild, so the order the two responses arrive in stops mattering.
    let cdAnLastHolders = null;

    // ── R-7 · TOP HOLDERS: view-all + CSV ─────────────────────────────────
    // The card shows the top 10 from the analytics endpoint (mints per buyer, from
    // OUR purchase rows). These two read action=holders instead - the LEDGER view,
    // which is who holds the NFTs now rather than who minted them. Different
    // questions, so the note says which is which.
    //
    // The indexed holders path is NOT rate-limited (the 6/min limiter sits inside
    // the verify branch), so paging is safe.
    function cdHoldersUrl(limit, offset) {
        const sc = cdAnScope();
        return CD_STORE_API + '?action=holders&issuer=' + encodeURIComponent(sc.issuer)
             + (sc.taxon !== 'all' ? '&taxon=' + encodeURIComponent(sc.taxon) : '')
             + '&limit=' + limit + '&offset=' + offset;
    }

    window.cdHoldersViewAll = async function () {
        const ul = document.getElementById('cd-hold-rows');
        const note = document.getElementById('cd-hold-note');
        if (!ul) return;
        if (note) note.textContent = 'Loading\u2026';
        try {
            const r = await fetch(cdHoldersUrl(100, 0));
            const d = await r.json();
            const rows = (d && d.success && Array.isArray(d.holders)) ? d.holders : [];
            if (!rows.length) { if (note) note.textContent = 'No on-ledger holders for this scope.'; return; }
            ul.innerHTML = rows.map((w, i) =>
                `<li><span>${i + 1}. ${escapeHtml(String(w.account || w.wallet || '').slice(0, 8))}\u2026${escapeHtml(String(w.account || w.wallet || '').slice(-4))}</span><span>${escapeHtml(String(w.held ?? w.mints ?? ''))}</span></li>`).join('');
            // R-9a: truncated surfaces here too. A partial ledger walk that reports
            // itself as complete is the exact failure the store work spent a day on;
            // showing the number without the caveat repeats it at the UI layer.
            if (note) note.textContent = `Top ${rows.length} by NFTs currently held, from the ledger index`
                + (d.distinct_holders ? ` \u00b7 ${d.distinct_holders} holders in total.` : '.')
                + (d.truncated ? ' \u26a0 This reading was incomplete when taken.' : '');
        } catch (e) {
            if (note) note.textContent = 'Could not reach the ledger index just now.';
        }
    };

    window.cdHoldersCsv = async function (btn) {
        const note = document.getElementById('cd-hold-note');
        const label = btn ? btn.textContent : '';
        if (btn) { btn.disabled = true; btn.textContent = 'Preparing\u2026'; }
        try {
            // ⚠ PAGED, NOT CAPPED. The endpoint maxes at 500 per request, and a
            // creator can exceed that issuer-wide. Stopping early and calling the
            // file "all holders" would be a quiet lie - the same class
            // of error as reporting a truncated ledger walk as complete.
            const PAGE = 500;
            let offset = 0, all = [], total = null, guard = 0, truncatedSeen = false;
            while (guard++ < 40) {
                const r = await fetch(cdHoldersUrl(PAGE, offset));
                const d = await r.json();
                if (!d || !d.success) break;
                if (total === null) total = Number(d.distinct_holders) || null;
                if (d.truncated) truncatedSeen = true;      // R-9a
                const rows = Array.isArray(d.holders) ? d.holders : [];
                all = all.concat(rows);
                if (rows.length < PAGE) break;
                offset += PAGE;
                if (total !== null && all.length >= total) break;
            }
            if (!all.length) { if (note) note.textContent = 'Nothing to export for this scope.'; return; }

            const sc = cdAnScope();
            const esc = v => `"${String(v == null ? '' : v).replace(/"/g, '""')}"`;
            const lines = ['rank,wallet,held,share_pct'];
            const supply = all.reduce((a, w) => a + (Number(w.held) || 0), 0) || 1;
            all.forEach((w, i) => {
                const held = Number(w.held) || 0;
                lines.push([i + 1, esc(w.account || w.wallet || ''), held,
                            (held * 100 / supply).toFixed(4)].join(','));
            });
            const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'holders-' + (sc.taxon === 'all' ? 'all-collections' : sc.taxon) + '.csv';
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            if (note) note.textContent = `Exported ${all.length} holders`
                + (total && all.length < total ? ` of ${total} \u2014 the index returned fewer than expected.` : '.')
                + (truncatedSeen ? ' \u26a0 At least one page was an incomplete reading.' : '');
        } catch (e) {
            if (note) note.textContent = 'Export failed \u2014 the ledger index could not be reached.';
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = label; }
        }
    };

    function cdAnRenderDist(h) {
        if (h !== undefined) cdAnLastHolders = h;      // remember the latest payload
        const box = document.getElementById('cd-an-dist');
        if (!box) return;
        h = cdAnLastHolders;
        if (h === null) return;                        // nothing fetched yet - leave the placeholder
        const d = (h && h.success && Array.isArray(h.distribution)) ? h.distribution : [];
        if (!d.length) {
            box.innerHTML = '<p class="cd-an-note">No on-ledger holders recorded for this scope yet.</p>';
            return;
        }
        // ⚠ R-9a FIX: the endpoint returns each bucket as
        //     ['label' => ..., 'holders' => ..., 'pct' => ...]
        // and this read x.wallets, which does not exist - so every bucket rendered
        // 0 beside the true holder total. It failed in the worst way available:
        // x.label DID resolve, so the buckets came out correctly named with empty
        // bars and looked finished. Reading `pct` from the payload too rather than
        // recomputing it - the endpoint already divides by distinct_holders.
        const max = Math.max.apply(null, d.map(x => Number(x.holders) || 0)) || 1;
        box.innerHTML = d.map(x => {
            const w = Number(x.holders) || 0;
            const pct = Math.round((w / max) * 100);
            return `<div class="cd-dist-row">
                <span>${escapeHtml(String(x.label || ''))}</span>
                <span class="cd-dist-bar"><i style="width:${pct}%"></i></span>
                <b>${w}</b>
            </div>`;
        }).join('')
        + `<p class="cd-an-note">Wallets grouped by how many of these NFTs they hold\u00a0\u00b7 ${escapeHtml(String(h.distinct_holders ?? '\u2014'))} holders in total.</p>`
        + (h.truncated ? `<p class="cd-an-note cd-store-warn">\u26a0 This reading was incomplete when it was taken, so the counts above may be low.</p>` : '');
    }

    function cdStoreRender(p, key) {
        const body = document.getElementById('cd-store-body');
        if (!body) return;
        cdStoreKill();
        const h = p.holders;
        // R-7: before the gate below - a failed holders call must still resolve the
        // distribution panel rather than leaving it on "Reading the ledger index".
        cdAnRenderDist(h);
        // A1: the same payload carries the floor and listed series. cdStoreRender
        // is where holders_history lands, so this is the one place it is in hand.
        try { cdAnDrawHistory(p.history || p.hist || null); } catch (e) { console.warn('history charts:', e); }
        try { cdAnDrawSales(p.history || p.hist || null); } catch (e) { console.warn('sales chart:', e); }
        if (!h || !h.success) {
            body.innerHTML = '<p class="cd-store-note">No on-ledger data for this collection yet.</p>';
            return;
        }
        const isAll = (key === 'all');
        const pts = (p.history && p.history.success && Array.isArray(p.history.points)) ? p.history.points : [];

        body.innerHTML = `
            <div class="cd-store-kpis">
                <div class="cd-store-kpi cd-surface"><div class="v">${h.distinct_holders ?? '\u2014'}</div><div class="l">Holders</div></div>
                <div class="cd-store-kpi cd-surface"><div class="v">${h.total_nfts ?? '\u2014'}</div><div class="l">On ledger</div></div>
                <div class="cd-store-kpi cd-surface"><div class="v">${h.top10_share_pct ?? '\u2014'}%</div><div class="l">Top 10 hold</div></div>
                <div class="cd-store-kpi cd-surface"><div class="v">${h.single_holders ?? '\u2014'}</div><div class="l">Hold just one</div></div>
            </div>
            ${isAll ? `<p class="cd-store-note">Across every collection you have minted. Holders is a
                       <strong>true count of distinct wallets</strong>, not the sum of each collection's
                       holders \u2014 anyone holding from two of your collections is counted once.
                       History is per collection; pick one to see it over time.</p>`
                    : `<div class="cd-an-canvas-wrap"><canvas id="cd-store-hist"></canvas></div>
                       <p class="cd-store-note" id="cd-store-histnote"></p>`}
            ${h.truncated ? `<p class="cd-store-note cd-store-warn">\u26a0 This reading was incomplete when it was taken, so it may under-report.</p>` : ''}`;

        if (isAll || typeof Chart === 'undefined') return;

        if (!pts.length) {
            const n = document.getElementById('cd-store-histnote');
            if (n) n.textContent = 'Daily readings for this collection start building from its first snapshot \u2014 nothing recorded yet.';
            const w = document.querySelector('#cd-store-body .cd-an-canvas-wrap');
            if (w) w.style.display = 'none';
            return;
        }

        cdStore.charts.hist = new Chart(document.getElementById('cd-store-hist').getContext('2d'), {
            type: 'line',
            data: { labels: pts.map(p2 => p2.date), datasets: [
                { label: 'Holders', data: pts.map(p2 => p2.distinct_holders),
                  borderColor: '#4da3ff', backgroundColor: 'rgba(77,163,255,0.12)',
                  borderWidth: 2, tension: 0.3, fill: true,
                  pointRadius: pts.length === 1 ? 5 : 0, pointHoverRadius: 5 },
                { label: 'On ledger', data: pts.map(p2 => p2.total_nfts),
                  borderColor: '#d6ba66', borderWidth: 2, borderDash: [4, 3],
                  tension: 0.3, fill: false,
                  pointRadius: pts.length === 1 ? 5 : 0, pointHoverRadius: 5 }
            ] },
            options: cdAnAxes()
        });
        const note = document.getElementById('cd-store-histnote');
        if (note) {
            // Playbook rule 4: ship the chart with one point and say when it began,
            // rather than hiding it. Snapshots started 8 Sep 2026.
            note.textContent = pts.length === 1
                ? `First daily reading \u2014 recorded ${pts[0].date}.`
                : `${pts.length} daily readings \u00b7 ${pts[0].date} to ${pts[pts.length - 1].date}.`;
        }
    }

    // R-7: one change handler for the whole tab. The analytics call is refetched
    // (the narrowing is server-side - it cannot be done to a response already
    // received) and the store card is repointed at the matching taxon.
    document.addEventListener('change', function (e) {
        if (!e.target || e.target.id !== 'cd-an-collection') return;
        const sc = cdAnScope();
        cdAn.loaded = null;                    // force a refetch at the new scope
        cdLoadAnalytics(true);
        cdLoadStore(sc.taxon);
    });
    window.cdLoadStore = cdLoadStore;
    window.cdStoreFillPicker = cdStoreFillPicker;

    // ── D-B2 · HEADER ACTIVITY WINDOW ───────────────────────────────
    // Separate from cdAn.win on purpose. If the header shared the Analytics tab's
    // window, opening that tab and picking 24h would silently re-scope the header
    // too - a number changing because you looked somewhere else.
    let cdHdrWin = '30d';
    let cdHdrData = null;

    const CD_WIN_LABEL = { '24h':'last 24 hours', '7d':'last 7 days', '30d':'last 30 days',
                           '90d':'last 90 days', '1y':'last year', 'all':'all time' };

    async function cdLoadHeaderActivity() {
        try {
            const url = `${CONFIG.endpoints.creatorAnalytics}?action=summary`
                      + `&account=${encodeURIComponent(CONFIG.account)}`
                      + `&window=${encodeURIComponent(cdHdrWin)}&t=${Date.now()}`
                      + `&nonce=${encodeURIComponent(CONFIG.nonce)}`;
            const res = await fetch(url, { credentials: 'include' });
            const data = await res.json();
            if (!data || !data.success) return;
            cdHdrData = data;
            cdRenderHeaderActivity();
        } catch (err) {
            // Non-fatal: the inventory figures are already painted and stay correct.
            console.warn('header activity unavailable:', err);
        }
    }

    function cdRenderHeaderActivity() {
        if (!cdHdrData) return;
        const per = document.getElementById('stat-minted-period');
        const rev = document.getElementById('stat-revenue');
        const sub = document.getElementById('stat-revenue-sub');
        const when = CD_WIN_LABEL[cdHdrWin] || cdHdrWin;

        if (per) {
            const m = cdHdrData.totals ? cdHdrData.totals.mints : 0;
            per.textContent = `${m} minted ${when}`;
        }

        // Never summed across currencies. The largest by mint count leads; the rest
        // sit beneath it, each in its own unit.
        const cur = (cdHdrData.byCurrency || []).slice();
        if (rev && sub) {
            if (!cur.length) {
                rev.textContent = '0';
                sub.textContent = `no paid mints ${when}`;
            } else {
                // R-1: lead with a currency that HAS a known amount (XRP), not simply
                // the one with most mints - otherwise the headline could be a dash
                // while a real number sits in the sub-line.
                const withAmt = cur.filter(c => c.amount_known !== false && c.amount !== null);
                const primary = withAmt[0] || cur[0];
                const others  = cur.filter(c => c !== primary);
                // R-1b: amount_fmt already carries the currency code, so the card
                // shows the compacted amount and the sub-line carries the rest.
                const pr = cdCurLine(primary);
                rev.textContent = pr.unknown ? '\u2014' : (primary.amount_fmt || String(primary.amount));
                sub.textContent = (others.length
                        ? others.map(c => {
                            const r = cdCurLine(c);
                            return r.unknown ? `${c.currency} ${c.mints} mints` : r.value;
                          }).join(' \u00b7 ') + ' \u00b7 '
                        : '')
                    + when;
            }
        }
    }

    // R-3: the control became a <select>, so this binds to CHANGE, not click, and
    // there is no .active class to manage - the option's selected state is the
    // control's own state. Converting the markup WITHOUT this would have left the
    // window inert and silent: the header would simply never re-scope, which reads
    // as "the data is wrong" rather than "the control is broken".
    // Still scoped by id. D-B2 shipped a bug from an unscoped '.cd-an-win'
    // selector binding the Analytics pills too; that selector is now gone from
    // the header entirely, but the discipline stands.
    (function () {
        const sel = document.getElementById('cd-win-select');
        if (!sel) return;
        sel.addEventListener('change', function () {
            cdHdrWin = this.value;
            cdLoadHeaderActivity();
        });
    })();
    window.cdLoadHeaderActivity = cdLoadHeaderActivity;

    // ── B-e · PER-COLLECTION FACTS FOR THE CARDS ────────────────────
    // Deliberately its OWN request at window=all, not a reuse of cdAn's data:
    //   - cdAn only runs when the Analytics tab is opened; the Collections tab
    //     may never trigger it
    //   - cdAn is window-scoped (30d by default), and a card's "last sold" must
    //     mean last EVER, which is how a creator will read it
    // Cached for the page's life; refreshed only when fetchListings() reloads.
    let cdCollFacts = null;            // { [collectionName]: {...} } or null

    async function cdLoadCollectionFacts() {
        try {
            const url = `${CONFIG.endpoints.creatorAnalytics}?action=summary`
                      + `&account=${encodeURIComponent(CONFIG.account)}&window=all&t=${Date.now()}`
                      + `&nonce=${encodeURIComponent(CONFIG.nonce)}`;
            const res = await fetch(url, { credentials: 'include' });
            const data = await res.json();
            if (!data || !data.success || !Array.isArray(data.collections)) return;
            const map = {};
            data.collections.forEach(c => { map[c.collection] = c; });
            cdCollFacts = map;
            renderCollections();           // repaint with the facts now present
        } catch (err) {
            // Non-fatal by design: the cards render fully without this.
            console.warn('collection facts unavailable:', err);
        }
    }

    // R-4: cdToggleCard removed with the card chevron - its only caller. An
    // exported function with no caller is how a file grows things nobody dares
    // delete later.

    // R-1: one place that decides how a currency row reads. Only XRP carries a
    // trustworthy amount - wp_imc_purchases never records what was paid in token
    // terms (price_xrp means what it says). For a token we show the mint count and
    // a dash, rather than print an XRP number under a token label.
    function cdCurLine(c) {
        const n = `${c.mints} mint${c.mints === 1 ? '' : 's'}`;
        const unknown = (c.amount_known === false || c.amount === null || c.amount === undefined);
        // R-1b: prefer the server's amount_fmt. Token totals are orders of magnitude
        // larger than XRP, so raw numbers
        // side by side read badly - the handler compacts them the same way
        // imc_amount_fmt() does on /stats. String(c.amount) is the fallback for a
        // cached response from before R-1b shipped.
        const shown = unknown ? '\u2014' : (c.amount_fmt || String(c.amount));
        return { label: `${c.currency} \u00b7 ${n}`, value: shown, unknown: unknown };
    }

    function cdAgo(ts) {
        if (!ts) return '—';
        const d = new Date(String(ts).replace(' ', 'T') + 'Z');
        if (isNaN(d)) return '—';
        const days = Math.floor((Date.now() - d.getTime()) / 86400000);
        if (days <= 0) return 'today';
        if (days === 1) return 'yesterday';
        if (days < 30) return days + 'd ago';
        if (days < 365) return Math.floor(days / 30) + 'mo ago';
        return Math.floor(days / 365) + 'y ago';
    }

    // ── D-D · ANALYTICS ────────────────────────────────────────────────────
    // Data comes from creator-analytics-handler.php (read-only, SELECT-only,
    // its own file - see the endpoint map note). Chart configs are ported from
    // trading.js's v29 collection charts so the two surfaces look like one
    // product: min:0 on every axis, pointRadius 5 when a series has a single
    // reading, and a note under each canvas saying what the reading count is.
    const cdAn = { charts: {}, win: '30d', loaded: null, busy: false };

    function cdAnKill() {
        Object.keys(cdAn.charts).forEach(k => {
            try { cdAn.charts[k].destroy(); } catch (e) {}
            delete cdAn.charts[k];
        });
    }
    // A2: one line PER CURRENCY. Currencies are never added together - there is
    // no honest way to combine them without a live price feed, which is the same
    // rule the revenue card states in its own note.
    function cdAnDrawRevenue(d) {
        const note = document.getElementById('cd-an-rev-note');
        const el = document.getElementById('cd-an-rev');
        if (!el) return;
        const rows = Array.isArray(d.seriesRevenue) ? d.seriesRevenue : [];
        if (!rows.length || typeof Chart === 'undefined') {
            if (note) note.textContent = rows.length
                ? 'Chart library unavailable \u2014 the totals below are unaffected.'
                : 'No revenue recorded in this window.';
            return;
        }
        const buckets = [...new Set(rows.map(r => r.bucket))].sort();
        const curs    = [...new Set(rows.map(r => r.currency))];
        const PALETTE = ['#d6ba66', '#60a5fa', '#34d399', '#f472b6', '#fbbf24', '#a78bfa'];
        const byKey = {};
        rows.forEach(r => { byKey[r.bucket + '|' + r.currency] = r; });

        let gaps = 0;
        const datasets = curs.map((c, i) => ({
            label: c,
            data: buckets.map(b => {
                const r = byKey[b + '|' + c];
                if (!r) return 0;
                if (!r.amount_known) { gaps++; return null; }   // unknown \u2260 zero
                return r.amount;
            }),
            borderColor: PALETTE[i % PALETTE.length],
            backgroundColor: PALETTE[i % PALETTE.length] + '33',
            spanGaps: false, tension: 0.3, borderWidth: 2, pointRadius: 0
        }));

        cdAn.charts.rev = new Chart(el.getContext('2d'), {
            type: 'line',
            data: { labels: buckets, datasets: datasets },
            options: cdAnAxes()
        });
        if (note) note.textContent = `${curs.join(' \u00b7 ')} \u2014 each in its own units, never added together.`
            + (gaps ? ` ${gaps} bucket${gaps === 1 ? '' : 's'} could not be priced and show as a gap.` : '');
    }

    // Returned on every analytics call since B-e and never drawn until now.
    function cdAnDrawByCollection(d) {
        const note = document.getElementById('cd-an-bycoll-note');
        const el = document.getElementById('cd-an-bycoll');
        if (!el) return;
        const rows = (Array.isArray(d.collections) ? d.collections : [])
            .filter(c => (c.mints || 0) > 0)
            .sort((a, b) => (b.mints || 0) - (a.mints || 0));
        if (!rows.length || typeof Chart === 'undefined') {
            if (note) note.textContent = rows.length
                ? 'Chart library unavailable.'
                : 'No mints in this window.';
            return;
        }
        cdAn.charts.bycoll = new Chart(el.getContext('2d'), {
            type: 'bar',
            data: { labels: rows.map(r => r.collection), datasets: [{
                label: 'Mints', data: rows.map(r => r.mints),
                backgroundColor: 'rgba(214,186,102,0.45)', borderColor: '#d6ba66', borderWidth: 1
            }] },
            options: cdAnAxes({ plugins: { legend: { display: false } } })
        });
        if (note) note.textContent = `${rows.length} collection${rows.length === 1 ? '' : 's'} with mints in this window.`;
    }

    // A3: SALE PRICES. Every field below is already in the holders_history payload.
    // cdAnSalesCur remembers the chosen currency across redraws; the selector is
    // built from `currencies`, which counts what this collection actually has, so
    // it never offers a currency with nothing in it.
    let cdAnSalesCur = null;

    function cdAnDrawSales(hist) {
        const card = document.getElementById('cd-an-sales-card');
        const note = document.getElementById('cd-an-sales-note');
        const sel  = document.getElementById('cd-an-sales-cur');
        const el   = document.getElementById('cd-an-sales');
        if (!card || !el) return;

        // Sale prices now renders on All collections from the pooled sale_points.
        // Priced secondary sales are a small share of the collection - split six ways
        // that is six empty charts; pooled it is one real one.
        card.style.display = '';

        const all  = (hist && hist.success && Array.isArray(hist.sale_points)) ? hist.sale_points : [];
        const curs = Object.keys((hist && hist.currencies) || {});
        if (sel) {
            sel.hidden = curs.length < 2;      // a selector of one is noise
            if (curs.length) {
                sel.innerHTML = curs.map(c => `<option value="${escapeHtml(c)}">${escapeHtml(c)} (${hist.currencies[c]})</option>`).join('');
                if (!cdAnSalesCur || curs.indexOf(cdAnSalesCur) === -1) cdAnSalesCur = curs[0];
                sel.value = cdAnSalesCur;
            }
        }

        const pts   = all.filter(p => p.cur === cdAnSalesCur);
        const other = all.length - pts.length;

        if (!pts.length || typeof Chart === 'undefined') {
            if (note) note.textContent = !all.length
                ? (hist && hist.sales_since
                    ? `No secondary sales recorded since ${escapeHtml(String(hist.sales_since))}.`
                    : 'No secondary sales recorded for this collection yet.')
                : (typeof Chart === 'undefined' ? 'Chart library unavailable.'
                    : `No ${escapeHtml(String(cdAnSalesCur))} sales in this period.`);
            return;
        }

        cdAn.charts.sales = new Chart(el.getContext('2d'), {
            type: 'scatter',
            data: { labels: pts.map(p => String(p.t).slice(0, 10)), datasets: [{
                data: pts.map((p, i) => ({ x: i, y: p.v })),
                // The alpha IS the heat map - two sales at the same price on the same
                // day overlap into a darker dot, so no binning is needed.
                backgroundColor: 'rgba(77,163,255,0.55)',
                borderColor: 'rgba(77,163,255,0.9)',
                borderWidth: 1, pointRadius: 4, pointHoverRadius: 7
            }] },
            options: cdAnAxes({ plugins: { legend: { display: false } } })
        });

        // ⚠ "since <date>", never an implied all-time. The ledger walk started on a
        // known day; everything before it is unrecorded, and calling that "all time"
        // would turn a gap in OUR data into a claim about the collection.
        const sc2 = (typeof cdAnScope === 'function') ? cdAnScope() : null;
        const pooled = (!sc2 || sc2.taxon === 'all');
        let n = `${pts.length} ${escapeHtml(String(cdAnSalesCur))} sale${pts.length === 1 ? '' : 's'}`
              + (pooled ? ' across all your collections' : '') + ` \u00b7 each dot is one trade`;
        if (hist.sales_since) n += ` \u00b7 recorded since ${escapeHtml(String(hist.sales_since))}`;
        if (other > 0) n += ` \u00b7 ${other} in other currencies not shown`;
        if (hist.sale_points_truncated) n += ' \u00b7 showing the most recent 2,000';
        if (note) note.textContent = n;
    }

    // A1: floor and listed count, from the holders_history payload R-9d already
    // fetches. Per-taxon only - holders_history requires an issuer AND a taxon.
    function cdAnDrawHistory(hist) {
        const scope = (typeof cdAnScope === 'function') ? cdAnScope() : { taxon: 'all' };
        const isAll = !scope || scope.taxon === 'all';

        // Listings now renders on All collections from the merged series; only FLOOR
        // stays per-collection, and it EXPLAINS rather than vanishing. A card that
        // disappears reads as a missing feature; one that says why reads as a
        // decision - the same reasoning as "gaps are days with no listings".
        const listedCard = document.getElementById('cd-an-listed-card');
        if (listedCard) listedCard.style.display = '';
        const floorCard = document.getElementById('cd-an-floor-card');
        if (floorCard) floorCard.style.display = '';

        if (isAll) {
            const fn = document.getElementById('cd-an-floor-note');
            const fc = document.getElementById('cd-an-floor');
            if (fc && fc.parentNode) fc.parentNode.style.display = 'none';
            if (fn) fn.innerHTML = 'Floor is measured <b>per collection</b> \u2014 there is no single floor across '
                + 'different collections, so pick one above to see it.';
        } else {
            const fc = document.getElementById('cd-an-floor');
            if (fc && fc.parentNode) fc.parentNode.style.display = '';
        }

        const pts = (hist && hist.success && Array.isArray(hist.points)) ? hist.points : [];
        if (!pts.length || typeof Chart === 'undefined') {
            cards.forEach(([, , nid]) => {
                const n = document.getElementById(nid);
                if (n) n.textContent = pts.length ? 'Chart library unavailable.' : 'No daily readings yet for this collection.';
            });
            return;
        }
        const labels = pts.map(p => p.date);

        // ⚠ A NULL FLOOR MEANS NOTHING WAS LISTED THAT DAY - not a floor of zero.
        // The indexer says so in its own comment. Preserved as null with
        // spanGaps:false, or the line dives to the axis and shows a collapse that
        // never happened.
        const floorEl = isAll ? null : document.getElementById('cd-an-floor');
        if (floorEl) {
            const vals = pts.map(p => (p.floor_xrp === null || p.floor_xrp === undefined) ? null : Number(p.floor_xrp));
            const known = vals.filter(v => v !== null).length;
            cdAn.charts.floor = new Chart(floorEl.getContext('2d'), {
                type: 'line',
                data: { labels: labels, datasets: [{
                    label: 'Floor (XRP)', data: vals,
                    borderColor: '#d6ba66', backgroundColor: 'rgba(214,186,102,0.15)',
                    spanGaps: false, tension: 0.3, borderWidth: 2, pointRadius: 0, fill: true
                }] },
                options: cdAnAxes({ plugins: { legend: { display: false } } })
            });
            const n = document.getElementById('cd-an-floor-note');
            if (n) n.textContent = known
                ? `${known} of ${pts.length} days had something listed. Gaps are days with no listings, not a floor of zero.`
                : 'Nothing from this collection has been listed for sale in this period.';
        }

        const listEl = document.getElementById('cd-an-listed');
        if (listEl) {
            cdAn.charts.listed = new Chart(listEl.getContext('2d'), {
                type: 'line',
                data: { labels: labels, datasets: [{
                    label: 'Listed', data: pts.map(p => Number(p.listed_count) || 0),
                    borderColor: '#60a5fa', backgroundColor: 'rgba(96,165,250,0.15)',
                    tension: 0.3, borderWidth: 2, pointRadius: 0, fill: true
                }] },
                options: cdAnAxes({ plugins: { legend: { display: false } } })
            });
            const n = document.getElementById('cd-an-listed-note');
            if (n) n.textContent = `How many of this collection were listed for sale, day by day.`;
        }
    }

    function cdAnAxes(extra) {
        return Object.assign({
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { labels: { color: '#9aa0ab', boxWidth: 12 } } },
            scales: {
                x: { ticks: { color: '#9aa0ab', maxRotation: 0, autoSkip: true }, grid: { color: 'rgba(255,255,255,0.05)' } },
                y: { beginAtZero: true, min: 0, ticks: { color: '#9aa0ab', precision: 0 }, grid: { color: 'rgba(255,255,255,0.05)' } }
            }
        }, extra || {});
    }

    async function cdLoadAnalytics(force) {
        const body = document.getElementById('cd-an-body');
        if (!body) return;
        if (cdAn.busy) return;
        if (!force && cdAn.loaded === cdAn.win) return;
        cdAn.busy = true;
        body.innerHTML = '<div class="cd-loading"><div class="cd-spinner"></div><span>Loading analytics...</span></div>';
        try {
            // R-7: collection goes to the SERVER. Five of the handler's queries did
            // not join the listings table, so narrowing a received response would
            // have moved the KPI numbers and left the charts issuer-wide.
            const anSc = cdAnScope();
            const url = `${CONFIG.endpoints.creatorAnalytics}?action=summary&account=${encodeURIComponent(CONFIG.account)}`
                      + `&window=${encodeURIComponent(cdAn.win)}`
                      + (anSc.name !== 'all' ? `&collection=${encodeURIComponent(anSc.name)}` : '')
                      + `&t=${Date.now()}`
                      + `&nonce=${encodeURIComponent(CONFIG.nonce)}`;
            const res = await fetch(url, { credentials: 'include' });
            const data = await res.json();
            if (!data || !data.success) throw new Error((data && data.error) || 'Analytics unavailable');
            cdAnRender(data);
            cdAn.loaded = cdAn.win;
        } catch (err) {
            console.error('analytics error:', err);
            body.innerHTML = '<div class="cd-an-card cd-surface"><p class="cd-ov-empty">Analytics could not be loaded just now. Nothing is wrong with your listings \u2014 please try again in a moment.</p></div>';
        } finally {
            cdAn.busy = false;
        }
    }

    function cdAnRender(d) {
        cdAnKill();
        const body = document.getElementById('cd-an-body');
        const t = d.totals || {};
        const cur = d.byCurrency || [];
        const col = d.collectors || { holders: 0, repeat: 0, top: [] };
        const tiers = d.tiers || [];
        const lst = d.listings || [];
        const series = d.series || [];

        if (!t.mints) {
            body.innerHTML = '<div class="cd-an-card cd-surface"><h4>No activity in this window</h4>'
                + '<p class="cd-ov-empty">Nothing was minted in the period selected. Try a longer window, or create your first NFT to get started.</p></div>';
            return;
        }

        // "Holders", not "unique buyers": buyer_account is a WALLET. Wallets
        // holding is literally true and claims no person-count we cannot prove.
        body.innerHTML = `
            <div class="cd-an-kpis">
                <div class="cd-an-kpi cd-surface"><div class="v">${t.mints}</div><div class="l">Mints</div></div>
                <div class="cd-an-kpi cd-surface"><div class="v">${col.holders}</div><div class="l">Holders</div></div>
                <div class="cd-an-kpi cd-surface"><div class="v">${col.repeat}</div><div class="l">Repeat holders</div></div>
                <div class="cd-an-kpi cd-surface"><div class="v">${t.listings}</div><div class="l">Listings sold</div></div>
            </div>
            <div class="cd-an-grid">
                <div class="cd-an-card cd-an-wide cd-surface">
                    <h4>Mints over time</h4>
                    <div class="cd-an-canvas-wrap"><canvas id="cd-an-mints"></canvas></div>
                    <p class="cd-an-note" id="cd-an-mints-note"></p>
                </div>
                
                <?php /* R-7: was Tier mix - a two-bar chart reading Amplifier 715 /
                   Untiered 452. That is a fact, not an insight, and the listing rows
                   already carry it. Holder distribution answers a question a creator
                   actually has: is this held by a crowd or by a handful?
                   Source is `distribution` on action=holders, which already exists and
                   works issuer-wide AND per collection - no endpoint work. */ ?>
                <?php /* CARD SIZING, by what the content needs:
                     WIDE  - a dense time series with a date axis (mints, revenue, sales)
                     HALF  - a chart that pairs naturally (floor + listings,
                             by-collection + distribution)
                     THIRD - a list or a short block (revenue by currency, top holders,
                             listing performance)
                   A 90-day axis at a third was unreadable; a ten-row list at full
                   width was mostly empty. */ ?>
                <?php /* A2: the chart neither this page nor the public collection
                   page had. Revenue as a SERIES, one line per currency - never a
                   sum across them, which is the rule the revenue card's own note
                   states. */ ?>
                <div class="cd-an-card cd-an-wide cd-surface">
                    <h4>Revenue over time</h4>
                    <div class="cd-an-canvas-wrap"><canvas id="cd-an-rev"></canvas></div>
                    <p class="cd-an-note" id="cd-an-rev-note"></p>
                </div>

                <?php /* A3: SALE PRICES. I argued for deferring this on the grounds
                   that 11 priced secondary sales make a thin chart. That was the wrong
                   call for two reasons: an active collection will have real volume, and
                   the data costs NOTHING - `sale_points`, `currencies`, `sales_since`
                   and `sale_points_truncated` are all already in the holders_history
                   payload R-9d fetches and discards.

                   `sales_since` is the honest axis label: this says "since <date>",
                   never an implied all-time, because the ledger walk only started
                   recording on a known day. */ ?>
                <div class="cd-an-card cd-an-wide cd-surface" id="cd-an-sales-card">
                    <div class="cd-an-cardhead">
                        <h4>Sale prices</h4>
                        <select id="cd-an-sales-cur" class="cd-an-cursel" hidden></select>
                    </div>
                    <div class="cd-an-canvas-wrap"><canvas id="cd-an-sales"></canvas></div>
                    <p class="cd-an-note" id="cd-an-sales-note"></p>
                </div>

                <?php /* A1: floor and listed count come from holders_history, which
                   R-9d already calls and then discarded every series in. Per-taxon
                   only - hidden with a reason when the scope is All collections. */ ?>
                <div class="cd-an-card cd-an-half cd-surface" id="cd-an-floor-card">
                    <h4>Floor price</h4>
                    <div class="cd-an-canvas-wrap"><canvas id="cd-an-floor"></canvas></div>
                    <p class="cd-an-note" id="cd-an-floor-note"></p>
                </div>

                <div class="cd-an-card cd-an-half cd-surface" id="cd-an-listed-card">
                    <h4>Listings over time</h4>
                    <div class="cd-an-canvas-wrap"><canvas id="cd-an-listed"></canvas></div>
                    <p class="cd-an-note" id="cd-an-listed-note"></p>
                </div>

                <?php /* Returned on every analytics call since B-e and never drawn. */ ?>
                <div class="cd-an-card cd-an-half cd-surface" id="cd-an-bycoll-card">
                    <h4>Mints by collection</h4>
                    <div class="cd-an-canvas-wrap"><canvas id="cd-an-bycoll"></canvas></div>
                    <p class="cd-an-note" id="cd-an-bycoll-note"></p>
                </div>

                <?php /* Bars with their own labels - reads well at a half beside
                   Mints by collection, and cramped at a third. */ ?>
                <div class="cd-an-card cd-an-half cd-surface">
                    <h4>Holder distribution</h4>
                    <div id="cd-an-dist"><p class="cd-an-note">Reading the ledger index\u2026</p></div>
                </div>
                <?php /* Moved here from above Revenue over time. At a third it used to
                   open a row and leave four columns empty, because the next card was a
                   full-width series that could not fit beside it. Beside the other two
                   thirds it closes the final row exactly. */ ?>
<div class="cd-an-card cd-surface">
                    <h4>Revenue by currency</h4>
                    ${cur.map(c => { const r = cdCurLine(c);
                        return `<div class="cd-an-cur"><span>${escapeHtml(r.label)}</span><b>${escapeHtml(r.value)}</b></div>`; }).join('')}
                    <p class="cd-an-note">Each currency is shown in its own unit and they are never added together \u2014 there is no honest way to combine them without a live price feed.${
                        cur.some(c => c.amount_known === false)
                        ? ' A dash means the amount was not recorded: token payments store a price in XRP terms, not the token amount.' : ''}</p>
                </div>

                <div class="cd-an-card cd-surface">
                    <h4>Top holders</h4>
                    <ul class="cd-an-rows" id="cd-hold-rows">${
                        col.top.length
                            ? col.top.map(w => `<li><span>${escapeHtml(w.wallet.slice(0, 8))}\u2026${escapeHtml(w.wallet.slice(-4))}</span><span>${w.mints}</span></li>`).join('')
                            : '<li><span class="cd-ov-empty">No holders yet.</span><span></span></li>'
                    }</ul>
                    <div class="cd-hold-acts">
                        <button type="button" class="cd-btn cd-btn-secondary cd-btn-sm" onclick="cdHoldersViewAll()">View top 100</button>
                        <button type="button" class="cd-btn cd-btn-secondary cd-btn-sm" onclick="cdHoldersCsv(this)">Download CSV</button>
                    </div>
                    <p class="cd-an-note" id="cd-hold-note"></p>
                </div>
                <div class="cd-an-card cd-surface">
                    <h4>Listing performance</h4>
                    <ul class="cd-an-rows">${
                        lst.slice(0, 8).map(l => `<li><span>${escapeHtml(l.name)}</span><span>${l.mints} \u00b7 ${l.last_activity ? new Date(l.last_activity.replace(' ', 'T') + 'Z').toLocaleDateString() : '\u2014'}</span></li>`).join('')
                    }</ul>
                    <p class="cd-an-note">Mints, and the date each listing last sold.</p>
                </div>
            </div>`;

        if (typeof Chart === 'undefined') {
            const n = document.getElementById('cd-an-mints-note');
            if (n) n.textContent = 'Charts could not load (the chart library is unavailable). The figures above are still accurate.';
            return;
        }

        // R-7b: the rebuild above replaced #cd-an-dist with its placeholder. Replay
        // the cached holders payload so the panel survives whichever response
        // arrives second.
        cdAnRenderDist(undefined);

        // ── A1/A2 · THE NEW CHARTS ────────────────────────────────────────
        // ⚠ Every one registers in cdAn.charts. cdAnKill() destroys what is in
        // that object and nothing else, so an unregistered chart survives a window
        // switch and leaks - two canvases fighting over one element.
        cdAnDrawRevenue(d);
        cdAnDrawByCollection(d);

        // ⚠ A3b FIX: floor, listed and sales are drawn from the STORE lifecycle
        // (cdStoreRender), but cdAnKill() above runs on the ANALYTICS lifecycle and
        // destroys every key in cdAn.charts - including those three. A window switch
        // (24h -> 7d) therefore blanked them until the collection was re-picked.
        //
        // Same shape as R-7b's distribution replay: the store payload is already
        // cached per issuer:taxon, so replay it rather than refetching.
        try {
            const sc = (typeof cdAnScope === 'function') ? cdAnScope() : null;
            // ⚠ CACHE KEY. cdLoadStore stores under String(which) - the TAXON alone,
            // or 'all'. These replays looked up `issuer + ':' + taxon`, which never
            // matched, so `cached` was always null and Floor and Listings drew
            // nothing on a specific collection. A silent miss: a null payload
            // renders as an empty card rather than an error.
            // (cdClLedger keeps its own issuer:taxon cache - a different object,
            // and correct there because that one is fetched per issuer.)
            const key = sc ? String(sc.taxon) : null;
            const cached = (key && cdStore.cache && cdStore.cache[key]) ? cdStore.cache[key] : null;
            const hist = cached ? (cached.history || cached.hist || null) : null;
            cdAnDrawHistory(hist);
            cdAnDrawSales(hist);
        } catch (e) { console.warn('history/sales replay:', e); }

        cdAn.charts.mints = new Chart(document.getElementById('cd-an-mints').getContext('2d'), {
            type: 'line',
            data: { labels: series.map(p => p.bucket), datasets: [{
                label: 'Mints', data: series.map(p => p.mints),
                borderColor: '#d6ba66', backgroundColor: 'rgba(214,186,102,0.12)',
                borderWidth: 2, tension: 0.3, fill: true,
                pointRadius: series.length === 1 ? 5 : 0,
                pointBackgroundColor: '#d6ba66', pointHoverRadius: 5
            }] },
            options: cdAnAxes()
        });
        const note = document.getElementById('cd-an-mints-note');
        if (note) {
            note.textContent = series.length === 1
                ? `A single ${d.bucket} of activity in this window.`
                : `${series.length} ${d.bucket}s with activity \u00b7 ${t.first_mint ? t.first_mint.slice(0, 10) : ''} to ${t.last_mint ? t.last_mint.slice(0, 10) : ''}.`;
        }

        // R-7: the tier chart is gone. Distribution is filled by cdAnRenderDist()
        // from the holders payload, which arrives on its own schedule.
    }

    // D-B2 FIX: SCOPED to #cd-an-windows. This was document.querySelectorAll('.cd-an-win'),
    // which was fine while the Analytics tab owned the only such control - but D-B2 adds
    // a second one in the header reusing the same class for its styling. Unscoped, a
    // click on a header pill fired BOTH handlers: it set cdAn.win from data-win (absent
    // on header buttons, so undefined), refetched analytics against a bad window, and
    // stripped .active from the Analytics tab's own pills.
    // Same trap as the .cd-tab collision B-c was built to avoid - shared class, two
    // controls, one unscoped listener. Both listeners are now container-scoped.
    document.querySelectorAll('#cd-an-windows .cd-an-win').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('#cd-an-windows .cd-an-win').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            cdAn.win = this.dataset.win;
            cdLoadAnalytics(true);
        });
    });
    window.cdLoadAnalytics = cdLoadAnalytics;

    // Tab filtering
    document.querySelectorAll('.cd-tab').forEach(tab => {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.cd-tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            currentFilter = this.dataset.filter;
            renderCollections();
        });
    });
    
    // Close modal on overlay click
    // R-5b: removed with the modal. getElementById would return null here and
    // addEventListener on null throws, which would have killed every listener
    // registered after this line.
    
    // ════════════════════════════════════════════════════════════════════════
    // Allowlist Management (v67)
    // ════════════════════════════════════════════════════════════════════════
    
    let currentAllowlists = [];
    let currentAllowlistId = null;
    
    // Fetch allowlists for current collection
    async function fetchAllowlists() {
        // v671: scoped to the SELECTED listing. Previously this used the collection's first
        // listing, so allowlists on any other listing were invisible and unreachable.
        if (!currentListingId) return;
        
        const container = document.getElementById('cd-allowlists-list');
        if (!container) return;
        
        try {
            const response = await fetch(`${CONFIG.endpoints.allowlistHandler}?action=get&listing_id=${currentListingId}&artist_account=${CONFIG.account}`);
            const data = await response.json();
            
            if (data.success) {
                currentAllowlists = data.data.allowlists;
                renderAllowlists();
            } else {
                container.innerHTML = '<div class="cd-no-allowlists">Failed to load allowlists.</div>';
            }
        } catch (err) {
            console.error('Fetch allowlists error:', err);
            container.innerHTML = '<div class="cd-no-allowlists">Error loading allowlists.</div>';
        }
    }
    
    function renderAllowlists() {
        const container = document.getElementById('cd-allowlists-list');
        if (!container) return;
        
        if (currentAllowlists.length === 0) {
            container.innerHTML = '<div class="cd-no-allowlists">No allowlists yet. Create one to enable early access, discounts, or exclusive minting!</div>';
            return;
        }
        
        const typeLabels = {
            'early_access':     '<svg class="imc-ic" aria-hidden="true"><use href="#ic-clock"></use></svg> Early Access',
            'discount':         '<svg class="imc-ic" aria-hidden="true"><use href="#ic-coins"></use></svg> Unlimited Discount',
            'discount_limited': '<svg class="imc-ic" aria-hidden="true"><use href="#ic-scroll"></use></svg> Holder Discount',
            'exclusive':        '<svg class="imc-ic" aria-hidden="true"><use href="#ic-lock"></use></svg> Exclusive',
            'limit_override':   '<svg class="imc-ic" aria-hidden="true"><use href="#ic-chart"></use></svg> Mint Cap'
        };
        
        container.innerHTML = currentAllowlists.map(al => {
            // v69: Fix - is_active comes as string "1" or "0"
            const isActive = parseInt(al.is_active) === 1;
            return `
            <div class="cd-allowlist-card ${isActive ? '' : 'cd-allowlist-inactive'}">
                <div class="cd-allowlist-card-header">
                    <span class="cd-allowlist-name">${escapeHtml(al.name)}</span>
                    <span class="cd-allowlist-type cd-type-${al.type}">${typeLabels[al.type] || al.type}</span>
                </div>
                <div class="cd-allowlist-meta">
                    <span><svg class="imc-ic" aria-hidden="true"><use href="#ic-smile"></use></svg> ${al.entry_count || 0} wallets</span>
                    <span>${al.total_minted || 0} minted</span>
                    ${al.type === 'discount_limited' ? `<span><svg class="imc-ic" aria-hidden="true"><use href="#ic-gift"></use></svg> ${al.total_minted || 0}/${al.discount_allocation || 0} allocation</span>` : ''}
                    ${(al.type === 'discount' || al.type === 'discount_limited') && al.custom_price !== null && al.custom_price !== undefined && al.custom_price !== '' ? `<span><svg class="imc-ic" aria-hidden="true"><use href="#ic-coins"></use></svg> ${parseFloat(al.custom_price) === 0 ? 'FREE' : parseFloat(al.custom_price).toFixed(2) + ' XRP'}</span>` : ''}
                    ${(al.type === 'discount' || al.type === 'discount_limited') && parseFloat(al.discount_percent) > 0 ? `<span><svg class="imc-ic" aria-hidden="true"><use href="#ic-coins"></use></svg> ${parseFloat(al.discount_percent)}% off</span>` : ''}
                    ${al.type === 'early_access' ? `<span><svg class="imc-ic" aria-hidden="true"><use href="#ic-clock"></use></svg> ${al.early_access_hours}h early</span>` : ''}
                    ${al.type === 'limit_override' && al.max_mint_override ? `<span><svg class="imc-ic" aria-hidden="true"><use href="#ic-chart"></use></svg> Max ${al.max_mint_override}</span>` : ''}
                    ${al.allowlist_holder_limit ? `<span><svg class="imc-ic" aria-hidden="true"><use href="#ic-lock"></use></svg> max ${al.allowlist_holder_limit}/wallet</span>` : ''}
                    ${(al.type === 'discount_limited' || al.type === 'limit_override') && parseInt(al.missing_amounts) > 0 ? `<span class="cd-allowlist-warn" style="color:#f0a020;" title="These wallets were added before amounts were supported. Re-add them with amounts (rWallet...,5) or re-upload the snapshot to repair."><svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> ${al.missing_amounts} need amounts</span>` : ''}
                </div>
                <div class="cd-allowlist-actions">
                    <button onclick="cdManageEntries(${al.id}, ${escapeJsAttr(al.name)})"><svg class="imc-ic" aria-hidden="true"><use href="#ic-smile"></use></svg> Wallets</button>
                    <button onclick="cdOpenEditAllowlist(${al.id})"><svg class="imc-ic" aria-hidden="true"><use href="#ic-gear"></use></svg> Edit</button>
                    <button onclick="cdEditAllowlist(${al.id})">${isActive ? 'Pause' : 'Enable'}</button>
                    <button onclick="cdDeleteAllowlist(${al.id})" title="Delete allowlist"><svg class="imc-ic" aria-hidden="true"><use href="#ic-x-circle"></use></svg> Delete</button>
                </div>
            </div>
        `}).join('');
    }
    
    // Show create allowlist modal
    window.cdShowCreateAllowlist = function() {
        // v671: bind to the listing being managed, not the collection's first listing.
        const listing = allListings.find(l => String(l.id) === String(currentListingId));
        if (!listing) {
            showToast('Open a listing to manage its allowlists', 'error');
            return;
        }
        
        document.getElementById('allowlist-modal-title').textContent = 'Create Allowlist — ' + (listing.nft_name || 'Listing');
        document.getElementById('allowlist-id').value = '';
        document.getElementById('allowlist-listing-id').value = listing.id;
        document.getElementById('allowlist-name').value = '';
        document.getElementById('allowlist-type').value = 'early_access';
        document.getElementById('allowlist-description').value = '';
        document.getElementById('allowlist-early-hours').value = '24';
        const cpField = document.getElementById('allowlist-custom-price');
        if (cpField) cpField.value = '';
        const dpField = document.getElementById('allowlist-discount-percent'); // v679
        if (dpField) dpField.value = '';
        const dmField = document.getElementById('allowlist-discount-mode');
        if (dmField) dmField.value = 'price';
        cdUpdateDiscountMode();
        document.getElementById('allowlist-max-mint').value = '5';
        document.getElementById('allowlist-holder-limit').value = '';   // AG-1: blank = no cap
        document.getElementById('inline-wallets').value = '';
        cdResetWalletRows(); // v678
        const csvFile = document.getElementById('inline-csv-file');
        if (csvFile) csvFile.value = '';
        const summary = document.getElementById('inline-added-summary');
        if (summary) { summary.style.display = 'none'; summary.textContent = ''; }
        document.getElementById('allowlist-save-btn').textContent = 'Save & Add Wallets';
        cdSwitchInlineTab('add');
        
        cdUpdateAllowlistTypeFields();
        // R-5d: reveal the inline section and bring it into view. Without the
        // scroll the button appears to do nothing on a long allowlist list - the
        // form opens above the fold you are looking at.
        const box = document.getElementById('cd-al-create');
        if (box) {
            box.hidden = false;
            box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    };
    
    // v676: stamp the shared "quantity per wallet" onto lines that don't already specify one.
    // The backend derives each entry's custom_max_mint by scanning the row for a number, so
    // appending it here needs no server change and per-wallet overrides still win.
    window.cdApplyInlineQuantity = function(text) {
        const qtyEl = document.getElementById('inline-quantity');
        const qty = qtyEl ? parseInt(qtyEl.value, 10) : 0;
        if (!qty || qty < 1) return text;
        return text.split(/\r?\n/).map(line => {
            const t = line.trim();
            if (!t) return '';
            // already carries a number in any column? leave it alone
            const hasQty = t.split(/[,;\t]/).some(p => /^\d+$/.test(p.trim()));
            return hasQty ? t : `${t},${qty}`;
        }).filter(Boolean).join('\n');
    };

    // v676: read a chosen CSV in the browser and prefill the wallet box (and the quantity box
    // when every row shares one amount), so creators can review before saving.
    window.cdPrefillFromCsv = function(input) {
        const file = input && input.files && input.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(e) {
            const rows = String(e.target.result || '').split(/\r?\n/).map(r => r.trim()).filter(Boolean);
            const wallets = [];
            const qtys = [];
            rows.forEach(r => {
                const parts = r.split(/[,;\t]/).map(p => p.trim());
                const w = parts.find(p => /^r[1-9A-HJ-NP-Za-km-z]{25,34}$/.test(p));
                if (!w) return; // skips header rows and junk
                const q = parts.find(p => /^\d+$/.test(p));
                wallets.push(q ? `${w},${q}` : w);
                if (q) qtys.push(parseInt(q, 10));
            });
            if (!wallets.length) { showToast('No wallet addresses found in that file', 'error'); return; }
            // v678: fill the row builder so each wallet's amount is editable in its own field
            const rowsWrap = document.getElementById('inline-wallet-rows');
            if (rowsWrap) rowsWrap.innerHTML = '';
            wallets.forEach(w => { const [a, q] = w.split(','); cdAddWalletRow(a, q || ''); });
            cdSerialiseWalletRows();
            // one shared amount across every row -> surface it in the quantity box
            const qtyEl = document.getElementById('inline-quantity');
            if (qtyEl && qtys.length === wallets.length && new Set(qtys).size === 1) {
                qtyEl.value = qtys[0];
            }
            cdSwitchInlineTab('add');
            showToast(`${wallets.length} wallet${wallets.length === 1 ? '' : 's'} loaded \u2014 review and save`, 'success');
        };
        reader.onerror = function() { showToast('Could not read that file', 'error'); };
        reader.readAsText(file);
    };

    // v678: wallet rows. Each wallet gets its own address + amount field, because holder
    // allocations are per wallet -- "address,number" text was doing a form field's job.
    window.cdAddWalletRow = function(addr, qty) {
        const wrap = document.getElementById('inline-wallet-rows');
        if (!wrap) return;
        const row = document.createElement('div');
        row.className = 'cd-wallet-row';
        row.innerHTML = `
            <input type="text" class="cd-wallet-addr" placeholder="rWalletAddress..." spellcheck="false">
            <input type="number" class="cd-wallet-qty" min="1" max="1000" placeholder="\u2014">
            <button type="button" class="cd-wallet-remove" title="Remove" onclick="this.parentElement.remove()">\u00d7</button>`;
        wrap.appendChild(row);
        if (addr) row.querySelector('.cd-wallet-addr').value = addr;
        if (qty)  row.querySelector('.cd-wallet-qty').value  = qty;
        cdSyncWalletQtyVisibility();
        return row;
    };

    window.cdResetWalletRows = function() {
        const wrap = document.getElementById('inline-wallet-rows');
        if (!wrap) return;
        wrap.innerHTML = '';
        cdAddWalletRow();
    };

    // The amount column only applies to the quantity-aware types.
    window.cdSyncWalletQtyVisibility = function() {
        const type = document.getElementById('allowlist-type')?.value;
        const show = (type === 'limit_override' || type === 'discount_limited');
        const head = document.getElementById('inline-rows-qty-head');
        if (head) head.style.visibility = show ? 'visible' : 'hidden';
        document.querySelectorAll('#inline-wallet-rows .cd-wallet-qty')
            .forEach(el => { el.style.display = show ? '' : 'none'; });
    };

    // Rows -> the "wallet,amount" lines the backend already understands.
    window.cdSerialiseWalletRows = function() {
        const out = [];
        document.querySelectorAll('#inline-wallet-rows .cd-wallet-row').forEach(r => {
            const a = (r.querySelector('.cd-wallet-addr')?.value || '').trim();
            if (!a) return;
            const q = (r.querySelector('.cd-wallet-qty')?.value || '').trim();
            out.push(q ? `${a},${q}` : a);
        });
        const ta = document.getElementById('inline-wallets');
        if (ta) ta.value = out.join('\n');
        return out.join('\n');
    };

    // v679: the benefits engine gives a fixed custom_price priority over a percentage, so
    // showing both invites a state where one silently does nothing. One is chosen, and only
    // that one is sent.
    window.cdUpdateDiscountMode = function() {
        const mode = document.getElementById('allowlist-discount-mode')?.value || 'price';
        const pg = document.getElementById('allowlist-price-group');
        const cg = document.getElementById('allowlist-percent-group');
        if (pg) pg.style.display = (mode === 'price')   ? 'block' : 'none';
        if (cg) cg.style.display = (mode === 'percent') ? 'block' : 'none';
    };

    window.cdUpdateAllowlistTypeFields = function() {
        const type = document.getElementById('allowlist-type').value;
        document.getElementById('allowlist-early-fields').style.display    = type === 'early_access'   ? 'block' : 'none';
        // v381: Both discount types show the price field
        document.getElementById('allowlist-discount-fields').style.display = (type === 'discount' || type === 'discount_limited') ? 'block' : 'none';
        // v731 (A4b): per-token matrix (JS-injected container, flag-gated). Free-claim
        // detection: price mode with value 0 hides it — free is free in every currency.
        (function() {
            const df = document.getElementById('allowlist-discount-fields');
            if (df && !document.getElementById('cd-create-matrix-wrap')) {
                const div = document.createElement('div');
                div.id = 'cd-create-matrix-wrap';
                div.style.display = 'none';
                div.style.marginTop = '10px';
                div.innerHTML = '<label>Per-Token Pricing (optional)</label>'
                    + '<small style="display:block;color:var(--cd-text-muted);margin-bottom:4px;">A rule here <strong>replaces</strong> the discount above for wallets paying in that token — even if it\'s smaller. Tokens without a rule keep the base discount. Free claims are free in every currency.</small>'
                    + '<div id="cd-create-matrix-rows"></div>';
                df.appendChild(div);
                // cdBuildMatrixUI expects <prefix>-wrap / <prefix>-rows
                div.id = 'cd-create-matrix-wrap';
            }
            const dMode = document.getElementById('allowlist-discount-mode')?.value || 'price';
            const cpv = parseFloat(document.getElementById('allowlist-custom-price')?.value);
            const isFreeNow = dMode === 'price' && Number.isFinite(cpv) && cpv === 0;
            const pseudoAl = { listing_id: document.getElementById('allowlist-listing-id')?.value || currentListingId,
                               custom_price: isFreeNow ? 0 : null, currency_benefits: null };
            cdBuildMatrixUI('cd-create-matrix', pseudoAl, (type === 'discount' || type === 'discount_limited'));
        })();
        // v677: the top-level field is ONLY meaningful for limit_override -- that type falls
        // back to max_mint_override when an entry has no value of its own. discount_limited
        // sums per-entry custom_max_mint and never reads max_mint_override, so showing it
        // there implied a single shared cap and hid that amounts are set per wallet.
        document.getElementById('allowlist-limit-fields').style.display = (type === 'limit_override') ? 'block' : 'none';

        // Per-wallet amounts: used by both quantity-aware types, and the ONLY route for
        // discount_limited (a holder snapshot -- each wallet gets what it holds).
        const usesQty = (type === 'limit_override' || type === 'discount_limited');

        // AG-1: the ceiling applies to exactly the types that carry a per-wallet
        // amount, so it rides the same flag rather than repeating the condition.
        const capBox = document.getElementById('allowlist-cap-fields');
        if (capBox) capBox.style.display = usesQty ? 'block' : 'none';
        const qBox = document.getElementById('inline-qty-wrap');
        if (qBox) qBox.style.display = usesQty ? 'block' : 'none';
        cdSyncWalletQtyVisibility(); // v678: show/hide the per-row amount field

        const wNote = document.getElementById('inline-wallets-note');
        const wArea = document.getElementById('inline-wallets');
        if (type === 'discount_limited') {
            if (wNote) wNote.innerHTML = '\u2b50 <strong>Each wallet gets its own amount</strong> \u2014 usually how many NFTs they hold in the collection you are rewarding. Put the number after the address, or upload a holder snapshot CSV.'
                + '<br>\u2139\ufe0f Amounts are <strong>totals, not top-ups</strong> \u2014 to grant more later, re-add the wallet with its new total.'
                + '<br>\u2139\ufe0f Holder Discounts apply from <strong>public launch</strong>; pair with an Early Access list if holders should also mint early.';
            if (wArea) wArea.placeholder = 'rWalletAddress1...,5\nrWalletAddress2...,15\nrWalletAddress3...,1\nrWalletAddress4...,22';
        } else if (type === 'limit_override') {
            if (wNote) wNote.innerHTML = 'Add a number after an address to give that wallet its own cap \u2014 otherwise the value above is used.';
            if (wArea) wArea.placeholder = 'rWalletAddress1...\nrWalletAddress2...,10\nOne per line or comma-separated';
        } else {
            if (wNote) wNote.innerHTML = '';
            if (wArea) wArea.placeholder = 'rWalletAddress1...\nrWalletAddress2...\nrWalletAddress3...\nOne per line or comma-separated';
        }
    };
    
    window.cdCloseAllowlistModal = function() {
        // R-5d: collapses the inline section. Name kept - it is called from the
        // post-save path and from Cancel, and renaming it would touch more than
        // it is worth for no behaviour change.
        const box = document.getElementById('cd-al-create');
        if (box) box.hidden = true;
        // Reset form fully for next open
        document.getElementById('cd-allowlist-form').reset();
        document.getElementById('allowlist-modal-title').textContent = 'Create Allowlist';
        document.getElementById('allowlist-save-btn').textContent = 'Save & Add Wallets';
        const summary = document.getElementById('inline-added-summary');
        if (summary) { summary.style.display = 'none'; summary.textContent = ''; }
        const csvFile = document.getElementById('inline-csv-file');
        if (csvFile) csvFile.value = '';
        cdSwitchInlineTab('add');
        cdUpdateAllowlistTypeFields();
    };

    // v252: Stage 2 tab switcher (inline within the allowlist modal)
    window.cdSwitchInlineTab = function(tab) {
        document.querySelectorAll('#inline-entries-tabs .cd-entries-tab').forEach(t => t.classList.remove('active'));
        const activeTab = document.querySelector(`#inline-entries-tabs .cd-entries-tab[data-tab="${tab}"]`);
        if (activeTab) activeTab.classList.add('active');
        document.getElementById('inline-tab-add').style.display = tab === 'add' ? 'block' : 'none';
        document.getElementById('inline-tab-csv').style.display  = tab === 'csv'  ? 'block' : 'none';
    };

    // v252: Add wallets inline (uses currentAllowlistId set during save)
    window.cdAddInlineEntries = async function() {
        cdSerialiseWalletRows(); // v678: rows -> hidden field
        let wallets = document.getElementById('inline-wallets').value.trim();
        if (!wallets || !currentAllowlistId) return;
        // v676: stamp the shared quantity onto any line that doesn't already carry one, so it
        // reaches each entry's custom_max_mint (which is what the allocation is built from).
        wallets = cdApplyInlineQuantity(wallets);

        const formData = new FormData();
        formData.append('action', 'add_entries');
        formData.append('allowlist_id', currentAllowlistId);
        formData.append('artist_account', CONFIG.account);
        formData.append('wallets', wallets);
        formData.append('nonce', CONFIG.nonce);

        try {
            const response = await fetch(CONFIG.endpoints.allowlistHandler, { method: 'POST', body: formData });
            const data = await response.json();
            if (data.success) {
                const added = data.data.added || 0;
                const invalid = data.data.invalid || 0;
                document.getElementById('inline-wallets').value = '';
        cdResetWalletRows(); // v678
                const summary = document.getElementById('inline-added-summary');
                summary.textContent = `Added ${added} wallet${added !== 1 ? 's' : ''}` + (invalid > 0 ? ` · ${invalid} invalid skipped` : '');
                summary.style.display = 'block';
                await fetchAllowlists();
                showToast(`Added ${added} wallet${added !== 1 ? 's' : ''}!`, 'success');
            } else {
                showToast(data.error || 'Failed to add wallets', 'error');
            }
        } catch (err) { showToast('Error adding wallets', 'error'); }
    };

    // v252: Upload CSV inline
    window.cdUploadInlineCSV = async function() {
        const fileInput = document.getElementById('inline-csv-file');
        if (!fileInput.files || !fileInput.files[0] || !currentAllowlistId) {
            showToast('Please select a CSV file', 'error'); return;
        }
        const formData = new FormData();
        formData.append('action', 'upload_csv');
        formData.append('allowlist_id', currentAllowlistId);
        formData.append('artist_account', CONFIG.account);
        formData.append('csv_file', fileInput.files[0]);
        formData.append('nonce', CONFIG.nonce);

        try {
            const response = await fetch(CONFIG.endpoints.allowlistHandler, { method: 'POST', body: formData });
            const data = await response.json();
            if (data.success) {
                const added = data.data.added || 0;
                const invalid = data.data.invalid || 0;
                fileInput.value = '';
                const summary = document.getElementById('inline-added-summary');
                summary.textContent = `Added ${added}` + (invalid > 0 ? ` · ${invalid} invalid skipped` : '');
                summary.style.display = 'block';
                await fetchAllowlists();
                showToast(`CSV processed! Added: ${added}`, 'success');
            } else {
                showToast(data.error || 'Failed to process CSV', 'error');
            }
        } catch (err) { showToast('Error uploading CSV', 'error'); }
    };

    // v253: Save allowlist then immediately process any wallets/CSV that were entered
    document.getElementById('cd-allowlist-form').addEventListener('submit', async function(e) {
        e.preventDefault();
        const saveBtn = document.getElementById('allowlist-save-btn');
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving…';

        const formData = new FormData();
        formData.append('action', 'create');
        formData.append('listing_id',         document.getElementById('allowlist-listing-id').value);
        formData.append('artist_account',     CONFIG.account);
        formData.append('nonce',              CONFIG.nonce);
        formData.append('name',              document.getElementById('allowlist-name').value);
        formData.append('type',              document.getElementById('allowlist-type').value);
        formData.append('description',       document.getElementById('allowlist-description').value);
        formData.append('early_access_hours', document.getElementById('allowlist-early-hours').value);
        // v679: send whichever discount the creator chose, and explicitly clear the other so
        // a stale value can never override it server-side.
        const dMode = document.getElementById('allowlist-discount-mode')?.value || 'price';
        formData.append('custom_price',      dMode === 'price'   ? (document.getElementById('allowlist-custom-price')?.value || '') : '');
        formData.append('discount_percent',  dMode === 'percent' ? (document.getElementById('allowlist-discount-percent')?.value || '') : '');
        // v731 (A4b): per-token rules (flag-gated UI; server re-validates + free-guards)
        if (typeof cdMatrixEnabled === 'function' && cdMatrixEnabled()) {
            const _mx = cdCollectMatrix('cd-create-matrix');
            if (_mx.length) formData.append('currency_benefits', JSON.stringify(_mx));
        }
        formData.append('max_mint_override',  document.getElementById('allowlist-max-mint').value);
        // AG-1: blank means NULL means no cap. The handler maps '' to null; sending 0
        // would also mean "no cap" but stores a number where the ABSENCE of one is the
        // honest record.
        formData.append('allowlist_holder_limit', document.getElementById('allowlist-holder-limit').value);

        try {
            const response = await fetch(CONFIG.endpoints.allowlistHandler, { method: 'POST', body: formData });
            const data = await response.json();

            if (data.success) {
                const newId   = data.data?.allowlist_id;
                const newName = document.getElementById('allowlist-name').value || 'Allowlist';
                currentAllowlistId = newId || null;
                await fetchAllowlists();

                // If wallets were entered, add them now
                const walletText = cdSerialiseWalletRows(); // v678: rows are the source of truth
                const csvFileInput = document.getElementById('inline-csv-file');
                const hasCsv = csvFileInput?.files?.length > 0;

                if (walletText && newId) {
                    await cdAddInlineEntries();
                } else if (hasCsv && newId) {
                    await cdUploadInlineCSV();
                }

                cdCloseAllowlistModal();
                showToast(`"${newName}" allowlist created!` + (walletText || hasCsv ? ' Wallets added.' : ' Add wallets via <svg class="imc-ic" aria-hidden="true"><use href="#ic-smile"></use></svg> Wallets.'), 'success');
            } else {
                showToast(data.error || 'Failed to create allowlist', 'error');
            }
        } catch (err) {
            showToast('Error creating allowlist', 'error');
        } finally {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save & Add Wallets';
        }
    });
    
    // Toggle allowlist active status
    window.cdEditAllowlist = async function(allowlistId) {
        const al = currentAllowlists.find(a => a.id == allowlistId);
        if (!al) return;
        
        // v69: Fix - is_active comes as string "1" or "0" from database
        const currentlyActive = parseInt(al.is_active) === 1;
        const newStatus = currentlyActive ? 0 : 1;
        
        const formData = new FormData();
        formData.append('action', 'update');
        formData.append('allowlist_id', allowlistId);
        formData.append('artist_account', CONFIG.account);
        formData.append('is_active', newStatus);
        formData.append('nonce', CONFIG.nonce);
        
        try {
            const response = await fetch(CONFIG.endpoints.allowlistHandler, { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success) {
                showToast(newStatus ? 'Allowlist enabled!' : 'Allowlist paused!', 'success');
                await fetchAllowlists();
            } else {
                showToast(data.error || 'Failed to update allowlist', 'error');
            }
        } catch (err) {
            showToast('Error updating allowlist', 'error');
        }
    };
    
    // v730 (A4/G2): real settings editor — first UI over the long-supported 'update' action
    window.cdOpenEditAllowlist = function(allowlistId) {
        const al = currentAllowlists.find(a => a.id == allowlistId);
        if (!al) return;
        document.getElementById('edit-allowlist-id').value = al.id;
        document.getElementById('edit-allowlist-title').textContent = 'Edit — ' + al.name;
        document.getElementById('edit-allowlist-name').value = al.name || '';
        document.getElementById('edit-allowlist-description').value = al.description || '';
        document.getElementById('edit-allowlist-percent').value = al.discount_percent !== null && al.discount_percent !== undefined ? parseFloat(al.discount_percent) : '';
        document.getElementById('edit-allowlist-price').value = (al.custom_price !== null && al.custom_price !== undefined && al.custom_price !== '') ? parseFloat(al.custom_price) : '';
        document.getElementById('edit-allowlist-hours').value = al.early_access_hours || 24;
        document.getElementById('edit-allowlist-max').value = al.max_mint_override || '';
        const isDiscount = (al.type === 'discount' || al.type === 'discount_limited');
        document.getElementById('edit-allowlist-discount-wrap').style.display = isDiscount ? 'block' : 'none';
        document.getElementById('edit-allowlist-hours-wrap').style.display = al.type === 'early_access' ? 'block' : 'none';
        document.getElementById('edit-allowlist-max-wrap').style.display = al.type === 'limit_override' ? 'block' : 'none';
        // v731 (A4b): per-token rules for discount types (hidden on free-claim lists)
        cdBuildMatrixUI('edit-allowlist-matrix', al, isDiscount);
        document.getElementById('cd-edit-allowlist-modal').classList.add('active');
    };

    // v731 (A4b): shared per-token matrix builder — rows from the listing's accepted
    // currencies (allListings carries the JSON via get_by_artist SELECT *).
    window.cdMatrixEnabled = function() {
        return document.getElementById('cd-edit-allowlist-modal')?.dataset.matrixEnabled === '1';
    };
    window.cdBuildMatrixUI = function(prefix, al, isDiscount) {
        const wrap = document.getElementById(prefix + '-wrap');
        const rows = document.getElementById(prefix + '-rows');
        if (!wrap || !rows) return;
        const isFree = al && al.custom_price !== null && al.custom_price !== undefined && al.custom_price !== '' && parseFloat(al.custom_price) === 0;
        const listing = allListings.find(l => String(l.id) === String(al ? al.listing_id : currentListingId));
        let tokens = [];
        try {
            // v733: get_by_artist runs listings through format_listing, which ALREADY
            // json_decodes accepted_currencies into an array (v71) — JSON.parse on an
            // array threw here, emptied tokens, and token-priced listings displayed as
            // "accepts XRP only". Accept both shapes.
            let ac = listing ? listing.accepted_currencies : [];
            if (typeof ac === 'string') { ac = JSON.parse(ac); }
            if (!Array.isArray(ac)) ac = [];
            tokens = ac.filter(c => c && c.enabled !== false && String(c.currency).toUpperCase() !== 'XRP' && parseFloat(c.price || 0) > 0);
        } catch (e) { tokens = []; }
        // v732: never hide silently. For discount lists the section always renders:
        // XRP-only listings get an explainer instead of rows, and an explicit XRP line
        // always states where the BASE discount applies.
        const show = cdMatrixEnabled() && isDiscount && !isFree;
        wrap.style.display = show ? 'block' : 'none';
        if (!show) { rows.innerHTML = ''; return; }
        const xrpLine = '<div style="display:flex;gap:8px;align-items:center;margin-top:6px;color:var(--cd-text-muted);font-size:0.85em;">XRP → base discount above</div>';
        if (tokens.length === 0) {
            rows.innerHTML = xrpLine + '<div style="color:var(--cd-text-muted);font-size:0.85em;margin-top:4px;">This listing accepts XRP only — add token pricing to the listing to unlock per-token rules.</div>';
            return;
        }
        let existing = {};
        try {
            const cb = al && al.currency_benefits ? JSON.parse(al.currency_benefits) : [];
            (Array.isArray(cb) ? cb : []).forEach(r => { existing[String(r.currency).toUpperCase()] = r; });
        } catch (e) {}
        rows.innerHTML = xrpLine + tokens.map(t => {
            const cur = String(t.currency).toUpperCase();
            const prev = existing[cur] || {};
            return `
            <div class="cd-matrix-row" data-currency="${cur}" data-issuer="${t.issuer || ''}" data-price="${t.price || 0}" style="display:flex;gap:8px;align-items:center;margin-top:6px;flex-wrap:wrap;">
                <label style="display:flex;align-items:center;gap:4px;min-width:90px;"><input type="checkbox" class="cd-mx-on" ${prev.mode ? 'checked' : ''} onchange="cdMatrixPreview(this)"> ${cur}</label>
                <select class="cd-mx-mode" style="width:auto;" onchange="cdMatrixPreview(this)">
                    <option value="percent" ${prev.mode !== 'price' ? 'selected' : ''}>% off</option>
                    <option value="price" ${prev.mode === 'price' ? 'selected' : ''}>fixed price</option>
                </select>
                <input type="number" class="cd-mx-value" style="width:110px;" min="0.000001" step="0.000001" placeholder="value" value="${prev.value !== undefined ? prev.value : ''}" oninput="cdMatrixPreview(this)">
                <span class="cd-mx-preview" style="color:var(--cd-text-muted);font-size:0.85em;"></span>
            </div>`;
        }).join('');
        rows.querySelectorAll('.cd-mx-on').forEach(el => cdMatrixPreview(el));
    };
    window.cdMatrixPreview = function(el) {
        const row = el.closest('.cd-matrix-row');
        if (!row) return;
        const on = row.querySelector('.cd-mx-on')?.checked;
        const mode = row.querySelector('.cd-mx-mode')?.value;
        const v = parseFloat(row.querySelector('.cd-mx-value')?.value);
        const price = parseFloat(row.dataset.price) || 0;
        const out = row.querySelector('.cd-mx-preview');
        if (!out) return;
        if (!on || !Number.isFinite(v)) { out.textContent = ''; return; }
        if (mode === 'percent') {
            out.textContent = (v >= 1 && v <= 99 && price > 0) ? ('→ pays ' + (price * (1 - v / 100)) .toFixed(6).replace(/\.?0+$/, '') + ' ' + row.dataset.currency) : '1-99% only (use FREE price 0 for free)';
        } else {
            out.textContent = v > 0 ? ('→ pays ' + v + ' ' + row.dataset.currency) : 'must be > 0';
        }
    };
    window.cdCollectMatrix = function(prefix) {
        const rules = [];
        document.querySelectorAll('#' + prefix + '-rows .cd-matrix-row').forEach(r => {
            if (!r.querySelector('.cd-mx-on')?.checked) return;
            const mode = r.querySelector('.cd-mx-mode')?.value === 'price' ? 'price' : 'percent';
            const v = parseFloat(r.querySelector('.cd-mx-value')?.value);
            if (!Number.isFinite(v)) return;
            if (mode === 'percent' && (v < 1 || v > 99)) return;
            if (mode === 'price' && !(v > 0)) return;
            rules.push({ currency: r.dataset.currency, issuer: r.dataset.issuer || undefined, mode: mode, value: v });
        });
        return rules;
    };

    window.cdCloseEditAllowlist = function() {
        document.getElementById('cd-edit-allowlist-modal').classList.remove('active');
    };

    window.cdSaveEditAllowlist = async function() {
        const id = document.getElementById('edit-allowlist-id').value;
        if (!id) return;
        const al = currentAllowlists.find(a => a.id == id);
        const formData = new FormData();
        formData.append('action', 'update');
        formData.append('allowlist_id', id);
        formData.append('artist_account', CONFIG.account);
        formData.append('name', document.getElementById('edit-allowlist-name').value.trim());
        formData.append('description', document.getElementById('edit-allowlist-description').value.trim());
        if (al && (al.type === 'discount' || al.type === 'discount_limited')) {
            formData.append('discount_percent', document.getElementById('edit-allowlist-percent').value);
            formData.append('custom_price', document.getElementById('edit-allowlist-price').value);
            // v731 (A4b): per-token rules — send [] to clear, JSON to set (flag-gated UI)
            if (cdMatrixEnabled()) {
                formData.append('currency_benefits', JSON.stringify(cdCollectMatrix('edit-allowlist-matrix')));
            }
        }
        if (al && al.type === 'early_access') {
            formData.append('early_access_hours', document.getElementById('edit-allowlist-hours').value || '24');
        }
        if (al && al.type === 'limit_override') {
            formData.append('max_mint_override', document.getElementById('edit-allowlist-max').value);
        }
        formData.append('nonce', CONFIG.nonce);
        try {
            const response = await fetch(CONFIG.endpoints.allowlistHandler, { method: 'POST', body: formData });
            const data = await response.json();
            if (data.success) {
                showToast('Allowlist updated!', 'success');
                cdCloseEditAllowlist();
                await fetchAllowlists();
            } else {
                showToast(data.error || 'Failed to update allowlist', 'error');
            }
        } catch (err) { showToast('Error updating allowlist', 'error'); }
    };

    // Delete allowlist
    window.cdDeleteAllowlist = async function(allowlistId) {
        if (!confirm('Delete this allowlist and all its entries? This cannot be undone.')) return;
        
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('allowlist_id', allowlistId);
        formData.append('artist_account', CONFIG.account);
        formData.append('nonce', CONFIG.nonce);
        
        try {
            const response = await fetch(CONFIG.endpoints.allowlistHandler, { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success) {
                showToast('Allowlist deleted!', 'success');
                await fetchAllowlists();
            } else {
                showToast(data.error || 'Failed to delete allowlist', 'error');
            }
        } catch (err) {
            showToast('Error deleting allowlist', 'error');
        }
    };
    
    // Manage entries modal
    window.cdManageEntries = async function(allowlistId, name) {
        // v730 (A4): entries-modal context — paging + quantity-aware field visibility
        cdEntriesOffset = 0;
        const _mAl = currentAllowlists.find(a => a.id == allowlistId);
        cdEntriesType = _mAl ? _mAl.type : '';
        const _usesQty = (cdEntriesType === 'discount_limited' || cdEntriesType === 'limit_override');
        const _dqw = document.getElementById('entries-default-qty-wrap');
        if (_dqw) _dqw.style.display = _usesQty ? 'block' : 'none';
        const _cdqw = document.getElementById('entries-csv-default-qty-wrap');
        if (_cdqw) _cdqw.style.display = _usesQty ? 'block' : 'none';
        const _cqh = document.getElementById('entries-csv-qty-help');
        if (_cqh) _cqh.style.display = _usesQty ? 'block' : 'none';
        currentAllowlistId = allowlistId;
        document.getElementById('entries-allowlist-id').value = allowlistId;
        document.getElementById('entries-modal-title').textContent = `${name} - Wallets`;
        document.getElementById('entries-wallets').value = '';
        
        cdSwitchEntriesTab('add');
        document.getElementById('cd-entries-modal').classList.add('active');
        
        await loadEntries();
    };
    
    window.cdCloseEntriesModal = function() {
        document.getElementById('cd-entries-modal').classList.remove('active');
        currentAllowlistId = null;
    };
    
    window.cdSwitchEntriesTab = function(tab) {
        // v254: Scoped to #cd-entries-modal to avoid deactivating tabs in the create-allowlist modal
        document.querySelectorAll('#cd-entries-modal .cd-entries-tab').forEach(t => t.classList.remove('active'));
        const activeTab = document.querySelector(`#cd-entries-modal .cd-entries-tab[data-tab="${tab}"]`);
        if (activeTab) activeTab.classList.add('active');
        
        document.getElementById('entries-tab-add').style.display = tab === 'add' ? 'block' : 'none';
        document.getElementById('entries-tab-list').style.display = tab === 'list' ? 'block' : 'none';
        document.getElementById('entries-tab-csv').style.display = tab === 'csv' ? 'block' : 'none';
        
        if (tab === 'list') loadEntries();
    };
    
    // v730 (A4): paging state + list type for the entries modal
    let cdEntriesOffset = 0;
    let cdEntriesType = '';
    const CD_ENTRIES_PAGE = 200;

    async function loadEntries() {
        const container = document.getElementById('entries-list-container');
        container.innerHTML = '<div style="text-align:center;padding:1rem;color:var(--cd-text-muted);">Loading...</div>';
        
        try {
            const response = await fetch(`${CONFIG.endpoints.allowlistHandler}?action=get_entries&allowlist_id=${currentAllowlistId}&artist_account=${CONFIG.account}&limit=${CD_ENTRIES_PAGE}&offset=${cdEntriesOffset}`);
            const data = await response.json();
            
            if (data.success) {
                const total = data.data.total || 0;
                document.getElementById('entries-count').textContent = total;
                
                if (!data.data.entries || data.data.entries.length === 0) {
                    container.innerHTML = '<div style="text-align:center;padding:1rem;color:var(--cd-text-muted);">No wallets in this allowlist yet.</div>';
                    return;
                }
                
                // v730 (A4/G1): amounts are finally VISIBLE — the gap that let B1 go
                // unnoticed. Quantity-aware lists show ×N (or a repair flag), and ✎
                // edits an amount in place via add_entries' ON DUPLICATE path.
                const usesQty = (cdEntriesType === 'discount_limited' || cdEntriesType === 'limit_override');
                const rows = data.data.entries.map(e => `
                    <div class="cd-entry-row">
                        <div>
                            <span class="cd-entry-wallet">${e.wallet_address.slice(0, 8)}...${e.wallet_address.slice(-6)}</span>
                            ${usesQty ? (e.custom_max_mint !== null && e.custom_max_mint !== undefined && e.custom_max_mint !== '' ? `<span class="cd-entry-label" style="color:var(--cd-accent,#4a9eff);">× ${e.custom_max_mint}</span>` : `<span class="cd-entry-label" style="color:#f0a020;" title="Added before amounts were supported — set one with <svg class="imc-ic" aria-hidden="true"><use href="#ic-pencil"></use></svg> "><svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> no amount</span>`) : ''}
                            ${e.label ? `<span class="cd-entry-label">(${escapeHtml(e.label)})</span>` : ''}
                            ${e.minted_count > 0 ? `<span class="cd-entry-label">• ${e.minted_count} minted</span>` : ''}
                        </div>
                        <div>
                            ${usesQty ? `<button class="cd-entry-remove" style="margin-right:6px;" title="Set amount" onclick="cdEditEntryAmount('${e.wallet_address}', ${e.custom_max_mint !== null && e.custom_max_mint !== undefined && e.custom_max_mint !== '' ? e.custom_max_mint : 'null'})"><svg class="imc-ic" aria-hidden="true"><use href="#ic-pencil"></use></svg> </button>` : ''}
                            <button class="cd-entry-remove" onclick="cdRemoveEntry(${e.id})"><svg class="imc-ic" aria-hidden="true"><use href="#ic-x-circle"></use></svg> </button>
                        </div>
                    </div>
                `).join('');

                const from = cdEntriesOffset + 1;
                const to = cdEntriesOffset + data.data.entries.length;
                const pager = total > CD_ENTRIES_PAGE ? `
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:0.5rem 0;">
                        <button class="cd-btn cd-btn-secondary" ${cdEntriesOffset === 0 ? 'disabled' : ''} onclick="cdEntriesPage(-1)">← Prev</button>
                        <span style="color:var(--cd-text-muted);font-size:0.85em;">${from}–${to} of ${total}</span>
                        <button class="cd-btn cd-btn-secondary" ${to >= total ? 'disabled' : ''} onclick="cdEntriesPage(1)">Next →</button>
                    </div>` : '';
                container.innerHTML = rows + pager;
            }
        } catch (err) {
            container.innerHTML = '<div style="text-align:center;padding:1rem;color:var(--cd-danger);">Error loading entries.</div>';
        }
    }

    window.cdEntriesPage = function(dir) {
        cdEntriesOffset = Math.max(0, cdEntriesOffset + dir * CD_ENTRIES_PAGE);
        loadEntries();
    };

    // v730 (A4/G2): in-place amount edit — reuses add_entries ("wallet,amount"), whose
    // ON DUPLICATE KEY UPDATE (v727) makes it an update. No new backend surface.
    window.cdEditEntryAmount = async function(wallet, current) {
        const input = prompt('Amount for ' + wallet.slice(0, 8) + '...' + wallet.slice(-6) + '\n(total mints allowed at this list\'s benefit — not a top-up)', current !== null ? current : '');
        if (input === null) return;
        const qty = parseInt(input, 10);
        if (!Number.isFinite(qty) || qty < 1 || qty > 99999) {
            showToast('Amount must be between 1 and 99999', 'error');
            return;
        }
        const formData = new FormData();
        formData.append('action', 'add_entries');
        formData.append('allowlist_id', currentAllowlistId);
        formData.append('artist_account', CONFIG.account);
        formData.append('wallets', wallet + ',' + qty);
        formData.append('nonce', CONFIG.nonce);
        try {
            const response = await fetch(CONFIG.endpoints.allowlistHandler, { method: 'POST', body: formData });
            const data = await response.json();
            if (data.success) {
                showToast('Amount set to ' + qty, 'success');
                await loadEntries();
                await fetchAllowlists();
            } else {
                showToast(data.error || 'Failed to set amount', 'error');
            }
        } catch (err) { showToast('Error setting amount', 'error'); }
    };
    
    // Add entries
    window.cdAddEntries = async function() {
        const wallets = document.getElementById('entries-wallets').value.trim();
        if (!wallets) {
            showToast('Please enter wallet addresses', 'error');
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'add_entries');
        formData.append('allowlist_id', currentAllowlistId);
        formData.append('artist_account', CONFIG.account);
        formData.append('wallets', wallets);
        // v730 (A4): default amount for quantity-aware lists (v727 backend support)
        const _dq = document.getElementById('entries-default-qty')?.value;
        if (_dq) formData.append('default_quantity', _dq);
        formData.append('nonce', CONFIG.nonce);
        
        try {
            const response = await fetch(CONFIG.endpoints.allowlistHandler, { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success) {
                showToast(`Added ${data.data.added} wallets!` + (data.data.updated > 0 ? ` Updated: ${data.data.updated}.` : '') + (data.data.invalid > 0 ? ` (${data.data.invalid} invalid)` : ''), 'success');
                document.getElementById('entries-wallets').value = '';
                await loadEntries();
                await fetchAllowlists();
            } else {
                showToast(data.error || 'Failed to add wallets', 'error');
            }
        } catch (err) {
            showToast('Error adding wallets', 'error');
        }
    };
    
    // Upload CSV
    window.cdUploadCSV = async function() {
        const fileInput = document.getElementById('entries-csv-file');
        if (!fileInput.files || !fileInput.files[0]) {
            showToast('Please select a CSV file', 'error');
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'upload_csv');
        formData.append('allowlist_id', currentAllowlistId);
        formData.append('artist_account', CONFIG.account);
        formData.append('csv_file', fileInput.files[0]);
        // v730 (A4): default amount for rows without a quantity column (v727 backend support)
        const _cdq = document.getElementById('entries-csv-default-qty')?.value;
        if (_cdq) formData.append('default_quantity', _cdq);
        formData.append('nonce', CONFIG.nonce);
        
        try {
            const response = await fetch(CONFIG.endpoints.allowlistHandler, { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success) {
                showToast(`Processed! Added: ${data.data.added}, Updated: ${data.data.updated}` + (data.data.invalid > 0 ? `, Invalid: ${data.data.invalid}` : ''), 'success');
                fileInput.value = '';
                await loadEntries();
                await fetchAllowlists();
            } else {
                showToast(data.error || 'Failed to process CSV', 'error');
            }
        } catch (err) {
            showToast('Error uploading CSV', 'error');
        }
    };
    
    // Remove single entry
    window.cdRemoveEntry = async function(entryId) {
        const formData = new FormData();
        formData.append('action', 'remove_entry');
        formData.append('entry_id', entryId);
        formData.append('artist_account', CONFIG.account);
        formData.append('nonce', CONFIG.nonce);
        
        try {
            const response = await fetch(CONFIG.endpoints.allowlistHandler, { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success) {
                showToast('Wallet removed!', 'success');
                await loadEntries();
                await fetchAllowlists();
            } else {
                showToast(data.error || 'Failed to remove wallet', 'error');
            }
        } catch (err) {
            showToast('Error removing wallet', 'error');
        }
    };
    
    // Close entries modal on overlay click
    document.getElementById('cd-entries-modal').addEventListener('click', function(e) {
        if (e.target === this) cdCloseEntriesModal();
    });
    
    // R-5d: removed with the modal. Third time this pattern has come up in R-5 -
    // getElementById returns null for a removed node and addEventListener on null
    // THROWS, killing every listener registered after it. Checked the remaining
    // overlay handlers rather than assuming; they all still have their nodes.
    
    // ════════════════════════════════════════════════════════════════════════
    // Edit Schedule (v249)
    // ════════════════════════════════════════════════════════════════════════

    window.cdEditSchedule = function(listingId, currentLaunchAt) {
        document.getElementById('schedule-listing-id').value = listingId;
        document.getElementById('schedule-resume-on-save').value = '0'; // v412: Not a release — just editing
        document.getElementById('schedule-modal-title').textContent = 'Edit Launch Schedule';

        const input = document.getElementById('schedule-datetime-input');

        // Pre-fill with existing launch_at (stored as UTC in DB)
        if (currentLaunchAt) {
            // Normalise: DB stores without Z, so add it so JS parses as UTC
            const utcStr = currentLaunchAt.includes('Z') ? currentLaunchAt : currentLaunchAt + 'Z';
            const d = new Date(utcStr);
            if (!isNaN(d)) {
                // datetime-local input requires local time as "YYYY-MM-DDTHH:MM"
                const pad = n => String(n).padStart(2, '0');
                input.value = `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
                cdUpdateSchedulePreview();
            }
        } else {
            input.value = '';
            document.getElementById('schedule-preview-text').textContent = '';
        }

        // Enforce minimum: at least 1 hour from now
        const minDate = new Date(Date.now() + 60 * 60 * 1000);
        const pad = n => String(n).padStart(2, '0');
        input.min = `${minDate.getFullYear()}-${pad(minDate.getMonth()+1)}-${pad(minDate.getDate())}T${pad(minDate.getHours())}:${pad(minDate.getMinutes())}`;

        // R-5c: already on screen - the panel is part of the listing manager.
    };

    // R-5c: no modal to close. Kept as a no-op that still clears the flag, because
    // cdSaveSchedule's success path calls it and leaving resume-on-save set to 1
    // would make the NEXT save silently resume a listing nobody asked to resume.
    window.cdCloseScheduleModal = function() {
        const f = document.getElementById('schedule-resume-on-save');
        if (f) f.value = '0';                       // v412's reset, preserved
    };

    // Replaces the modal's Cancel: re-read the listing and repaint the form in the
    // right mode for its current status.
    window.cdLmReloadSchedule = function () {
        const l = allListings.find(x => String(x.id) === String(currentListingId));
        if (!l) return;
        cdLmOpenSchedule(l);
        showToast('Schedule reset', 'success');
    };

    window.cdUpdateSchedulePreview = function() {
        const input = document.getElementById('schedule-datetime-input').value;
        const preview = document.getElementById('schedule-preview-text');
        if (!input) { preview.textContent = ''; return; }
        const d = new Date(input); // Interpreted as local time by the browser
        if (isNaN(d)) { preview.textContent = ''; return; }
        const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
        const formatted = d.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
                        + ' at '
                        + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
        preview.textContent = `Goes live: ${formatted} (${tz})`;
    };

    window.cdSaveSchedule = async function() {
        const listingId = document.getElementById('schedule-listing-id').value;
        const input     = document.getElementById('schedule-datetime-input').value;
        const saveBtn   = document.getElementById('schedule-save-btn');

        if (!input) { showToast('Please select a date and time.', 'error'); return; }

        const localDate = new Date(input);
        if (isNaN(localDate)) { showToast('Invalid date selected.', 'error'); return; }
        if (localDate <= new Date()) { showToast('Launch date must be in the future.', 'error'); return; }

        // Convert local datetime to UTC string in the format the backend expects: "YYYY-MM-DD HH:MM:SS"
        const utcStr = localDate.toISOString().slice(0, 19).replace('T', ' ');

        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving…';

        try {
            const formData = new FormData();
            formData.append('action', 'update');
            formData.append('listing_id', listingId);
            formData.append('artist_account', CONFIG.account);
            formData.append('nonce', CONFIG.nonce);
            formData.append('launch_type', 'scheduled');
            formData.append('launch_at', utcStr);

            // v412: Schedule Release — also resume the paused listing
            const isRelease = document.getElementById('schedule-resume-on-save').value === '1';
            if (isRelease) {
                formData.append('status', 'active');
            }

            const resp = await fetch(CONFIG.endpoints.listingsHandler, { method: 'POST', body: formData });
            const data = await resp.json();

            if (data.success) {
                cdCloseScheduleModal();
                showToast(isRelease ? 'Collection scheduled for release! <svg class="imc-ic" aria-hidden="true"><use href="#ic-calendar"></use></svg> <svg class="imc-ic" aria-hidden="true"><use href="#ic-rocket"></use></svg> ' : 'Launch schedule updated! <svg class="imc-ic" aria-hidden="true"><use href="#ic-calendar"></use></svg> ', 'success');
                await fetchListings();
                cdRefreshCurrentView();          // R-9b
            } else {
                throw new Error(data.error || 'Failed to update schedule');
            }
        } catch (err) {
            showToast('Error: ' + err.message, 'error');
        } finally {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save Schedule';
        }
    };

    // R-5c: the backdrop-click handler went with the modal. getElementById would
    // return null here and addEventListener on null THROWS - which would have
    // killed every listener registered after this line, silently. R-5b hit the
    // identical trap on the pricing overlay; this is the same fix.
    // The four handlers that remain (cancel, entries, allowlist, edit-collection)
    // all target modals that still exist - checked, not assumed.

    // ════════════════════════════════════════════════════════════════════════
    // v507 Phase C-hotfix: Edit Collection (name + cover + description)
    // Lives INSIDE the dashboard IIFE — the v506 block sat outside the closure
    // and threw ReferenceError on CONFIG/currentCollection (the dead-button bug).
    // Covers pinned via upload-handler (Pinata) like the mint wizard. A rename
    // cascades to imc_listings server-side; the dashboard reloads to stay in sync.
    // ════════════════════════════════════════════════════════════════════════
    let cdEcCoverIpfs = null;   // ipfs:// of a newly uploaded cover; null = unchanged
    let cdEcOriginalName = '';  // to detect renames

    window.cdOpenEditCollection = async function() {
        if (!currentCollection) return;
        try {
            const res = await fetch(`${CONFIG.endpoints.collectionsApi}?action=get_by_artist&account=${encodeURIComponent(CONFIG.account)}`);
            const data = await res.json();
            const cols = (data && data.collections) || [];
            const col = cols.find(c => (c.collection_name || '') === currentCollection);
            if (!col) {
                showToast('Collection record not found for this account', 'error');
                return;
            }
            document.getElementById('cd-ec-id').value = col.id;
            cdEcOriginalName = col.collection_name || '';
            document.getElementById('cd-ec-name').value = cdEcOriginalName;
            document.getElementById('cd-ec-desc').value = col.collection_description || '';
            cdEcCoverIpfs = null;
            document.getElementById('cd-ec-cover-preview').src = col.cover_image_ipfs ? ipfsToHttp(col.cover_image_ipfs) : '';
            document.getElementById('cd-ec-upload-status').textContent = '';
            document.getElementById('cd-edit-collection-modal').classList.add('active');
        } catch (e) {
            showToast('Failed to load collection details', 'error');
        }
    };

    window.cdCloseEditCollection = function() {
        document.getElementById('cd-edit-collection-modal').classList.remove('active');
    };

    window.cdEcUploadCover = async function(input) {
        const file = input.files && input.files[0];
        if (!file) return;
        const status = document.getElementById('cd-ec-upload-status');
        status.textContent = 'Uploading to IPFS…';
        try {
            const fd = new FormData();
            fd.append('action', 'file');
            fd.append('nonce', CONFIG.nonce);
            fd.append('type', 'cover');
            fd.append('file', file);
            const res = await fetch(CONFIG.endpoints.uploadHandler, { method: 'POST', body: fd });
            const data = await res.json();
            // v516 FIX: upload-handler responds via wp_send_json_success/_error, which
            // nests the payload under data.data ({success:true,data:{ipfs_url:...}}).
            // v507 read data.ipfs_url (undefined) so successful Pinata uploads were
            // reported as failures and the cover never saved — for every user.
            const payload = (data && typeof data.data === 'object' && data.data) ? data.data : (data || {});
            if (data && data.success && payload.ipfs_url) {
                cdEcCoverIpfs = payload.ipfs_url;
                document.getElementById('cd-ec-cover-preview').src = ipfsToHttp(payload.ipfs_url);
                status.textContent = 'New cover uploaded — click Save Changes to apply';
            } else {
                const errMsg = (payload && payload.error) || (data && data.error) || '';
                status.textContent = 'Upload failed' + (errMsg ? ': ' + errMsg : '');
            }
        } catch (e) {
            status.textContent = 'Upload failed — please try again';
        }
        input.value = '';
    };

    window.cdSaveEditCollection = async function() {
        const id = document.getElementById('cd-ec-id').value;
        if (!id) return;
        const newName = document.getElementById('cd-ec-name').value.trim();
        if (!newName) {
            showToast('Collection name cannot be empty', 'error');
            return;
        }
        const renamed = newName !== cdEcOriginalName;
        const btn = document.getElementById('cd-ec-save');
        btn.disabled = true;
        btn.textContent = 'Saving…';
        try {
            const body = new URLSearchParams();
            body.append('action', 'update');
            body.append('nonce', CONFIG.nonce);
            body.append('id', id);
            body.append('artist_account', CONFIG.account);
            body.append('collection_description', document.getElementById('cd-ec-desc').value);
            if (renamed) body.append('collection_name', newName);
            if (cdEcCoverIpfs) body.append('cover_image_ipfs', cdEcCoverIpfs);
            const res = await fetch(CONFIG.endpoints.collectionsApi, { method: 'POST', body });
            const data = await res.json();
            if (data && data.success) {
                cdCloseEditCollection();
                if (renamed) {
                    showToast('Collection renamed — refreshing dashboard…');
                    setTimeout(function() { location.reload(); }, 900);
                } else {
                    showToast('Collection updated — changes are live on the public page');
                }
            } else {
                showToast('Update failed' + (data && data.error ? ': ' + data.error : ''), 'error');
            }
        } catch (e) {
            showToast('Update failed — please try again', 'error');
        }
        btn.disabled = false;
        btn.textContent = 'Save Changes';
    };

    document.getElementById('cd-edit-collection-modal').addEventListener('click', function(e) {
        if (e.target === this) cdCloseEditCollection();
    });

    // ════════════════════════════════════════════════════════════════════════
    // Initialize
    // ════════════════════════════════════════════════════════════════════════
    
    if (CONFIG.account) {
        fetchListings();
    }
    
    console.log('Creator Dashboard v69 initialized - Allowlist fixes + UI polish');
    
})();


</script>

<?php get_footer(); ?>