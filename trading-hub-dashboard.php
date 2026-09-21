<?php
/**
 * Template Name: Trading Hub Dashboard
 * File: trading-hub-dashboard.php
 * Path: /public_html/wp-content/themes/astra/page-templates/trading-hub-dashboard.php
 * Description: Cafe-style offer dashboard with collapsible sections
 * Version: 3.0.0 - Separate sections for each offer type
 */

get_header();

$xrpl_account = function_exists('imc_session_wallet') ? imc_session_wallet() : '';
$nonce = wp_create_nonce('xrpl_marketplace_nonce');

$endpoints_json = wp_json_encode([
    'xummProxy'          => home_url('/xumm-proxy.php'),
    'offerHandler'       => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/offer-handler.php',
    'nftLoader'          => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/nft-loader.php',
    'watchlistHandler'   => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/watchlist-handler.php',
    'taskHandler'        => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/task-handler.php',
    'filterHandler'      => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/filter-handler.php',
    'collectionsHandler' => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/collections-handler.php',
    'listingsHandler'    => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/listings-handler.php',
    'mintOnDemand'       => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/mint-on-demand-handler.php',
]);
?>

<style>
/* Loading Overlay */
.dashboard-loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(10, 10, 20, 0.85);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    z-index: 9998;
    backdrop-filter: blur(4px);
    transition: opacity 0.3s ease;
}

.dashboard-loading-overlay.hidden {
    opacity: 0;
    pointer-events: none;
}

.dashboard-spinner {
    width: 60px;
    height: 60px;
    border: 4px solid rgba(212, 175, 55, 0.2);
    border-top-color: var(--gold, #d4af37);
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-bottom: 1.5rem;
}

.dashboard-loading-text {
    color: var(--gold, #d4af37);
    font-size: 1.1rem;
    font-weight: 500;
    margin-bottom: 0.5rem;
}

.dashboard-loading-subtext {
    color: var(--text-muted, #888);
    font-size: 0.9rem;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* ===== QR Code Popup - Compact Professional Design ===== */
#qr-code-popup {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.88);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 999999;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    animation: qrBackdropIn 0.25s ease-out;
}

@keyframes qrBackdropIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes qrCardIn {
    from { opacity: 0; transform: scale(0.92) translateY(10px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}

@keyframes statusPulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.4; }
}

/* v198: Force Astra header behind popup */
body.imc-modal-open .site-header,
body.imc-modal-open #masthead,
body.imc-modal-open .ast-primary-header,
body.imc-modal-open .ast-above-header-wrap,
body.imc-modal-open .ast-header-stacked,
body.imc-modal-open .ast-mobile-header-wrap,
body.imc-modal-open header.entry-header {
    z-index: 1 !important;
    position: relative !important;
}

.qr-popup-card {
    background: linear-gradient(165deg, #1c1c2e 0%, #141420 100%);
    border: 1px solid rgba(212, 175, 55, 0.35);
    border-radius: 20px;
    padding: 1.75rem 2rem 1.5rem;
    width: 320px;
    max-width: 92vw;
    text-align: center;
    position: relative;
    box-shadow: 
        0 25px 60px rgba(0, 0, 0, 0.5),
        0 0 0 1px rgba(255, 255, 255, 0.03) inset,
        0 0 80px -20px rgba(212, 175, 55, 0.15);
    animation: qrCardIn 0.35s cubic-bezier(0.16, 1, 0.3, 1);
}

.qr-close-btn {
    position: absolute;
    top: 12px;
    right: 12px;
    width: 32px;
    height: 32px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 8px;
    color: #666;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.qr-close-btn:hover {
    background: rgba(239, 68, 68, 0.15);
    border-color: rgba(239, 68, 68, 0.3);
    color: #ef4444;
}

.qr-header {
    margin-bottom: 1.25rem;
}

.qr-icon {
    width: 44px;
    height: 44px;
    background: linear-gradient(135deg, rgba(212, 175, 55, 0.15), rgba(212, 175, 55, 0.05));
    border: 1px solid rgba(212, 175, 55, 0.25);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 0.75rem;
    color: var(--gold, #d4af37);
}

.qr-popup-card h3 {
    color: #fff;
    font-size: 1.25rem;
    font-weight: 600;
    margin: 0 0 0.25rem;
    letter-spacing: -0.3px;
}

.qr-subtitle {
    color: #888;
    font-size: 0.85rem;
    margin: 0;
}

.qr-code-wrapper {
    background: #fff;
    border-radius: 12px;
    padding: 12px;
    display: inline-block;
    margin-bottom: 1rem;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
}

.qr-code-wrapper img {
    display: block;
    width: 180px;
    height: 180px;
}

.qr-offer-info {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    margin-bottom: 1rem;
    font-size: 0.8rem;
}

.qr-offer-label {
    color: #666;
}

.qr-offer-value {
    color: #999;
    font-family: 'SF Mono', 'Monaco', 'Consolas', monospace;
    background: rgba(255, 255, 255, 0.04);
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
}

.qr-xaman-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    width: 100%;
    padding: 0.875rem 1.5rem;
    background: linear-gradient(135deg, #d4af37, #b8962e);
    color: #000;
    font-weight: 600;
    font-size: 0.95rem;
    text-decoration: none;
    border-radius: 10px;
    transition: all 0.2s ease;
    box-shadow: 0 4px 15px rgba(212, 175, 55, 0.3);
}

.qr-xaman-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 25px rgba(212, 175, 55, 0.4);
    background: linear-gradient(135deg, #e0bc45, #d4af37);
}

.qr-xaman-btn:active {
    transform: translateY(0);
}

.qr-status {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    margin-top: 1rem;
    padding: 0.65rem 1rem;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.05);
    border-radius: 8px;
    font-size: 0.85rem;
    color: #aaa;
}

.qr-status .status-dot {
    width: 8px;
    height: 8px;
    background: var(--gold, #d4af37);
    border-radius: 50%;
    animation: statusPulse 1.5s ease-in-out infinite;
}

.qr-status.success {
    background: rgba(34, 197, 94, 0.1);
    border-color: rgba(34, 197, 94, 0.2);
    color: #22c55e;
}

.qr-status.success .status-dot {
    background: #22c55e;
    animation: none;
}

.qr-status.error {
    background: rgba(239, 68, 68, 0.1);
    border-color: rgba(239, 68, 68, 0.2);
    color: #ef4444;
}

.qr-status.error .status-dot {
    background: #ef4444;
    animation: none;
}

.qr-expires {
    margin: 0.75rem 0 0;
    font-size: 0.7rem;
    color: #555;
}

/* ===== End QR Code Popup ===== */

/* Cafe-style Offer Sections */
.offer-sections-wrapper {
    max-width: 900px;
    margin: 0 auto;
    padding: 0 1rem;
}

/* ── v304: Recently Minted Banner ─────────────────────────────────────────── */
.recently-minted-banner {
    background: linear-gradient(135deg, rgba(201,168,76,0.13) 0%, rgba(155,89,182,0.09) 100%);
    border: 1px solid rgba(201,168,76,0.35);
    border-radius: 14px;
    padding: 1.25rem 1.4rem;
    margin-bottom: 1.5rem;
    animation: recentlyMintedIn 0.4s ease;
}
@keyframes recentlyMintedIn {
    from { opacity: 0; transform: translateY(-10px); }
    to   { opacity: 1; transform: translateY(0); }
}
.recently-minted-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1rem;
}
.recently-minted-heading {
    font-size: 1.05rem;
    margin: 0;
    /* colour + font: .imu-heading-gold via trading-hub.css */
}
.recently-minted-dismiss {
    background: none;
    border: none;
    color: #666;
    cursor: pointer;
    font-size: 1.1rem;
    padding: 0.1rem 0.4rem;
    border-radius: 4px;
    transition: color 0.2s;
}
.recently-minted-dismiss:hover { color: #ccc; }
.recently-minted-scroll {
    display: flex;
    gap: 1rem;
    overflow-x: auto;
    padding-bottom: 0.5rem;
    justify-content: center; /* v327: centre cards when fewer than fill the row */
    scrollbar-width: thin;
    scrollbar-color: rgba(201,168,76,0.3) transparent;
}
/* v327: Footer link below the minted cards */
.recently-minted-footer {
    margin-top: 0.9rem;
    text-align: center;
}
.recently-minted-footer a {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--gold, #c9a84c);
    text-decoration: none;
    opacity: 0.75;
    transition: opacity 0.2s;
    letter-spacing: 0.02em;
}
.recently-minted-footer a:hover { opacity: 1; text-decoration: none; }
.recently-minted-card {
    flex-shrink: 0;
    width: 150px;
    background: rgba(12,13,18,0.85);
    border: 1px solid rgba(201,168,76,0.22);
    border-radius: 10px;
    overflow: hidden;
    transition: border-color 0.2s, transform 0.2s;
}
.recently-minted-card:hover {
    border-color: rgba(201,168,76,0.55);
    transform: translateY(-2px);
}
.recently-minted-card-img {
    width: 100%;
    aspect-ratio: 1;
    object-fit: cover;
    display: block;
}
.recently-minted-card-body {
    padding: 0.55rem 0.6rem 0.3rem;
}
.recently-minted-card-name {
    font-size: 0.8rem;
    font-weight: 600;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 0.15rem;
}
.recently-minted-card-edition {
    font-size: 0.72rem;
    color: #888;
}
.recently-minted-card-link {
    display: block;
    text-align: center;
    font-size: 0.77rem;
    font-weight: 600;
    color: var(--gold, #c9a84c);
    padding: 0.4rem 0.5rem;
    border-top: 1px solid rgba(201,168,76,0.12);
    text-decoration: none;
    transition: background 0.15s;
}
.recently-minted-card-link:hover { background: rgba(201,168,76,0.08); }
.recently-minted-card-link.no-link {
    color: #555;
    cursor: default;
}

.offer-section {
    background: var(--card-bg, #1a1a2e);
    border: 1px solid var(--border-color, #333);
    border-radius: 12px;
    margin-bottom: 0.75rem;
    overflow: hidden;
    transition: all 0.2s ease;
}

.offer-section:hover {
    border-color: rgba(212, 175, 55, 0.5);
}

.offer-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.25rem;
    cursor: pointer;
    user-select: none;
    background: linear-gradient(135deg, rgba(255,255,255,0.02) 0%, rgba(255,255,255,0.05) 100%);
    transition: background 0.2s ease;
}

.offer-section-header:hover {
    background: linear-gradient(135deg, rgba(255,255,255,0.05) 0%, rgba(255,255,255,0.08) 100%);
}

.offer-section-title {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 1rem;
    font-weight: 600;
    margin: 0;
    /* colour + font: offer-section-title Cinzel via trading-hub.css */
}

.section-icon {
    font-size: 1.2rem;
}

.offer-section-meta {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.offer-count-badge {
    background: var(--gold, #d4af37);
    color: #000;
    font-size: 0.75rem;
    font-weight: 700;
    padding: 0.15rem 0.6rem;
    border-radius: 12px;
    min-width: 22px;
    text-align: center;
}

.offer-count-badge.empty {
    background: rgba(255,255,255,0.1);
    color: var(--text-muted, #666);
}

/* P2-J1 (lite): loud token marker on incoming offers — the offer pays in an
   IOU, not XRP. Scam-issuer chip lands with J1-full (needs amount_issuer in
   the get_dashboard_v2 payload). */
.offer-token-chip {
    display: inline-block;
    background: rgba(212,175,55,0.16);
    color: var(--gold, #d4af37);
    border: 1px solid rgba(212,175,55,0.45);
    font-size: 0.68rem;
    font-weight: 700;
    padding: 1px 8px;
    border-radius: 10px;
    vertical-align: middle;
    white-space: nowrap;
}

.section-chevron {
    color: var(--text-muted, #888);
    transition: transform 0.3s ease;
    font-size: 0.75rem;
}

.offer-section.expanded .section-chevron {
    transform: rotate(180deg);
}

.offer-section-content {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.35s ease;
}

.offer-section.expanded .offer-section-content {
    max-height: 2000px;
}

.offer-section-inner {
    padding: 0 1.25rem 1.25rem;
}

.section-empty-msg {
    text-align: center;
    color: var(--text-muted, #666);
    font-size: 0.9rem;
    padding: 1rem 0;
}

.section-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    padding: 1.5rem;
    color: var(--text-muted, #888);
}

.loading-spinner-small {
    width: 18px;
    height: 18px;
    border: 2px solid rgba(212, 175, 55, 0.3);
    border-top-color: var(--gold, #d4af37);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* ===== Watchlist Carousel Styles ===== */
.watchlist-carousel-container {
    position: relative;
    width: 100%;
    overflow: hidden;
}

.watchlist-carousel {
    display: flex;
    gap: 1rem;
    overflow-x: auto;
    scroll-behavior: smooth;
    scrollbar-width: none;
    -ms-overflow-style: none;
    padding: 0.5rem 0;
}

.watchlist-carousel::-webkit-scrollbar {
    display: none;
}

.watchlist-card {
    flex: 0 0 180px;
    background: rgba(0, 0, 0, 0.3);
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid rgba(212, 175, 55, 0.15);
    transition: all 0.2s ease;
    cursor: pointer;
    text-decoration: none;
    display: block;
}

.watchlist-card:hover {
    border-color: var(--gold, #d4af37);
    transform: translateY(-4px);
    box-shadow: 0 8px 25px rgba(212, 175, 55, 0.15);
}

.watchlist-card-image {
    width: 100%;
    aspect-ratio: 1;
    object-fit: cover;
    background: #0a0a0f;
}

.watchlist-card-content {
    padding: 0.75rem;
}

.watchlist-card-name {
    color: #e8e6e3;
    font-weight: 600;
    font-size: 0.85rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 0.4rem;
}

.watchlist-card-actions {
    display: flex;
    gap: 0.5rem;
}

.watchlist-card-btn {
    flex: 1;
    padding: 0.4rem 0.5rem;
    font-size: 0.7rem;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.2s;
}

.watchlist-card-btn.view-btn {
    background: rgba(212, 175, 55, 0.2);
    color: var(--gold, #d4af37);
}

.watchlist-card-btn.view-btn:hover {
    background: rgba(212, 175, 55, 0.35);
}

.watchlist-card-btn.remove-btn {
    background: rgba(255, 107, 107, 0.2);
    color: #ff6b6b;
}

.watchlist-card-btn.remove-btn:hover {
    background: rgba(255, 107, 107, 0.35);
}

.watchlist-scroll-btn {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: rgba(212, 175, 55, 0.9);
    color: #000;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    font-weight: bold;
    z-index: 10;
    opacity: 0;
    transition: opacity 0.2s;
}

.watchlist-carousel-container:hover .watchlist-scroll-btn {
    opacity: 1;
}

.watchlist-scroll-btn:hover {
    background: var(--gold, #d4af37);
}

.watchlist-scroll-btn.scroll-left {
    left: 5px;
}

.watchlist-scroll-btn.scroll-right {
    right: 5px;
}

.watchlist-empty {
    text-align: center;
    padding: 2rem 1rem;
    color: var(--text-muted, #888);
}

.watchlist-empty p {
    margin-bottom: 1rem;
}

.watchlist-empty a {
    color: var(--gold, #d4af37);
    text-decoration: none;
}

.watchlist-empty a:hover {
    text-decoration: underline;
}

@media (max-width: 768px) {
    .watchlist-card {
        flex: 0 0 150px;
    }
    
    .watchlist-scroll-btn {
        display: none;
    }
    
    .purchase-card {
        flex: 0 0 150px;
    }
    
    .purchase-scroll-btn {
        display: none;
    }
}

/* Offer Cards */
.offer-card {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: rgba(0, 0, 0, 0.25);
    border-radius: 10px;
    margin-bottom: 0.6rem;
    border: 1px solid transparent;
    transition: all 0.2s ease;
}

.offer-card:last-child {
    margin-bottom: 0;
}

.offer-card:hover {
    border-color: var(--gold, #d4af37);
    transform: translateX(4px);
}

.offer-card-image {
    width: 56px;
    height: 56px;
    border-radius: 8px;
    object-fit: cover;
    background: #222;
    flex-shrink: 0;
}

.offer-card-details {
    flex: 1;
    min-width: 0;
}

.offer-card-name {
    font-weight: 600;
    font-size: 0.95rem;
    color: var(--text-primary, #fff);
    margin-bottom: 0.3rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.offer-card-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem 1rem;
    font-size: 0.8rem;
    color: var(--text-muted, #aaa);
}

.offer-card-amount {
    color: var(--gold, #d4af37);
    font-weight: 600;
    /* v706: a non-decodable 40-char currency code must never break the row */
    min-width: 0;
    overflow-wrap: anywhere;
}

.offer-card-actions {
    display: flex;
    gap: 0.5rem;
    flex-shrink: 0;
}

.offer-btn {
    padding: 0.5rem 1rem;
    border: none;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.offer-btn:hover {
    transform: translateY(-2px);
}

.btn-claim {
    background: linear-gradient(135deg, var(--gold, #d4af37), #f0c040);
    color: #000;
}

.btn-claim:hover {
    box-shadow: 0 4px 15px rgba(212, 175, 55, 0.4);
}

.btn-accept {
    background: linear-gradient(135deg, #28a745, #34d058);
    color: #fff;
}

.btn-accept:hover {
    box-shadow: 0 4px 15px rgba(40, 167, 69, 0.4);
}

.btn-cancel {
    background: rgba(255, 255, 255, 0.08);
    color: var(--text-primary, #fff);
    border: 1px solid rgba(255,255,255,0.15);
}

.btn-cancel:hover {
    background: rgba(220, 53, 69, 0.15);
    border-color: #dc3545;
    color: #ff6b6b;
}

.btn-decline {
    background: transparent;
    color: var(--text-muted, #888);
    padding: 0.5rem 0.75rem;
}

.btn-decline:hover {
    color: #ff6b6b;
}

/* v396: Block/Unblock wallet buttons */
.btn-block {
    background: transparent;
    color: var(--text-muted, #888);
    padding: 0.5rem 0.6rem;
    font-size: 0.8rem;
    min-width: auto;
}

.btn-block:hover {
    color: #ff6b6b;
    background: rgba(220, 53, 69, 0.1);
}

.btn-unblock {
    background: rgba(255, 255, 255, 0.08);
    color: var(--text-primary, #fff);
    border: 1px solid rgba(255,255,255,0.15);
}

.btn-unblock:hover {
    background: rgba(40, 167, 69, 0.15);
    border-color: #28a745;
    color: #34d058;
}

.blocked-wallet-card .offer-card-name {
    font-family: monospace;
    font-size: 0.8rem;
    word-break: break-all;
}

/* Mobile responsive */
@media (max-width: 600px) {
    .offer-card {
        flex-wrap: wrap;
    }
    .offer-card-actions {
        width: 100%;
        justify-content: flex-end;
        margin-top: 0.5rem;
    }
}

.page-footer-note {
    text-align: center;
    margin-top: 2rem;
    color: var(--text-muted, #555);
    font-size: 0.8rem;
}

/* Purchase History Carousel (matches Watchlist style) */
.purchase-carousel-container {
    position: relative;
    width: 100%;
    overflow: hidden;
}

.purchase-carousel {
    display: flex;
    gap: 1rem;
    overflow-x: auto;
    scroll-behavior: smooth;
    scrollbar-width: none;
    -ms-overflow-style: none;
    padding: 0.5rem 0;
}

.purchase-carousel::-webkit-scrollbar {
    display: none;
}

.purchase-card {
    flex: 0 0 180px;
    background: rgba(0, 0, 0, 0.3);
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid rgba(212, 175, 55, 0.15);
    transition: all 0.2s ease;
    display: block;
    text-decoration: none;
    position: relative;
}

.purchase-card:hover {
    border-color: var(--gold, #d4af37);
    transform: translateY(-4px);
    box-shadow: 0 8px 25px rgba(212, 175, 55, 0.15);
}

.purchase-card-image {
    width: 100%;
    aspect-ratio: 1;
    object-fit: cover;
    background: #0a0a0f;
}

.purchase-card-badge {
    position: absolute;
    top: 8px;
    right: 8px;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.2rem 0.5rem;
    border-radius: 9999px;
    font-size: 0.65rem;
    font-weight: 600;
    backdrop-filter: blur(6px);
    z-index: 2;
}

.purchase-card-badge.delivered {
    background: rgba(16, 185, 129, 0.25);
    color: #10b981;
    border: 1px solid rgba(16, 185, 129, 0.3);
}

.purchase-card-badge.ready {
    background: rgba(212, 175, 55, 0.25);
    color: #d4af37;
    border: 1px solid rgba(212, 175, 55, 0.3);
    animation: purchase-pulse 2s infinite;
}

@keyframes purchase-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(212, 175, 55, 0.3); }
    50% { box-shadow: 0 0 0 4px rgba(212, 175, 55, 0); }
}

.purchase-card-badge.minting {
    background: rgba(99, 102, 241, 0.25);
    color: #6366f1;
    border: 1px solid rgba(99, 102, 241, 0.3);
}

.purchase-card-badge.expired { background: rgba(128,128,136,0.22); color: #9a9aa2; } /* v515 */
.purchase-card-badge.pending {
    background: rgba(160, 160, 176, 0.25);
    color: #a0a0b0;
    border: 1px solid rgba(160, 160, 176, 0.3);
}

.purchase-card-badge.failed {
    background: rgba(239, 68, 68, 0.25);
    color: #ef4444;
    border: 1px solid rgba(239, 68, 68, 0.3);
}

/* v278: Retry button for failed mints */
.purchase-card-btn.retry-btn {
    background: rgba(201,168,76,0.15);
    color: var(--gold, #c9a84c);
    border: 1px solid rgba(201,168,76,0.4);
    padding: 0.35rem 0.75rem;
    border-radius: 6px;
    font-size: 0.75rem;
    cursor: pointer;
    transition: background 0.2s;
    white-space: nowrap;
}
.purchase-card-btn.retry-btn:hover { background: rgba(201,168,76,0.3); }

/* v278: Pending Claims count badge highlights gold when items present */
#count-pending-claims:not(.empty) {
    background: var(--gold, #c9a84c) !important;
    color: #1a1a1a !important;
    font-weight: 700;
}

.purchase-card-content {
    padding: 0.75rem;
}

.purchase-card-name {
    color: #e8e6e3;
    font-weight: 600;
    font-size: 0.85rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 0.2rem;
}

.purchase-card-meta {
    font-size: 0.7rem;
    color: #888;
    margin-bottom: 0.5rem;
}

.purchase-card-actions {
    display: flex;
    gap: 0.5rem;
}

.purchase-card-btn {
    flex: 1;
    padding: 0.4rem 0.5rem;
    font-size: 0.7rem;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.2s;
    text-align: center;
    text-decoration: none;
    display: block;
}

.purchase-card-btn.view-btn {
    background: rgba(212, 175, 55, 0.2);
    color: var(--gold, #d4af37);
}

.purchase-card-btn.view-btn:hover {
    background: rgba(212, 175, 55, 0.35);
}

.purchase-card-btn.claim-btn {
    background: linear-gradient(135deg, var(--imu-gold, var(--imu-gold, #d6ba66)), #c9a227);
    color: #000;
}

.purchase-card-btn.claim-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(212, 175, 55, 0.3);
}

.purchase-card-btn.cancel-btn {
    background: rgba(160, 160, 168, 0.18);
    color: #c9c9d2;
}

.purchase-card-btn.cancel-btn:hover {
    background: rgba(239, 68, 68, 0.22);
    color: #ef8a8a;
}

.purchase-scroll-btn {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: rgba(212, 175, 55, 0.9);
    color: #000;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    font-weight: bold;
    z-index: 10;
    opacity: 0;
    transition: opacity 0.2s;
}

.purchase-carousel-container:hover .purchase-scroll-btn {
    opacity: 1;
}

.purchase-scroll-btn:hover {
    background: var(--gold, #d4af37);
}

.purchase-scroll-btn.scroll-left {
    left: 5px;
}

.purchase-scroll-btn.scroll-right {
    right: 5px;
}

.purchase-empty-state {
    text-align: center;
    padding: 2rem;
    color: var(--text-muted, #888);
}

.purchase-empty-state .empty-icon {
    font-size: 2rem;
    margin-bottom: 0.5rem;
    opacity: 0.6;
}
</style>

<div id="trading-hub-dashboard" class="trading-hub-container full-page-hub"
     data-account="<?php echo esc_attr($xrpl_account); ?>"
     data-nonce="<?php echo esc_attr($nonce); ?>"
     data-endpoints='<?php echo $endpoints_json; ?>'>

    <!-- Loading Overlay -->
    <div id="dashboard-loading" class="dashboard-loading-overlay hidden">
        <div class="dashboard-spinner"></div>
        <div class="dashboard-loading-text">Loading Your Offers</div>
        <div class="dashboard-loading-subtext">Checking XRPL ledger...</div>
    </div>

    <!-- Navigation -->
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
        <a href="<?php echo esc_url(home_url('/trading-hub-dashboard/')); ?>" class="nav-link active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
            Dashboard
        </a>
        <a href="<?php echo esc_url(home_url('/my-nfts/')); ?>" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>
            My NFTs
        </a>
    </nav>

    <header class="marketplace-header">
        <br><h1 class="imu-heading-gold">Offers Hub</h1><br>
    </header>

    <div class="hub-content">
        <!-- v304: Recently Minted Banner — hidden until JS populates it -->
        <div id="recently-minted-banner" class="recently-minted-banner" style="display:none;">
            <div class="recently-minted-top">
                <h2 class="recently-minted-heading">🎉 Your Newly Minted NFTs!</h2>
                <button class="recently-minted-dismiss" onclick="document.getElementById('recently-minted-banner').style.display='none';try{sessionStorage.setItem('_imc_rm_dismissed','1');}catch(e){}" title="Dismiss">✕</button>
            </div>
            <div class="recently-minted-scroll" id="recently-minted-scroll">
                <!-- Cards injected by loadRecentlyMinted() -->
            </div>
            <!-- v327: View Collection link injected by JS once cards are known -->
            <div class="recently-minted-footer" id="recently-minted-footer" style="display:none;"></div>
        </div>

        <div class="offer-sections-wrapper" id="offer-sections">
            
            <!-- Watchlist Section -->
            <div class="offer-section" id="section-watchlist">
                <div class="offer-section-header" onclick="toggleSection('watchlist')">
                    <h3 class="offer-section-title">
                        <span class="section-icon">♥</span>
                        Favourites
                    </h3>
                    <div class="offer-section-meta">
                        <span class="offer-count-badge empty" id="count-watchlist">0</span>
                        <span class="section-chevron">▼</span>
                    </div>
                </div>
                <div class="offer-section-content">
                    <div class="offer-section-inner" id="content-watchlist">
                        <div class="section-loading"><div class="loading-spinner-small"></div> Loading...</div>
                    </div>
                </div>
            </div>

            <!-- ═══ PENDING CLAIMS: Minted (unclaimed) + Failed mints ═══ -->
            <div class="offer-section" id="section-pending-claims">
                <div class="offer-section-header" onclick="toggleSection('pending-claims')">
                    <h3 class="offer-section-title">
                        <span class="section-icon">🎁</span>
                        Pending Claims
                    </h3>
                    <div class="offer-section-meta">
                        <span class="offer-count-badge empty" id="count-pending-claims">0</span>
                        <span class="section-chevron">▼</span>
                    </div>
                </div>
                <div class="offer-section-content">
                    <div class="offer-section-inner" id="content-pending-claims">
                        <div class="section-loading"><div class="loading-spinner-small"></div> Loading...</div>
                    </div>
                </div>
            </div>

            <!-- Incoming Transfers -->
            <div class="offer-section" id="section-incoming-transfers">
                <div class="offer-section-header" onclick="toggleSection('incoming-transfers')">
                    <h3 class="offer-section-title">
                        <span class="section-icon">🎁</span>
                        Incoming transfers
                    </h3>
                    <div class="offer-section-meta">
                        <span class="offer-count-badge empty" id="count-incoming-transfers">0</span>
                        <span class="section-chevron">▼</span>
                    </div>
                </div>
                <div class="offer-section-content">
                    <div class="offer-section-inner" id="content-incoming-transfers">
                        <div class="section-loading"><div class="loading-spinner-small"></div> Loading...</div>
                    </div>
                </div>
            </div>

            <!-- Outgoing Transfers -->
            <div class="offer-section" id="section-outgoing-transfers">
                <div class="offer-section-header" onclick="toggleSection('outgoing-transfers')">
                    <h3 class="offer-section-title">
                        <span class="section-icon">📤</span>
                        Outgoing transfers
                    </h3>
                    <div class="offer-section-meta">
                        <span class="offer-count-badge empty" id="count-outgoing-transfers">0</span>
                        <span class="section-chevron">▼</span>
                    </div>
                </div>
                <div class="offer-section-content">
                    <div class="offer-section-inner" id="content-outgoing-transfers">
                        <div class="section-loading"><div class="loading-spinner-small"></div> Loading...</div>
                    </div>
                </div>
            </div>

            <!-- Auctions -->
            <div class="offer-section" id="section-auctions">
                <div class="offer-section-header" onclick="toggleSection('auctions')">
                    <h3 class="offer-section-title">
                        <span class="section-icon">🔨</span>
                        Auctions
                    </h3>
                    <div class="offer-section-meta">
                        <span class="offer-count-badge empty" id="count-auctions">0</span>
                        <span class="section-chevron">▼</span>
                    </div>
                </div>
                <div class="offer-section-content">
                    <div class="offer-section-inner" id="content-auctions">
                        <p class="section-empty-msg">Coming soon!</p>
                    </div>
                </div>
            </div>

            <!-- Offers Received -->
            <div class="offer-section" id="section-offers-received">
                <div class="offer-section-header" onclick="toggleSection('offers-received')">
                    <h3 class="offer-section-title">
                        <span class="section-icon">💰</span>
                        Offers received
                    </h3>
                    <div class="offer-section-meta">
                        <span class="offer-count-badge empty" id="count-offers-received">0</span>
                        <span class="section-chevron">▼</span>
                    </div>
                </div>
                <div class="offer-section-content">
                    <div class="offer-section-inner" id="content-offers-received">
                        <div class="section-loading"><div class="loading-spinner-small"></div> Loading...</div>
                    </div>
                </div>
            </div>

            <!-- Listed Items -->
            <div class="offer-section" id="section-listed-items">
                <div class="offer-section-header" onclick="toggleSection('listed-items')">
                    <h3 class="offer-section-title">
                        <span class="section-icon">🏷️</span>
                        Listed items
                    </h3>
                    <div class="offer-section-meta">
                        <span class="offer-count-badge empty" id="count-listed-items">0</span>
                        <span class="section-chevron">▼</span>
                    </div>
                </div>
                <div class="offer-section-content">
                    <div class="offer-section-inner" id="content-listed-items">
                        <div class="section-loading"><div class="loading-spinner-small"></div> Loading...</div>
                    </div>
                </div>
            </div>

            <!-- Offers Made -->
            <div class="offer-section" id="section-offers-made">
                <div class="offer-section-header" onclick="toggleSection('offers-made')">
                    <h3 class="offer-section-title">
                        <span class="section-icon">🛒</span>
                        Offers made
                    </h3>
                    <div class="offer-section-meta">
                        <span class="offer-count-badge empty" id="count-offers-made">0</span>
                        <span class="section-chevron">▼</span>
                    </div>
                </div>
                <div class="offer-section-content">
                    <div class="offer-section-inner" id="content-offers-made">
                        <div class="section-loading"><div class="loading-spinner-small"></div> Loading...</div>
                    </div>
                </div>
            </div>
            
            <!-- ============================================================ -->
            <!-- MINT DRAFTS SECTION -->
            <div class="offer-section" id="section-drafts">
                <div class="offer-section-header" onclick="toggleSection('drafts')">
                    <h3 class="offer-section-title">
                        <span class="section-icon">📝</span>
                        Mint Drafts
                    </h3>
                    <div class="offer-section-meta">
                        <span class="offer-count" id="drafts-count">0</span>
                        <span class="section-chevron">▼</span>
                    </div>
                </div>
                <div class="offer-section-content">
                    <div class="offer-section-inner" id="content-drafts">
                        <div class="section-loading">Checking for drafts...</div>
                    </div>
                </div>
            </div>

            <!-- PURCHASE HISTORY SECTION - Mint-on-Demand Purchases -->
            <!-- ============================================================ -->
            <div class="offer-section" id="section-purchase-history">
                <div class="offer-section-header" onclick="toggleSection('purchase-history')">
                    <h3 class="offer-section-title">
                        <span class="section-icon">🎵</span>
                        Purchase History
                    </h3>
                    <div class="offer-section-meta">
                        <span class="offer-count-badge empty" id="count-purchase-history">0</span>
                        <span class="section-chevron">▼</span>
                    </div>
                </div>
                <div class="offer-section-content">
                    <div class="offer-section-inner" id="content-purchase-history">
                        <div class="section-loading"><div class="loading-spinner-small"></div> Loading...</div>
                    </div>
                </div>
            </div>

            <!-- v396: Blocked Wallets -->
            <div class="offer-section" id="section-blocked-wallets">
                <div class="offer-section-header" onclick="toggleSection('blocked-wallets')">
                    <h3 class="offer-section-title">
                        <span class="section-icon">🚫</span>
                        Blocked Wallets
                    </h3>
                    <div class="offer-section-meta">
                        <span class="offer-count-badge empty" id="count-blocked-wallets">0</span>
                        <span class="section-chevron">▼</span>
                    </div>
                </div>
                <div class="offer-section-content">
                    <div class="offer-section-inner" id="content-blocked-wallets">
                        <p class="section-empty-msg">No blocked wallets</p>
                    </div>
                </div>
            </div>

        </div>

        <p class="page-footer-note">Data from XRPL ledger • Always real-time</p>
    </div>

    <!-- Confetti Container -->
    <div id="confetti-container" aria-hidden="true"></div>

    <!-- Loading Overlay -->
    <div id="submission-loading-overlay" class="loading-overlay" style="display: none;" aria-hidden="true">
        <div class="loading-spinner"></div>
        <span>Processing transaction...</span>
    </div>

    <!-- QR Code Popup - Redesigned -->
    <div id="qr-code-popup" style="display: none;" data-locked="false" role="dialog">
        <div class="qr-popup-card">
            <button class="qr-close-btn" id="close-qr-popup" aria-label="Close">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M1 1L13 13M1 13L13 1" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
            
            <div class="qr-header">
                <div class="qr-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                </div>
                <h3 id="qr-code-title">Sign Transaction</h3>
                <p class="qr-subtitle">Scan with Xaman wallet</p>
            </div>
            
            <div class="qr-code-wrapper">
                <img id="qr-code-image" src="" alt="QR Code">
            </div>
            
            <div class="qr-offer-info">
                <span class="qr-offer-label">Offer</span>
                <span id="qr-offer-id" class="qr-offer-value"></span>
            </div>
            
            <a id="qr-deeplink" href="#" target="_blank" rel="noopener" class="qr-xaman-btn">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                Open in Xaman
            </a>
            
            <div id="signing-status" class="qr-status">
                <div class="status-dot"></div>
                <span>Waiting for signature...</span>
            </div>
            
            <p class="qr-expires">Link expires in 5 minutes</p>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toast-container" aria-live="polite"></div>
</div>

<script>
function toggleSection(sectionId) {
    const section = document.getElementById('section-' + sectionId);
    if (section) {
        section.classList.toggle('expanded');
    }
}

// Cafe-style Dashboard Loader
(function() {
    'use strict';
    
    const offerSections = document.getElementById('offer-sections');
    if (!offerSections) return;
    
    const dashboardEl = document.getElementById('trading-hub-dashboard');
    const userAccount = dashboardEl?.dataset.account || '';
    const nonce = dashboardEl?.dataset.nonce || '';
    const endpoints = dashboardEl?.dataset.endpoints ? JSON.parse(dashboardEl.dataset.endpoints) : {};
    // -- v589 mobile UX (additive): same-tab Xaman + instant resume on return (offers only) --
    const dashIsMobileUA = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent || '');
    let dashCurrentUuid = null;
    let dashReturnBusy = false;
    
    const esc = (s) => String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"}[c]));
    // safeImg — escape AND PROXY (Aug 2026).
    //
    // This used to escape only, so any raw gateway URL a handler produced went
    // straight into <img src>. Measured on this page:
    //     ERR_BLOCKED_BY_RESPONSE.NotSameOrigin   ipfs.io/ipfs/bafybei…
    // ipfs.io sends Cross-Origin-Resource-Policy: same-origin, so the BROWSER
    // refuses to render it cross-origin — the fetch itself is a clean 200 in
    // 0.18s server-side, which is why every curl test passed while the cards
    // showed placeholders.
    //
    // ★ MATCH THE PATH, NOT THE HOST. A host list is an enumeration of the
    //   gateways known on the day it was written; the same defect broke 139 of
    //   150 cards on collections.php, and IMUP3's isValidStreamUrl carried it
    //   until F0a generalised it to the /ipfs/ path.
    // ⚠ &thumb=1 is included: these are card-sized images, and the un-thumbed
    //   path is substantially heavier per request.
    const proxyImg = (src) => {
        if (!src) return src;
        if (src.startsWith('ipfs://')) {
            return 'https://metadata.imcollectibles.io/img.php?url='
                 + encodeURIComponent(src) + '&thumb=1';
        }
        if (src.includes('/ipfs/') && !src.includes('img.php')) {
            const cid = src.replace(/^.*\/ipfs\//, '');
            return 'https://metadata.imcollectibles.io/img.php?url='
                 + encodeURIComponent('ipfs://' + cid) + '&thumb=1';
        }
        return src;   // already proxied, or a plain https asset — leave it alone
    };
    const safeImg = (src) => (!src || src === 'undefined' || src === 'null') ? '/wp-content/uploads/fallback-nft.svg' : esc(proxyImg(src));
    const formatAmount = (amt, curr) => (amt === 0 || amt === '0') ? 'Free (0 XRP)' : parseFloat(amt).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 6}) + ' ' + (curr || 'XRP');
    const truncAddr = (addr) => (!addr || addr.length < 10) ? (addr || 'Unknown') : addr.slice(0, 6) + '...' + addr.slice(-4);
    
    // ── v396: Blocked Wallet System (localStorage-based) ─────────────────────
    // Allows users to hide offers from specific wallets across all dashboard sections.
    // Pure client-side filtering — server still returns all offers for data integrity.
    const BLOCKED_KEY = 'imc_blocked_wallets';
    
    function getBlockedWallets() {
        try { return JSON.parse(localStorage.getItem(BLOCKED_KEY) || '[]'); }
        catch { return []; }
    }
    
    function isWalletBlocked(wallet) {
        if (!wallet) return false;
        return getBlockedWallets().includes(wallet);
    }
    
    function blockWallet(wallet) {
        if (!wallet || wallet.length < 20) return;
        const list = getBlockedWallets();
        if (!list.includes(wallet)) {
            list.push(wallet);
            localStorage.setItem(BLOCKED_KEY, JSON.stringify(list));
        }
        // Re-render dashboard to apply filter + update blocked section
        if (typeof window.reloadDashboard === 'function') window.reloadDashboard();
        renderBlockedWalletsSection();
    }
    
    function unblockWallet(wallet) {
        const list = getBlockedWallets().filter(w => w !== wallet);
        localStorage.setItem(BLOCKED_KEY, JSON.stringify(list));
        if (typeof window.reloadDashboard === 'function') window.reloadDashboard();
        renderBlockedWalletsSection();
    }
    
    function filterBlockedOffers(offers) {
        if (!offers || !offers.length) return offers;
        const blocked = getBlockedWallets();
        if (!blocked.length) return offers;
        return offers.filter(o => {
            const parties = [o.from, o.offerer, o.owner, o.destination, o.recipient].filter(Boolean);
            return !parties.some(p => blocked.includes(p));
        });
    }
    
    // Extract the counterparty wallet from an offer based on category
    function getCounterparty(offer, category) {
        if (category === 'incoming_transfers') return offer.from || offer.offerer || '';
        if (category === 'outgoing_transfers') return offer.destination || offer.recipient || '';
        if (category === 'offers_received') return offer.offerer || offer.from || '';
        return '';
    }
    
    function renderBlockedWalletsSection() {
        const container = document.getElementById('content-blocked-wallets');
        const countEl = document.getElementById('count-blocked-wallets');
        const section = document.getElementById('section-blocked-wallets');
        if (!container) return;
        
        const blocked = getBlockedWallets();
        if (countEl) {
            countEl.textContent = blocked.length;
            countEl.classList.toggle('empty', blocked.length === 0);
        }
        if (blocked.length === 0) {
            container.innerHTML = '<p class="section-empty-msg">No blocked wallets</p>';
            if (section) section.classList.remove('expanded');
            return;
        }
        container.innerHTML = blocked.map(wallet => `
            <div class="offer-card blocked-wallet-card">
                <div class="offer-card-details">
                    <div class="offer-card-name" title="${esc(wallet)}">${esc(wallet)}</div>
                    <div class="offer-card-meta"><span>Offers from this wallet are hidden</span></div>
                </div>
                <div class="offer-card-actions">
                    <button class="offer-btn btn-unblock" onclick="unblockWallet('${esc(wallet)}')">Unblock</button>
                </div>
            </div>`).join('');
        if (section) section.classList.add('expanded');
    }
    // ── End Blocked Wallet System ────────────────────────────────────────────

    function renderOfferCard(offer, category) {
        const isZero = offer.amount === 0 || offer.amount === '0';
        const imgErr = "this.src='/wp-content/uploads/fallback-nft.svg'";
        
        if (category === 'incoming_transfers') {
            const counterparty = getCounterparty(offer, category);
            return `<div class="offer-card" data-offer-id="${esc(offer.offer_id)}" data-nft-id="${esc(offer.nft_id)}" data-accept-type="sell_offer">
                <img src="${safeImg(offer.nft_image)}" alt="${esc(offer.nft_name)}" class="offer-card-image" onerror="${imgErr}">
                <div class="offer-card-details">
                    <div class="offer-card-name">${esc(offer.nft_name || 'Incoming NFT')}</div>
                    <div class="offer-card-meta">
                        <span class="offer-card-amount">${formatAmount(offer.amount, offer.currency_display || offer.currency)}</span>
                        <span>From: ${truncAddr(offer.from || offer.offerer)}</span>
                    </div>
                </div>
                <div class="offer-card-actions">
                    <button class="offer-btn btn-claim" data-offer-id="${esc(offer.offer_id)}" data-action="accept_sell">Claim NFT</button>
                    ${counterparty ? `<button class="offer-btn btn-block" onclick="blockWallet('${esc(counterparty)}')" title="Block this wallet">🚫</button>` : ''}
                </div>
            </div>`;
        }
        if (category === 'outgoing_transfers') {
            return `<div class="offer-card" data-offer-id="${esc(offer.offer_id)}" data-nft-id="${esc(offer.nft_id)}">
                <img src="${safeImg(offer.nft_image)}" alt="${esc(offer.nft_name)}" class="offer-card-image" onerror="${imgErr}">
                <div class="offer-card-details">
                    <div class="offer-card-name">${esc(offer.nft_name || 'Unnamed NFT')}</div>
                    <div class="offer-card-meta">
                        <span class="offer-card-amount">${formatAmount(offer.amount, offer.currency_display || offer.currency)}</span>
                        <span>To: ${truncAddr(offer.destination || offer.recipient)}</span>
                    </div>
                </div>
                <div class="offer-card-actions">
                    <button class="offer-btn btn-cancel" data-offer-id="${esc(offer.offer_id)}" data-action="cancel">Cancel</button>
                </div>
            </div>`;
        }
        if (category === 'offers_received') {
            const netText = isZero ? '' : `<span>Net: ${formatAmount(offer.net_amount, offer.currency_display || offer.currency)}</span>`;
            const tokenChip = (offer.currency && offer.currency !== 'XRP') ? `<span class="offer-token-chip" title="This offer pays in a token, not XRP">🪙 ${esc(offer.currency_display || offer.currency)}</span>` : ''; /* P2-J1 lite */
            const counterparty = getCounterparty(offer, category);
            return `<div class="offer-card" data-offer-id="${esc(offer.offer_id)}" data-nft-id="${esc(offer.nft_id)}" data-accept-type="buy_offer">
                <img src="${safeImg(offer.nft_image)}" alt="${esc(offer.nft_name)}" class="offer-card-image" onerror="${imgErr}">
                <div class="offer-card-details">
                    <div class="offer-card-name">${esc(offer.nft_name || 'Unnamed NFT')}</div>
                    <div class="offer-card-meta">
                        <span class="offer-card-amount">${formatAmount(offer.amount, offer.currency_display || offer.currency)}</span>
                        <span>From: ${truncAddr(offer.offerer || offer.from)}</span>
                        ${netText}
                        ${tokenChip}
                    </div>
                </div>
                <div class="offer-card-actions">
                    <button class="offer-btn btn-accept" data-offer-id="${esc(offer.offer_id)}" data-action="accept">Accept</button>
                    <button class="offer-btn btn-decline" data-offer-id="${esc(offer.offer_id)}" data-action="decline">✕</button>
                    ${counterparty ? `<button class="offer-btn btn-block" onclick="blockWallet('${esc(counterparty)}')" title="Block this wallet">🚫</button>` : ''}
                </div>
            </div>`;
        }
        if (category === 'listed_items') {
            return `<div class="offer-card" data-offer-id="${esc(offer.offer_id)}" data-nft-id="${esc(offer.nft_id)}">
                <img src="${safeImg(offer.nft_image)}" alt="${esc(offer.nft_name)}" class="offer-card-image" onerror="${imgErr}">
                <div class="offer-card-details">
                    <div class="offer-card-name">${esc(offer.nft_name || 'Unnamed NFT')}</div>
                    <div class="offer-card-meta">
                        <span class="offer-card-amount">${formatAmount(offer.amount, offer.currency_display || offer.currency)}</span>
                        <span>Listed for sale</span>
                    </div>
                </div>
                <div class="offer-card-actions">
                    <button class="offer-btn btn-cancel" data-offer-id="${esc(offer.offer_id)}" data-action="cancel">Cancel</button>
                </div>
            </div>`;
        }
        if (category === 'offers_made') {
            return `<div class="offer-card" data-offer-id="${esc(offer.offer_id)}" data-nft-id="${esc(offer.nft_id)}">
                <img src="${safeImg(offer.nft_image)}" alt="${esc(offer.nft_name)}" class="offer-card-image" onerror="${imgErr}">
                <div class="offer-card-details">
                    <div class="offer-card-name">${esc(offer.nft_name || 'Unnamed NFT')}</div>
                    <div class="offer-card-meta">
                        <span class="offer-card-amount">${formatAmount(offer.amount, offer.currency_display || offer.currency)}</span>
                        <span>Your buy offer</span>
                    </div>
                </div>
                <div class="offer-card-actions">
                    <button class="offer-btn btn-cancel" data-offer-id="${esc(offer.offer_id)}" data-action="cancel">Cancel</button>
                </div>
            </div>`;
        }
        return '';
    }
    
    function updateSection(sectionId, offers, category) {
        const content = document.getElementById('content-' + sectionId);
        const countEl = document.getElementById('count-' + sectionId);
        const section = document.getElementById('section-' + sectionId);
        if (!content) return;
        
        // v396: Filter out offers from blocked wallets before rendering
        const filtered = filterBlockedOffers(offers || []);
        const count = filtered.length;
        if (countEl) {
            countEl.textContent = count;
            countEl.classList.toggle('empty', count === 0);
        }
        if (count > 0) {
            content.innerHTML = filtered.map(o => renderOfferCard(o, category)).join('');
            if (section) section.classList.add('expanded');
        } else {
            content.innerHTML = '<p class="section-empty-msg">No items</p>';
        }
    }
    
    function setupButtonHandlers() {
        document.querySelectorAll('.offer-card .offer-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                const action = btn.dataset.action;
                const offerId = btn.dataset.offerId;
                const card = btn.closest('.offer-card');
                const acceptType = card?.dataset.acceptType;
                if (!offerId || !action) return;
                
                btn.disabled = true;
                const origText = btn.textContent;
                btn.textContent = '...';
                
                try {
                    if (action === 'accept' || action === 'accept_sell') {
                        const actName = acceptType === 'sell_offer' ? 'accept_sell' : 'accept_buy';

                        // --- Joey branch (v551, additive): accept is Joey-wired server-side
                        // (joey_verify_accept_offer). Sign the NFTokenAcceptOffer locally; no QR popup.
                        if (window.__joeySession && window.__joeySession.live && window.imuWallet) {
                            if (userAccount !== window.__joeySession.account) {
                                throw new Error('Connected wallet does not match your account. Please reconnect.');
                            }
                            const jr = await fetch(`${endpoints.offerHandler}?action=${actName}&offer_id=${encodeURIComponent(offerId)}&account=${encodeURIComponent(userAccount)}&nonce=${encodeURIComponent(nonce)}&skip_verify=1&wallet=joey`);
                            const jd = await jr.json();
                            if (!jd.success || !jd.txjson) throw new Error(jd.error || 'Failed to prepare transaction');
                            showToast('Check your wallet to sign...', 'info');
                            let jsigned;
                            try { jsigned = await (window.imuJoeySign||window.imuWallet.sign)(jd.txjson); }
                            catch (e) { const em = (e && e.message) || ''; throw new Error(/timed out|timeout/i.test(em) ? 'Signing timed out. Please try again.' : /cancel/i.test(em) ? 'Signing cancelled.' : 'Signing was rejected.'); }
                            const jtx = jsigned && (jsigned.hash || jsigned.tx_hash);
                            if (!jtx) throw new Error('No transaction hash returned from your wallet.');
                            const jvfd = new FormData();
                            jvfd.append('action', 'joey_verify_accept_offer');
                            jvfd.append('tx_hash', jtx);
                            jvfd.append('nft_id', jd.nft_id || '');
                            jvfd.append('nonce', nonce);
                            const jvr = await fetch(endpoints.offerHandler, { method: 'POST', body: jvfd });
                            const jvd = await jvr.json();
                            if (!jvd.success) throw new Error(jvd.error || 'Verification failed');
                            showToast('Offer accepted.', 'success');
                            setTimeout(function() { location.reload(); }, 1500);
                            return;
                        }

                        showToast('Preparing transaction...', 'info');
                        // skip_verify=1 because dashboard already verified the offer exists
                        const res = await fetch(`${endpoints.offerHandler}?action=${actName}&offer_id=${encodeURIComponent(offerId)}&account=${encodeURIComponent(userAccount)}&nonce=${encodeURIComponent(nonce)}&skip_verify=1`);
                        const data = await res.json();
                        // v684: server-decided Joey response on the Xaman path (see the v683/v684
                        // wallet-authority change). Already fails safe via the else-throw below,
                        // but that surfaces a bare "Failed" -- give the real reason instead.
                        if (data.wallet === 'joey' && data.txjson) throw new Error('Wallet still connecting \u2014 please try again in a moment.');
                        if (data.success && data.qr_code) {
                            if (typeof window.showQRCodePopup === 'function') {
                                window.showQRCodePopup(data.qr_code, data.deeplink, offerId, data.payload_uuid);
                            } else {
                                if (dashIsMobileUA) { window.location.href = data.deeplink; } else { window.open(data.deeplink, '_blank'); }
                            }
                        } else {
                            throw new Error(data.error || 'Failed');
                        }
                    } else if (action === 'decline') {
                        showToast('To decline, simply ignore the offer.', 'info');
                        btn.disabled = false;
                        btn.textContent = origText;
                    } else if (action === 'cancel') {
                        showToast('Preparing cancellation...', 'info');
                        // --- Joey branch (v548, additive) ---
                        if (window.__joeySession && window.__joeySession.live && window.imuWallet) {
                            if (userAccount !== window.__joeySession.account) {
                                throw new Error('Connected wallet does not match your account. Please reconnect.');
                            }
                            const jr = await fetch(`${endpoints.offerHandler}?action=cancel_offer&offer_id=${encodeURIComponent(offerId)}&account=${encodeURIComponent(userAccount)}&nonce=${encodeURIComponent(nonce)}&wallet=joey`);
                            const jd = await jr.json();
                            if (!jd.success || !jd.txjson) throw new Error(jd.error || 'Failed to prepare cancel');
                            showToast('Check your wallet to sign the cancel...', 'info');
                            let jsigned;
                            try { jsigned = await (window.imuJoeySign||window.imuWallet.sign)(jd.txjson); }
                            catch (e) { throw new Error('Signing was cancelled or failed.'); }
                            const jtx = jsigned && (jsigned.hash || jsigned.tx_hash);
                            if (!jtx) throw new Error('No transaction hash returned from your wallet.');
                            const jvfd = new FormData();
                            jvfd.append('action', 'joey_verify_cancel_offer');
                            jvfd.append('tx_hash', jtx);
                            jvfd.append('nonce', nonce);
                            const jvr = await fetch(endpoints.offerHandler, { method: 'POST', body: jvfd });
                            const jvd = await jvr.json();
                            if (!jvd.success) throw new Error(jvd.error || 'Cancel verification failed');
                            showToast('Offer cancelled.', 'success');
                            setTimeout(function() { location.reload(); }, 1500);
                            return;
                        }
                        const res = await fetch(`${endpoints.offerHandler}?action=cancel_offer&offer_id=${encodeURIComponent(offerId)}&account=${encodeURIComponent(userAccount)}&nonce=${encodeURIComponent(nonce)}`);
                        const data = await res.json();
                        // v684: server-decided Joey response on the Xaman path (see the v683/v684
                        // wallet-authority change). Already fails safe via the else-throw below,
                        // but that surfaces a bare "Failed" -- give the real reason instead.
                        if (data.wallet === 'joey' && data.txjson) throw new Error('Wallet still connecting \u2014 please try again in a moment.');
                        if (data.success && data.qr_code) {
                            if (typeof window.showQRCodePopup === 'function') {
                                window.showQRCodePopup(data.qr_code, data.deeplink, offerId, data.payload_uuid);
                            } else {
                                if (dashIsMobileUA) { window.location.href = data.deeplink; } else { window.open(data.deeplink, '_blank'); }
                            }
                        } else {
                            throw new Error(data.error || 'Failed');
                        }
                    }
                } catch (err) {
                    console.error('Action error:', err);
                    showToast('Error: ' + err.message, 'error');
                    btn.disabled = false;
                    btn.textContent = origText;
                }
            });
        });
    }
    
    function showToast(message, type = 'info') {
        if (typeof window.showToast === 'function') { window.showToast(message, type); return; }
        const toast = document.createElement('div');
        toast.style.cssText = `position:fixed;bottom:20px;right:20px;padding:12px 20px;background:${type === 'error' ? '#dc3545' : type === 'success' ? '#28a745' : '#333'};color:#fff;border-radius:8px;z-index:9999;`;
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 4000);
    }
    
    // ===== QR Code Popup Functions =====
    let pollInterval = null;
    document.addEventListener('visibilitychange', function() {
        if (document.hidden || dashReturnBusy) return;
        const popup = document.getElementById('qr-code-popup');
        if (!popup || popup.style.display === 'none' || !dashCurrentUuid) return;
        dashReturnBusy = true;
        fetch(`${endpoints.offerHandler}?action=poll_xumm_payload&uuid=${encodeURIComponent(dashCurrentUuid)}&nonce=${encodeURIComponent(nonce)}`)
            .then(function(r){ return r.json(); })
            .then(function(d){
                dashReturnBusy = false;
                if (d && d.signed === true) {
                    if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
                    showToast('Transaction signed! Refreshing...', 'success');
                    setTimeout(function(){ hideQRCodePopup(); loadDashboard(); if (typeof loadPendingClaims === 'function') loadPendingClaims(); }, 800);
                }
            })
            .catch(function(){ dashReturnBusy = false; });
    });
    
    function showQRCodePopup(qrCode, deeplink, offerId, payloadUuid) {
        const popup = document.getElementById('qr-code-popup');
        const qrImage = document.getElementById('qr-code-image');
        const qrDeeplink = document.getElementById('qr-deeplink');
        const qrOfferId = document.getElementById('qr-offer-id');
        const signingStatus = document.getElementById('signing-status');
        
        if (!popup) return;
        
        // v198: Safe QR — hide on error, never show NFT placeholder
        qrImage.onerror = function() { this.style.display = 'none'; };
        qrImage.style.display = '';
        qrImage.src = qrCode || '';
        
        // Set deeplink
        qrDeeplink.href = deeplink || '#';
        if (dashIsMobileUA) qrDeeplink.setAttribute('target', '_self'); // v589 mobile: open Xaman in same tab -- no orphan tab
        
        // Set offer ID (truncated)
        if (qrOfferId) {
            qrOfferId.textContent = offerId ? offerId.slice(0, 8) + '...' + offerId.slice(-6) : '';
        }
        
        // Reset status
        signingStatus.className = 'qr-status';
        signingStatus.querySelector('span').textContent = 'Waiting for signature...';
        
        // Show popup + push header behind
        document.body.classList.add('imc-modal-open');
        popup.style.display = 'flex';
        popup.dataset.locked = 'true';
        
        // Start polling for signature
        if (payloadUuid) {
            startPayloadPolling(payloadUuid);
        }
        
        console.log('QR popup shown for payload:', payloadUuid);
    }
    
    function hideQRCodePopup() {
        const popup = document.getElementById('qr-code-popup');
        if (popup) {
            popup.style.display = 'none';
            popup.dataset.locked = 'false';
        }
        document.body.classList.remove('imc-modal-open'); // v198
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
    }
    
    function startPayloadPolling(payloadUuid) {
        if (pollInterval) clearInterval(pollInterval);
        dashCurrentUuid = payloadUuid;
        
        const signingStatus = document.getElementById('signing-status');
        const statusText = signingStatus?.querySelector('span');
        let pollCount = 0;
        const maxPolls = 60;
        
        pollInterval = setInterval(async () => {
            pollCount++;
            
            if (pollCount > maxPolls) {
                clearInterval(pollInterval);
                pollInterval = null;
                signingStatus.className = 'qr-status error';
                statusText.textContent = 'Request expired';
                return;
            }
            
            try {
                const res = await fetch(`${endpoints.offerHandler}?action=poll_xumm_payload&uuid=${encodeURIComponent(payloadUuid)}&nonce=${encodeURIComponent(nonce)}`);
                const data = await res.json();
                
                if (data.signed === true) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                    signingStatus.className = 'qr-status success';
                    statusText.textContent = 'Transaction signed!';
                    showToast('Transaction signed! Refreshing...', 'success');
                    setTimeout(() => { hideQRCodePopup(); loadDashboard(); loadPendingClaims(); }, 1500);
                } else if (data.signed === false && data.resolved) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                    signingStatus.className = 'qr-status error';
                    statusText.textContent = 'Transaction rejected';
                    showToast('Transaction was rejected', 'error');
                    setTimeout(hideQRCodePopup, 2000);
                }
            } catch (err) {
                console.error('Poll error:', err);
            }
        }, 5000);
    }
    
    // Close handlers
    document.getElementById('close-qr-popup')?.addEventListener('click', hideQRCodePopup);
    document.getElementById('qr-code-popup')?.addEventListener('click', (e) => {
        if (e.target.id === 'qr-code-popup') hideQRCodePopup();
    });
    
    window.showQRCodePopup = showQRCodePopup;
    // ===== End QR Popup Functions =====
    
    // ===== Watchlist Functions =====
    async function loadWatchlist() {
        const content = document.getElementById('content-watchlist');
        const countEl = document.getElementById('count-watchlist');
        const section = document.getElementById('section-watchlist');
        
        if (!content || !userAccount) {
            if (content) content.innerHTML = '<p class="section-empty-msg">Please log in with Xaman</p>';
            return;
        }
        
        try {
            const res = await fetch(`${endpoints.watchlistHandler}?action=get&account=${encodeURIComponent(userAccount)}&nonce=${encodeURIComponent(nonce)}`);
            const data = await res.json();
            
            if (!data.success) throw new Error(data.data?.error || 'Failed to load watchlist');
            
            const watchlist = data.data?.watchlist || [];
            const count = watchlist.length;
            
            if (countEl) {
                countEl.textContent = count;
                countEl.classList.toggle('empty', count === 0);
            }
            
            if (count > 0) {
                // Fetch metadata for each NFT
                const nftsWithMeta = await Promise.all(watchlist.map(async (item) => {
                    try {
                        // P1d: fetch=fast (bounded miss-path). Any-mode: skip pending rows.
                        const metaRes = await fetch(`https://metadata.imcollectibles.io/?action=get&id=${item.nft_id}&fetch=fast`);
                        const metaData = await metaRes.json();
                        if (metaData.success && metaData.nft && (!metaData.nft.decode_status || metaData.nft.decode_status === 'success')) {
                            const nft = metaData.nft;
                            return {
                                ...item,
                                nft_name: nft.metadata?.name || nft.name || item.nft_name || 'NFT',
                                image: nft.assets?.image || nft.metadata?.image || nft.image || '/wp-content/uploads/fallback-nft.svg'
                            };
                        }
                    } catch (e) {
                        console.error('Failed to fetch NFT metadata:', e);
                    }
                    return {
                        ...item,
                        image: '/wp-content/uploads/fallback-nft.svg'
                    };
                }));
                
                content.innerHTML = renderWatchlistCarousel(nftsWithMeta);
                setupWatchlistHandlers();
                if (section) section.classList.add('expanded');
            } else {
                content.innerHTML = `
                    <div class="watchlist-empty">
                        <p>♡ Your watchlist is empty</p>
                        <a href="/collections/">Browse NFTs to add to your watchlist →</a>
                    </div>
                `;
            }
        } catch (err) {
            console.error('Watchlist load error:', err);
            content.innerHTML = '<p class="section-empty-msg">Failed to load watchlist</p>';
        }
    }
    
    function renderWatchlistCarousel(items) {
        const imgErr = "this.src='/wp-content/uploads/fallback-nft.svg'";
        const cards = items.map(item => `
            <div class="watchlist-card" data-nft-id="${esc(item.nft_id)}">
                <img class="watchlist-card-image" src="${safeImg(item.image)}" alt="${esc(item.nft_name)}" onerror="${imgErr}">
                <div class="watchlist-card-content">
                    <div class="watchlist-card-name">${esc(item.nft_name)}</div>
                    <div class="watchlist-card-actions">
                        <a href="/nft/${esc(item.nft_id)}/" class="watchlist-card-btn view-btn">View</a>
                        <button class="watchlist-card-btn remove-btn" data-nft-id="${esc(item.nft_id)}">♥</button>
                    </div>
                </div>
            </div>
        `).join('');
        
        return `
            <div class="watchlist-carousel-container">
                <button class="watchlist-scroll-btn scroll-left" onclick="scrollWatchlist(-200)">‹</button>
                <div class="watchlist-carousel" id="watchlist-carousel">
                    ${cards}
                </div>
                <button class="watchlist-scroll-btn scroll-right" onclick="scrollWatchlist(200)">›</button>
            </div>
        `;
    }
    
    function setupWatchlistHandlers() {
        document.querySelectorAll('.watchlist-card .remove-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                
                const nftId = btn.dataset.nftId;
                const card = btn.closest('.watchlist-card');
                
                btn.disabled = true;
                btn.textContent = '...';
                
                try {
                    const formData = new FormData();
                    formData.append('action', 'remove');
                    formData.append('account', userAccount);
                    formData.append('nft_id', nftId);
                    formData.append('nonce', nonce);
                    
                    const res = await fetch(endpoints.watchlistHandler, {
                        method: 'POST',
                        body: formData
                    });
                    const data = await res.json();
                    
                    if (data.success) {
                        card.style.transform = 'scale(0.8)';
                        card.style.opacity = '0';
                        setTimeout(() => {
                            card.remove();
                            // Update count
                            const remaining = document.querySelectorAll('.watchlist-card').length;
                            const countEl = document.getElementById('count-watchlist');
                            if (countEl) {
                                countEl.textContent = remaining;
                                countEl.classList.toggle('empty', remaining === 0);
                            }
                            if (remaining === 0) {
                                loadWatchlist(); // Reload to show empty state
                            }
                        }, 200);
                        showToast('Removed from watchlist', 'info');
                    } else {
                        throw new Error(data.data?.error || 'Failed');
                    }
                } catch (err) {
                    console.error('Remove from watchlist error:', err);
                    showToast('Failed to remove: ' + err.message, 'error');
                    btn.disabled = false;
                    btn.textContent = '♥';
                }
            });
        });
    }
    
    // Global scroll function for watchlist carousel
    window.scrollWatchlist = function(amount) {
        const carousel = document.getElementById('watchlist-carousel');
        if (carousel) {
            carousel.scrollBy({ left: amount, behavior: 'smooth' });
        }
    };
    
    // v278: optional carouselId for Pending Claims vs Purchase History
    window.scrollPurchases = function(amount, carouselId) {
        const carousel = document.getElementById(carouselId || 'purchase-carousel');
        if (carousel) {
            carousel.scrollBy({ left: amount, behavior: 'smooth' });
        }
    };
    
    // =========================================================================
    // v363: UNIFIED SINGLE-CALL DASHBOARD
    // =========================================================================
    // One endpoint (get_dashboard_v2) returns ALL 5 sections from 2 Bithomp
    // HTTP calls. Zero XRPL RPC. Works for 5 or 5,000 NFTs identically.
    //
    // Old: 3 parallel calls, N+1 nft_buy_offers (50+ XRPL calls, 120s timeout)
    // New: 1 fetch, 2 Bithomp calls server-side, all sections render together
    // =========================================================================

    function setSectionLoading(sectionId, msg) {
        const el = document.getElementById('content-' + sectionId);
        if (el) el.innerHTML = '<div class="section-loading"><div class="loading-spinner-small"></div> ' + (msg || 'Loading...') + '</div>';
    }

    function setSectionError(sectionId, isTimeout) {
        const el = document.getElementById('content-' + sectionId);
        if (!el) return;
        const retry = '<a href="#" onclick="loadDashboard(); return false;" style="color:var(--gold);font-weight:600;">Retry</a>';
        el.innerHTML = '<p class="section-empty-msg">' + (isTimeout ? 'Timeout' : 'Failed') + ' &mdash; ' + retry + '</p>';
    }

    async function fetchSection(action, params = {}, timeoutMs = 60000) {
        const qs = new URLSearchParams({
            action,
            account: userAccount,
            nonce,
            t: Date.now(),
            ...params
        });
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeoutMs);
        try {
            const res = await fetch(endpoints.offerHandler + '?' + qs.toString(), { signal: controller.signal });
            clearTimeout(timer);
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Server error');
            return data;
        } catch (err) {
            clearTimeout(timer);
            throw err;
        }
    }

    async function loadDashboard() {
        if (!userAccount) {
            // v279: Mobile cookie fix
            const cookieAccount = document.cookie.split('; ')
                .find(r => r.startsWith('xrpl_account='))?.split('=')[1];
            if (cookieAccount && /^r[1-9A-HJ-NP-Za-km-z]{25,34}$/.test(cookieAccount)) {
                if (!sessionStorage.getItem('_imc_dash_reload')) {
                    sessionStorage.setItem('_imc_dash_reload', '1');
                    window.location.reload();
                    return;
                }
                sessionStorage.removeItem('_imc_dash_reload');
            }
            ['incoming-transfers','outgoing-transfers','offers-received','listed-items','offers-made'].forEach(id => {
                const el = document.getElementById('content-' + id);
                if (el) el.innerHTML = '<p class="section-empty-msg">Please log in with Xaman</p>';
            });
            return;
        }
        sessionStorage.removeItem('_imc_dash_reload');

        // Hide old full-screen overlay
        const loadingOverlay = document.getElementById('dashboard-loading');
        if (loadingOverlay) loadingOverlay.classList.add('hidden');

        // Show spinners on all XRPL-backed sections
        const sections = ['incoming-transfers','outgoing-transfers','offers-received','listed-items','offers-made'];
        sections.forEach(id => setSectionLoading(id, 'Scanning offers...'));

        console.log('[Dashboard] Single-call load for', userAccount);

        try {
            const data = await fetchSection('get_dashboard_v2', {}, 30000);

            updateSection('listed-items',        data.listed_items,       'listed_items');
            updateSection('outgoing-transfers',  data.outgoing_transfers, 'outgoing_transfers');
            updateSection('offers-made',         data.offers_made,        'offers_made');
            updateSection('offers-received',     data.offers_received,    'offers_received');
            updateSection('incoming-transfers',  data.incoming_transfers, 'incoming_transfers');
            setupButtonHandlers();
            renderBlockedWalletsSection(); // v396: Populate blocked wallets list

            // Notify user if data may be incomplete
            if (data.data_source === 'xrpl_only') {
                showToast('Incoming transfers may be limited — indexer unavailable', 'info');
            }

            console.log('[Dashboard] Done in', data.elapsed_ms + 'ms', '| source:', data.data_source, '| totals:', JSON.stringify(data.totals));
            if (data.timing) console.log('[Dashboard] Step timing (ms):', JSON.stringify(data.timing));
        } catch (err) {
            const isTO = err.name === 'AbortError' || (err.message || '').includes('abort');
            sections.forEach(id => setSectionError(id, isTO));
            console.warn('[Dashboard] Failed:', err.message);
        }

        // Watchlist: independent endpoint
        loadWatchlist();
    }
    
    /**
 * v278: Load Pending Claims — NFTs minted but not yet claimed, plus failed mints needing retry.
 * Shown at the top of the dashboard so users never miss an actionable item.
 */
async function loadPendingClaims() {
    const container = document.getElementById('content-pending-claims');
    const countBadge = document.getElementById('count-pending-claims');
    if (!container) return;

    // v283: Cookie fallback — PHP-baked account is empty when page renders before
    // the wallet cookie is set (stale tab, mobile Xaman redirect timing).
    // Read cookie at runtime so this works even on first render without reload.
    const phpAccount = '<?php echo esc_js($xrpl_account); ?>';
    const cookieMatch = document.cookie.match('(^|;)\s*xrpl_account\s*=\s*([^;]+)');
    const cookieAccount = cookieMatch ? decodeURIComponent(cookieMatch[2]) : '';
    const account = phpAccount || (cookieAccount && /^r[1-9A-HJ-NP-Za-km-z]{25,34}$/.test(cookieAccount) ? cookieAccount : '');

    if (!account) {
        container.innerHTML = '<p class="section-empty-msg">Connect wallet to view pending claims.</p>';
        return;
    }

    try {
        const mintOnDemandUrl = (typeof xrplMarketplace !== 'undefined' && xrplMarketplace?.endpoints?.mintOnDemand) ||
            (typeof endpoints !== 'undefined' && endpoints?.mintOnDemand) ||
            '<?php echo get_stylesheet_directory_uri(); ?>/xrpl-nft-marketplace/backend/mint-on-demand-handler.php';

        const response = await fetch(
            `${mintOnDemandUrl}?action=get_buyer_purchases&account=${encodeURIComponent(account)}&limit=100`
        );
        const data = await response.json();

        if (!data.success || !data.data?.purchases) {
            container.innerHTML = '<p class="section-empty-msg">No pending claims.</p>';
            countBadge.textContent = '0';
            countBadge.classList.add('empty');
            return;
        }

        // Filter to only actionable items: ready-to-claim OR failed/paid (needs retry)
        // v304 FIX: Use parseInt() — PHP serialises delivered as string '0' / '1' from MySQL
        // TINYINT. In JS: !'0' === false (non-empty string is truthy), so the old
        // `!p.delivered && p.delivered != 1` silently excluded all undelivered rows.
        // v429: Include 'paid' status — these are recovered stuck mints that need retry.
        const all = data.data.purchases;
        const claimable = all.filter(p => p.mint_status === 'minted' && p.sell_offer_id && parseInt(p.delivered) !== 1);
        const failed    = all.filter(p => p.mint_status === 'failed');
        const paid      = all.filter(p => p.mint_status === 'paid');
        const items     = [...claimable, ...failed, ...paid];

        if (items.length === 0) {
            container.innerHTML = '<p class="section-empty-msg">No pending claims \u2014 all caught up! \u2705</p>';
            countBadge.textContent = '0';
            countBadge.classList.add('empty');
            return;
        }

        // Badge: highlight if there are claimable items
        countBadge.textContent = items.length;
        countBadge.classList.remove('empty');
        if (claimable.length > 0) countBadge.style.background = 'var(--gold, #c9a84c)';

        // Auto-expand this section if there are items
        const section = document.getElementById('section-pending-claims');
        if (section && items.length > 0) section.classList.add('expanded');

        const imgErr = "this.src='/wp-content/uploads/fallback-nft.svg'";

        const cards = items.map(p => {
            const cid = (p.cover_ipfs || '').replace('ipfs://', '');
            const imgSrc = cid
                ? `https://metadata.imcollectibles.io/img.php?url=${encodeURIComponent('ipfs://'+cid)}&thumb=1` /* L3b: claim cards — thumb */
                : '/wp-content/uploads/fallback-nft.svg';

            // Edition label — never show #0
            const editionLabel = p.edition_number > 0
                ? `Edition #${p.edition_number}${p.tier_name ? ' · ' + escapeHtml(p.tier_name) : ''}`
                : (p.mint_status === 'failed' ? (p.recoverable === false ? 'Mint failed' : 'Finishing up...') : 'Assigning edition...');   // 4j

            let badgeClass, badgeText, actionHtml;

            if (p.mint_status === 'minted') {
                badgeClass = 'ready';
                badgeText  = '🎁 Ready to Claim';
                actionHtml = `<button class="purchase-card-btn claim-btn"
                                       onclick="claimPurchase(${p.id})">
                                  Claim NFT
                              </button>`;
            } else if (p.mint_status === 'paid') {
                // v429: Paid but not yet minted — needs retry (recovered from stuck mint)
                badgeClass = 'failed';
                badgeText  = '⏳ Awaiting Mint';
                actionHtml = p.group_id
                    ? `<button class="purchase-card-btn retry-btn"
                               onclick="retryMint(${p.group_id})"
                               title="Your payment was received — tap to mint your NFT">
                           🔄 Mint Now
                       </button>`
                    : '<span style="font-size:0.75rem;color:#888;">Contact support</span>';
            } else {
                // failed
                badgeClass = 'failed';
                // 4j: the server's `recoverable` flag mirrors the healer's own predicate. Only a
                // genuinely permanent failure gets the red cross; everything else is in hand.
                badgeText  = (p.recoverable === false) ? '❌ Mint Failed' : '⏳ Finishing up';
                actionHtml = p.group_id
                    ? `<button class="purchase-card-btn retry-btn"
                               onclick="retryMint(${p.group_id})"
                               title="Your payment was received — tap to retry minting">
                           🔄 Retry Mint
                       </button>`
                    : '<span style="font-size:0.75rem;color:#888;">Contact support</span>';
            }

            return `
                <div class="purchase-card" data-purchase-id="${p.id}">
                    <span class="purchase-card-badge ${badgeClass}">${badgeText}</span>
                    <img class="purchase-card-image" src="${imgSrc}"
                         alt="${escapeHtml(p.nft_name || 'NFT')}" onerror="${imgErr}">
                    <div class="purchase-card-content">
                        <div class="purchase-card-name">${escapeHtml(p.nft_name || 'NFT')}</div>
                        <div class="purchase-card-meta">${editionLabel}</div>
                        <div class="purchase-card-actions">${actionHtml}</div>
                    </div>
                </div>
            `;
        }).join('');

        container.innerHTML = `
            <div class="purchase-carousel-container">
                <button class="purchase-scroll-btn scroll-left" onclick="scrollPurchases(-200, 'pending-claims-carousel')">‹</button>
                <div class="purchase-carousel" id="pending-claims-carousel">${cards}</div>
                <button class="purchase-scroll-btn scroll-right" onclick="scrollPurchases(200, 'pending-claims-carousel')">›</button>
            </div>
        `;

    } catch (err) {
        console.error('loadPendingClaims error:', err);
        container.innerHTML = '<p class="section-empty-msg">Failed to load — <a href="javascript:location.reload()" style="color:var(--gold)">retry</a></p>';
    }
}

/**
 * v278: Retry a failed mint from the Pending Claims section.
 */
async function retryMint(groupId) {
    if (!window.mintOnDemand?._retryFromHistory) {
        alert('Mint module not loaded — please refresh.');
        return;
    }
    try {
        await window.mintOnDemand._retryFromHistory(groupId);
    } catch (err) {
        console.error('retryMint error:', err);
        alert('Retry failed: ' + err.message);
    }
}

/**
 * v562: Cancel a buyer's own unpaid reservation (self-service slot release).
 * Calls the server cancel_reservation action, which verifies ownership and refuses
 * if the Xaman payload was already signed (never cancels a just-paid reservation).
 */
async function cancelReservation(groupId, btn) {
    if (!groupId) return;
    if (!confirm('Cancel this reservation? Your slot will be released so you can mint again.')) return;

    const dashboardEl = document.getElementById('trading-hub-dashboard');
    const account = dashboardEl?.dataset.account || '';
    const nonce   = dashboardEl?.dataset.nonce || '';
    let eps = {};
    try { eps = dashboardEl?.dataset.endpoints ? JSON.parse(dashboardEl.dataset.endpoints) : {}; } catch (e) {}
    const url = eps.mintOnDemand
        || '<?php echo get_stylesheet_directory_uri(); ?>/xrpl-nft-marketplace/backend/mint-on-demand-handler.php';

    if (!account) { alert('Connect your wallet first.'); return; }

    const orig = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = 'Cancelling...'; }

    try {
        const fd = new FormData();
        fd.append('action', 'cancel_reservation');
        fd.append('nonce', nonce);
        fd.append('buyer_account', account);
        fd.append('group_id', groupId);

        const res = await fetch(url, { method: 'POST', body: fd });
        const data = await res.json();

        if (!data.success) {
            if (btn) { btn.disabled = false; btn.innerHTML = orig; }
            if (data.nonce_expired) { alert('Your session expired. Please refresh the page and try again.'); return; }
            throw new Error(data.error || 'Cancel failed');
        }

        // Refresh: the reservation row flips to Expired and the wallet's slot/limit is freed.
        if (typeof loadPurchaseHistory === 'function') loadPurchaseHistory();
        if (typeof loadPendingClaims === 'function') loadPendingClaims();
    } catch (err) {
        console.error('cancelReservation error:', err);
        alert('Could not cancel: ' + err.message);
        if (btn) { btn.disabled = false; btn.innerHTML = orig; }
    }
}

async function loadPurchaseHistory() {
    const container = document.getElementById('content-purchase-history');
    const countBadge = document.getElementById('count-purchase-history');
    
    if (!container) return;
    
    const account = '<?php echo esc_js($xrpl_account); ?>';
    if (!account) {
        container.innerHTML = `
            <div class="purchase-empty-state">
                <div class="empty-icon">🔗</div>
                <p>Connect wallet to view purchases</p>
            </div>
        `;
        return;
    }
    
    try {
        const mintOnDemandUrl = (typeof xrplMarketplace !== 'undefined' && xrplMarketplace?.endpoints?.mintOnDemand) || 
            (typeof endpoints !== 'undefined' && endpoints?.mintOnDemand) ||
            '<?php echo get_stylesheet_directory_uri(); ?>/xrpl-nft-marketplace/backend/mint-on-demand-handler.php';
        
        const response = await fetch(
            `${mintOnDemandUrl}?action=get_buyer_purchases&account=${encodeURIComponent(account)}&limit=50`
        );
        
        const data = await response.json();
        
        if (!data.success || !data.data?.purchases || data.data.purchases.length === 0) {
            container.innerHTML = `
                <div class="purchase-empty-state">
                    <div class="empty-icon">🛒</div>
                    <p>No purchases yet</p>
                    <a href="/collections/" class="purchase-card-btn view-btn" style="display:inline-block;margin-top:0.5rem;">Browse Marketplace</a>
                </div>
            `;
            countBadge.textContent = '0';
            countBadge.classList.add('empty');
            return;
        }
        
        const purchases = data.data.purchases;
        countBadge.textContent = purchases.length;
        countBadge.classList.remove('empty');
        
        container.innerHTML = renderPurchaseCarousel(purchases);
        
    } catch (error) {
        console.error('Failed to load purchase history:', error);
        container.innerHTML = `
            <div class="purchase-empty-state">
                <div class="empty-icon">⚠️</div>
                <p>Failed to load purchases</p>
            </div>
        `;
    }
}

/**
 * Render purchase history as a card carousel (matching Watchlist style)
 */
function renderPurchaseCarousel(purchases) {
    const imgErr = "this.src='/wp-content/uploads/fallback-nft.svg'";
    
    const cards = purchases.map(purchase => {
        // Determine status
        let badgeClass = 'pending';
        let badgeText = '⏳ Pending';
        let actionHtml = '';
        
        if (purchase.delivered == 1 || purchase.delivered === true || purchase.delivered === '1') {
            badgeClass = 'delivered';
            badgeText = '✅ Owned';
            actionHtml = `<a href="/nft/${encodeURIComponent(purchase.nftoken_id || '')}/" class="purchase-card-btn view-btn">View</a>`;
        } else if (purchase.mint_status === 'minted' && purchase.sell_offer_id) {
            badgeClass = 'ready';
            badgeText = '🎁 Claim';
            actionHtml = `<button class="purchase-card-btn claim-btn" onclick="claimPurchase(${purchase.id})">Claim</button>`;
        } else if (purchase.mint_status === 'minting') {
            badgeClass = 'minting';
            badgeText = '⚡ Minting';
        } else if (purchase.mint_status === 'failed') {
            badgeClass = 'failed';
            badgeText = '❌ Failed';
            // v278: Retry button so user can self-serve without contacting support
            if (purchase.group_id) {
                actionHtml = `<button class="purchase-card-btn retry-btn"
                                       onclick="retryMint(${purchase.group_id})"
                                       title="Payment received — click to retry minting">
                                  🔄 Retry
                              </button>`;
            }
        } else if (purchase.mint_status === 'cancelled') {
            // v515: cancelled rows previously fell through to the default
            // '⏳ Pending' badge permanently. These are expired, never-paid
            // reservations — label honestly, no action button.
            badgeClass = 'expired';
            badgeText = '⌛ Expired';
        } else if (purchase.mint_status === 'pending') {
            // v562: reserved but not yet paid. Let the buyer release the slot
            // themselves instead of waiting up to 30 min for it to expire.
            // cancelReservation() calls the server cancel_reservation action, which
            // refuses if the Xaman payload was already signed -- so a just-paid
            // reservation is never wrongly cancelled.
            badgeClass = 'pending';
            badgeText = '⏳ Pending Payment';
            if (purchase.group_id) {
                actionHtml = `<button class="purchase-card-btn cancel-btn"
                                      onclick="cancelReservation(${purchase.group_id}, this)"
                                      title="Release this reservation so you can mint again">
                                  ✖ Cancel
                              </button>`;
            }
        }
        
        // Cover image
        let coverUrl = '/wp-content/uploads/fallback-nft.svg';
        if (purchase.cover_ipfs) {
            const cid = purchase.cover_ipfs.replace('ipfs://', '');
            coverUrl = `https://metadata.imcollectibles.io/img.php?url=${encodeURIComponent('ipfs://'+cid)}&thumb=1`; /* L3b: purchase-row cover — thumb */
        }
        
        // Edition info — never show Edition #0 (default DB value for unassigned)
        const edition = purchase.edition_number > 0
            ? `Edition #${purchase.edition_number}${purchase.tier_name ? ' · ' + escapeHtml(purchase.tier_name || '') : ''}`
            : (purchase.mint_status === 'failed' ? 'Mint failed' : '');
        
        return `
            <div class="purchase-card" data-purchase-id="${purchase.id}">
                <span class="purchase-card-badge ${badgeClass}">${badgeText}</span>
                <img class="purchase-card-image" src="${coverUrl}" alt="${escapeHtml(purchase.nft_name || 'NFT')}" onerror="${imgErr}">
                <div class="purchase-card-content">
                    <div class="purchase-card-name">${escapeHtml(purchase.nft_name || 'NFT')}</div>
                    <div class="purchase-card-meta">${edition}</div>
                    <div class="purchase-card-actions">
                        ${actionHtml}
                    </div>
                </div>
            </div>
        `;
    }).join('');
    
    return `
        <div class="purchase-carousel-container">
            <button class="purchase-scroll-btn scroll-left" onclick="scrollPurchases(-200)">‹</button>
            <div class="purchase-carousel" id="purchase-carousel">
                ${cards}
            </div>
            <button class="purchase-scroll-btn scroll-right" onclick="scrollPurchases(200)">›</button>
        </div>
    `;
}

/**
 * Claim a pending purchase
 */
async function claimPurchase(purchaseId) {
    if (!window.mintOnDemand) {
        alert('Mint-on-demand module not loaded. Please refresh the page.');
        return;
    }
    
    try {
        await window.mintOnDemand.claimPurchase(purchaseId);
    } catch (error) {
        console.error('Claim error:', error);
        alert('Failed to claim: ' + error.message);
    }
}

/**
 * Helper: Escape HTML
 */
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// v139: Load mint drafts from localStorage
function loadDrafts() {
    const container = document.getElementById('content-drafts');
    const countEl = document.getElementById('drafts-count');
    if (!container) return;
    
    try {
        const raw = localStorage.getItem('imc_mint_draft');
        if (!raw) {
            container.innerHTML = '<div class="empty-section-msg">No saved drafts</div>';
            if (countEl) countEl.textContent = '0';
            return;
        }
        
        const draft = JSON.parse(raw);
        if (!draft || !draft.version) {
            container.innerHTML = '<div class="empty-section-msg">No saved drafts</div>';
            if (countEl) countEl.textContent = '0';
            return;
        }
        
        // Check age (7 day max)
        const savedDate = new Date(draft.savedAt);
        const age = Date.now() - savedDate.getTime();
        if (age > 7 * 24 * 60 * 60 * 1000) {
            localStorage.removeItem('imc_mint_draft');
            container.innerHTML = '<div class="empty-section-msg">No saved drafts</div>';
            if (countEl) countEl.textContent = '0';
            return;
        }
        
        const typeName = draft.contentType === 'musicVideo' ? 'Music Video' :
                        draft.contentType === 'art' ? 'Art' :
                        draft.contentType === 'film' ? 'Film' :
                        draft.contentType === 'music' ? 'Music' : 'NFT';
        const timeAgo = formatTimeAgo(savedDate);
        const nftTitle = draft.formData?.nft_title || draft.formData?.nft_name || 'Untitled';
        const collectionName = draft.collection?.name || 'No collection';
        
        if (countEl) countEl.textContent = '1';
        
        container.innerHTML = `
            <div class="draft-card" style="display:flex;align-items:center;justify-content:space-between;padding:1rem;background:rgba(214,186,102,0.08);border:1px solid rgba(214,186,102,0.2);border-radius:10px;margin-bottom:0.5rem;">
                <div style="display:flex;flex-direction:column;gap:0.25rem;">
                    <strong style="color:#d6ba66;">${escapeHtml(nftTitle)}</strong>
                    <span style="color:#8a8a8a;font-size:0.85rem;">${escapeHtml(typeName)} Access · ${escapeHtml(collectionName)} · Saved ${timeAgo}</span>
                </div>
                <div style="display:flex;gap:0.5rem;">
                    <a href="/mint/" class="btn-primary" style="padding:0.5rem 1rem;border-radius:8px;text-decoration:none;font-size:0.85rem;background:#d6ba66;color:#000;font-weight:600;">Resume</a>
                    <button onclick="if(confirm('Discard this draft?')){localStorage.removeItem('imc_mint_draft');loadDrafts();}" style="padding:0.5rem 1rem;border-radius:8px;border:1px solid rgba(255,255,255,0.2);background:transparent;color:#8a8a8a;cursor:pointer;font-size:0.85rem;">Discard</button>
                </div>
            </div>
        `;
    } catch (e) {
        container.innerHTML = '<div class="empty-section-msg">No saved drafts</div>';
        if (countEl) countEl.textContent = '0';
    }
}

function formatTimeAgo(date) {
    const diff = Date.now() - date.getTime();
    const mins = Math.floor(diff / 60000);
    const hrs = Math.floor(diff / 3600000);
    const days = Math.floor(diff / 86400000);
    if (days > 0) return `${days} day${days > 1 ? 's' : ''} ago`;
    if (hrs > 0) return `${hrs} hour${hrs > 1 ? 's' : ''} ago`;
    if (mins > 0) return `${mins} minute${mins > 1 ? 's' : ''} ago`;
    return 'just now';
}

    /**
     * v305: Load and display the "Recently Minted" banner.
     * Auto-detects the most recently purchased group from purchase history —
     * no URL param needed. Called on every dashboard load so the user always
     * sees their latest acquisition at the top of the page.
     * The banner is dismissable and stays hidden once the user closes it for
     * the current session (sessionStorage key).
     */
    async function loadRecentlyMinted() {
        const banner   = document.getElementById('recently-minted-banner');
        const carousel = document.getElementById('recently-minted-scroll');
        if (!banner || !carousel) return;

        // Don't re-show if the user dismissed it this session
        try {
            if (sessionStorage.getItem('_imc_rm_dismissed')) return;
        } catch(e) {}

        const account = '<?php echo esc_js($xrpl_account); ?>' ||
            (() => { const m = document.cookie.match('(^|;)\s*xrpl_account\s*=\s*([^;]+)'); return m ? decodeURIComponent(m[2]) : ''; })();
        if (!account) return;

        const mintOnDemandUrl = (typeof xrplMarketplace !== 'undefined' && xrplMarketplace?.endpoints?.mintOnDemand) ||
            '<?php echo get_stylesheet_directory_uri(); ?>/xrpl-nft-marketplace/backend/mint-on-demand-handler.php';

        try {
            // Fetch the buyer's recent purchase history
            const res  = await fetch(`${mintOnDemandUrl}?action=get_buyer_purchases&account=${encodeURIComponent(account)}&limit=100`);
            const data = await res.json();
            if (!data.success || !data.data?.purchases?.length) return;

            const all = data.data.purchases;

            // Find the most recently delivered/minted purchase(s).
            // Group by group_id; prefer groups where at least one item is delivered.
            // Pick the group with the most-recent minted_at / created_at timestamp.
            const groupMap = new Map(); // group_id → purchases[]
            for (const p of all) {
                const gid = p.group_id || ('solo_' + p.id); // solo purchases get a virtual group key
                if (!groupMap.has(gid)) groupMap.set(gid, []);
                groupMap.get(gid).push(p);
            }

            // Score each group: must have at least one delivered NFT, sort by newest first
            let bestGroup = null;
            let bestTs    = 0;
            for (const [, purchases] of groupMap) {
                const hasDelivered = purchases.some(p => parseInt(p.delivered) === 1);
                if (!hasDelivered) continue;
                const ts = Math.max(...purchases.map(p => new Date(p.minted_at || p.created_at || 0).getTime()));
                if (ts > bestTs) { bestTs = ts; bestGroup = purchases; }
            }

            if (!bestGroup) return;

            const imgErr = "this.src='/wp-content/uploads/fallback-nft.svg'";
            const cards  = bestGroup.map(p => {
                const cid    = (p.cover_ipfs || '').replace('ipfs://', '');
                const imgSrc = cid
                    ? `https://metadata.imcollectibles.io/img.php?url=${encodeURIComponent('ipfs://'+cid)}&thumb=1` /* L3b: latest-mint cards — thumb */
                    : '/wp-content/uploads/fallback-nft.svg';
                const name    = escapeHtml(p.nft_name || 'NFT');
                const edition = p.edition_number > 0
                    ? `Edition #${p.edition_number}${p.tier_name ? ' · '+escapeHtml(p.tier_name) : ''}` : '';
                const delivered = parseInt(p.delivered) === 1;
                const linkHtml  = delivered && p.nftoken_id
                    ? `<a href="/nft/${encodeURIComponent(p.nftoken_id)}/" class="recently-minted-card-link">View NFT →</a>`
                    : `<span class="recently-minted-card-link no-link">${delivered ? 'No token ID' : '⏳ Pending…'}</span>`;
                return `
                    <div class="recently-minted-card">
                        <img class="recently-minted-card-img" src="${imgSrc}" alt="${name}" onerror="${imgErr}">
                        <div class="recently-minted-card-body">
                            <div class="recently-minted-card-name">${name}</div>
                            ${edition ? `<div class="recently-minted-card-edition">${edition}</div>` : ''}
                        </div>
                        ${linkHtml}
                    </div>`;
            }).join('');

            carousel.innerHTML = cards;
            banner.style.display = 'block';

            // v327: Derive collection URL from the first purchase in the group
            // and show a "View Collection →" link in the banner footer.
            // Mirrors the three-tier logic in page-nft-single.php:
            //   (1) Known IMU slug  →  /collections/{slug}/
            //   (2) Platform NFT    →  /collections/?issuer=X&taxon=Y
            const imuSlugs = {
                'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR_0':          'guardians',
                'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga_717825':      'frequencies',
                'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt_1056369418':  'ledger',
                'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR_777':         'lasvegas',
                'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR_666':         'firepit',
                'rHsrif6nHTkmyh38W7JmYjairPWhq5P3AH_0':           'special-edition'
            };
            // Use first purchase that has artist_account + collection_taxon
            const ref = bestGroup.find(p => p.artist_account && p.collection_taxon != null) || bestGroup[0];
            if (ref && ref.artist_account) {
                const mapKey  = ref.artist_account + '_' + ref.collection_taxon;
                const slug    = imuSlugs[mapKey];
                const colUrl  = slug
                    ? `/collections/${slug}/`
                    : `/collections/?issuer=${encodeURIComponent(ref.artist_account)}&taxon=${encodeURIComponent(ref.collection_taxon)}`;
                const footer = document.getElementById('recently-minted-footer');
                if (footer) {
                    footer.innerHTML = `<a href="${colUrl}">View Collection →</a>`;
                    footer.style.display = 'block';
                }
            }

        } catch (err) {
            console.warn('loadRecentlyMinted error:', err);
        }
    }

    loadDrafts();
    loadRecentlyMinted();     // v305: Always show most recent purchase group at top
    loadPendingClaims();      // v278: Pending Claims must load first — actionable items
    loadPurchaseHistory();
    loadDashboard();
    window.reloadDashboard = loadDashboard;

    // v286: Expose Pending Claims action functions to window scope.
    // retryMint() and claimPurchase() are called via onclick attributes in
    // dynamically rendered HTML. onclick= resolves against window scope, not
    // the IIFE scope where these functions are defined. Without these
    // assignments the Claim and Retry buttons in Pending Claims are silently
    // non-functional — the calls throw "retryMint is not defined" in console.
    window.retryMint    = retryMint;
    window.claimPurchase = claimPurchase;

    // v283: ?pending=1 — auto-expand Pending Claims (legacy multi-mint return URL)
    if (new URLSearchParams(window.location.search).get('pending') === '1') {
        const section = document.getElementById('section-pending-claims');
        if (section) {
            section.classList.add('expanded');
            setTimeout(() => section.scrollIntoView({ behavior: 'smooth', block: 'start' }), 600);
        }
    }

    // v305: Recently Minted now auto-loads from history — no URL param handler needed.

    // ── v295: MOBILE PAYMENT COMPLETE HANDLER ─────────────────────────────
    // When a buyer pays via Xaman on mobile, Xaman fires the return_url which
    // is now /trading-hub-dashboard/?payment_pending=1&group_id=X&listing_id=Y
    //
    // PREVIOUS BEHAVIOUR: return_url was /collections/?purchase_pending=1
    // The resumePaymentPollIfNeeded() in mint-on-demand.js tried to detect this
    // on the collections page, but: the purchase modal doesn't exist on a fresh
    // page load (createPurchaseModal() was never called), so the modal was null,
    // UI setup was silently skipped, and the user saw a blank /collections/ page.
    //
    // FIX: Land on the trading hub dashboard instead. This handler:
    //   1. Reads group_id from the URL param
    //   2. Also checks sessionStorage for _imc_pay_uuid (saved when payment started)
    //   3. Calls window.mintOnDemand.resumePaymentFromDashboard(groupId, uuid)
    //      which handles both "already paid" and "still polling" cases
    //   4. The purchase modal is created on-demand by resumePaymentFromDashboard
    // ─────────────────────────────────────────────────────────────────────────
    (function handlePaymentPending() {
        const params  = new URLSearchParams(window.location.search);
        const groupId = parseInt(params.get('group_id') || '0', 10);

        if (params.get('payment_pending') !== '1' || !groupId) return;

        // Clean URL bar (cosmetic — removes the ?payment_pending=1 query string)
        try {
            window.history.replaceState({}, '', window.location.pathname + '#pending-claims');
        } catch (e) {}

        // Retrieve UUID from sessionStorage (saved by savePaymentPollState when
        // the user first tapped the Xaman deeplink on the /collections/ page)
        let storedUuid = null;
        try {
            const uuid = sessionStorage.getItem('_imc_pay_uuid');
            const grp  = sessionStorage.getItem('_imc_pay_group');
            const ts   = parseInt(sessionStorage.getItem('_imc_pay_ts') || '0', 10);
            // Only use if it matches this group and is < 30 minutes old
            if (uuid && grp && String(groupId) === grp && (Date.now() - ts) < 1800000) {
                storedUuid = uuid;
                // Clear it — the dashboard handler takes over from here
                sessionStorage.removeItem('_imc_pay_uuid');
                sessionStorage.removeItem('_imc_pay_group');
                sessionStorage.removeItem('_imc_pay_ts');
            }
        } catch (e) {}

        // Expand + scroll to Pending Claims immediately while we process
        const section = document.getElementById('section-pending-claims');
        if (section) {
            section.classList.add('expanded');
            setTimeout(() => section.scrollIntoView({ behavior: 'smooth', block: 'start' }), 300);
        }

        // Wait for mintOnDemand to initialise (it loads async after DOM ready)
        function tryResume(attempts) {
            if (window.mintOnDemand?.resumePaymentFromDashboard) {
                showToast('Resuming your purchase...', 'info');
                window.mintOnDemand.resumePaymentFromDashboard(groupId, storedUuid);
            } else if (attempts > 0) {
                setTimeout(() => tryResume(attempts - 1), 200);
            } else {
                showToast('Could not resume purchase automatically. Check Pending Claims below or retry from purchase history.', 'info');
            }
        }
        // Give mint-on-demand.js up to 3 seconds (15 × 200ms) to initialise
        setTimeout(() => tryResume(15), 300);
    })();

    // ── v294: MOBILE CLAIM COMPLETE HANDLER ───────────────────────────────
    // When a user claims their NFT via Xaman on mobile, Xaman fires the
    // return_url which is now always:
    //   /trading-hub-dashboard/?claim_complete=1&purchase_id=X[&nftoken_id=Y]
    //
    // The JS poll loop (startClaimPolling in the purchase modal) is gone —
    // it lived in the /collections/ page that was navigated away from.
    // confirmDelivery() was never called, so delivered=0 remains in the DB.
    //
    // This handler detects claim_complete, calls reconcile_delivery on the
    // server (which verifies via ledger_entry that the sell offer is gone),
    // then reloads Pending Claims so the card disappears immediately.
    // ─────────────────────────────────────────────────────────────────────
    (async function handleClaimComplete() {
        const params     = new URLSearchParams(window.location.search);
        const purchaseId = params.get('purchase_id');
        const nftokenId  = params.get('nftoken_id');

        if (params.get('claim_complete') !== '1' || !purchaseId) return;

        // Remove params from URL bar (cosmetic)
        try {
            window.history.replaceState({}, '', window.location.pathname);
        } catch (e) {}

        const mintOnDemandUrl = (typeof xrplMarketplace !== 'undefined' && xrplMarketplace?.endpoints?.mintOnDemand) ||
            '<?php echo get_stylesheet_directory_uri(); ?>/xrpl-nft-marketplace/backend/mint-on-demand-handler.php';

        // Show a brief status toast while we verify
        showToast('Verifying your claim...', 'info');

        // Expand Pending Claims immediately
        const section = document.getElementById('section-pending-claims');
        if (section) {
            section.classList.add('expanded');
            setTimeout(() => section.scrollIntoView({ behavior: 'smooth', block: 'start' }), 300);
        }

        try {
            const account = '<?php echo esc_js($xrpl_account); ?>' ||
                (() => { const m = document.cookie.match('(^|;)\\s*xrpl_account\\s*=\\s*([^;]+)'); return m ? decodeURIComponent(m[2]) : ''; })();

            if (!account) { showToast('Wallet not connected — please refresh.', 'error'); return; }

            const fd = new FormData();
            fd.append('action',       'reconcile_delivery');
            fd.append('nonce',        '<?php echo wp_create_nonce('xrpl_marketplace_nonce'); ?>');
            fd.append('purchase_id',  purchaseId);
            fd.append('buyer_account', account);

            const res  = await fetch(mintOnDemandUrl, { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success && data.data?.status === 'delivered') {
                showToast('🎉 NFT claimed successfully!', 'success');
                // v305: Reload both Pending Claims and Recently Minted simultaneously.
                // Recently Minted auto-detects the latest group — no groupId param needed.
                await loadPendingClaims();
                try { sessionStorage.removeItem('_imc_rm_dismissed'); } catch(e) {}
                loadRecentlyMinted();
                // If more remain, scroll to pending claims; else the banner scrolls into view
                const stillPending = document.querySelectorAll('#content-pending-claims .purchase-card');
                if (stillPending.length > 0) {
                    const section = document.getElementById('section-pending-claims');
                    if (section) setTimeout(() => section.scrollIntoView({ behavior: 'smooth', block: 'start' }), 400);
                }
            } else if (data.success && data.data?.status === 'already_delivered') {
                showToast('NFT already marked as claimed.', 'info');
                await loadPendingClaims();
            } else if (data.success && data.data?.status === 'pending') {
                // Offer still on ledger — user may have cancelled in Xaman
                showToast('Claim not detected on blockchain yet. If you signed in Xaman, it may take a moment.', 'info');
                // Reload after 5s to check again
                setTimeout(loadPendingClaims, 5000);
            } else {
                showToast('Could not verify claim: ' + (data.error || 'Unknown error'), 'error');
                await loadPendingClaims();
            }
        } catch (err) {
            console.error('claim_complete handler error:', err);
            showToast('Verification failed — refreshing claims list.', 'error');
            await loadPendingClaims();
        }
    })();

})(); // v299: close outer (function() { IIFE opened at line 1265
</script>

<?php if (function_exists('imc_xrplto_attribution')) imc_xrplto_attribution(); ?>
<?php get_footer(); ?>