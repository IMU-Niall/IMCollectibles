<?php
/**
 * Template Name: IMU Terms of Service
 * File: page-terms.php
 * Path: /wp-content/themes/astra/page-terms.php
 * Terms of Service & Content Creator Agreement — IMU, LLC
 * Effective: May 22, 2026
 */
global $imc_og_data;
$imc_og_data = [
    'title'       => 'Terms of Service | IMCollectibles',
    'description' => 'Terms of Service and Content Creator Agreement for IMU, LLC — governing all use of IMUTV, IMUP3, IM Collectibles, and the IMU Merch Store.',
    'image'       => defined('IMC_OG_DEFAULT_IMAGE') ? IMC_OG_DEFAULT_IMAGE : 'https://imcollectibles.io/wp-content/uploads/og-default.png',
    'url'         => home_url('/terms/'),
    'type'        => 'website',
];
get_header();
?>
<style>
/* ============================================================
   IMU Legal Pages — Shared CSS (Privacy + Terms)
   Matches IMC branding: flexbox centering, gold gradient H1,
   left-border section accents, dark card containers
   ============================================================ */

/* ── Outer wrapper — mirrors .tasks-page-container pattern ── */
.legal-page-wrap {
    position: relative;
    min-height: 100vh;
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    background: linear-gradient(160deg, #08080e 0%, #0e0e16 60%, #141420 100%);
    padding: 3rem 1.5rem 6rem;
    box-sizing: border-box;
    font-family: 'Montserrat', sans-serif;
    color: var(--imp-text, #e8e6e3);
}

.legal-container {
    width: 100%;
    max-width: 880px;
}

/* ── Hero ── */
.legal-hero {
    text-align: center;
    padding: 2.5rem 0 3rem;
    border-bottom: 1px solid var(--imp-border-gold, rgba(var(--imu-gold-rgb), 0.4));
    margin-bottom: 3rem;
    width: 100%;
}
.legal-eyebrow {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.25em;
    text-transform: uppercase;
    color: var(--imp-gold, var(--imu-gold, #d6ba66));
    margin-bottom: 1rem;
}
.legal-hero h1 {
    font-family: 'Lora', serif !important;
    font-size: clamp(2.2rem, 5vw, 3.2rem);
    font-weight: 700 !important;
    line-height: 1.2;
    margin: 0 0 1rem;
    text-align: center;
    background: linear-gradient(180deg, #ffe066 0%, var(--imu-gold, #d6ba66) 40%, #b8942a 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    filter: drop-shadow(0 0 20px rgba(212,175,55,0.35));
}
.legal-subtitle {
    font-size: 0.93rem;
    color: var(--imp-text-muted, #8a8a8a);
    line-height: 1.75;
    max-width: 660px;
    margin: 0 auto 1.5rem;
}
.legal-meta {
    display: flex;
    justify-content: center;
    gap: 2rem;
    flex-wrap: wrap;
    font-size: 0.78rem;
    color: var(--imp-text-muted, #8a8a8a);
}
.legal-meta strong { color: var(--imp-gold, var(--imu-gold, #d6ba66)); }

/* ── Table of Contents ── */
.legal-toc {
    background: var(--imp-bg-card, #12121a);
    border: 1px solid var(--imp-border, rgba(var(--imu-gold-rgb), 0.15));
    border-top: 2px solid var(--imp-gold, var(--imu-gold, #d6ba66));
    border-radius: 10px;
    padding: 2rem 2.5rem;
    margin-bottom: 3rem;
    width: 100%;
    box-sizing: border-box;
}
.legal-toc-title {
    font-family: 'Montserrat', sans-serif;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.22em;
    text-transform: uppercase;
    color: var(--imp-gold, var(--imu-gold, #d6ba66));
    text-align: center;
    margin: 0 0 1.5rem;
}
.legal-toc-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.3rem 2.5rem;
}
.legal-toc-item {
    display: flex;
    gap: 0.6rem;
    font-size: 0.83rem;
    padding: 0.25rem 0;
    border-bottom: 1px solid rgba(var(--imu-gold-rgb), 0.07);
    align-items: baseline;
}
.legal-toc-num {
    color: var(--imp-gold, var(--imu-gold, #d6ba66));
    font-weight: 700;
    min-width: 1.8rem;
    font-size: 0.78rem;
}
.legal-toc-item a {
    color: var(--imp-text, #e8e6e3);
    text-decoration: none;
    transition: color 0.2s;
    line-height: 1.4;
}
.legal-toc-item a:hover { color: var(--imp-gold, var(--imu-gold, #d6ba66)); }
/* Part header rows in TOC */
.legal-toc-part {
    grid-column: 1 / -1;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--imp-gold, var(--imu-gold, #d6ba66));
    margin: 1rem 0 0.25rem;
    padding-bottom: 0.25rem;
    border-bottom: 1px solid var(--imp-border-gold, rgba(var(--imu-gold-rgb), 0.3));
}

/* ── Part Chapter Headers ── */
.legal-part-header {
    width: 100%;
    margin: 3.5rem 0 2rem;
    text-align: center;
    padding: 1.5rem 2rem;
    background: var(--imp-bg-card, #12121a);
    border: 1px solid var(--imp-border-gold, rgba(var(--imu-gold-rgb), 0.4));
    border-radius: 10px;
    box-sizing: border-box;
}
.legal-part-header .part-label {
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.3em;
    text-transform: uppercase;
    color: var(--imp-gold, var(--imu-gold, #d6ba66));
    margin-bottom: 0.4rem;
}
.legal-part-header h2 {
    font-family: 'Lora', serif !important;
    font-size: 1.4rem;
    font-weight: 700;
    color: #fff;
    margin: 0;
    border: none !important;
    padding: 0 !important;
}
.legal-part-header p {
    font-size: 0.83rem;
    color: var(--imp-text-muted, #8a8a8a);
    margin: 0.5rem 0 0;
    line-height: 1.6;
}

/* ── Sections ── */
.legal-section {
    width: 100%;
    margin-bottom: 2.75rem;
    scroll-margin-top: 85px;
    box-sizing: border-box;
}
.legal-section h2 {
    font-family: 'Lora', serif !important;
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--imp-gold, var(--imu-gold, #d6ba66));
    margin: 0 0 1.1rem;
    padding: 0 0 0.5rem 0.9rem;
    border-left: 3px solid var(--imp-gold, var(--imu-gold, #d6ba66));
    border-bottom: 1px solid var(--imp-border, rgba(var(--imu-gold-rgb), 0.12));
    line-height: 1.3;
}
.legal-section h3 {
    font-family: 'Montserrat', sans-serif;
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--imp-gold-light, #e8d38a);
    margin: 1.75rem 0 0.65rem;
}
.legal-section h4 {
    font-family: 'Montserrat', sans-serif;
    font-size: 0.88rem;
    font-weight: 700;
    color: var(--imp-text, #e8e6e3);
    margin: 1.2rem 0 0.4rem;
}
.legal-section p {
    font-size: 0.9rem;
    line-height: 1.85;
    color: var(--imp-text, #e8e6e3);
    margin: 0 0 0.9rem;
}
.legal-section ul,
.legal-section ol {
    margin: 0 0 0.9rem 1.6rem;
    padding: 0;
}
.legal-section li {
    font-size: 0.9rem;
    line-height: 1.8;
    color: var(--imp-text, #e8e6e3);
    margin-bottom: 0.3rem;
}
.legal-section a {
    color: var(--imp-gold, var(--imu-gold, #d6ba66));
    text-decoration: underline;
}
.legal-section a:hover { color: var(--imp-gold-light, #e8d38a); }

/* Caps / uppercase legal text blocks */
.legal-caps {
    font-size: 0.85rem !important;
    font-weight: 700 !important;
    color: #fff !important;
    line-height: 1.7 !important;
    letter-spacing: 0.01em;
}

/* ── Callout / Notice Boxes ── */
.legal-notice {
    background: rgba(var(--imu-gold-rgb), 0.07);
    border-left: 3px solid var(--imp-gold, var(--imu-gold, #d6ba66));
    border-radius: 0 8px 8px 0;
    padding: 0.9rem 1.2rem;
    margin: 1.1rem 0;
    font-size: 0.88rem;
    line-height: 1.75;
    color: var(--imp-text, #e8e6e3);
}
.legal-notice strong { color: var(--imp-gold, var(--imu-gold, #d6ba66)); }
.legal-notice.warning {
    background: rgba(239,68,68,0.07);
    border-left-color: #ef4444;
}

/* ── Tables ── */
.legal-table-wrap {
    overflow-x: auto;
    margin: 1.2rem 0;
    border-radius: 8px;
    border: 1px solid var(--imp-border, rgba(var(--imu-gold-rgb), 0.15));
    width: 100%;
}
.legal-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.83rem;
    min-width: 480px;
}
.legal-table thead tr {
    background: var(--imp-bg-card, #12121a);
}
.legal-table th {
    padding: 0.7rem 1rem;
    text-align: left;
    font-family: 'Montserrat', sans-serif;
    font-weight: 700;
    font-size: 0.75rem;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--imp-gold, var(--imu-gold, #d6ba66));
    border-bottom: 1px solid var(--imp-border-gold, rgba(var(--imu-gold-rgb), 0.4));
    white-space: nowrap;
}
.legal-table td {
    padding: 0.7rem 1rem;
    vertical-align: top;
    color: var(--imp-text, #e8e6e3);
    border-bottom: 1px solid rgba(var(--imu-gold-rgb), 0.08);
    line-height: 1.6;
}
.legal-table tbody tr:last-child td { border-bottom: none; }
.legal-table tbody tr:hover td { background: rgba(var(--imu-gold-rgb), 0.04); }

/* ── Contact Box ── */
.legal-contact-box {
    background: var(--imp-bg-card, #12121a);
    border: 1px solid var(--imp-border-gold, rgba(var(--imu-gold-rgb), 0.4));
    border-top: 2px solid var(--imp-gold, var(--imu-gold, #d6ba66));
    border-radius: 10px;
    padding: 2rem 2.5rem;
    margin-top: 1.25rem;
}
.legal-contact-box h3 {
    font-family: 'Lora', serif;
    font-size: 1.1rem;
    color: var(--imp-gold, var(--imu-gold, #d6ba66));
    margin: 0 0 1rem;
}
.legal-contact-box p {
    margin: 0.3rem 0;
    font-size: 0.88rem;
    color: var(--imp-text, #e8e6e3);
}
.legal-contact-box a { color: var(--imp-gold, var(--imu-gold, #d6ba66)); text-decoration: underline; }
.legal-contact-divider {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid rgba(var(--imu-gold-rgb), 0.18);
}

/* ── Exhibit divider ── */
.legal-exhibit-header {
    width: 100%;
    margin: 4rem 0 2.5rem;
    text-align: center;
    position: relative;
}
.legal-exhibit-header::before {
    content: '';
    display: block;
    height: 1px;
    background: linear-gradient(90deg, transparent, var(--imp-gold, var(--imu-gold, #d6ba66)), transparent);
    margin-bottom: 2rem;
}
.legal-exhibit-header .exhibit-label {
    font-size: 0.68rem;
    letter-spacing: 0.3em;
    text-transform: uppercase;
    color: var(--imp-gold, var(--imu-gold, #d6ba66));
    margin-bottom: 0.5rem;
    font-weight: 700;
}
.legal-exhibit-header h2 {
    font-family: 'Lora', serif !important;
    font-size: clamp(1.6rem, 4vw, 2.2rem);
    font-weight: 700;
    background: linear-gradient(180deg, #ffe066 0%, var(--imu-gold, #d6ba66) 40%, #b8942a 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    filter: drop-shadow(0 0 14px rgba(212,175,55,0.3));
    margin: 0 0 0.75rem;
    border: none !important;
    padding: 0 !important;
}
.legal-exhibit-header p {
    font-size: 0.85rem;
    color: var(--imp-text-muted, #8a8a8a);
    max-width: 600px;
    margin: 0 auto;
    line-height: 1.7;
}

/* ── Back to top ── */
.legal-back-top {
    display: block;
    text-align: right;
    font-size: 0.76rem;
    color: var(--imp-text-muted, #8a8a8a);
    text-decoration: none;
    margin-top: 0.75rem;
    transition: color 0.2s;
}
.legal-back-top:hover { color: var(--imp-gold, var(--imu-gold, #d6ba66)); }

/* ── Footer note ── */
.legal-footer-note {
    text-align: center;
    margin-top: 2.5rem;
    font-size: 0.76rem;
    color: var(--imp-text-muted, #8a8a8a);
}
.legal-footer-note a { color: var(--imp-gold, var(--imu-gold, #d6ba66)); }

/* ── Responsive ── */
@media (max-width: 640px) {
    .legal-toc-grid { grid-template-columns: 1fr; }
    .legal-toc { padding: 1.5rem; }
    .legal-contact-box { padding: 1.5rem; }
    .legal-meta { gap: 0.75rem; flex-direction: column; align-items: center; }
    .legal-part-header { padding: 1.2rem; }
}
</style>

<div class="legal-page-wrap" id="terms-top">
<div class="legal-container">

<!-- Hero -->
<div class="legal-hero">
    <div class="legal-eyebrow">IMU, LLC &mdash; Legal</div>
    <h1>Terms of Service<br>&amp; Content Creator Agreement</h1>
    <p class="legal-subtitle">Governing all use of the IMUTV, IMUP3, IM Collectibles, and IMU Merch platforms, including web, mobile, and connected-TV applications.</p>
    <div class="legal-meta">
        <span><strong>Effective Date:</strong> May 22, 2026</span>
        <span><strong>Last Updated:</strong> May 22, 2026</span>
        <span><strong>Entity:</strong> IMU, LLC &mdash; A Florida Limited Liability Company</span>
    </div>
</div>

<!-- TOC -->
<div class="legal-toc" id="toc">
    <div class="legal-toc-title">Table of Contents</div>
    <div class="legal-toc-grid">
        <div class="legal-toc-part">Part I &mdash; General Terms of Service</div>
        <div class="legal-toc-item"><span class="legal-toc-num">1.</span><a href="#t-s1">Definitions</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">6.</span><a href="#t-s6">Acceptable Use Policy</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">2.</span><a href="#t-s2">Eligibility</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">7.</span><a href="#t-s7">Intellectual Property of the Platform</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">3.</span><a href="#t-s3">Your Account</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">8.</span><a href="#t-s8">Copyright Policy (DMCA)</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">4.</span><a href="#t-s4">Description of the Service</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">9.</span><a href="#t-s9">Live Streaming Policy</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">5.</span><a href="#t-s5">Monetization, Payments &amp; Subscriptions</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">10.</span><a href="#t-s10">Content Moderation</a></div>

        <div class="legal-toc-part">Part II &mdash; Content Creator Agreement</div>
        <div class="legal-toc-item"><span class="legal-toc-num">11.</span><a href="#t-s11">Grant of Distribution License</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">15.</span><a href="#t-s15">Special Provisions for Anime &amp; Fan Content</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">12.</span><a href="#t-s12">Consent to Technical Modifications</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">16.</span><a href="#t-s16">AI-Generated Content Policy</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">13.</span><a href="#t-s13">Creator Representations and Warranties</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">17.</span><a href="#t-s17">Creator Plans</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">14.</span><a href="#t-s14">Errors &amp; Omissions Insurance</a></div>
        <div class="legal-toc-item"></div>

        <div class="legal-toc-part">Part III &mdash; IMUP3 Audio Streaming Terms</div>
        <div class="legal-toc-item"><span class="legal-toc-num">18.</span><a href="#t-s18">IMUP3 Service Description</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">20.</span><a href="#t-s20">Audio Streaming User Rights</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">19.</span><a href="#t-s19">Podcast Terms</a></div>
        <div class="legal-toc-item"></div>

        <div class="legal-toc-part">Part IV &mdash; IM Collectibles (NFT Marketplace) Terms</div>
        <div class="legal-toc-item"><span class="legal-toc-num">21.</span><a href="#t-s21">IM Collectibles Service Description</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">24.</span><a href="#t-s24">XRPL &amp; Blockchain Terms</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">22.</span><a href="#t-s22">What You Purchase</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">25.</span><a href="#t-s25">AML/KYC &amp; OFAC Compliance</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">23.</span><a href="#t-s23">Creator Warranties for Digital Collectibles</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">26.</span><a href="#t-s26">Refund Policy for Digital Collectibles</a></div>

        <div class="legal-toc-part">Part V &mdash; IMU Merch Store Terms</div>
        <div class="legal-toc-item"><span class="legal-toc-num">27.</span><a href="#t-s27">Merch Store Description</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">29.</span><a href="#t-s29">Shipping, Returns &amp; Refunds</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">28.</span><a href="#t-s28">Purchases &amp; Payments</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">30.</span><a href="#t-s30">Creator Merch Fulfillment Services</a></div>

        <div class="legal-toc-part">Part VI &mdash; General Legal Provisions</div>
        <div class="legal-toc-item"><span class="legal-toc-num">31.</span><a href="#t-s31">Indemnification</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">36.</span><a href="#t-s36">Termination</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">32.</span><a href="#t-s32">Limitation of Liability</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">37.</span><a href="#t-s37">Modifications to Terms</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">33.</span><a href="#t-s33">Disclaimers</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">38.</span><a href="#t-s38">App Store Terms</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">34.</span><a href="#t-s34">Dispute Resolution &amp; Arbitration</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">39.</span><a href="#t-s39">Miscellaneous</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">35.</span><a href="#t-s35">Governing Law</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">40.</span><a href="#t-s40">Contact Information</a></div>

        <div class="legal-toc-part">Schedule A &mdash; XRP, RLUSD &amp; XFT Cryptocurrency Payment Terms</div>
        <div class="legal-toc-item"><span class="legal-toc-num">SA1.</span><a href="#sa-s1">Scope and Relationship to TOS</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">SA8.</span><a href="#sa-s8">Tipping</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">SA2.</span><a href="#sa-s2">Definitions</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">SA9.</span><a href="#sa-s9">Refund Mechanics for Crypto Transactions</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">SA3.</span><a href="#sa-s3">Accepted Cryptocurrencies</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">SA10.</span><a href="#sa-s10">Recurring Payments (XRPL Subscriptions)</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">SA4.</span><a href="#sa-s4">XRP Payment Mechanics</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">SA11.</span><a href="#sa-s11">Tax Treatment</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">SA5.</span><a href="#sa-s5">RLUSD Payment Mechanics</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">SA12.</span><a href="#sa-s12">Wallet Security and Non-Custodial Architecture</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">SA6.</span><a href="#sa-s6">XFT Payment Mechanics</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">SA13.</span><a href="#sa-s13">Modifications</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">SA7.</span><a href="#sa-s7">Creator Payouts</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">SA14.</span><a href="#sa-s14">Contact</a></div>

        <div class="legal-toc-part">Schedule B &mdash; IM Collectibles Terms of Sale</div>
        <div class="legal-toc-item"><span class="legal-toc-num">A1.</span><a href="#ex-s1">Introduction &amp; Scope</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">A11.</span><a href="#ex-s11">Copyright Disputes &amp; DMCA for NFTs</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">A2.</span><a href="#ex-s2">Definitions</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">A12.</span><a href="#ex-s12">AML/KYC &amp; Sanctions Compliance</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">A3.</span><a href="#ex-s3">Nature of Digital Collectibles</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">A13.</span><a href="#ex-s13">Platform Role &amp; Limitations</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">A4.</span><a href="#ex-s4">How Transactions Work</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">A14.</span><a href="#ex-s14">Limitation of Liability &amp; Disclaimers</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">A5.</span><a href="#ex-s5">Fees &amp; Costs</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">A15.</span><a href="#ex-s15">Dispute Resolution</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">A6.</span><a href="#ex-s6">License Granted to Buyers</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">A16.</span><a href="#ex-s16">Governing Law</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">A7.</span><a href="#ex-s7">Creator Obligations</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">A17.</span><a href="#ex-s17">Modifications to These Sale Terms</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">A8.</span><a href="#ex-s8">Buyer Responsibilities &amp; Acknowledgments</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">A18.</span><a href="#ex-s18">Account Termination &amp; Marketplace Removal</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">A9.</span><a href="#ex-s9">Refund &amp; Cancellation Policy</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">A19.</span><a href="#ex-s19">Contact Information</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">A10.</span><a href="#ex-s10">Prohibited Marketplace Activities</a></div>
        <div class="legal-toc-item"></div>

        <div class="legal-toc-part">Schedule C &mdash; IM Collectibles Feature Addendum</div>
        <div class="legal-toc-item"><span class="legal-toc-num">C1.</span><a href="#sc-s1">Scope and Relationship to Other Documents</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">C6.</span><a href="#sc-s6">Redeem (NFT Holder Ad Revenue Distribution)</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">C2.</span><a href="#sc-s2">General Provisions Applicable to All Features</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">C7.</span><a href="#sc-s7">IMU Airdrop</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">C3.</span><a href="#sc-s3">Burn 2 Earn</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">C8.</span><a href="#sc-s8">Champion of Frequencies (IMU Gaming)</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">C4.</span><a href="#sc-s4">Frequency Fountain</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">C9.</span><a href="#sc-s9">General Provisions</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">C5.</span><a href="#sc-s5">Tasks &amp; Rewards</a></div>
        <div class="legal-toc-item"></div>

        <div class="legal-toc-part">Schedule D &mdash; XFT Streaming Rewards Program Terms</div>
        <div class="legal-toc-item"><span class="legal-toc-num">D1.</span><a href="#sd-s1">Program Description</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">D7.</span><a href="#sd-s7">Tax Responsibility</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">D2.</span><a href="#sd-s2">Nature of XFT</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">D8.</span><a href="#sd-s8">Relationship to Other Reward Programs</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">D3.</span><a href="#sd-s3">Viewer Eligibility and Rewards</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">D9.</span><a href="#sd-s9">Modification, Suspension, and Termination</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">D4.</span><a href="#sd-s4">Music Artist Eligibility and Royalties</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">D10.</span><a href="#sd-s10">Disclaimers and Limitation of Liability</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">D5.</span><a href="#sd-s5">Payout Mechanics</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">D11.</span><a href="#sd-s11">Governing Law and Disputes</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">D6.</span><a href="#sd-s6">Anti-Gaming and Fraud Prevention</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">D12.</span><a href="#sd-s12">Contact</a></div>
    </div>
</div>

<!-- ===================== PART I ===================== -->
<div class="legal-part-header" id="part1">
    <div class="part-label">Part I</div>
    <h2>General Terms of Service</h2>
    <p>Welcome to the IMU ecosystem. These Terms constitute a binding legal agreement between you and IMU, LLC, a Florida limited liability company.</p>
</div>

<div class="legal-notice">BY ACCESSING, USING, OR SUBMITTING CONTENT TO THE SERVICE, YOU AGREE TO BE BOUND BY ALL PARTS OF THESE TERMS. IF YOU DO NOT AGREE, DO NOT USE THE SERVICE.</div>
<br>

<div class="legal-section" id="t-s1">
    <h2>1. Definitions</h2>
    <p>This document (these "Terms") outlines the legal terms that govern your use of the IMUTV website and applications, the IMUP3 audio streaming application, the IM Collectibles digital marketplace, the IMU Merch Store, and all related services (collectively, the "Service"). These Terms are a binding legal agreement between you ("User," "Creator," "you") and IMU, LLC ("IMU," "IMUTV," "we," "us," or "our"), a Florida limited liability company.</p>
    <p>Your use of the Service is also governed by our <a href="<?php echo home_url('/privacy/'); ?>">Privacy Policy</a>, which is a separate document available at https://imutv.tv/privacy and https://imcollectibles.io/privacy. Our Content Submission Guidelines, Community Guidelines, and any platform-specific addenda are incorporated into these Terms by reference.</p>
    <ul>
        <li><strong>"Content"</strong> means any and all materials submitted by Creators, including but not limited to: sound recordings, musical compositions, audiovisual works (films, series, music videos, short-form video), visual art (paintings, digital art, photography, NFTs), animation, literary works, podcasts, and live stream broadcasts.</li>
        <li><strong>"Creator"</strong> means any User who uploads, submits, or otherwise makes content available on the Service through the approved submission process.</li>
        <li><strong>"Digital Collectible" or "NFT"</strong> means a non-fungible token minted on the XRP Ledger (XRPL) through the IM Collectibles platform, representing a unique digital asset.</li>
        <li><strong>"IMUTV"</strong> means the connected-TV and web video platform operated by IMU, providing on-demand and live video streaming across Roku, Amazon Fire TV, Google TV, Android TV, Apple TV, web, and mobile applications.</li>
        <li><strong>"IMUP3"</strong> means the interactive audio streaming application operated by IMU, providing on-demand music streaming, playlist creation, and podcast hosting across web, iOS, and Android.</li>
        <li><strong>"IM Collectibles" or "IMC"</strong> means the digital collectibles and NFT marketplace operated by IMU at www.imcollectibles.io, built on the XRP Ledger.</li>
        <li><strong>"IMU Content"</strong> means all content on the Service that is not User-submitted Content, including our branding, logos, software, text, graphics, and proprietary technology.</li>
        <li><strong>"Platform"</strong> means any individual IMU service (IMUTV, IMUP3, IM Collectibles, or IMU Merch Store) or any combination thereof.</li>
        <li><strong>"Service"</strong> means, collectively, all IMU platforms, websites, applications, and related services.</li>
        <li><strong>"User"</strong> means any person who accesses or uses the Service, including general viewers, listeners, purchasers, and Creators.</li>
        <li><strong>"XRPL"</strong> means the XRP Ledger, a decentralized, open-source blockchain.</li>
    </ul>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s2">
    <h2>2. Eligibility</h2>
    <h3>2.1 Age Requirement</h3>
    <p>You must be at least eighteen (18) years of age to access or use the Service. By accessing the Service, you represent and warrant that you are at least 18 years old. If you are under 18, you may not access or use the Service under any circumstances.</p>
    <h3>2.2 Legal Capacity</h3>
    <p>You represent that you have the legal capacity to enter into a binding agreement. If you are accessing the Service on behalf of an organization, you represent and warrant that you have the authority to bind that organization to these Terms.</p>
    <h3>2.3 Geographic Restrictions</h3>
    <p>The Service is available globally, subject to applicable law. You may not use the Service if you are located in, or a national or resident of, any country subject to comprehensive U.S. sanctions administered by the Office of Foreign Assets Control (OFAC), including but not limited to Cuba, North Korea, Iran, Syria, and the Crimea, Donetsk, and Luhansk regions of Ukraine.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s3">
    <h2>3. Your Account</h2>
    <h3>3.1 Account Creation</h3>
    <p>You may browse certain portions of the Service without an account. However, an account is required to: (a) purchase goods, subscriptions, or digital collectibles; (b) submit Content as a Creator; (c) create playlists or save favorites; (d) access live streaming features; or (e) participate in community features.</p>
    <h3>3.2 Account Security</h3>
    <p>You are responsible for maintaining the confidentiality of your account credentials and for all activities that occur under your account. You must immediately notify us at <a href="mailto:privacy@imutv.tv">privacy@imutv.tv</a> if you become aware of any unauthorized use of your account.</p>
    <h3>3.3 Account Accuracy</h3>
    <p>You agree to provide accurate, current, and complete information during registration and to update such information as necessary. We reserve the right to suspend or terminate accounts with false or incomplete information.</p>
    <h3>3.4 One Account Per Person</h3>
    <p>Each individual may maintain only one (1) User account. Creators may have separate Creator profiles associated with their User account.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s4">
    <h2>4. Description of the Service</h2>
    <p>IMU operates a creator-first, licensed entertainment infrastructure platform. IMU is not a record label, music publisher, talent agency, content studio, or financial institution. Creators retain ownership of their Content while participating in transparent monetization. The Service consists of the following platforms:</p>
    <ul>
        <li><strong>IMUTV:</strong> An interactive, on-demand video streaming and live broadcast platform delivering independent music videos, films, episodic television, animation, short-form content, and live streams through connected-TV applications (Roku, Fire TV, Google TV, Android TV, Apple TV), web, and mobile.</li>
        <li><strong>IMUP3:</strong> An interactive, on-demand audio streaming application providing music streaming, user-created playlists and favorites, and podcast hosting through iOS, Android, and web applications.</li>
        <li><strong>IM Collectibles (IMC):</strong> A digital collectibles and NFT marketplace built on the XRP Ledger (XRPL) where Creators can mint, list, and sell digital art, music collectibles, and other digital assets.</li>
        <li><strong>IMU Merch Store:</strong> An e-commerce platform for the sale of physical merchandise, including IMU-branded products and Creator-designed merchandise fulfilled through IMU's fulfillment services.</li>
    </ul>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s5">
    <h2>5. Monetization, Payments &amp; Subscriptions</h2>
    <h3>5.1 Revenue Model</h3>
    <p>The Service operates a multi-tier monetization model:</p>
    <ul>
        <li><strong>Free with Advertising (AVOD):</strong> Users may access certain Content at no charge, supported by advertising.</li>
        <li><strong>Pay-Per-View (TVOD):</strong> Users may purchase access to specific Content on a per-item basis.</li>
        <li><strong>Creator Subscriptions (SVOD):</strong> Creators may offer subscription-based channels, paywalls, or content access tiers. Subscription payments are processed through the Service and paid to the Creator, less the Platform Fee described in Section 5.2.</li>
        <li><strong>Merchandise Purchases:</strong> Users may purchase physical merchandise through the IMU Merch Store.</li>
        <li><strong>Digital Collectible Purchases:</strong> Users may purchase NFTs and digital collectibles through IM Collectibles.</li>
    </ul>
    <h3>5.2 Creator Subscription Platform Fee</h3>
    <p>For Creator-offered subscriptions, IMU retains a platform fee of <strong>five percent (5%)</strong> of the gross subscription revenue collected. The remaining ninety-five percent (95%) is remitted to the Creator in accordance with the monthly payout schedule described in Section 5.5. This 5% platform fee covers payment processing, platform hosting, infrastructure costs, and content delivery. IMU reserves the right to modify the platform fee percentage with sixty (60) days' prior written notice to affected Creators.</p>
    <h3>5.3 Payment Processing</h3>
    <p>The Service supports the following payment methods:</p>
    <ul>
        <li><strong>Fiat Currency:</strong> Processed through Stripe or other authorized payment processors. All fiat transactions are subject to the payment processor's terms of service.</li>
        <li><strong>XRPL/XRP:</strong> The Service accepts XRP payments for eligible transactions. XRPL transactions are processed through a non-custodial architecture. IMU does not custody, hold, or control your XRP tokens, wallet, or private keys. You acknowledge that blockchain transactions are irreversible and that IMU bears no responsibility for wallet security, lost private keys, or incorrect wallet addresses.</li>
    </ul>
    <p><strong>Dynamic Pricing &amp; XRP Payment Mechanics.</strong> When a User selects XRP as a payment method, the USD-denominated price is converted into an XRP equivalent using a real-time dynamic pricing mechanism. All XRP payment processing, dynamic pricing, exchange rate calculations, exchange rate risk allocation, refund mechanics for XRP-paid transactions, transaction settlement, wallet security responsibilities, and all other cryptocurrency-related payment terms are governed by <strong>Schedule A: XRP, RLUSD &amp; XFT Cryptocurrency Payment Terms</strong>, which is incorporated into these Terms of Service by reference. In the event of a conflict between these Terms and Schedule A regarding cryptocurrency payment matters, Schedule A shall control.</p>
    <h3>5.4 Subscription Billing &amp; Cancellation</h3>
    <p>Subscription billing and cancellation policies vary by payment method.</p>
    <p><strong>(a) General.</strong> Creator subscriptions are billed on the schedule set by the Creator (monthly or annually) in U.S. Dollars. Subscription fees are non-refundable except as required by applicable law. No partial refunds are provided for unused subscription time.</p>
    <p><strong>(b) Stripe (Fiat) Subscriptions.</strong> Subscriptions paid via credit or debit card through Stripe auto-renew at the end of each billing period unless cancelled by the subscriber. You may cancel through your account settings at any time; cancellation takes effect at the end of the current billing period.</p>
    <p><strong>(c) XRPL Subscriptions (XRP, RLUSD, XFT).</strong> Subscriptions paid via XRPL do NOT auto-renew. XRPL transactions are one-time payments granting access for the selected billing period. The subscriber must manually renew before or after expiry to continue access. IMU sends email reminders at seven (7) days and twenty-four (24) hours before XRPL subscription expiry, and a forty-eight (48) hour post-expiry grace period applies. The full recurring payment mechanics for XRPL subscriptions are governed by Schedule A: XRP, RLUSD &amp; XFT Cryptocurrency Payment Terms, Section 10.</p>
    <p><strong>(d) Effect of Creator Plan Changes on Fan Subscriptions.</strong> Active fan subscriptions are not terminated when a Creator changes their plan. Subscribers retain access until their current billing period ends. However, subscriber-only features (such as private chat or private streams) may become publicly accessible if a Creator downgrades to a plan that does not support those features. IMU does not issue refunds to fan subscribers affected by a Creator's plan change. One-time content purchases (TVOD) and NFT-gated content entitlements are not affected by Creator plan changes. The full Creator Plan tier framework, including downgrade cascade effects on Creators and Subscribers, is set forth in Section 17 of these Terms.</p>
    <p><strong>(e) Tipping.</strong> Tips are voluntary, non-refundable, and passed through one hundred percent (100%) to the Creator. Tipping mechanics, including XRPL irreversibility and tax responsibility, are governed by Schedule A, Section 8.</p>
    <h3>5.5 Creator Payout Schedule</h3>
    <p>Creator payouts for subscription revenue (less the 5% platform fee) and pay-per-view revenue are processed instantly. Creator payouts involving XRP, RLUSD, or XFT-paid transactions, including payout currency election, revenue calculation basis, fair market value determination, and payout thresholds, are governed by Schedule A: XRP, RLUSD &amp; XFT Cryptocurrency Payment Terms, Section 7.</p>
    <h3>5.6 Tax Reporting</h3>
    <p>Creators receiving payments through the Service are responsible for all applicable taxes on their earnings. IMU will issue IRS Form 1099 (or equivalent) to Creators earning six hundred dollars ($600) or more in a calendar year, as required by U.S. law. Creators are responsible for providing accurate tax information (W-9 or W-8BEN for non-U.S. Creators) upon request. The tax treatment of XRP, RLUSD, and XFT transactions, including fair market value determination, 1099 reporting methodology for cryptocurrency payments, buyer capital gains implications, and international tax considerations, is governed by Schedule A: XRP, RLUSD &amp; XFT Cryptocurrency Payment Terms, Section 11.</p>
    <h3>5.7 Chargebacks &amp; Disputes</h3>
    <p>If a payment is subject to a chargeback or dispute by a purchaser, IMU reserves the right to deduct the disputed amount from the Creator's pending payout balance. Repeated chargebacks associated with a Creator's content may result in account review or suspension.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s6">
    <h2>6. Acceptable Use Policy</h2>
    <p>You agree not to use the Service to:</p>
    <ul>
        <li>Post, upload, or transmit Content that is illegal, threatening, defamatory, obscene, pornographic, or profane.</li>
        <li>Engage in hate speech, harassment, bullying, or discrimination based on race, ethnicity, national origin, religion, gender, gender identity, sexual orientation, disability, or any other protected characteristic.</li>
        <li>Infringe upon the intellectual property rights of any third party, including unauthorized use of copyrighted music, characters, trademarks, or other protected works.</li>
        <li>Upload viruses, malware, or any code designed to disrupt, damage, or limit the functionality of the Service.</li>
        <li>Attempt to gain unauthorized access to other Users' accounts or to IMU's systems or networks.</li>
        <li>Use the Service for any form of money laundering, fraud, or to conceal criminal proceeds.</li>
        <li>Use the Service to facilitate sex trafficking or any activity prohibited under FOSTA-SESTA (18 U.S.C. § 1591 et seq.).</li>
        <li>Broadcast, upload, or distribute non-consensual intimate imagery (NCII), including AI-generated deepfakes, in violation of the TAKE IT DOWN Act.</li>
        <li>Use automated scripts, bots, or scraping tools to access, collect data from, or interact with the Service without prior written authorization.</li>
        <li>Circumvent or attempt to circumvent any access controls, digital rights management, content gating, or geographic restrictions.</li>
        <li>Impersonate any person or entity, or falsely state or misrepresent your affiliation with any person or entity.</li>
        <li>Use the Service to artificially inflate stream counts, views, followers, or any other metric.</li>
    </ul>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s7">
    <h2>7. Intellectual Property of the Platform</h2>
    <p>All IMU Content, including our software, code, user interface design, branding, logos, trademarks (IMU™, IMUTV™, IMUP3™, IM Collectibles™), text, and graphics is owned by IMU, LLC and is protected by U.S. and international intellectual property laws. You are granted a limited, non-exclusive, non-transferable, revocable license to access and view the Service for personal, non-commercial use. You may not copy, modify, distribute, sell, or create derivative works based on IMU Content without our prior written consent.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s8">
    <h2>8. Copyright Policy (DMCA)</h2>
    <h3>8.1 Commitment to Copyright Compliance</h3>
    <p>IMU respects intellectual property rights and complies fully with the Digital Millennium Copyright Act ("DMCA"), 17 U.S.C. § 512. We maintain a registered DMCA Agent with the U.S. Copyright Office.</p>
    <h3>8.2 DMCA Takedown Notices</h3>
    <p>If you believe your copyrighted work has been infringed on the Service, you may submit a written notification to our designated Copyright Agent containing:</p>
    <ul>
        <li>A physical or electronic signature of the copyright owner or authorized agent.</li>
        <li>Identification of the copyrighted work claimed to have been infringed.</li>
        <li>Identification of the material that is claimed to be infringing, with sufficient information for us to locate it on the Service.</li>
        <li>Your contact information (address, telephone number, and email).</li>
        <li>A statement that you have a good faith belief that the use is not authorized by the copyright owner, its agent, or the law.</li>
        <li>A statement, under penalty of perjury, that the information in the notification is accurate and that you are the copyright owner or authorized to act on the owner's behalf.</li>
    </ul>
    <p><strong>Designated Copyright Agent:</strong> IMUTV Legal Department &mdash; <a href="mailto:dmca@imutv.tv">dmca@imutv.tv</a></p>
    <h3>8.3 Counter-Notifications</h3>
    <p>If you believe your Content was removed in error, you may submit a counter-notification containing: (a) your physical or electronic signature; (b) identification of the removed material and its former location; (c) a statement under penalty of perjury that you believe the removal was a mistake or misidentification; and (d) your consent to the jurisdiction of the federal courts in the State of Florida. We will forward counter-notifications to the complaining party, who has fourteen (14) business days to file a court action before the material is restored.</p>
    <h3>8.4 Repeat Infringer Policy</h3>
    <p>IMU maintains a strict policy of terminating the accounts of Users who are repeat infringers. After three (3) valid DMCA notices against a User's account, the account will be permanently terminated. IMU reserves the right to terminate accounts after fewer than three notices in cases of egregious or willful infringement.</p>
    <h3>8.5 Rights Dispute Procedure</h3>
    <p>When a copyright dispute arises between Creators, the disputed Content will be paused (removed from public access) and any associated revenue will be held in escrow pending resolution. Disputes may be resolved through: (a) mutual agreement between the parties; (b) submission of documentation establishing ownership; or (c) court order.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s9">
    <h2>9. Live Streaming Policy</h2>
    <h3>9.1 General Rules</h3>
    <p>IMUTV provides live streaming capabilities for approved Creators. All live streams must comply with these Terms, the Acceptable Use Policy, and all applicable laws.</p>
    <h3>9.2 Music in Live Streams &mdash; Royalty-Free Requirement</h3>
    <p>Live streamers may NOT play copyrighted third-party music during live streams. All background music, sound effects, and audio elements used in a live stream must be either:</p>
    <ul>
        <li>(a) Royalty-free or Creative Commons-licensed music for which the streamer has obtained a valid license; or</li>
        <li>(b) The streamer's own original musical compositions performed live by the streamer during their own livestream.</li>
    </ul>
    <h3>9.3 Musician Exception</h3>
    <p>Musicians and performing artists who are streaming their own live performance may perform their own original music during their livestream. This exception applies ONLY to original compositions owned by the performing musician. Performing cover songs (compositions written by others) during a livestream requires the musician to have obtained appropriate performance licenses independently.</p>
    <h3>9.4 Liability for Unauthorized Music Use</h3>
    <p>Creators who use unauthorized copyrighted music in a live stream assume all legal liability for any resulting copyright claims. IMU is not liable for copyright infringement claims arising from a Creator's unauthorized use of third-party music during a live stream. Violations may result in immediate stream termination, content removal, and account suspension under the repeat infringer policy.</p>
    <h3>9.5 FTC Endorsement Disclosures</h3>
    <p>Live streamers who receive compensation, sponsorship, or free products in connection with their streams must make clear, conspicuous, and unavoidable disclosures (e.g., "Sponsored," "Paid Partnership," "Ad") in compliance with FTC Endorsement Guides (16 CFR Part 255). Failure to disclose material connections constitutes a violation of these Terms.</p>
    <h3>9.6 Non-Consensual Intimate Imagery (NCII)</h3>
    <p>In compliance with the TAKE IT DOWN Act, any live stream broadcasting non-consensual intimate imagery, including AI-generated deepfakes, will be immediately terminated. Victims may report NCII content to <a href="mailto:legal@imutv.tv">legal@imutv.tv</a> and IMU will remove such content within forty-eight (48) hours of receiving a valid report.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s10">
    <h2>10. Content Moderation</h2>
    <h3>10.1 Approval-Based Content Model</h3>
    <p>IMUTV operates an approval-based content model. All Content is reviewed and approved prior to publication. IMUTV does not allow unrestricted public uploads.</p>
    <h3>10.2 Moderation Methods</h3>
    <p>Content moderation is performed through a combination of: (a) manual review by IMU staff during the Content submission and approval process; and (b) community reporting facilitated by designated Creator Ambassadors who serve as community moderators.</p>
    <h3>10.3 Content Removal</h3>
    <p>IMU reserves the right to remove, disable, or restrict access to any Content at any time, with or without notice, if we determine in our sole discretion that the Content violates these Terms, applicable law, or poses a risk to the Service, other Users, or third parties.</p>
    <h3>10.4 Content Ratings</h3>
    <p>All video Content must be assigned a content rating by the Creator during the submission process, using the TV Parental Guidelines system (TV-Y, TV-G, TV-PG, TV-14, TV-MA) or MPAA equivalent (G, PG, PG-13, R). IMU reserves the right to reclassify Content ratings. Mature-rated content (TV-MA/R) is subject to age-gating restrictions.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<!-- ===================== PART II ===================== -->
<div class="legal-part-header" id="part2">
    <div class="part-label">Part II</div>
    <h2>Content Creator Agreement</h2>
    <p>This Part II applies to all Creators who submit Content to the Service. By submitting Content, you agree to the terms of this Part II in addition to all other provisions of these Terms.</p>
</div>

<div class="legal-section" id="t-s11">
    <h2>11. Grant of Distribution License</h2>
    <h3>11.1 License Grant</h3>
    <p>By submitting Content to the Service, you grant IMU, LLC a non-exclusive, worldwide, sublicensable, and transferable license to:</p>
    <ul>
        <li>Host, store, reproduce, and cache your Content on IMU servers and content delivery networks as necessary to operate the Service.</li>
        <li>Distribute, publicly perform, display, and broadcast your Content across all IMU platforms (including web, Roku, Fire TV, Google TV, Android TV, Apple TV, iOS, Android, and future platforms).</li>
        <li>Create derivative works limited to technical modifications necessary for platform compatibility, including but not limited to: transcoding, format conversion, resolution adjustments, cropping for thumbnails, and audio normalization.</li>
        <li>Use your name, likeness, artistic pseudonym, album art, and biographical information in connection with the promotion and marketing of your Content and the Service, including but not limited to promotional trailers, social media posts, email marketing, and on-platform featured placements.</li>
    </ul>
    <h3>11.2 Purpose of License</h3>
    <p>This license is granted solely for the purpose of operating the Service, distributing your Content to audiences, and marketing both your Content and the Service. This license does not constitute a transfer of copyright or ownership in your Content. You retain all ownership rights in your Content.</p>
    <h3>11.3 Statutory Royalty Obligations</h3>
    <p>IMU shall be responsible for reporting to and remitting all applicable statutory royalties to the following Performing Rights Organizations ASCAP and BMI, The Mechanical Licensing Collective (MLC), based on the actual usage (stream counts, performances) of Content on the Service. IMU does not accept music licensed with Performing Rights Organizations outside of ASCAP and BMI.</p>
    <h3>11.4 Creator Compensation</h3>
    <p>Creators who offer subscriptions or pay-per-view content on the Service receive the applicable revenue less the 5% Platform Fee described in Section 5.2. The terms of this Section 11 grant IMU the operational rights necessary to distribute and promote your Content; they do not waive or replace any compensation to which you may be entitled under these Terms or applicable law.</p>
    <h3>11.5 Revocability</h3>
    <p>This license is revocable. You may request removal of your Content at any time by submitting a written takedown request to <a href="mailto:legal@imutv.tv">legal@imutv.tv</a>. IMU will use commercially reasonable efforts to remove your Content from all platforms within thirty (30) calendar days of receiving a valid takedown request. You acknowledge that: (a) cached copies may persist on content delivery networks for a reasonable period after removal; (b) Content that has already been incorporated into NFTs or purchased downloads cannot be recalled from end users; and (c) IMU retains the right to maintain internal copies for legal compliance, reporting, and dispute resolution purposes.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s12">
    <h2>12. Consent to Technical Modifications</h2>
    <p>You consent to IMU making reasonable technical modifications to your Content as necessary for display, distribution, and playback across different platforms, devices, and screen sizes. Such modifications may include, but are not limited to: cropping, resizing, aspect ratio adjustments, format conversion, transcoding, compression, audio normalization, thumbnail generation, and the addition of platform-required overlays (such as content ratings or closed captioning indicators). These modifications are solely for operational and technical purposes and do not constitute a claim of authorship or an alteration of your creative work.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s13">
    <h2>13. Creator Representations and Warranties</h2>
    <p>By submitting Content to the Service, you represent and warrant that:</p>
    <h3>13.1 Ownership</h3>
    <p>You are the sole owner of the Content, including all copyrights, or you have obtained all necessary rights, licenses, consents, and permissions to grant the license in Section 11.</p>
    <h3>13.2 Music in Video (Sync Rights)</h3>
    <p>If you are submitting audiovisual Content (films, series, music videos, short-form video, animation) that contains music, you warrant that you have secured all necessary Synchronization Licenses and Master Use Licenses for every piece of music contained within your video. IMU's blanket public performance licenses with PROs do NOT cover the right to synchronize music with visual content — that right must be obtained by you, the Content creator, directly from the songwriter/publisher (sync) and recording owner (master).</p>
    <h3>13.3 Cue Sheets</h3>
    <p>For all long-form audiovisual Content (films, series, and any video exceeding ten (10) minutes in length), you must submit a completed Cue Sheet listing every piece of music used, including: title, composer(s), publisher(s), PRO affiliation, duration, and type of use (background, feature, theme). IMU will forward Cue Sheets to the applicable PROs to ensure proper royalty distribution.</p>
    <h3>13.4 Talent Releases</h3>
    <p>You have obtained written releases from all identifiable persons appearing in your Content, authorizing their appearance and the use of their name and likeness.</p>
    <h3>13.5 No Infringement</h3>
    <p>Your Content does not infringe upon the copyright, trademark, patent, trade secret, right of publicity, right of privacy, or any other proprietary right of any third party.</p>
    <h3>13.6 Accuracy</h3>
    <p>All metadata, credits, and information submitted with your Content is accurate and complete.</p>
    <h3>13.7 Compliance with Law</h3>
    <p>Your Content complies with all applicable laws, regulations, and industry standards.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s14">
    <h2>14. Errors &amp; Omissions Insurance (Long-Form Content)</h2>
    <p>Creators submitting long-form audiovisual Content (feature-length films, episodic series of three or more episodes, and documentary films) are strongly encouraged and may be required to maintain Producer's Errors and Omissions (E&amp;O) liability insurance. If E&amp;O insurance is required, IMU must be named as an "Additional Insured" on the policy. Minimum coverage requirements will be communicated during the Content approval process.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s15">
    <h2>15. Special Provisions for Anime &amp; Fan Content</h2>
    <h3>15.1 Derivative Works</h3>
    <p>You acknowledge that fan art, fan fiction, or any creative work based on existing copyrighted properties may constitute a "derivative work" under copyright law. You represent and warrant that your fan Content either: (a) qualifies as fair use under 17 U.S.C. § 107; or (b) you have obtained written permission from the original rights holder.</p>
    <h3>15.2 Indemnification for Fan Content</h3>
    <p>You agree to indemnify, defend, and hold harmless IMU from any claims arising from a third-party rights holder's assertion that your fan Content infringes their intellectual property rights.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s16">
    <h2>16. AI-Generated Content Policy</h2>
    <h3>16.1 Disclosure Requirement</h3>
    <p>You must disclose if your Content was generated by, or significantly assisted by, artificial intelligence tools during the Content submission process. IMU, LLC reserves the right to remove or reject any and all AI content submitted that was created with an AI model that was not licensed at the time of AI model training.</p>
    <h3>16.2 Copyrightability</h3>
    <p>You acknowledge that purely AI-generated output may not be copyrightable under current U.S. Copyright Office guidance. You represent that any Content submitted contains sufficient original human authorship to qualify for copyright protection.</p>
    <h3>16.3 AI-Generated Likenesses</h3>
    <p>Content featuring AI-generated likenesses of real persons (including voice cloning and deepfakes) is prohibited unless the Creator has obtained verifiable written consent from the depicted individual.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s17">
    <h2>17. Creator Plans</h2>
    <h3>17.1 Plan Tiers</h3>
    <p>IMU offers four (4) Creator Plan tiers: Hobby (free), Beginner ($60/year or $6/month), Streamer ($100/year or $10/month), and Devoted (custom pricing set by IMU administration). Each tier provides a different combination of on-demand storage, monthly live streaming hours, monetization features (XRPL payment rails, NFT content gating, streaming royalty eligibility), subscriber-only features (private streams, private chat), and support level. Hobby Creators are limited to traditional Stripe-based payments, public content, and tipping; XRPL payment rails, NFT gating, and XFT streaming royalties are available only to Beginner, Streamer, and Devoted Creators. Tipping is available across all plans. Creator Plan subscriptions are billed monthly or annually in U.S. Dollars via Stripe. All Plan fees are non-refundable; no partial refunds are issued for unused subscription time. Stripe-billed Plans auto-renew at the end of each billing period unless cancelled. If a renewal payment fails after Stripe's automatic retry process, the Plan is automatically downgraded to Hobby and the cascade effects in Section 17.2 apply. Devoted Plans are individually negotiated and may include additional written terms. The complete Creator Plan tier framework, including the full feature matrix and pricing, is incorporated into these Terms by reference and is published on the Service's pricing page.</p>
    <h3>17.2 Plan Transition Effects</h3>
    <p>When a Creator changes their Plan tier (whether by voluntary upgrade or downgrade, or by automatic downgrade due to payment failure), the following cascade effects apply:</p>
    <p><strong>(a) Downgrade to Hobby.</strong> XRPL payment rails are disabled and all paid content reverts to Stripe-only payment; NFT content gating is disabled on all Creator content; the Creator's public profile is hidden from public listings; XRPL tipping is disabled and tips revert to Stripe only; subscriber-only stream and chat locks are removed, making streams and chat publicly accessible; and XFT streaming royalty accrual ceases (already-accrued unpaid XFT remains payable per the XFT Streaming Rewards Program Terms).</p>
    <p><strong>(b) Downgrade from Streamer to Beginner.</strong> Private chat (subscriber-only chat rooms) is disabled and the Creator's chat room becomes publicly accessible; NFT content gating is disabled on all content (a Streamer-and-above feature only); all other Beginner-tier features remain active.</p>
    <p><strong>(c) Upgrades.</strong> Features unlocked by the new plan tier become available immediately; however, XRPL payment rails, NFT content gating, and other advanced features are NOT auto-enabled and Creators must opt in via their Dashboard settings to prevent unintended exposure of payment configurations; Creator profile visibility is automatically set to public when upgrading from Hobby.</p>
    <p><strong>(d) Effect on Active Fan Subscriptions.</strong> Active fan subscriptions are NOT terminated when a Creator changes their Plan; subscribers retain access until their current billing period ends. However, subscriber-only features may become publicly accessible if a Creator downgrades, and IMU does not issue refunds to fan subscribers affected by a Creator's Plan change. One-time content purchases (TVOD) and NFT-gated content entitlements are not affected by Creator Plan changes.</p>
    <h3>17.3 Storage Add-Ons and Plan Modifications</h3>
    <p><strong>(a) Storage Add-Ons.</strong> Creators may purchase additional storage capacity as yearly add-ons in the following increments: 50 GB ($20/year), 100 GB ($35/year), or 250 GB ($75/year). Storage add-ons are non-refundable, non-auto-renewing one-time payments for a twelve (12) month period. IMU sends an email notification approximately thirty (30) days before a storage add-on approaches expiry. When a storage add-on expires, the corresponding capacity is automatically removed from the Creator's account; if total used storage exceeds the remaining limit, the Creator will be unable to upload new content until storage is freed or a new add-on is purchased (existing hosted content is not deleted). Storage add-ons are portable across plan upgrades and downgrades and stack on top of base allocations. Multiple add-ons may be purchased and stacked, each with its own independent twelve-month expiry.</p>
    <p><strong>(b) Subscriber Tier System.</strong> Creators on Beginner, Streamer, and Devoted Plans may offer multiple subscription tiers (each, a "Subscriber Tier") to their fans, with differentiated pricing and access levels. Each Subscriber Tier may unlock different combinations of subscriber-only content, private streams, private chat (Streamer+), and other Creator-defined perks. Subscribers retain access only to the content and features associated with the specific Subscriber Tier they purchased. If a Creator modifies or removes a Subscriber Tier, existing subscribers retain their original tier benefits until the end of their current billing period.</p>
    <p><strong>(c) Plan Modifications by IMU.</strong> IMU reserves the right to modify Creator Plan pricing, features, storage limits, streaming allocations, and other Plan attributes with thirty (30) days' prior written notice. Modifications apply to renewals occurring after the effective date of the change; existing paid Creators are not retroactively charged additional amounts during their current billing period.</p>
    <p><strong>(d) Cancellation.</strong> Creators may cancel their Plan at any time through their Dashboard. Cancellation takes effect at the end of the current billing period. No partial refunds are issued for unused subscription time.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<!-- ===================== PART III ===================== -->
<div class="legal-part-header" id="part3">
    <div class="part-label">Part III</div>
    <h2>IMUP3 Audio Streaming Terms</h2>
    <p>This Part III contains additional terms specific to the IMUP3 audio streaming application.</p>
</div>

<div class="legal-section" id="t-s18">
    <h2>18. IMUP3 Service Description</h2>
    <p>IMUP3 is an interactive, on-demand audio streaming service. Users can search for, select, and play specific songs, albums, and podcasts. Users can create personal playlists, save favorites, and build libraries within their accounts. IMUP3 is classified as an interactive service under U.S. copyright law (17 U.S.C. § 114(j)(7)).</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s19">
    <h2>19. Podcast Terms</h2>
    <h3>19.1 Podcast Submissions</h3>
    <p>Creators may submit podcasts for distribution through IMUP3. All podcasts are subject to the approval-based content model and must comply with these Terms.</p>
    <h3>19.2 Podcast Music Policy</h3>
    <p>Podcast creators are solely responsible for ensuring all music, sound effects, jingles, and audio content used in their podcasts is either: (a) original content owned by the podcast creator; (b) properly licensed from the rights holder; or (c) royalty-free or Creative Commons-licensed content. IMU's blanket performance licenses with PROs and The MLC do NOT extend to music embedded in podcast episodes by creators. Podcasts containing unlicensed third-party music will be removed, and repeated violations will result in account termination.</p>
    <h3>19.3 Podcast Content Standards</h3>
    <p>Podcasts must comply with the Acceptable Use Policy. Podcasts containing explicit language or mature themes must be marked as explicit during submission.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s20">
    <h2>20. Audio Streaming User Rights</h2>
    <p>Your use of IMUP3 is limited to personal, non-commercial streaming. You may not: (a) record, download, or copy streams except through features expressly provided by the Service; (b) redistribute, broadcast, or publicly perform content streamed from IMUP3; (c) use IMUP3 content in any commercial establishment without a separate public performance license; or (d) circumvent any stream quality limitations, geographic restrictions, or access controls.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<!-- ===================== PART IV ===================== -->
<div class="legal-part-header" id="part4">
    <div class="part-label">Part IV</div>
    <h2>IM Collectibles (NFT Marketplace) Terms</h2>
    <p>This Part IV contains additional terms specific to the IM Collectibles digital collectibles marketplace at www.imcollectibles.io. With Terms of Sale attached as Schedule &quot;B&quot; to this document.</p>
</div>

<div class="legal-section" id="t-s21">
    <h2>21. IM Collectibles Service Description</h2>
    <p>IM Collectibles is a digital collectibles marketplace built on the XRP Ledger (XRPL). Creators can mint, list, and sell non-fungible tokens (NFTs) representing digital art, music collectibles, and other digital assets. Purchasers can buy, collect, display, and resell Digital Collectibles on the marketplace.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s22">
    <h2>22. What You Purchase</h2>
    <h3>22.1 Consumer Collectible</h3>
    <p>When you purchase a Digital Collectible, you are purchasing a blockchain-verified token linked to associated digital content. Digital Collectibles are purchased for consumer enjoyment, use, and personal collection. Digital Collectibles are expressly NOT securities, investment contracts, or financial products as defined under U.S. federal or state securities laws.</p>
    <h3>22.2 No Investment Expectation</h3>
    <p>IMU makes no representations or guarantees regarding the future value, market demand, or resale potential of any Digital Collectible. You acknowledge that Digital Collectibles may lose all value. You should not purchase Digital Collectibles with any expectation of profit or investment return.</p>
    <h3>22.3 Limited License to Buyer</h3>
    <p>Unless a separate license agreement is provided by the Creator at the time of sale, purchasing a Digital Collectible grants you ONLY a non-exclusive, non-sublicensable, royalty-free license to use, copy, and display the associated digital content for personal, non-commercial use. Owning a Digital Collectible does NOT grant you:</p>
    <ul>
        <li>Copyright ownership in the underlying artwork, music, or content.</li>
        <li>The right to commercialize, reproduce for sale, or create derivative works.</li>
        <li>Trademark rights or any right to use the Creator's brand, name, or likeness.</li>
        <li>Any ownership interest, profit share, or governance rights in IMU or any Creator's business.</li>
    </ul>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s23">
    <h2>23. Creator Warranties for Digital Collectibles</h2>
    <p>Creators minting Digital Collectibles represent and warrant that: (a) they own the copyright or have all necessary rights to the digital content linked to the NFT; (b) the minting and sale does not infringe any third-party IP rights; (c) any AI-generated elements are disclosed; and (d) the content complies with the Acceptable Use Policy.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s24">
    <h2>24. XRPL &amp; Blockchain Terms</h2>
    <h3>24.1 Blockchain Irreversibility</h3>
    <p>Transactions on the XRP Ledger are irreversible once confirmed. IMU cannot reverse, cancel, or modify any on-chain transaction.</p>
    <h3>24.2 Non-Custodial</h3>
    <p>IMU does not custody your XRP tokens, digital assets, or private keys. IMU is not a digital asset exchange, broker, wallet provider, or custodian.</p>
    <h3>24.3 Network Fees</h3>
    <p>Blockchain transactions may require network fees determined by the XRPL network, not set or collected by IMU.</p>
    <h3>24.4 Creator Royalties on Secondary Sales</h3>
    <p>Royalty payments may include automatic royalty mechanisms paying the original Creator a percentage of secondary sales. These royalties are enforced at the marketplace level and may not be enforceable on external marketplaces.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s25">
    <h2>25. AML/KYC &amp; OFAC Compliance</h2>
    <h3>25.1 Anti-Money Laundering</h3>
    <p>You agree not to use IM Collectibles to launder money, finance terrorism, or conceal criminal proceeds. IMU implements creator identity verification, transaction logging, and anomaly monitoring.</p>
    <h3>25.2 Know Your Customer</h3>
    <p>IMU reserves the right to require identity verification for Creators and for purchasers conducting high-value transactions.</p>
    <h3>25.3 OFAC Sanctions</h3>
    <p>You represent that you are not located in any OFAC-sanctioned country or listed on any U.S. government sanctions list. IMU may implement geographic restrictions to enforce sanctions compliance.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s26">
    <h2>26. Refund Policy for Digital Collectibles</h2>
    <p>Due to the nature of blockchain technology, all Digital Collectible sales are final. No refunds, exchanges, or cancellations are available once a transaction is confirmed on the XRPL.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<!-- ===================== PART V ===================== -->
<div class="legal-part-header" id="part5">
    <div class="part-label">Part V</div>
    <h2>IMU Merch Store Terms</h2>
</div>

<div class="legal-section" id="t-s27">
    <h2>27. Merch Store Description</h2>
    <p>The IMU Merch Store sells physical merchandise, including IMU-branded products and merchandise designed by Creators that is manufactured and fulfilled by IMU or its fulfillment partners.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s28">
    <h2>28. Purchases &amp; Payments</h2>
    <p>All merchandise purchases are processed through authorized payment processors. Prices are in U.S. dollars unless otherwise indicated. Sales tax will be calculated and collected as required by applicable state and local law.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s29">
    <h2>29. Shipping, Returns &amp; Refunds</h2>
    <h3>29.1 Shipping</h3>
    <p>Shipping timelines and costs are displayed at checkout. IMU is not liable for delays caused by carriers, customs, or force majeure events.</p>
    <h3>29.2 Returns</h3>
    <p>Merchandise may be returned within thirty (30) days of delivery if defective, damaged, or materially different from the listing. Custom or personalized merchandise is non-returnable unless defective.</p>
    <h3>29.3 Refunds</h3>
    <p>Approved refunds will be processed to the original payment method within ten (10) business days of receiving the returned item.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s30">
    <h2>30. Creator Merch Fulfillment Services</h2>
    <p>Creators may utilize IMU's fulfillment services for merchandise featuring their own designs, governed by a separate Creator Fulfillment Agreement. Creators warrant ownership of all design IP and agree to indemnify, defend, and hold harmless IMU from any claims arising from intellectual property infringement related to their designs.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<!-- ===================== PART VI ===================== -->
<div class="legal-part-header" id="part6">
    <div class="part-label">Part VI</div>
    <h2>General Legal Provisions</h2>
</div>

<div class="legal-section" id="t-s31">
    <h2>31. Indemnification</h2>
    <p>You agree to indemnify, defend, and hold harmless IMU, LLC, its officers, directors, employees, agents, and affiliates from any claims, damages, losses, liabilities, costs, and expenses (including reasonable attorneys' fees) arising from: (a) your use of the Service; (b) your Content; (c) your violation of these Terms; (d) your violation of any third-party right; or (e) any dispute between you and another User or third party.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s32">
    <h2>32. Limitation of Liability</h2>
    <p class="legal-caps">TO THE FULLEST EXTENT PERMITTED BY LAW, IMU, LLC SHALL NOT BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, EXEMPLARY, OR PUNITIVE DAMAGES ARISING OUT OF YOUR USE OF THE SERVICE, INCLUDING LOSS OF REVENUE, PROFITS, DATA, OR BUSINESS OPPORTUNITY, REGARDLESS OF THE THEORY OF LIABILITY AND EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGES.</p>
    <p class="legal-caps">IMU'S TOTAL AGGREGATE LIABILITY SHALL NOT EXCEED THE GREATER OF: (A) THE AMOUNT YOU PAID TO IMU IN THE TWELVE (12) MONTHS PRECEDING THE CLAIM; OR (B) ONE HUNDRED DOLLARS ($100).</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s33">
    <h2>33. Disclaimers</h2>
    <p class="legal-caps">THE SERVICE IS PROVIDED "AS IS" AND "AS AVAILABLE" WITHOUT WARRANTIES OF ANY KIND, EITHER EXPRESS OR IMPLIED, INCLUDING IMPLIED WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, TITLE, AND NON-INFRINGEMENT. IMU DOES NOT WARRANT THAT THE SERVICE WILL BE UNINTERRUPTED, ERROR-FREE, OR SECURE.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s34">
    <h2>34. Dispute Resolution &amp; Arbitration</h2>
    <h3>34.1 Informal Resolution</h3>
    <p>Before initiating formal dispute resolution, you agree to contact us at <a href="mailto:legal@imutv.tv">legal@imutv.tv</a> and attempt to resolve the dispute informally for at least thirty (30) days.</p>
    <h3>34.2 Binding Arbitration</h3>
    <p>Any dispute that cannot be resolved informally shall be resolved by binding arbitration administered by the American Arbitration Association ("AAA") in accordance with its Consumer Arbitration Rules, conducted in the State of Florida. The arbitrator's decision shall be final and binding.</p>
    <h3>34.3 Class Action Waiver</h3>
    <p class="legal-caps">YOU AND IMU AGREE THAT EACH PARTY MAY BRING CLAIMS ONLY IN INDIVIDUAL CAPACITY AND NOT AS A PLAINTIFF OR CLASS MEMBER IN ANY CLASS, CONSOLIDATED, OR REPRESENTATIVE ACTION.</p>
    <h3>34.4 Exceptions</h3>
    <p>Either party may seek injunctive relief in any court of competent jurisdiction to prevent infringement or violation of intellectual property rights.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s35">
    <h2>35. Governing Law</h2>
    <p>These Terms shall be governed by the laws of the State of Florida, without regard to conflict of laws provisions. Legal actions not subject to arbitration shall be brought exclusively in the state or federal courts in Florida.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s36">
    <h2>36. Termination</h2>
    <h3>36.1 By You</h3>
    <p>You may terminate your account at any time by contacting <a href="mailto:legal@imutv.tv">legal@imutv.tv</a>.</p>
    <h3>36.2 By IMU</h3>
    <p>IMU may suspend or terminate your account at any time for violation of Terms, fraudulent activity, or legal requirements, with reasonable notice where practicable.</p>
    <h3>36.3 Effect</h3>
    <p>Upon termination: access ceases; outstanding payment obligations survive; Content removal per Section 11.5; and surviving provisions (indemnification, liability, dispute resolution) continue.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s37">
    <h2>37. Modifications to Terms</h2>
    <p>IMU may modify these Terms at any time with at least thirty (30) days' notice for material changes. Continued use after the effective date constitutes acceptance.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s38">
    <h2>38. App Store Terms</h2>
    <h3>38.1 Apple</h3>
    <p>These Terms are between you and IMU, not Apple. Apple has no obligation for maintenance, support, or warranty for the Service.</p>
    <h3>38.2 Google Play</h3>
    <p>Use on Google Play devices is also subject to Google Play Terms of Service.</p>
    <h3>38.3 Roku</h3>
    <p>Use on Roku devices is subject to the Roku End User License Agreement.</p>
    <h3>38.4 Amazon Fire TV</h3>
    <p>Use on Amazon Fire TV devices is subject to Amazon's applicable terms.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s39">
    <h2>39. Miscellaneous</h2>
    <h3>39.1 Entire Agreement</h3>
    <p>These Terms, together with the Privacy Policy, Content Submission Guidelines, Community Guidelines, Schedule A: XRP, RLUSD &amp; XFT Cryptocurrency Payment Terms, the XFT Streaming Rewards Program Terms, the Creator Plan Tiers (Section 17 of these Terms), the IM Collectibles Terms of Sale (for Marketplace participants), and the IM Collectibles Feature Addendum (for participants in Burn 2 Earn, Frequency Fountain, Tasks &amp; Rewards, Redeem, Airdrops, or Champion of Frequencies), constitute the entire agreement between you and IMU with respect to the Service and supersede all prior agreements, understandings, and representations relating thereto.</p>
    <h3>39.2 Severability</h3>
    <p>If any provision is held invalid, the remaining provisions continue in full force.</p>
    <h3>39.3 Waiver</h3>
    <p>Failure to enforce any provision does not constitute a waiver.</p>
    <h3>39.4 Assignment</h3>
    <p>You may not assign your rights without IMU's consent. IMU may assign in connection with a merger, acquisition, or asset sale.</p>
    <h3>39.5 Force Majeure</h3>
    <p>IMU is not liable for delays caused by events beyond reasonable control.</p>
    <h3>39.6 Notices</h3>
    <p>Notices to IMU: <a href="mailto:legal@imutv.tv">legal@imutv.tv</a> or IMU, LLC, 4651 Babcock St., NE, STE 18 Box 112, Palm Bay, FL 32905. Notices to you: email on file.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="t-s40">
    <h2>40. Contact Information</h2>
    <div class="legal-contact-box">
        <h3>IMU, LLC</h3>
        <p><strong>Email:</strong> <a href="mailto:legal@imutv.tv">legal@imutv.tv</a></p>
        <p><strong>Website:</strong> <a href="https://www.imutv.tv" target="_blank" rel="noopener">www.imutv.tv</a></p>
        <p><strong>Website:</strong> <a href="https://www.imcollectibles.io" target="_blank" rel="noopener">www.imcollectibles.io</a></p>
        <p><strong>Mailing Address:</strong> 4651 Babcock St., NE, STE 18 Box 112, Palm Bay, FL 32905</p>
    </div>
    <div class="legal-footer-note" style="margin-top:1.5rem;">Last Updated: May 22, 2026 &nbsp;&middot;&nbsp; <a href="#toc">&#8593; Back to Table of Contents</a></div>
</div>

<!-- ===================== SCHEDULE A ===================== -->
<div class="legal-exhibit-header" id="schedule-a">
    <div class="exhibit-label">Schedule &quot;A&quot; &mdash; Attached to IMU Terms of Service</div>
    <h2>XRP, RLUSD &amp; XFT Cryptocurrency Payment Terms</h2>
    <p>Governing all cryptocurrency and digital asset payment transactions across all IMU platforms &mdash; Effective Date: February 22, 2026</p>
</div>

<div class="legal-notice">THIS SCHEDULE SUPPLEMENTS AND IS INCORPORATED INTO THE IMU, LLC TERMS OF SERVICE. IN THE EVENT OF A CONFLICT BETWEEN THIS SCHEDULE AND THE TERMS OF SERVICE REGARDING CRYPTOCURRENCY PAYMENT MATTERS, THIS SCHEDULE SHALL CONTROL.</div>
<br>
<div class="legal-notice">BY MAKING OR RECEIVING ANY CRYPTOCURRENCY PAYMENT THROUGH THE SERVICE, YOU AGREE TO BE BOUND BY THIS SCHEDULE.</div>
<br>

<div class="legal-section" id="sa-s1">
    <h2>SA1. Scope and Relationship to Terms of Service</h2>
    <p>This Schedule A ("Schedule") supplements and is incorporated into the IMU, LLC Terms of Service ("Terms of Service" or "TOS"). This Schedule governs all cryptocurrency and digital asset payment transactions across all IMU platforms, including IMUTV, IMUP3, IM Collectibles, and IMU Merch Store (collectively, the "Service"). In the event of a conflict between this Schedule and the Terms of Service regarding cryptocurrency payment matters, this Schedule shall control.</p>
    <p>Capitalized terms used but not defined in this Schedule have the meanings assigned to them in the Terms of Service.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sa-s2">
    <h2>SA2. Definitions</h2>
    <ul>
        <li><strong>"Accepted Cryptocurrencies"</strong> means XRP, RLUSD, and XFT, as described in Section SA3.</li>
        <li><strong>"Dynamic Pricing"</strong> means the real-time conversion of a USD-denominated price into a cryptocurrency equivalent at the time of transaction.</li>
        <li><strong>"Exchange Rate Oracle"</strong> means the third-party pricing feed or aggregator used by IMU to determine the current exchange rate between USD and an Accepted Cryptocurrency.</li>
        <li><strong>"Fair Market Value" or "FMV"</strong> means the USD-equivalent value of a cryptocurrency at a specific point in time, as determined by the Exchange Rate Oracle.</li>
        <li><strong>"Price Quote"</strong> means the specific cryptocurrency amount displayed to the User at checkout, calculated via Dynamic Pricing and valid for the Quote Validity Window.</li>
        <li><strong>"Quote Validity Window"</strong> means the period during which a Price Quote remains valid, as specified in Section SA4.2.</li>
        <li><strong>"RLUSD"</strong> means the USD-pegged stablecoin issued by Ripple Labs on the XRP Ledger, designed to maintain a 1:1 parity with the U.S. Dollar.</li>
        <li><strong>"XFT"</strong> means the utility token issued by IMU, LLC on the XRP Ledger, used as a platform engagement reward and accepted as a payment method on the Service.</li>
        <li><strong>"XRP"</strong> means the native digital asset of the XRP Ledger.</li>
        <li><strong>"XRPL"</strong> means the XRP Ledger, a decentralized, open-source blockchain.</li>
    </ul>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sa-s3">
    <h2>SA3. Accepted Cryptocurrencies</h2>
    <p>The Service accepts the following cryptocurrencies for eligible transactions:</p>
    <div class="legal-table-wrap">
        <table class="legal-table">
            <thead><tr><th>Currency</th><th>Type</th><th>Issuer</th><th>Characteristics</th></tr></thead>
            <tbody>
                <tr><td><strong>XRP</strong></td><td>Native XRPL asset</td><td>N/A (native)</td><td>Volatile; subject to Dynamic Pricing; exchange rate fluctuates</td></tr>
                <tr><td><strong>RLUSD</strong></td><td>USD-pegged stablecoin</td><td>Ripple Labs</td><td>Designed to maintain 1:1 USD parity; treated as USD-equivalent for pricing purposes</td></tr>
                <tr><td><strong>XFT</strong></td><td>Utility token (IOU)</td><td>IMU, LLC</td><td>Platform engagement reward; value set by IMU for payment purposes; NOT a security</td></tr>
            </tbody>
        </table>
    </div>
    <h3>SA3.1 Not All Payment Methods Available on All Plans</h3>
    <p>XRPL payment rails (XRP, RLUSD, XFT) are available only to Users transacting with Creators on Beginner, Streamer, or Devoted plans. Hobby-plan Creators may accept fiat (Stripe) payments and 1 XRPL payment option. Tipping via XRPL is available across all plans.</p>
    <h3>SA3.2 IMU Reserves the Right to Add or Remove Accepted Cryptocurrencies</h3>
    <p>IMU reserves the right to add or remove Accepted Cryptocurrencies at any time with thirty (30) days' prior written notice.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sa-s4">
    <h2>SA4. XRP Payment Mechanics</h2>
    <h3>SA4.1 Dynamic Pricing</h3>
    <p>When a User selects XRP as a payment method, the Service converts the USD-denominated price into an XRP equivalent using a real-time Dynamic Pricing mechanism. The Exchange Rate Oracle sources the current XRP/USD rate from one or more reputable cryptocurrency pricing aggregators. The resulting Price Quote reflects the USD price, the XRP/USD exchange rate, and the calculated XRP amount.</p>
    <h3>SA4.2 Quote Validity Window</h3>
    <p>Each Price Quote is valid for a limited period displayed at checkout (the "Quote Validity Window"), typically between sixty (60) and one hundred twenty (120) seconds. If the transaction is not completed within the Quote Validity Window, the Price Quote expires and a new quote must be generated at the then-current exchange rate. IMU is not liable for price changes between an expired quote and a newly generated quote.</p>
    <h3>SA4.3 Settlement</h3>
    <p>Upon successful completion of an XRP payment, the XRPL transaction is settled on-chain. The transaction is confirmed when it is included in a validated XRPL ledger. IMU records the XRPL transaction hash, the USD amount, the XRP amount, and the exchange rate at the time of settlement for accounting and tax reporting purposes.</p>
    <h3>SA4.4 Exchange Rate Risk</h3>
    <p class="legal-caps">YOU ACKNOWLEDGE AND ACCEPT THAT THE VALUE OF XRP IS VOLATILE AND MAY FLUCTUATE SIGNIFICANTLY. ONCE A TRANSACTION IS SETTLED ON THE XRPL, THE EXCHANGE RATE RISK PASSES ENTIRELY TO THE RECIPIENT. IMU IS NOT RESPONSIBLE FOR ANY GAIN OR LOSS IN VALUE BETWEEN THE TIME OF PAYMENT AND ANY SUBSEQUENT CONVERSION TO FIAT CURRENCY.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sa-s5">
    <h2>SA5. RLUSD Payment Mechanics</h2>
    <h3>SA5.1 USD-Equivalent Pricing</h3>
    <p>RLUSD is designed to maintain a 1:1 parity with the U.S. Dollar. When a User selects RLUSD as a payment method, the RLUSD amount equals the USD-denominated price (e.g., a $10.00 purchase requires 10.00 RLUSD). No Dynamic Pricing conversion is applied to RLUSD transactions.</p>
    <h3>SA5.2 Peg Risk Acknowledgment</h3>
    <p>While RLUSD is designed to maintain a 1:1 USD peg, IMU does not guarantee this peg. In the event that RLUSD deviates materially from its USD peg (defined as a deviation exceeding 2% from $1.00 USD), IMU reserves the right to: (a) temporarily suspend acceptance of RLUSD payments; (b) apply Dynamic Pricing to RLUSD transactions until the peg is restored; or (c) permanently remove RLUSD as an Accepted Cryptocurrency.</p>
    <h3>SA5.3 Settlement</h3>
    <p>RLUSD transactions are settled on-chain on the XRPL in the same manner as XRP transactions. The transaction hash, USD amount, and RLUSD amount are recorded for compliance purposes.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sa-s6">
    <h2>SA6. XFT Payment Mechanics</h2>
    <h3>SA6.1 XFT as Payment Method</h3>
    <p>XFT may be used to pay for eligible transactions on the Service where XRPL payment rails are available. The XFT value for payment purposes is determined by IMU at its sole discretion based on the then-current platform valuation of XFT.</p>
    <h3>SA6.2 XFT Valuation</h3>
    <p>IMU sets the XFT-to-USD conversion rate used for payment transactions. This rate is displayed at checkout and may differ from any rate available on third-party exchanges or decentralized trading platforms. IMU makes no representation that its internal XFT valuation reflects any external market value.</p>
    <h3>SA6.3 XFT Is Not a Security</h3>
    <p class="legal-caps">XFT IS A UTILITY TOKEN DISTRIBUTED AS A PLATFORM ENGAGEMENT REWARD. XFT IS NOT A SECURITY, INVESTMENT CONTRACT, COMMODITY, SWAP, OR FINANCIAL INSTRUMENT AS DEFINED UNDER U.S. FEDERAL OR STATE SECURITIES LAWS, INCLUDING THE SECURITIES ACT OF 1933, THE SECURITIES EXCHANGE ACT OF 1934, OR THE INVESTMENT COMPANY ACT OF 1940. IMU DOES NOT OFFER, SOLICIT, OR PROMOTE XFT AS AN INVESTMENT. YOU SHOULD NOT ACQUIRE, HOLD, OR USE XFT WITH ANY EXPECTATION OF PROFIT, INVESTMENT RETURN, CAPITAL APPRECIATION, OR INCOME GENERATION.</p>
    <h3>SA6.4 No Guaranteed Value</h3>
    <p>IMU makes no representations or guarantees regarding the monetary value, market demand, exchange rate, liquidity, or future utility of XFT. XFT may have zero monetary value. The value of XFT is entirely at IMU's discretion for platform payment purposes and is inherently speculative in any external context.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sa-s7">
    <h2>SA7. Creator Payouts</h2>
    <h3>SA7.1 Payout Currency Election</h3>
    <p>Creators receiving revenue from cryptocurrency-paid transactions may elect to receive payouts in: (a) USD, deposited via their connected Stripe account; or (b) the original cryptocurrency (XRP, RLUSD, or XFT), deposited to their connected XRPL wallet address. The payout currency election is made in the Creator's Dashboard settings and may be changed at any time. Changes apply to future payouts only.</p>
    <h3>SA7.2 Revenue Calculation Basis</h3>
    <p>For XRP-paid transactions, Creator revenue is calculated based on the USD value at the time of the original transaction (using the exchange rate locked at settlement), less the applicable Platform Fee. For RLUSD-paid transactions, the USD value equals the RLUSD amount. For XFT-paid transactions, the USD value is based on IMU's internal XFT valuation rate at the time of settlement.</p>
    <h3>SA7.3 Payout Frequency</h3>
    <p>Creator payouts for subscription revenue (less the 5% Platform Fee per Section 5.2 of the TOS) and pay-per-view revenue are processed in accordance with the payout schedule described in TOS Section 5.5. Cryptocurrency payouts are processed via XRPL transactions to the Creator's connected wallet address.</p>
    <h3>SA7.4 Payout Threshold</h3>
    <p>Payouts are subject to a minimum payout threshold of one U.S. dollar ($1.00) or the cryptocurrency equivalent. Balances below the threshold accumulate until the threshold is met.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sa-s8">
    <h2>SA8. Tipping</h2>
    <h3>SA8.1 Tip Pass-Through</h3>
    <p>Tips sent to Creators via XRPL payment rails (XRP, RLUSD, or XFT) are passed through one hundred percent (100%) to the Creator. IMU does not retain any percentage, commission, or fee on tips. Creators are solely responsible for any fees charged by their connected Stripe account for fiat-denominated tips.</p>
    <h3>SA8.2 Non-Refundable</h3>
    <p>All tips are voluntary, non-refundable, and non-reversible. For XRPL-based tips, blockchain irreversibility applies — once a tip transaction is confirmed on the XRPL, it cannot be reversed, cancelled, or modified by IMU.</p>
    <h3>SA8.3 Tax Responsibility</h3>
    <p>Tip recipients are solely responsible for reporting all tip income for tax purposes. IMU will include tip income in IRS Form 1099 reporting if the Creator's total earnings (including tips) meet the $600 annual reporting threshold.</p>
    <h3>SA8.4 Prohibited Conduct</h3>
    <p>The following tipping behaviors are prohibited and may result in account suspension: (a) tip manipulation or artificial tip inflation using multiple accounts; (b) coerced tipping or tip-for-favors schemes; (c) using tips to circumvent Platform Fees or subscription pricing; (d) money laundering or structuring transactions through tipping.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sa-s9">
    <h2>SA9. Refund Mechanics for Cryptocurrency Transactions</h2>
    <h3>SA9.1 General Rule</h3>
    <p>Due to the irreversible nature of blockchain transactions, cryptocurrency payments are generally non-refundable. Refunds for cryptocurrency-paid transactions are available only in the limited circumstances described below.</p>
    <h3>SA9.2 XRP Refunds</h3>
    <p>If a refund is approved for an XRP-paid transaction (e.g., due to a platform technical error as described in TOS Section 29), the refund will be issued in the USD-equivalent value at the time of the original transaction, deposited via the User's Stripe payment method (if available) or as a platform credit. IMU does not issue refunds in XRP. This protects both parties from exchange rate fluctuation between the transaction date and refund date.</p>
    <h3>SA9.3 RLUSD Refunds</h3>
    <p>Refunds for RLUSD-paid transactions may be issued in RLUSD to the User's original XRPL wallet address, or in USD via Stripe, at IMU's discretion. Because RLUSD is designed to maintain a 1:1 USD peg, the refund amount equals the original payment amount in either currency.</p>
    <h3>SA9.4 XFT Refunds</h3>
    <p>XFT used as payment is non-refundable. If a refund is approved for an XFT-paid transaction, IMU will issue the refund as a platform credit valued at the original XFT amount, redeemable for future transactions on the Service.</p>
    <h3>SA9.5 IM Collectibles</h3>
    <p>All Digital Collectible (NFT) purchases are final and non-refundable regardless of payment method, as set forth in the IM Collectibles Terms of Sale.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sa-s10">
    <h2>SA10. Recurring Payments (XRPL Subscriptions)</h2>
    <h3>SA10.1 No Auto-Renewal on XRPL</h3>
    <p>Fan subscriptions and Creator subscriptions paid via XRPL (XRP, RLUSD, or XFT) do NOT auto-renew. XRPL transactions are one-time payments granting access for the selected billing period (monthly or annually). The subscriber must manually renew before or after expiry to continue access.</p>
    <h3>SA10.2 Renewal Reminders</h3>
    <p>IMU will send email reminders at seven (7) days and twenty-four (24) hours before XRPL subscription expiry. Failure to receive a reminder does not extend the subscription or relieve the obligation to renew.</p>
    <h3>SA10.3 Post-Expiry Grace Period</h3>
    <p>After an XRPL subscription expires, the subscriber has a forty-eight (48) hour grace period during which subscriber-only content remains accessible. If renewal is not completed within the grace period, access to subscriber-only content, streams, and chat is revoked until renewal.</p>
    <h3>SA10.4 Dynamic Re-Pricing at Renewal</h3>
    <p>For XRP-paid subscriptions, the renewal price is recalculated at the then-current XRP/USD exchange rate at the time of renewal. The subscriber will see the updated XRP amount at checkout. RLUSD subscriptions renew at the same RLUSD amount (equal to USD price). XFT subscriptions renew at IMU's then-current XFT valuation rate.</p>
    <h3>SA10.5 Fiat (Stripe) Subscriptions Auto-Renew</h3>
    <p>For clarity, fan subscriptions paid via Stripe (credit/debit card) auto-renew at the end of each billing period unless cancelled by the subscriber through their account settings. Cancellation takes effect at the end of the current billing period per TOS Section 5.4.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sa-s11">
    <h2>SA11. Tax Treatment</h2>
    <h3>SA11.1 General Tax Responsibility</h3>
    <p>You are solely responsible for determining and fulfilling all tax obligations arising from your use of cryptocurrency on the Service, including but not limited to: income tax on earnings received in cryptocurrency, capital gains tax on the disposition of cryptocurrency, sales tax or value-added tax where applicable, and any reporting obligations under the laws of your jurisdiction.</p>
    <h3>SA11.2 1099 Reporting</h3>
    <p>IMU will issue IRS Form 1099 (or equivalent) to U.S. Creators and Users receiving cryptocurrency payments through the Service who earn six hundred dollars ($600) or more in a calendar year, as required by U.S. law. For 1099 reporting purposes, the USD value of cryptocurrency payments is determined at the Fair Market Value at the time of settlement, as recorded by IMU's Exchange Rate Oracle.</p>
    <h3>SA11.3 Fair Market Value Determination</h3>
    <p>For tax reporting purposes, the FMV of XRP is determined using the exchange rate from IMU's Exchange Rate Oracle at the time of transaction settlement. The FMV of RLUSD is its USD face value ($1.00 per RLUSD). The FMV of XFT is determined using IMU's internal valuation rate at the time of distribution or transaction.</p>
    <h3>SA11.4 Buyer Capital Gains</h3>
    <p>You acknowledge that using cryptocurrency (XRP, RLUSD, or XFT) to make a purchase on the Service may constitute a taxable event under U.S. tax law, potentially triggering capital gains or losses based on the difference between your cost basis and the FMV at the time of payment. IMU does not provide tax advice. Consult a qualified tax professional.</p>
    <h3>SA11.5 W-9/W-8BEN</h3>
    <p>Creators and Users receiving cryptocurrency payments are required to provide accurate tax identification information (W-9 for U.S. persons, W-8BEN for non-U.S. persons) upon request. Failure to provide tax documentation may result in withholding, payout suspension, or account restriction.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sa-s12">
    <h2>SA12. Wallet Security and Non-Custodial Architecture</h2>
    <h3>SA12.1 Non-Custodial</h3>
    <p>IMU does not custody, hold, or control your XRP, RLUSD, XFT, or any other digital assets. IMU does not custody your private keys, seed phrases, or wallet credentials. You connect your own XRPL-compatible wallet (e.g., Xaman) to the Service to complete transactions. IMU is not a digital asset exchange, broker, wallet provider, or custodian.</p>
    <h3>SA12.2 User Responsibility</h3>
    <p>You are solely responsible for: (a) the security of your XRPL wallet, private keys, and seed phrases; (b) ensuring you send payments to the correct wallet address; (c) maintaining a sufficient XRP reserve in your wallet to cover XRPL network fees; and (d) maintaining trustlines for RLUSD and/or XFT if you wish to receive direct Payment deliveries of those tokens.</p>
    <h3>SA12.3 Lost Access</h3>
    <p>IMU cannot recover lost or stolen tokens, restore access to compromised wallets, or reverse transactions sent to incorrect addresses. If you lose access to your wallet, any cryptocurrency or digital assets in that wallet are permanently inaccessible. IMU bears no responsibility for wallet compromise, lost private keys, or incorrect wallet addresses.</p>
    <h3>SA12.4 Network Fees</h3>
    <p>All XRPL transactions are subject to network fees (sometimes called "transaction fees" or "gas fees") determined by the XRPL network protocol, not set or collected by IMU. These fees are typically minimal (fractions of an XRP) but are the User's responsibility.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sa-s13">
    <h2>SA13. Modifications</h2>
    <p>IMU reserves the right to modify this Schedule at any time. We will provide at least thirty (30) days' notice of material changes by updating this Schedule on the Service and, where possible, by email notification. Your continued use of cryptocurrency payment features after the effective date constitutes acceptance. Transactions completed before the effective date of any change remain governed by the Schedule in effect at the time of the transaction.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sa-s14">
    <h2>SA14. Contact</h2>
    <p>For questions about this Schedule or cryptocurrency payments on the Service:</p>
    <div class="legal-contact-box">
        <h3>Schedule A &mdash; Cryptocurrency Payment Terms</h3>
        <p><strong>Legal &amp; Compliance:</strong> <a href="mailto:legal@imutv.tv">legal@imutv.tv</a></p>
        <p><strong>Creator Support:</strong> <a href="mailto:creators@imutv.tv">creators@imutv.tv</a></p>
        <p><strong>General:</strong> <a href="mailto:contact@imutv.tv">contact@imutv.tv</a></p>
    </div>
    <div class="legal-footer-note" style="margin-top:1.5rem;">Last Updated: February 22, 2026 &nbsp;&middot;&nbsp; <a href="#toc">&#8593; Back to Table of Contents</a></div>
</div>

<!-- ===================== SCHEDULE B ===================== -->
<div class="legal-exhibit-header" id="schedule-b">
    <div class="exhibit-label">Schedule &quot;B&quot; &mdash; Attached to IMU Terms of Service</div>
    <h2>IM Collectibles Terms of Sale</h2>
    <p>Governing all purchases, sales, minting, and transfers of Digital Collectibles on the IM Collectibles marketplace at www.imcollectibles.io &mdash; Effective Date: February 22, 2026</p>
</div>

<div class="legal-notice">THESE SALE TERMS SUPPLEMENT AND ARE INCORPORATED INTO THE IMU TERMS OF SERVICE. IN THE EVENT OF A CONFLICT BETWEEN THESE SALE TERMS AND THE GENERAL TERMS OF SERVICE, THESE SALE TERMS SHALL CONTROL WITH RESPECT TO TRANSACTIONS ON THE IM COLLECTIBLES MARKETPLACE.</div>
<br>
<div class="legal-notice">BY PURCHASING, LISTING, MINTING, BIDDING ON, OR OTHERWISE TRANSACTING IN DIGITAL COLLECTIBLES ON IM COLLECTIBLES, YOU AGREE TO BE BOUND BY THESE SALE TERMS. IF YOU DO NOT AGREE, DO NOT USE THE MARKETPLACE.</div>
<br>

<div class="legal-section" id="ex-s1">
    <h2>A1. Introduction &amp; Scope</h2>
    <p>These Terms of Sale ("Sale Terms") govern all transactions involving Digital Collectibles (non-fungible tokens, or "NFTs") on the IM Collectibles marketplace, operated by IMU, LLC ("IMU," "we," "us") at www.imcollectibles.io. These Sale Terms apply to all participants in the IM Collectibles marketplace, including Buyers, Sellers (Creators), and Collectors.</p>
    <p>These Sale Terms supplement and are incorporated into the IMU Terms of Service. Capitalized terms used but not defined in these Sale Terms have the meanings assigned to them in the Terms of Service.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="ex-s2">
    <h2>A2. Definitions</h2>
    <ul>
        <li><strong>"Buyer" or "Collector"</strong> means any User who purchases, receives, or otherwise acquires a Digital Collectible through the IM Collectibles marketplace.</li>
        <li><strong>"Creator" or "Seller"</strong> means any User who mints, lists, and sells Digital Collectibles on the IM Collectibles marketplace.</li>
        <li><strong>"Digital Collectible" or "NFT"</strong> means a non-fungible token minted on the XRP Ledger (XRPL) through the IM Collectibles platform, consisting of: (a) the on-chain token (a unique, blockchain-verified entry on the XRPL); and (b) the associated digital content (artwork, music, animation, or other media linked to the token).</li>
        <li><strong>"Associated Content"</strong> means the digital artwork, music, animation, video, or other media that is linked to and displayed in connection with a Digital Collectible.</li>
        <li><strong>"Marketplace"</strong> means the IM Collectibles platform at www.imcollectibles.io, including all associated web and mobile interfaces.</li>
        <li><strong>"Minting"</strong> means the process of creating a new Digital Collectible on the XRPL blockchain through the IM Collectibles platform.</li>
        <li><strong>"Primary Sale"</strong> means the first sale of a Digital Collectible from the Creator to a Buyer on the Marketplace.</li>
        <li><strong>"Secondary Sale" or "Resale"</strong> means any subsequent sale or transfer of a Digital Collectible after the Primary Sale.</li>
        <li><strong>"Listing"</strong> means a Creator's public offer to sell a Digital Collectible on the Marketplace at a specified price or through an auction.</li>
        <li><strong>"Creator Royalty"</strong> means the percentage of the Secondary Sale price automatically paid to the original Creator.</li>
        <li><strong>"Platform Fee"</strong> means the percentage of the sale price retained by IMU for operating the Marketplace.</li>
        <li><strong>"XRPL"</strong> means the XRP Ledger, a decentralized, open-source, public blockchain.</li>
    </ul>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="ex-s3">
    <h2>A3. Nature of Digital Collectibles</h2>
    <h3>A3.1 What You Are Purchasing</h3>
    <p>When you purchase a Digital Collectible on IM Collectibles, you are acquiring:</p>
    <ul>
        <li><strong>A blockchain token:</strong> A unique, cryptographically verified entry on the XRP Ledger that represents your ownership of that specific Digital Collectible. This token is recorded on a public, decentralized, immutable ledger.</li>
        <li><strong>A license to the Associated Content:</strong> A limited license to use the digital artwork, music, or other media linked to the token, subject to the terms described in Section A6 (License Granted to Buyers).</li>
    </ul>
    <p>You are NOT acquiring:</p>
    <ul>
        <li>Copyright ownership in the Associated Content</li>
        <li>Any intellectual property rights beyond the limited license granted</li>
        <li>The right to commercialize, reproduce for sale, or create derivative works (unless a Commercial License is explicitly included)</li>
        <li>Trademark rights or any right to use the Creator's brand, name, or likeness for commercial purposes</li>
        <li>Any ownership interest, equity, profit share, revenue share, dividend, governance right, or voting right in IMU, LLC, the Creator's business, or any other entity</li>
        <li>Any right to future deliverables, access, or utility beyond what is expressly stated in the Listing at the time of purchase</li>
    </ul>
    <h3>A3.2 Digital Collectibles Are NOT Securities</h3>
    <p class="legal-caps">DIGITAL COLLECTIBLES SOLD ON IM COLLECTIBLES ARE CONSUMER PRODUCTS PURCHASED FOR PERSONAL ENJOYMENT, USE, AND COLLECTION. THEY ARE EXPRESSLY NOT SECURITIES, INVESTMENT CONTRACTS, COMMODITIES, SWAPS, OR FINANCIAL INSTRUMENTS AS DEFINED UNDER U.S. FEDERAL OR STATE SECURITIES LAWS, INCLUDING THE SECURITIES ACT OF 1933, THE SECURITIES EXCHANGE ACT OF 1934, OR THE INVESTMENT COMPANY ACT OF 1940.</p>
    <p>IMU does not offer, solicit, promote, or sell Digital Collectibles as investments. IMU makes no representations regarding the future value, appreciation potential, scarcity premium, or market demand of any Digital Collectible. You should NOT purchase Digital Collectibles with any expectation of profit, investment return, capital appreciation, or income generation.</p>
    <p>The value of Digital Collectibles is inherently subjective, speculative, and volatile. Digital Collectibles may lose all value. Past sales prices are not indicative of future value. IMU is not a broker-dealer, investment adviser, exchange, or alternative trading system. The Marketplace is not a securities exchange and does not facilitate securities transactions.</p>
    <h3>A3.3 No Guaranteed Utility or Future Benefits</h3>
    <p>Unless explicitly stated in the Listing at the time of purchase, Digital Collectibles do not include:</p>
    <ul>
        <li>Access to future events, concerts, or experiences</li>
        <li>Rights to future airdrops, token distributions, or additional NFTs</li>
        <li>Membership in any DAO, club, or organization</li>
        <li>Physical merchandise or redeemable physical goods</li>
        <li>Ongoing access to digital content beyond the Associated Content</li>
    </ul>
    <p>If a Listing describes specific utility (e.g., "includes backstage pass to [Event]"), that utility is the sole responsibility of the Creator. IMU does not guarantee fulfillment of Creator-promised utility and is not liable for any failure to deliver.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="ex-s4">
    <h2>A4. How Transactions Work</h2>
    <h3>A4.1 Marketplace Architecture</h3>
    <p>IM Collectibles operates as a non-custodial marketplace. This means:</p>
    <ul>
        <li><strong>Your wallet, your keys:</strong> IMU does not hold, custody, or control your XRP, digital assets, or private keys at any time. You connect your own XRPL-compatible wallet to the Marketplace to complete transactions.</li>
        <li><strong>On-chain settlement:</strong> All purchases, sales, and transfers of Digital Collectibles are settled directly on the XRP Ledger. IMU facilitates the Marketplace interface but does not serve as an intermediary, escrow agent, or custodian for any transaction.</li>
        <li><strong>Batch Transaction and associated offers:</strong> Platform Fees and Creator Royalties are programmatically enforced through on-chain mechanisms where supported by the XRPL protocol.</li>
    </ul>
    <h3>A4.2 Primary Sales (Creator to Buyer)</h3>
    <p>When a Creator lists a Digital Collectible for sale and a Buyer purchases it:</p>
    <ul>
        <li><strong>Step 1 — Listing:</strong> The Creator mints the NFT on the XRPL and creates a sell offer on the Marketplace at a fixed price or sets up an auction.</li>
        <li><strong>Step 2 — Purchase:</strong> The Buyer connects their XRPL wallet, reviews the Listing (price, Associated Content, license terms, Creator Royalty percentage), and submits a buy offer.</li>
        <li><strong>Step 3 — Settlement:</strong> The XRPL matches the sell and buy offers. The NFT transfers to the Buyer's wallet, and the payment (in XRP) is distributed: the Creator receives the sale price minus the Platform Fee and any applicable network fees.</li>
        <li><strong>Step 4 — Confirmation:</strong> The Marketplace displays a transaction confirmation with the XRPL transaction hash, which can be independently verified on any XRPL blockchain explorer.</li>
    </ul>
    <h3>A4.3 Secondary Sales (Resales Between Collectors)</h3>
    <p>After the Primary Sale, the Buyer (now a Collector) may resell the Digital Collectible on the IM Collectibles Marketplace:</p>
    <ul>
        <li><strong>Listing:</strong> The Collector creates a new sell offer at their chosen price.</li>
        <li><strong>Creator Royalty:</strong> On each Secondary Sale completed through the IM Collectibles Marketplace, the original Creator receives a royalty payment equal to the percentage set at minting (typically 5-10%). This royalty is automatically deducted from the sale proceeds.</li>
        <li><strong>Platform Fee:</strong> IMU's Platform Fee is also deducted from Secondary Sales completed on the Marketplace.</li>
        <li><strong>External Sales:</strong> If a Collector transfers or sells the NFT outside of the IM Collectibles Marketplace (on another XRPL marketplace or through a direct wallet-to-wallet transfer), Creator Royalties and Platform Fees may not be enforceable, as enforcement depends on the receiving marketplace honoring on-chain royalty standards.</li>
    </ul>
    <h3>A4.4 Auctions</h3>
    <p>The Marketplace may support auction-format sales for Digital Collectibles:</p>
    <ul>
        <li><strong>Reserve Price:</strong> Creators may set a minimum reserve price below which the NFT will not sell.</li>
        <li><strong>Bidding:</strong> Bids are placed through the Marketplace interface. By placing a bid, you are making a binding offer to purchase the Digital Collectible at that price if your bid is the winning bid.</li>
        <li><strong>Winning Bid:</strong> When the auction ends, the highest bid meeting or exceeding the reserve price wins. Settlement follows the same on-chain process as fixed-price sales.</li>
        <li><strong>Bid Cancellation:</strong> Bids may be cancelled before the auction ends, subject to any auction-specific rules displayed at the time of bidding. Once the auction closes, the winning bid is final.</li>
    </ul>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="ex-s5">
    <h2>A5. Fees &amp; Costs</h2>
    <div class="legal-table-wrap">
        <table class="legal-table">
            <thead><tr><th>Fee Type</th><th>Amount</th><th>Description</th></tr></thead>
            <tbody>
                <tr>
                    <td><strong>Platform Fee (Primary Sale)</strong></td>
                    <td>None</td>
                    <td>IMU does not collect a fee from artist on Primary Sale.</td>
                </tr>
                <tr>
                    <td><strong>Platform Fee (Secondary Sale)</strong></td>
                    <td>2% of sale price</td>
                    <td>Retained by IMU from the Seller's proceeds on resale transactions completed through the Marketplace.</td>
                </tr>
                <tr>
                    <td><strong>Creator Royalty (Secondary Sale)</strong></td>
                    <td>Set by Creator (typically 5–10%)</td>
                    <td>Paid to the original Creator on each Secondary Sale completed through the Marketplace. Set at time of minting and encoded on-chain.</td>
                </tr>
                <tr>
                    <td><strong>Minting Fee</strong></td>
                    <td>XRPL network fee (minimal)</td>
                    <td>The XRPL's native transaction fee for minting an NFToken. This fee is set by the XRPL network protocol, not by IMU. Typically fractions of an XRP.</td>
                </tr>
                <tr>
                    <td><strong>Transaction / Network Fee</strong></td>
                    <td>XRPL network fee (minimal)</td>
                    <td>Standard XRPL transaction fees for buy/sell offers and transfers. Set by the network, not by IMU.</td>
                </tr>
                <tr>
                    <td><strong>Listing Fee — Digital &amp; Fine Art</strong> (Access Tier Based)</td>
                    <td>Base Fee per Master File — ≤100 tier: 2 XRP; ≤500 tier: 4 XRP; 500+ tier: 6 XRP &nbsp;+&nbsp; 0.03 XRP per NFT</td>
                    <td rowspan="6">All listing fees paid by the Creator of a newly minted Digital Collectible.<br><br><strong>Open Minting:</strong> Creators have an option to create an NFT and set time limits for an unlimited number of NFTs to be minted during the set period. If creators select this option, the base fee, respective to the NFT type, shall be triple the listing fee, as shown in this section, plus the Platform Fee in the amount of 2% shall be paid to IMC with each NFT sale if utilizing the Open Minting option per transaction.<br><br>Some features and/or fees may not become available until Batch Posting becomes available on the XRPL.</td>
                </tr>
                <tr>
                    <td><strong>Listing Fee — Music Access (Single)</strong></td>
                    <td>2.5 XRP Base Fee per Master File + 0.06 XRP per NFT</td>
                </tr>
                <tr>
                    <td><strong>Listing Fee — Music Access (Album)</strong></td>
                    <td>5 XRP Base Fee + 1 XRP per master file + 0.06 XRP per album NFT</td>
                </tr>
                <tr>
                    <td><strong>Listing Fee — Music Video Access</strong></td>
                    <td>3 XRP Base Fee per Master File + 0.08 XRP per NFT</td>
                </tr>
                <tr>
                    <td><strong>Listing Fee — Short-Film Access</strong></td>
                    <td>5 XRP Base Fee per Master File + 0.1 XRP per NFT</td>
                </tr>
                <tr>
                    <td><strong>Listing Fee — Film Access</strong></td>
                    <td>15 XRP Base Fee per Master File + 0.1 XRP per NFT</td>
                </tr>
            </tbody>
        </table>
    </div>
    <p><strong>Fee Modifications:</strong> IMU reserves the right to modify Platform Fee percentages with thirty (30) days' prior written notice. Fee changes apply to transactions completed after the effective date of the change. Creator Royalty percentages are set at minting and cannot be modified by IMU after minting.</p>
    <p><strong>Net Proceeds:</strong> Creator/Seller net proceeds = Sale Price – Platform Fee – Creator Royalty (on Secondary Sales) – Network Fee.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="ex-s6">
    <h2>A6. License Granted to Buyers</h2>
    <h3>A6.1 Default Personal Use License</h3>
    <p>Unless the Creator specifies a different license in the Listing, purchasing a Digital Collectible grants you the following default license:</p>
    <p><strong>Grant:</strong> A non-exclusive, worldwide, non-sublicensable, royalty-free license to use, copy, display, and enjoy the Associated Content for personal, non-commercial purposes.</p>
    <p><strong>Permitted Uses:</strong></p>
    <ul>
        <li>Display the Associated Content on personal devices, screens, and digital frames</li>
        <li>Display the Associated Content in your personal XRPL wallet, portfolio, or collection viewer</li>
        <li>Display the Associated Content on social media profiles and posts as part of showcasing your personal collection</li>
        <li>Print a physical copy of the Associated Content for personal display (e.g., hang on your wall)</li>
        <li>Use the Associated Content as a personal avatar or profile picture</li>
        <li>Resell the Digital Collectible on the IM Collectibles Marketplace or transfer it to another XRPL wallet</li>
    </ul>
    <p><strong>Prohibited Uses (under the Default Personal Use License):</strong></p>
    <ul>
        <li>Commercialize the Associated Content (sell prints, merchandise, products, or services featuring the content)</li>
        <li>Use the Associated Content in advertising, marketing, or promotional materials for any business or product</li>
        <li>Create derivative works based on the Associated Content for sale or commercial distribution</li>
        <li>Sub-license the Associated Content to third parties</li>
        <li>Use the Associated Content to create competing NFTs, token collections, or digital products</li>
        <li>Remove or alter any Creator attribution, signature, or watermark embedded in the Associated Content</li>
        <li>Use the Associated Content in connection with hate speech, illegal activity, or content that violates the IMU Community Guidelines</li>
    </ul>
    <h3>A6.2 Commercial License</h3>
    <p>A Creator may, at their discretion, offer a Commercial License with their Digital Collectible. If a Commercial License is offered, the specific terms and scope will be described in the Listing. A Commercial License may permit uses such as merchandise production, inclusion in commercial projects, or derivative works, subject to the conditions stated.</p>
    <div class="legal-notice"><strong>Important:</strong> Commercial Licenses are granted by the Creator, not by IMU. IMU does not warrant or guarantee the scope, validity, or enforceability of Creator-offered Commercial Licenses. If you require a Commercial License, review the terms carefully in the Listing and contact the Creator directly for clarification before purchasing.</div>
    <h3>A6.3 License Follows the Token</h3>
    <p>The license to the Associated Content is tied to ownership of the Digital Collectible token. When you transfer or sell the Digital Collectible, your license to the Associated Content terminates automatically and passes to the new owner. You may not retain copies of the Associated Content for further use after transferring the token, except for incidental copies retained in personal backup systems not used for display or distribution.</p>
    <h3>A6.4 Creator's Retained Rights</h3>
    <p>The Creator retains all intellectual property rights in the Associated Content not expressly granted to the Buyer. The Creator may:</p>
    <ul>
        <li>Continue to display, promote, and showcase the Associated Content in their portfolio, social media, and marketing materials</li>
        <li>Create and sell additional NFTs featuring the same or similar content (unless the Listing specifies a 1/1 exclusive)</li>
        <li>License the Associated Content to third parties independently of the NFT sale</li>
        <li>Modify, adapt, or create derivative works from the Associated Content</li>
    </ul>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="ex-s7">
    <h2>A7. Creator Obligations</h2>
    <h3>A7.1 Representations and Warranties</h3>
    <p>By minting and listing a Digital Collectible on IM Collectibles, the Creator represents and warrants that:</p>
    <ul>
        <li><strong>Ownership:</strong> The Creator is the sole owner of the Associated Content, including all copyrights, or has obtained all necessary rights, licenses, and permissions to mint and sell the content as an NFT.</li>
        <li><strong>No Infringement:</strong> The Associated Content does not infringe upon the copyright, trademark, patent, trade secret, right of publicity, right of privacy, or any other proprietary right of any third party.</li>
        <li><strong>Original Work:</strong> The Associated Content is original work created by the Creator, except where AI assistance is disclosed and where third-party elements are properly licensed.</li>
        <li><strong>Accurate Description:</strong> The Listing description, metadata, edition size, and any described utility are accurate and not misleading.</li>
        <li><strong>AI Disclosure:</strong> If the Associated Content was created using AI tools (image generators, music generators, voice synthesis, etc.), this has been disclosed in the Listing.</li>
        <li><strong>Legal Compliance:</strong> The Digital Collectible and its sale comply with all applicable laws and regulations.</li>
    </ul>
    <h3>A7.2 Creator Indemnification</h3>
    <p>Creators agree to indemnify, defend, and hold harmless IMU, its officers, directors, employees, and affiliates from and against any claims, damages, losses, liabilities, costs, and expenses (including reasonable attorneys' fees) arising from: (a) any breach of the Creator's representations and warranties; (b) any claim that the Associated Content infringes a third party's intellectual property rights; (c) any failure to deliver Creator-promised utility; or (d) any dispute between the Creator and a Buyer regarding the Digital Collectible.</p>
    <h3>A7.3 Content Removal</h3>
    <p>If a Digital Collectible's Associated Content is determined to violate these Sale Terms, the Terms of Service, or the Community Guidelines, or if a valid DMCA takedown notice is received, IMU may:</p>
    <ul>
        <li>Remove the Listing from the Marketplace</li>
        <li>Disable display of the Associated Content on the Marketplace</li>
        <li>Suspend or terminate the Creator's account</li>
    </ul>
    <div class="legal-notice"><strong>Note:</strong> Removing a Listing or disabling content display does NOT affect the on-chain NFT token itself. The token will remain in the current holder's XRPL wallet. However, the Associated Content will no longer be displayed through the IM Collectibles interface, and the Marketplace's resale functionality for that token will be disabled.</div>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="ex-s8">
    <h2>A8. Buyer Responsibilities &amp; Acknowledgments</h2>
    <p>By purchasing a Digital Collectible on IM Collectibles, you acknowledge and agree to the following:</p>
    <h3>A8.1 Due Diligence</h3>
    <ul>
        <li>You are solely responsible for evaluating the Digital Collectible, the Associated Content, the Creator, and the terms of the Listing before purchasing.</li>
        <li>IMU does not verify the accuracy of Creators' claims regarding the Associated Content, its provenance, or any promised utility.</li>
        <li>IMU does not provide appraisals, valuations, or investment advice regarding Digital Collectibles.</li>
        <li>You should conduct your own research and, if necessary, consult with legal and financial advisors before making purchases.</li>
    </ul>
    <h3>A8.2 Wallet Security</h3>
    <ul>
        <li>You are solely responsible for the security of your XRPL wallet, private keys, and seed phrases.</li>
        <li>IMU cannot recover lost or stolen tokens resulting from compromised wallets, phishing attacks, or lost private keys.</li>
        <li>If your wallet is compromised, notify IMU immediately at <a href="mailto:legal@imutv.tv">legal@imutv.tv</a> so we can flag the affected tokens in our system, but we cannot reverse on-chain transfers.</li>
    </ul>
    <h3>A8.3 Tax Obligations</h3>
    <p>You are solely responsible for determining and fulfilling your tax obligations arising from the purchase, sale, or exchange of Digital Collectibles. This may include capital gains tax, sales tax, value-added tax, or other taxes depending on your jurisdiction. IMU does not provide tax advice. Consult a qualified tax professional regarding your specific situation.</p>
    <h3>A8.4 Risk Acknowledgment</h3>
    <p class="legal-caps">YOU EXPRESSLY ACKNOWLEDGE AND ACCEPT THE FOLLOWING RISKS ASSOCIATED WITH PURCHASING DIGITAL COLLECTIBLES:</p>
    <ul>
        <li><strong>Market Risk:</strong> The value of Digital Collectibles is highly volatile and speculative. You may lose some or all of the value of your purchase. Past prices are not indicative of future value.</li>
        <li><strong>Liquidity Risk:</strong> There may be no secondary market or buyer demand for your Digital Collectible. You may be unable to resell it at any price.</li>
        <li><strong>Technology Risk:</strong> The XRPL, blockchain technology, and smart contracts may experience bugs, vulnerabilities, forks, or protocol changes that could affect your Digital Collectible.</li>
        <li><strong>Regulatory Risk:</strong> The legal and regulatory landscape for NFTs and digital assets is evolving. Future laws or regulations may restrict, prohibit, or impose additional requirements on the purchase, sale, or ownership of Digital Collectibles.</li>
        <li><strong>Content Hosting Risk:</strong> Associated Content hosted by IMU (off-chain) may become unavailable if IMU ceases operations or if the content is removed due to a legal dispute. The on-chain token would persist, but the visual/audio content may not be retrievable.</li>
        <li><strong>Interoperability Risk:</strong> Your Digital Collectible may not be displayable or tradeable on all XRPL-compatible platforms or wallets. Creator Royalties may not be enforceable on external marketplaces.</li>
    </ul>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="ex-s9">
    <h2>A9. Refund &amp; Cancellation Policy</h2>
    <h3>A9.1 All Sales Are Final</h3>
    <p class="legal-caps">DUE TO THE NATURE OF BLOCKCHAIN TECHNOLOGY, ALL DIGITAL COLLECTIBLE SALES ON IM COLLECTIBLES ARE FINAL. NO REFUNDS, RETURNS, EXCHANGES, OR CANCELLATIONS ARE AVAILABLE ONCE A TRANSACTION HAS BEEN CONFIRMED ON THE XRP LEDGER.</p>
    <p>This policy exists because:</p>
    <ul>
        <li>Blockchain transactions are irreversible by design. Once confirmed on the XRPL, neither IMU nor any third party can reverse the transaction.</li>
        <li>IMU operates a non-custodial marketplace. At no point does IMU hold or control the funds or tokens involved in a transaction.</li>
        <li>The transfer of the NFT and the payment occur simultaneously and atomically on-chain. There is no intermediate state where a reversal is possible.</li>
    </ul>
    <h3>A9.2 Limited Exceptions</h3>
    <p>In the following narrow circumstances, IMU may provide marketplace-level remedies (but cannot reverse on-chain transactions):</p>
    <ul>
        <li><strong>Fraudulent Listing:</strong> If a Creator is determined to have engaged in fraud (e.g., minting stolen artwork, misrepresenting the Associated Content, or operating a counterfeit collection), IMU may remove the Listing, ban the Creator, and cooperate with Buyers in pursuing claims against the Creator. IMU is not responsible for refunding the purchase price in cases of Creator fraud.</li>
        <li><strong>Technical Failure:</strong> If a technical failure on the IMU platform (not the XRPL network) causes a transaction to execute incorrectly (e.g., wrong price charged due to a platform bug), IMU will investigate and may issue a platform credit or coordinate a corrective transaction. This does not apply to XRPL network issues, gas fee spikes, or user error (e.g., sending to the wrong wallet).</li>
        <li><strong>Duplicate Charge:</strong> If you are charged twice for the same transaction due to a platform error, IMU will refund the duplicate charge.</li>
    </ul>
    <h3>A9.3 Chargebacks (Fiat On-Ramp)</h3>
    <p>If IM Collectibles supports fiat currency purchases (USD via credit card) through an on-ramp provider, initiating a fraudulent chargeback after receiving a Digital Collectible constitutes a violation of these Sale Terms and may result in account termination, legal action, and reporting to fraud prevention databases. Legitimate chargebacks for unauthorized transactions are handled in accordance with the payment processor's policies.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="ex-s10">
    <h2>A10. Prohibited Marketplace Activities</h2>
    <p>The following activities are prohibited on the IM Collectibles Marketplace and may result in immediate account termination:</p>
    <h3>A10.1 Intellectual Property Violations</h3>
    <ul>
        <li>Minting NFTs of artwork, music, photography, or designs created by someone else without authorization</li>
        <li>Minting NFTs that infringe upon trademarks, including replicas or confusingly similar imitations of established collections or brands</li>
        <li>Minting NFTs incorporating copyrighted characters, logos, or other IP without a license from the rights holder</li>
    </ul>
    <h3>A10.2 Market Manipulation</h3>
    <ul>
        <li><strong>Wash Trading:</strong> Buying your own NFTs (through alternate wallets or coordinated accounts) to create the appearance of market activity or artificially inflate the sale history or floor price of a collection.</li>
        <li><strong>Shill Bidding:</strong> Placing bids on your own NFTs (or having collaborators bid) to drive up auction prices.</li>
        <li><strong>Pump and Dump:</strong> Coordinating the artificial inflation of a collection's price through misleading promotion, then selling at the inflated price.</li>
        <li><strong>Front-Running:</strong> Exploiting advance knowledge of upcoming Listings or marketplace changes to gain an unfair trading advantage.</li>
        <li><strong>Insider Information:</strong> Using non-public information about Creator drops, platform features, or marketplace changes to trade ahead of public disclosure.</li>
    </ul>
    <h3>A10.3 Fraud &amp; Deception</h3>
    <ul>
        <li>Creating counterfeit or replica Digital Collectibles that impersonate established collections or artists</li>
        <li>Misrepresenting the Associated Content, edition size, rarity, or provenance</li>
        <li>Promising utility, access, or benefits with no intent or ability to deliver</li>
        <li>Operating multiple accounts to circumvent enforcement actions or manipulate the marketplace</li>
        <li>Phishing, social engineering, or impersonation to steal wallet credentials or tokens</li>
    </ul>
    <h3>A10.4 Financial Crimes</h3>
    <ul>
        <li>Using the Marketplace for money laundering or to conceal criminal proceeds</li>
        <li>Structuring transactions to evade reporting thresholds</li>
        <li>Financing terrorism or sanctioned activities</li>
        <li>Facilitating tax evasion</li>
    </ul>
    <h3>A10.5 Sanctions Violations</h3>
    <p>Transacting on the Marketplace from, through, or on behalf of any person, entity, or jurisdiction subject to U.S. economic sanctions administered by OFAC. See Section A12 (AML/KYC &amp; Sanctions Compliance).</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="ex-s11">
    <h2>A11. Copyright Disputes &amp; DMCA for NFTs</h2>
    <h3>A11.1 DMCA Takedowns</h3>
    <p>If you believe a Digital Collectible on IM Collectibles infringes your copyright, you may file a DMCA takedown notice with our Copyright Agent at <a href="mailto:legal@imutv.tv">legal@imutv.tv</a>. Upon receipt of a valid takedown notice, IMU will:</p>
    <ul>
        <li>Remove the infringing Listing from the Marketplace</li>
        <li>Disable display of the Associated Content on the Marketplace interface</li>
        <li>Notify the Creator of the takedown</li>
    </ul>
    <div class="legal-notice"><strong>Important:</strong> IMU cannot remove or "burn" the on-chain NFT token. The token will remain in the holder's wallet. However, the Associated Content will no longer be displayed or tradeable through the IM Collectibles interface.</div>
    <h3>A11.2 Counter-Notifications</h3>
    <p>Creators who believe their content was removed in error may file a counter-notification as described in the Terms of Service (Section 8.3). The standard DMCA counter-notification process applies.</p>
    <h3>A11.3 Repeat Infringer Policy</h3>
    <p>Creators who are the subject of three (3) or more valid DMCA takedown notices will have their accounts permanently terminated and will be permanently banned from the IM Collectibles Marketplace.</p>
    <h3>A11.4 Rights Disputes Between Creators</h3>
    <p>When a copyright ownership dispute arises between Creators regarding a Digital Collectible, IMU will disable the Listing, and any associated revenue will be held pending resolution. IMU is not an arbiter of intellectual property disputes and encourages the parties to resolve disputes through mutual agreement, submission of copyright registration documentation, or court order.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="ex-s12">
    <h2>A12. AML/KYC &amp; Sanctions Compliance</h2>
    <h3>A12.1 Anti-Money Laundering (AML)</h3>
    <p>IMU implements the following measures to prevent the use of IM Collectibles for money laundering and illicit finance:</p>
    <ul>
        <li><strong>Creator Identity Verification:</strong> Creators who mint and sell Digital Collectibles may be required to complete identity verification (KYC) before listing. Verification may include government-issued ID, proof of address, and tax identification.</li>
        <li><strong>Transaction Monitoring:</strong> IMU monitors marketplace transactions for anomalous patterns, including unusually large purchases, rapid resale activity, and transactions with wallets flagged for suspicious activity.</li>
        <li><strong>Transaction Logging:</strong> All marketplace transactions are logged (with XRPL transaction hashes) for audit and compliance purposes.</li>
        <li><strong>Suspicious Activity Reporting:</strong> If IMU identifies transactions that it reasonably believes may involve money laundering, fraud, or other illegal activity, IMU may report such transactions to the Financial Crimes Enforcement Network (FinCEN) or other appropriate authorities.</li>
    </ul>
    <h3>A12.2 Know Your Customer (KYC)</h3>
    <p>IMU reserves the right to require identity verification for:</p>
    <ul>
        <li>All Creators before their first Listing</li>
        <li>Buyers conducting transactions exceeding a specified dollar threshold (as determined by IMU)</li>
        <li>Any User when IMU has reasonable grounds to suspect fraudulent, illegal, or sanctioned activity</li>
    </ul>
    <p>Failure to complete requested KYC verification will result in: suspension of listing privileges (for Creators), restriction of purchasing capabilities (for Buyers), or account suspension until verification is completed.</p>
    <h3>A12.3 OFAC Sanctions Screening</h3>
    <p>IMU screens marketplace participants against the following U.S. government sanctions lists:</p>
    <ul>
        <li>OFAC Specially Designated Nationals and Blocked Persons (SDN) List</li>
        <li>OFAC Sectoral Sanctions Identifications (SSI) List</li>
        <li>OFAC Consolidated Sanctions List</li>
    </ul>
    <p>Users located in, or nationals or residents of, comprehensively sanctioned countries (currently including Cuba, North Korea, Syria, and the Crimea, Donetsk, and Luhansk regions of Ukraine) are prohibited from using the Marketplace. IMU may implement IP-based geographic restrictions and wallet screening to enforce these prohibitions.</p>
    <div class="legal-notice warning"><strong>Consequences of Violations:</strong> Users found to have provided false KYC information, evaded sanctions screening, or used the Marketplace for money laundering or sanctions evasion will be immediately and permanently banned, and all available transaction data will be reported to relevant authorities.</div>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="ex-s13">
    <h2>A13. Platform Role &amp; Limitations</h2>
    <h3>A13.1 IMU as Marketplace Operator</h3>
    <p>IMU operates the IM Collectibles Marketplace as a technology platform that facilitates transactions between Creators and Buyers. IMU is NOT:</p>
    <ul>
        <li>A party to any transaction between a Creator and a Buyer</li>
        <li>A custodian, escrow agent, or fiduciary for any party</li>
        <li>A broker-dealer, investment adviser, or financial institution</li>
        <li>A guarantor of any Digital Collectible's value, authenticity, or quality</li>
        <li>A guarantor of any Creator-promised utility, access, or future benefits</li>
    </ul>
    <h3>A13.2 No Endorsement</h3>
    <p>The listing of a Digital Collectible on the Marketplace does not constitute an endorsement, recommendation, or approval by IMU of the Creator, the Associated Content, or the transaction. IMU's content review process verifies compliance with our policies, but does not constitute verification of artistic merit, market value, copyright ownership, or factual accuracy of Listing descriptions.</p>
    <h3>A13.3 Platform Availability</h3>
    <p>IMU does not guarantee that the Marketplace will be available at all times. The Marketplace may be subject to scheduled maintenance, unscheduled outages, or temporary suspension due to technical issues, security incidents, or regulatory requirements. IMU is not liable for any losses resulting from Marketplace downtime.</p>
    <h3>A13.4 First-Party Collections (Protectors of IMU)</h3>
    <p>IMU mints, lists, and sells certain Digital Collectibles directly through the Marketplace, including the Guardians of the Frequencies, Protectors of the Frequencies, Protectors of the Ledger, Protectors of Las Vegas, Firepit Protectors, Special Edition Protectors, and other IMU-branded collections (collectively, the "First-Party Collections" or "Protectors of IMU"). For First-Party Collection transactions, IMU acts as both the Marketplace operator and the Creator/Seller. The following provisions apply specifically to First-Party Collections:</p>
    <p><strong>(a) Dual Role Acknowledgment.</strong> The platform-neutrality limitations of Section A13.1 (which state that IMU is not a party to transactions between Creators and Buyers) do NOT apply to First-Party Collection transactions. For First-Party Collections, IMU is a direct party to the transaction as the Seller.</p>
    <p><strong>(b) Creator Warranties Made by IMU.</strong> The Creator representations and warranties set forth in Section A7.1 (Ownership, No Infringement, Original Work, Accurate Description, AI Disclosure, Legal Compliance) are made by IMU directly with respect to First-Party Collections.</p>
    <p><strong>(c) Creator Indemnification Inapplicable.</strong> The Creator indemnification provisions of Section A7.2 do not apply to First-Party Collection transactions, since there is no separate Creator to indemnify IMU.</p>
    <p><strong>(d) Disclaimer Scope.</strong> Notwithstanding the dual-role acknowledgment in this Section A13.4, the disclaimers and limitations of liability set forth in Section A14 (Limitation of Liability &amp; Disclaimers), including the "AS IS" provision, the disclaimer of warranties of title and merchantability, the disclaimer of any guarantee of value or future utility, the buyer's assumption of risk, and the aggregate liability cap, continue to apply to First-Party Collection transactions to the maximum extent permitted by applicable law.</p>
    <p><strong>(e) Securities and Investment Disclaimer Reaffirmed.</strong> First-Party Collections, like all Digital Collectibles on the Marketplace, are NOT securities, investment contracts, or financial instruments as set forth in Section A3.2. Holding a First-Party Collection NFT does not create any ownership interest, equity stake, profit share, governance right, or membership interest in IMU, LLC. Promotional rewards associated with First-Party Collections (including Frequency Fountain rewards, Burn 2 Earn eligibility, and any future Redeem distributions) are discretionary platform incentives, not dividends, yield, or investment returns, and are subject to the terms of the IM Collectibles Feature Addendum.</p>
    <p><strong>(f) DMCA Counter-Notification Process.</strong> For First-Party Collections, the DMCA counter-notification process described in Section A11.2 of these Sale Terms is initiated by IMU as the Creator/rights holder.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="ex-s14">
    <h2>A14. Limitation of Liability &amp; Disclaimers</h2>
    <h3>A14.1 Disclaimers</h3>
    <p class="legal-caps">THE IM COLLECTIBLES MARKETPLACE AND ALL DIGITAL COLLECTIBLES ARE PROVIDED "AS IS" AND "AS AVAILABLE" WITHOUT WARRANTIES OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING IMPLIED WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, TITLE, AND NON-INFRINGEMENT.</p>
    <p class="legal-caps">IMU DOES NOT WARRANT THAT: (A) THE MARKETPLACE WILL BE UNINTERRUPTED, ERROR-FREE, OR SECURE; (B) ANY DIGITAL COLLECTIBLE WILL RETAIN OR INCREASE IN VALUE; (C) THE ASSOCIATED CONTENT WILL REMAIN ACCESSIBLE INDEFINITELY; (D) THE XRPL NETWORK WILL FUNCTION AS INTENDED; OR (E) CREATOR-PROMISED UTILITY WILL BE DELIVERED.</p>
    <h3>A14.2 Limitation of Liability</h3>
    <p class="legal-caps">TO THE FULLEST EXTENT PERMITTED BY LAW, IMU SHALL NOT BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, EXEMPLARY, OR PUNITIVE DAMAGES ARISING OUT OF YOUR USE OF THE MARKETPLACE OR YOUR PURCHASE, SALE, OR OWNERSHIP OF DIGITAL COLLECTIBLES, INCLUDING BUT NOT LIMITED TO: LOSS OF VALUE, LOSS OF ACCESS TO DIGITAL ASSETS, LOSS OF PROFITS, WALLET COMPROMISE, BLOCKCHAIN NETWORK FAILURES, SMART CONTRACT BUGS, REGULATORY CHANGES, OR CREATOR MISCONDUCT.</p>
    <p class="legal-caps">IMU'S TOTAL AGGREGATE LIABILITY FOR ALL CLAIMS ARISING OUT OF OR RELATED TO THESE SALE TERMS OR THE MARKETPLACE SHALL NOT EXCEED THE GREATER OF: (A) THE PLATFORM FEES ACTUALLY RECEIVED BY IMU FROM YOUR TRANSACTIONS IN THE TWELVE (12) MONTHS PRECEDING THE CLAIM; OR (B) ONE HUNDRED DOLLARS ($100).</p>
    <h3>A14.3 Buyer's Assumption of Risk</h3>
    <p>You expressly assume all risks associated with purchasing, owning, and transacting in Digital Collectibles, including all risks described in Section A8.4. You agree that IMU shall not be liable for any losses arising from market volatility, technology failures, regulatory changes, Creator misconduct, or any other risks inherent in blockchain-based digital assets.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="ex-s15">
    <h2>A15. Dispute Resolution</h2>
    <h3>A15.1 Creator-Buyer Disputes</h3>
    <p>Disputes between Creators and Buyers regarding Digital Collectibles (including disputes about the quality, accuracy, or utility of the Associated Content) are the responsibility of the parties involved. IMU is not a party to Creator-Buyer transactions and does not mediate commercial disputes. However, if a dispute involves a potential violation of these Sale Terms or the Terms of Service (e.g., fraudulent Listing, IP infringement), IMU will investigate and take appropriate enforcement action.</p>
    <h3>A15.2 Disputes with IMU</h3>
    <p>Any dispute between you and IMU arising out of or relating to these Sale Terms or the Marketplace shall be resolved in accordance with the dispute resolution provisions of the IMU Terms of Service, including the binding arbitration clause and class action waiver (Terms of Service, Section 34). To recap:</p>
    <ul>
        <li><strong>Informal Resolution First:</strong> Contact <a href="mailto:legal@imutv.tv">legal@imutv.tv</a> and attempt to resolve the dispute informally for at least thirty (30) days.</li>
        <li><strong>Binding Arbitration:</strong> Unresolved disputes are subject to binding arbitration administered by the American Arbitration Association (AAA) in the State of Florida.</li>
        <li><strong>Class Action Waiver:</strong> You and IMU agree to bring claims only in individual capacity, not as part of any class or representative action.</li>
        <li><strong>Exception:</strong> Either party may seek injunctive relief in court for intellectual property infringement.</li>
    </ul>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="ex-s16">
    <h2>A16. Governing Law</h2>
    <p>These Sale Terms shall be governed by and construed in accordance with the laws of the State of Florida, without regard to its conflict of laws provisions. Legal proceedings not subject to arbitration shall be brought exclusively in the state or federal courts located in the State of Florida, and you consent to personal jurisdiction in such courts.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="ex-s17">
    <h2>A17. Modifications to These Sale Terms</h2>
    <p>IMU reserves the right to modify these Sale Terms at any time. We will provide at least thirty (30) days' notice of material changes by posting the updated Sale Terms on the Marketplace and, where possible, by email notification. Your continued use of the Marketplace after the effective date constitutes acceptance. Transactions completed before the effective date of any change remain governed by the Sale Terms in effect at the time of the transaction.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="ex-s18">
    <h2>A18. Account Termination &amp; Marketplace Removal</h2>
    <h3>A18.1 Termination by IMU</h3>
    <p>IMU may suspend or permanently terminate your access to the IM Collectibles Marketplace for violation of these Sale Terms, the Terms of Service, or the Community Guidelines. Upon termination:</p>
    <ul>
        <li>Your ability to list, purchase, bid on, and browse Digital Collectibles on the Marketplace will be disabled.</li>
        <li>Outstanding Listings will be removed.</li>
        <li>Digital Collectibles already in your XRPL wallet will remain in your wallet (IMU cannot remove on-chain tokens).</li>
        <li>Any pending Platform Fee payments or Creator Royalty distributions may be forfeited.</li>
        <li>You retain the ability to interact with your NFTs through other XRPL-compatible interfaces, subject to any legal restrictions.</li>
    </ul>
    <h3>A18.2 Voluntary Account Deletion</h3>
    <p>If you delete your IMU account, your Listings will be removed from the Marketplace. Digital Collectibles in your wallet remain yours. The association between your IMU account and your wallet address will be removed from our systems, but on-chain transaction history is permanent and immutable.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="ex-s19">
    <h2>A19. Contact Information</h2>
    <p>For questions about these Sale Terms, the IM Collectibles Marketplace, or to report a violation:</p>
    <div class="legal-contact-box">
        <h3>IM Collectibles Marketplace</h3>
        <p><strong>Website:</strong> <a href="https://www.imcollectibles.io" target="_blank" rel="noopener">www.imcollectibles.io</a></p>
        <p><strong>Legal &amp; Compliance:</strong> <a href="mailto:legal@imutv.tv">legal@imutv.tv</a></p>
        <p><strong>Copyright / DMCA:</strong> <a href="mailto:dmca@imutv.tv">dmca@imutv.tv</a></p>
        <p><strong>Creator Support:</strong> <a href="mailto:creators@imutv.tv">creators@imutv.tv</a></p>
        <p><strong>General:</strong> <a href="mailto:contact@imutv.tv">contact@imutv.tv</a></p>
    </div>
    <div class="legal-footer-note" style="margin-top:1.5rem;">Last Updated: February 22, 2026 &nbsp;&middot;&nbsp; <a href="#toc">&#8593; Back to Table of Contents</a></div>
</div>

<!-- ===================== SCHEDULE C ===================== -->
<div class="legal-exhibit-header" id="schedule-c">
    <div class="exhibit-label">Schedule &quot;C&quot; &mdash; Attached to IMU Terms of Service for IM Collectibles</div>
    <h2>IM Collectibles Feature Addendum</h2>
    <p>Burn 2 Earn &bull; Frequency Fountain &bull; Tasks &amp; Rewards &bull; Redeem &bull; Airdrops &bull; IMU Gaming &mdash; Effective Date: February 22, 2026</p>
</div>

<div class="legal-notice">THIS FEATURE ADDENDUM SUPPLEMENTS AND IS INCORPORATED INTO THE IMU, LLC TERMS OF SERVICE AND THE IM COLLECTIBLES TERMS OF SALE. IN THE EVENT OF A CONFLICT, THIS FEATURE ADDENDUM SHALL CONTROL WITH RESPECT TO THE SPECIFIC FEATURES DESCRIBED HEREIN.</div>
<br>
<div class="legal-notice">BY USING ANY OF THE FEATURES, YOU AGREE TO BE BOUND BY THIS FEATURE ADDENDUM.</div>
<br>

<div class="legal-section" id="sc-s1">
    <h2>C1. Scope and Relationship to Other Documents</h2>
    <p>This IM Collectibles Feature Addendum ("Feature Addendum") supplements and is incorporated into the IMU, LLC Terms of Service and the IM Collectibles Terms of Sale. This Feature Addendum governs the following promotional and engagement features available on the IM Collectibles marketplace at www.imcollectibles.io and the broader IMU ecosystem: Burn 2 Earn, Frequency Fountain, Tasks &amp; Rewards, Redeem, IMU Airdrops, and Champion of Frequencies (IMU Gaming) (collectively, the "Features").</p>
    <p>In the event of a conflict between this Feature Addendum and the Terms of Service or Terms of Sale, this Feature Addendum shall control with respect to the specific Features described herein. Capitalized terms used but not defined in this Feature Addendum have the meanings assigned in the Terms of Service or Terms of Sale.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sc-s2">
    <h2>C2. General Provisions Applicable to All Features</h2>
    <h3>C2.1 Promotional Programs</h3>
    <p>Each of the Features is a promotional engagement program operated by IMU at its discretion. The Features are not contractual entitlements, and IMU may modify, suspend, or terminate any Feature at any time, with or without notice, except as expressly required by this Feature Addendum.</p>
    <h3>C2.2 No Securities, No Investment Contract</h3>
    <p class="legal-caps">NONE OF THE FEATURES, INCLUDING ANY REWARDS, POINTS, TOKENS, OR NFTS DISTRIBUTED THROUGH THEM, CONSTITUTE SECURITIES, INVESTMENT CONTRACTS, COMMODITIES, SWAPS, OR FINANCIAL INSTRUMENTS UNDER U.S. FEDERAL OR STATE LAW. PARTICIPATION IN ANY FEATURE DOES NOT CREATE AN INVESTMENT RELATIONSHIP BETWEEN YOU AND IMU. YOU SHOULD NOT PARTICIPATE IN ANY FEATURE WITH AN EXPECTATION OF PROFIT, INVESTMENT RETURN, OR INCOME GENERATION.</p>
    <h3>C2.3 No Guaranteed Value</h3>
    <p>IMU makes no representations or guarantees regarding the monetary value, market demand, or future utility of any rewards distributed through the Features, including XFT tokens, points, NFTs, or any other items. All rewards may have zero monetary value.</p>
    <h3>C2.4 Tax Responsibility</h3>
    <p>You are solely responsible for determining and fulfilling any tax obligations arising from your participation in the Features, including the receipt of XFT tokens, NFTs, or other items of value. Receipt of tokens or NFTs may constitute taxable income at fair market value upon receipt under U.S. tax law. IMU does not provide tax advice. Consult a qualified tax professional.</p>
    <h3>C2.5 Eligibility</h3>
    <p>Participation in the Features is restricted to Users who are at least eighteen (18) years of age, in compliance with the OFAC sanctions screening provisions of the Terms of Service and not otherwise prohibited from using the Service.</p>
    <h3>C2.6 Anti-Gaming</h3>
    <p>The use of bots, automated scripts, multiple accounts, or any other artificial means to gain rewards or manipulate Feature outcomes is strictly prohibited and may result in forfeiture of all rewards and account termination.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sc-s3">
    <h2>C3. Burn 2 Earn</h2>
    <p>"Burn 2 Earn" is a promotional program that allows holders of qualifying Protector NFTs to permanently destroy ("burn") ten (10) qualifying NFTs in exchange for a custom 1-of-1 Frequency Firepit Protector NFT ("Firepit Protector") with Guardian-tier reward eligibility.</p>
    <h3>C3.1 Eligible NFTs</h3>
    <p>Only the following IMU first-party NFT collections are eligible for Burn 2 Earn: Protectors of the Frequencies and Protectors of the Ledger. Other NFT collections (including Guardians of the Frequencies, third-party Creator NFTs, and Digital Collectibles) are NOT eligible. Eligibility is determined by IMU and may change at IMU's discretion.</p>
    <h3>C3.2 Burn Process</h3>
    <p><strong>C3.2.1 Selection.</strong> The User selects ten (10) eligible NFTs from their connected XRPL wallet for burning. The selection is made through the Burn 2 Earn interface at www.imcollectibles.io/burn-to-earn/.</p>
    <p><strong>C3.2.2 Design Submission.</strong> The User submits a design request for their custom Firepit Protector, including: a 200-character maximum design description, background selection, optional reference image (5MB maximum), an XRPL wallet address, an X (Twitter) handle, and an email address.</p>
    <p><strong>C3.2.3 Design Review.</strong> IMU reviews submitted designs at its sole discretion. IMU reserves the right to approve, reject, or request modifications to any design submission for any reason, including but not limited to: violation of the Acceptable Use Policy, infringement of third-party intellectual property, technical infeasibility, or inconsistency with the Protectors brand aesthetic.</p>
    <p><strong>C3.2.4 Burn Confirmation.</strong> Upon design approval, the User is prompted to sign the burn transactions through their connected XRPL wallet. The User must explicitly confirm each burn transaction.</p>
    <div class="legal-notice warning"><strong>WARNING:</strong> THE BURN ACTION IS PERMANENT AND IRREVERSIBLE. ONCE A BURN TRANSACTION IS CONFIRMED ON THE XRPL, THE BURNED NFTS ARE DESTROYED AND CANNOT BE RECOVERED.</div>
    <p><strong>C3.2.5 Firepit Protector Mint.</strong> Upon successful burning of all ten (10) NFTs, IMU will mint and deliver the custom Firepit Protector NFT to the User's connected XRPL wallet within thirty (30) calendar days of design approval and burn confirmation. Delivery timing may be affected by XRPL network conditions, design complexity, or operational factors.</p>
    <h3>C3.3 Design Rejection</h3>
    <p>If a User's design submission is rejected, the User may submit a revised design or a different design at no additional cost. NO BURNS OCCUR until a design has been approved. If a User chooses not to submit a revised design after rejection, no burns occur and no Firepit Protector is minted.</p>
    <h3>C3.4 Guardian-Tier Reward Eligibility</h3>
    <p>Firepit Protectors are minted with Guardian-tier reward eligibility, meaning they qualify for the same Frequency Fountain reward rate as Guardians of the Frequencies NFTs (see Section C4 below). Guardian-tier eligibility is encoded in the Firepit Protector's metadata at minting and persists through subsequent transfers, except as IMU may modify in accordance with Section C2.1.</p>
    <h3>C3.5 License and IP</h3>
    <p><strong>C3.5.1</strong> The license to the Associated Content of each burned NFT terminates upon burning. The Firepit Protector is a new digital asset with its own license terms as set forth in the IM Collectibles Terms of Sale, Section A6.</p>
    <p><strong>C3.5.2</strong> Custom design elements submitted by the User and incorporated into the Firepit Protector remain subject to IMU's standard NFT license framework. By submitting a design, the User grants IMU a perpetual, worldwide, royalty-free license to use the design in connection with the minted Firepit Protector and IMU's promotional materials. The User represents and warrants that any submitted design or reference image does not infringe third-party intellectual property rights.</p>
    <h3>C3.6 No Refunds</h3>
    <p>Burn 2 Earn participation is non-refundable. Burned NFTs cannot be restored under any circumstances. If the Firepit Protector cannot be delivered due to a technical failure on IMU's platform (not due to XRPL network issues, user wallet issues, or design rejection), IMU's sole obligation is to attempt redelivery or, at its discretion, mint a substitute Firepit Protector with equivalent Guardian-tier eligibility.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sc-s4">
    <h2>C4. Frequency Fountain</h2>
    <p>"Frequency Fountain" is a daily XFT distribution program for holders of qualifying IMU NFTs and XFT tokens. Frequency Fountain rewards are claimable every twenty-four (24) hours based on holdings recorded at the time of each user's claim.</p>
    <h3>C4.1 Eligible Holdings</h3>
    <p><strong>C4.1.1 Qualifying NFTs.</strong> The following IMU first-party NFT collections qualify for Frequency Fountain rewards: Guardians of the Frequencies, Protectors of the Frequencies, Protectors of the Ledger, Protectors of Las Vegas, Firepit Protectors (minted via Burn 2 Earn), and Special Edition Protectors. Reward rates may vary by collection, with Guardian-tier collections (Guardians of the Frequencies and Firepit Protectors) typically receiving higher per-NFT rates than Protector-tier collections.</p>
    <p><strong>C4.1.2 Qualifying XFT.</strong> Holders of XFT tokens in their connected XRPL wallet at the time of the user's claim also qualify for Frequency Fountain rewards based on their XFT balance.</p>
    <p><strong>C4.1.3 Wallet Connection Required.</strong> To receive Frequency Fountain rewards, the User must have an XRPL wallet connected to their IMU account, and the wallet must hold the qualifying NFTs and/or XFT tokens at the time of the users claim.</p>
    <h3>C4.2 Reward Distribution</h3>
    <p><strong>C4.2.1 User's Claim.</strong> It is the responsibility of the user to claim their rewards.</p>
    <p><strong>C4.2.2 Reward Calculation.</strong> Reward amounts are calculated based on the number and type of qualifying NFTs held and the XFT balance held at the time of user's claim. The exact reward formula, rates, and any rate adjustments are determined by IMU at its sole discretion and may be modified at any time.</p>
    <p><strong>C4.2.3 Distribution.</strong> Approved rewards are distributed via XRPL transactions to the User's connected wallet at the time of user's claim. If a trustline to the XFT issuer is not set, these rewards are not delivered and forfeited.</p>
    <p><strong>C4.2.4 Minimum Threshold and Carryover.</strong> The Frequency Fountain may apply a minimum payout threshold and carryover policy similar to the XFT Streaming Rewards Program. Pending balances below the threshold accumulate across daily distributions until the threshold is met.</p>
    <h3>C4.3 Securities Disclaimer</h3>
    <p class="legal-caps">FREQUENCY FOUNTAIN REWARDS ARE PROMOTIONAL DISTRIBUTIONS, NOT DIVIDENDS, INTEREST, YIELD, OR INVESTMENT RETURNS. FREQUENCY FOUNTAIN DOES NOT CREATE A SECURITY, INVESTMENT CONTRACT, OR ANY OTHER FINANCIAL INSTRUMENT. HOLDING IMU NFTS OR XFT TOKENS DOES NOT ENTITLE YOU TO ANY OWNERSHIP INTEREST, PROFIT SHARE, OR GOVERNANCE RIGHT IN IMU, LLC.</p>
    <p>Frequency Fountain rewards are distributed at IMU's sole discretion as a platform engagement incentive. The rewards do not result from the entrepreneurial or managerial efforts of IMU within the meaning of the Howey Test — they are simply a promotional benefit IMU provides to active community members. IMU may modify, reduce, or eliminate Frequency Fountain rewards at any time, and Users have no contractual right to continued distributions.</p>
    <p class="legal-caps">YOU SHOULD NOT ACQUIRE IMU NFTS OR XFT TOKENS WITH AN EXPECTATION OF EARNING FREQUENCY FOUNTAIN REWARDS OR DERIVING FINANCIAL BENEFIT FROM THEM. PAST DISTRIBUTIONS ARE NOT INDICATIVE OF FUTURE DISTRIBUTIONS.</p>
    <h3>C4.4 Modification and Termination</h3>
    <p>IMU reserves the right to modify Frequency Fountain reward rates, eligible collections, distribution mechanics, and any other aspect of the program at any time, with or without notice. IMU may suspend or permanently terminate the Frequency Fountain at any time. Upon termination, any pending undistributed rewards above the minimum threshold (if any) will be distributed in a final batch within thirty (30) days. Pending rewards below the minimum threshold are forfeited at termination.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sc-s5">
    <h2>C5. Tasks &amp; Rewards</h2>
    <p>"Tasks &amp; Rewards" is a promotional engagement program in which Users can complete designated tasks ("Tasks") on the IM Collectibles platform to earn points ("Points") and/or XFT tokens. Points and XFT earned through Tasks &amp; Rewards are distributed at IMU's discretion and are subject to the rules set forth in this Section C5.</p>
    <h3>C5.1 Tasks</h3>
    <p><strong>C5.1.1</strong> Tasks are designated by IMU and displayed in the Tasks &amp; Rewards interface. Tasks may include actions such as connecting a wallet, completing a profile, minting an NFT, making a purchase, sharing content on social media, referring new Users, or participating in community events.</p>
    <p><strong>C5.1.2</strong> Task availability, point values, and XFT rewards are determined by IMU and may change at any time. Some Tasks may be one-time only, while others may be repeatable on a daily, weekly, or other periodic basis.</p>
    <p><strong>C5.1.3</strong> IMU reserves the right to verify Task completion before crediting points or XFT. Tasks completed in a manner inconsistent with their intended purpose, or completed through artificial or fraudulent means, will not be credited.</p>
    <h3>C5.2 Points</h3>
    <p><strong>C5.2.1 Nature of Points.</strong> Points are a promotional engagement metric and a unit of account within the Tasks &amp; Rewards program. Points are not currency, are not legal tender, and have no cash value. Points cannot be transferred, sold, or assigned to any other person.</p>
    <p><strong>C5.2.2 Conversion to XFT.</strong> Points may be converted to XFT tokens through the Tasks &amp; Rewards interface at a conversion rate determined by IMU. The conversion rate is subject to change at any time. Once converted to XFT, the XFT tokens are subject to the same terms and disclaimers applicable to all XFT distributions, including the absence of any guaranteed monetary value.</p>
    <p><strong>C5.2.3 Point Expiration.</strong> IMU reserves the right to expire unused points after a period of inactivity. Currently, points do not expire, but this policy may be modified with thirty (30) days' prior notice.</p>
    <p><strong>C5.2.4 Forfeiture.</strong> Points may be forfeited in their entirety upon: (a) account termination for violation of the Terms of Service; (b) confirmed fraudulent or anti-gaming activity; or (c) IMU's discontinuation of the Tasks &amp; Rewards program (subject to a thirty (30) day wind-down period during which Users may convert points to XFT).</p>
    <h3>C5.3 Leaderboard</h3>
    <p>Tasks &amp; Rewards may include a public leaderboard displaying top point earners. Participation in the leaderboard is a feature of the program. Users who do not wish to appear on the leaderboard may opt out through their account settings (where supported).</p>
    <h3>C5.4 No Securities, No Money Transmission</h3>
    <p class="legal-caps">POINTS AND XFT EARNED THROUGH TASKS &amp; REWARDS ARE PROMOTIONAL REWARDS, NOT SECURITIES, NOT INVESTMENT CONTRACTS, AND NOT VIRTUAL CURRENCY FOR PURPOSES OF STATE MONEY TRANSMITTER LICENSING. POINTS HAVE NO MONETARY VALUE. THE TASKS &amp; REWARDS PROGRAM IS NOT A WAGERING, SWEEPSTAKES, OR LOTTERY ARRANGEMENT.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sc-s6">
    <h2>C6. Redeem (NFT Holder Ad Revenue Distribution)</h2>
    <p>"Redeem" is a future promotional program through which holders of qualifying IMU NFTs may receive a portion of advertising revenue generated by IMU once paid advertising is launched on IMUTV. The Redeem program is not currently active and will be activated by IMU at a future date.</p>
    <h3>C6.1 Activation Conditions</h3>
    <p>The Redeem program will become active when IMU has secured paid advertisers and begins generating advertising revenue on IMUTV at a level sufficient, in IMU's sole discretion, to support distributions. IMU makes no commitment as to when, or whether, the Redeem program will become active. Until activation, the Redeem feature page may display program information but no distributions will occur.</p>
    <h3>C6.2 Eligible Holdings</h3>
    <p>Eligibility for Redeem distributions will be determined by IMU prior to activation and may include holders of specified IMU NFT collections, XFT token holders, or other participant categories. Specific eligibility criteria, distribution rates, and qualifying snapshot mechanics will be published before the program becomes active.</p>
    <h3>C6.3 Distribution Mechanics</h3>
    <p><strong>C6.3.1</strong> Redeem distributions, when active, will be calculated based on a percentage of net advertising revenue determined by IMU at its sole discretion. "Net advertising revenue" means gross advertising revenue received by IMU from paid advertisers, less applicable platform costs, payment processing fees, ad network commissions, taxes, and operational expenses.</p>
    <p><strong>C6.3.2</strong> Distributions, when active, will be paid in XFT or other tokens or credits as determined by IMU. Distributions will be made to the connected XRPL wallets of eligible participants on a schedule determined by IMU.</p>
    <p><strong>C6.3.3</strong> IMU reserves the absolute right to determine, modify, suspend, or terminate the Redeem program at any time, including before activation. Activation of the Redeem program is not guaranteed.</p>
    <h3>C6.4 Securities Disclaimer — Critical</h3>
    <p class="legal-caps">THE REDEEM PROGRAM IS A PROMOTIONAL DISTRIBUTION DESIGNED TO REWARD ACTIVE COMMUNITY PARTICIPATION. REDEEM DISTRIBUTIONS ARE NOT DIVIDENDS, NOT PROFIT SHARES, NOT INTEREST, NOT YIELD, AND NOT SECURITIES. HOLDING AN IMU NFT OR XFT TOKEN DOES NOT CREATE OR REPRESENT AN OWNERSHIP INTEREST, EQUITY STAKE, MEMBERSHIP INTEREST, OR ANY OTHER PROPRIETARY RIGHT IN IMU, LLC.</p>
    <p class="legal-caps">REDEEM DISTRIBUTIONS, IF AND WHEN MADE, ARE DISCRETIONARY PROMOTIONAL PAYMENTS BY IMU. THEY DO NOT ARISE FROM ANY CONTRACTUAL ENTITLEMENT, ARE NOT REQUIRED BY LAW, AND CAN BE MODIFIED, REDUCED, OR ELIMINATED AT IMU'S SOLE DISCRETION AT ANY TIME. NO USER HAS ANY VESTED RIGHT TO RECEIVE ANY REDEEM DISTRIBUTION.</p>
    <p class="legal-caps">YOU SHOULD NOT PURCHASE, MINT, OR HOLD ANY IMU NFT OR XFT TOKEN WITH ANY EXPECTATION OF RECEIVING REDEEM DISTRIBUTIONS OR DERIVING FINANCIAL BENEFIT FROM THEM. THE REDEEM PROGRAM MAY NEVER BE ACTIVATED. EVEN IF ACTIVATED, DISTRIBUTIONS MAY BE NEGLIGIBLE OR ZERO. PAST PROMOTIONAL DISTRIBUTIONS BY IMU ARE NOT INDICATIVE OF FUTURE DISTRIBUTIONS.</p>
    <p>Notwithstanding the foregoing, IMU reserves the right to structure the Redeem program differently or to abandon it entirely if doing so is necessary to comply with applicable law, including securities, money transmission, gaming, or consumer protection regulations.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sc-s7">
    <h2>C7. IMU Airdrop</h2>
    <p>"IMU Airdrop" refers to promotional distributions of XFT tokens or other digital assets to eligible Users, made available for claim through the IMU Airdrop interface at airdrop.imcollectibles.io or such other URL as IMU may designate.</p>
    <h3>C7.1 Eligibility</h3>
    <p><strong>C7.1.1</strong> Airdrop eligibility is determined by IMU at its sole discretion and may be based on factors such as: prior platform engagement, holding of specific IMU NFTs, completion of specific Tasks, participation in promotional events, or other criteria announced at the time of the airdrop.</p>
    <p><strong>C7.1.2</strong> Airdrop participants must: (a) have a connected XRPL wallet; (b) be at least eighteen (18) years of age; (c) not be located in or a resident of any OFAC-sanctioned jurisdiction; and (d) not be on any U.S. government sanctions list.</p>
    <h3>C7.2 Claim Mechanics</h3>
    <p><strong>C7.2.1</strong> Eligible Users may claim their airdrop allocation through the airdrop interface during the claim window. The claim window has a defined start and end date set by IMU.</p>
    <p><strong>C7.2.2</strong> Users must initiate the claim transaction through their connected XRPL wallet. Users are responsible for any XRPL network fees associated with the claim transaction.</p>
    <p><strong>C7.2.3</strong> Unclaimed airdrop allocations expire at the end of the claim window. Expired allocations are forfeited and may be redistributed or burned at IMU's discretion.</p>
    <h3>C7.3 Tax Disclosure</h3>
    <p class="legal-caps">RECEIPT OF AN AIRDROPPED TOKEN MAY CONSTITUTE TAXABLE INCOME UNDER U.S. FEDERAL TAX LAW. THE IRS GENERALLY TREATS AIRDROPPED CRYPTOCURRENCIES AS ORDINARY INCOME AT THEIR FAIR MARKET VALUE ON THE DATE OF RECEIPT. YOU ARE SOLELY RESPONSIBLE FOR DETERMINING AND FULFILLING ANY TAX OBLIGATIONS RESULTING FROM YOUR PARTICIPATION IN AN AIRDROP. CONSULT A QUALIFIED TAX PROFESSIONAL.</p>
    <p>IMU will include airdrop distributions in IRS Form 1099 reporting in accordance with Schedule A, Section SA11 of the Terms of Service if applicable thresholds are met.</p>
    <h3>C7.4 No Investment, No Solicitation</h3>
    <p class="legal-caps">AIRDROPPED TOKENS ARE PROMOTIONAL DISTRIBUTIONS DESIGNED TO REWARD COMMUNITY ENGAGEMENT. THEY ARE NOT OFFERED OR SOLD AS INVESTMENTS. IMU MAKES NO REPRESENTATIONS REGARDING THE FUTURE VALUE OF AIRDROPPED TOKENS. AIRDROPPED TOKENS MAY HAVE ZERO MONETARY VALUE.</p>
    <h3>C7.5 Modification and Cancellation</h3>
    <p>IMU reserves the right to modify airdrop terms, eligibility criteria, allocation amounts, or claim windows at any time before or during the claim window. IMU may cancel an airdrop entirely at any time, including after eligibility has been announced. In the event of cancellation, no claims will be processed.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sc-s8">
    <h2>C8. Champion of Frequencies (IMU Gaming)</h2>
    <p>"Champion of Frequencies" is a skill-based gaming feature operated by IMU through which Users may earn points and XFT tokens by participating in gameplay activities. Champion of Frequencies is part of the IMU Gaming offering and is accessible through the IM Collectibles platform.</p>
    <h3>C8.1 Skill-Based, Not a Game of Chance</h3>
    <p><strong>C8.1.1</strong> Champion of Frequencies is a skill-based game. Outcomes are determined by player skill, strategy, and gameplay performance, not by chance, random selection, or wagering.</p>
    <p class="legal-caps"><strong>C8.1.2</strong> CHAMPION OF FREQUENCIES IS NOT A GAMBLING ACTIVITY, LOTTERY, SWEEPSTAKES, OR GAME OF CHANCE. NO PURCHASE, ENTRY FEE, WAGER, OR "CONSIDERATION" IS REQUIRED TO PARTICIPATE OR TO EARN REWARDS. PARTICIPATION IS FREE TO ALL ELIGIBLE USERS.</p>
    <p><strong>C8.1.3</strong> Champion of Frequencies does not involve any element of risk that would classify it as gambling under U.S. federal law (including the Unlawful Internet Gambling Enforcement Act, 31 U.S.C. § 5361 et seq.) or under Florida law (including Chapter 849 of the Florida Statutes).</p>
    <h3>C8.2 Eligibility</h3>
    <p>Participation in Champion of Frequencies is restricted to Users who: (a) are at least eighteen (18) years of age; (b) have a registered IMU account; (c) are not located in or a resident of any OFAC-sanctioned jurisdiction; and (d) are not otherwise prohibited from using the Service. Users in jurisdictions where skill-based gaming with token rewards is restricted by law may be excluded from participation at IMU's discretion.</p>
    <h3>C8.3 Rewards</h3>
    <p><strong>C8.3.1 Points and XFT.</strong> Players may earn points and XFT tokens through gameplay achievements, including but not limited to: completing levels, achieving high scores, completing challenges, participating in tournaments, or other gameplay milestones determined by IMU.</p>
    <p><strong>C8.3.2 Points-to-XFT Conversion.</strong> Points earned in Champion of Frequencies may be converted to XFT tokens through the gameplay interface or through the broader Tasks &amp; Rewards system at conversion rates determined by IMU. Conversion rates are subject to change at any time.</p>
    <p><strong>C8.3.3 Reward Distribution.</strong> Earned XFT is distributed to the User's connected XRPL wallet according to the same payout mechanics described in the XFT Streaming Rewards Program Terms, including minimum payout thresholds and carryover policies.</p>
    <h3>C8.4 Anti-Cheat</h3>
    <p>The use of cheats, exploits, automation, third-party software, or any other means to gain an unfair advantage in Champion of Frequencies is strictly prohibited. Confirmed cheating will result in: (a) forfeiture of all earned points and XFT; (b) account suspension or permanent termination; and (c) potential disqualification from other IMU promotional programs.</p>
    <h3>C8.5 Intellectual Property</h3>
    <p><strong>C8.5.1</strong> Champion of Frequencies and all related game assets, characters, artwork, code, and audio are owned by IMU, LLC and protected by copyright and other intellectual property laws. Users receive a limited, non-exclusive, non-transferable license to play the game for personal, non-commercial purposes.</p>
    <p><strong>C8.5.2</strong> Any user-generated content created within Champion of Frequencies (including custom characters, levels, replays, screenshots, and recordings) is governed by the same content license framework set forth in Part II of the Terms of Service. By creating or uploading user-generated content within the game, you grant IMU the licenses described therein.</p>
    <h3>C8.6 Modification and Termination</h3>
    <p>IMU reserves the right to modify game mechanics, reward rates, eligible activities, or any other aspect of Champion of Frequencies at any time. IMU may suspend or permanently terminate Champion of Frequencies at any time, with or without notice. Upon termination, any pending undistributed XFT rewards above the minimum payout threshold will be distributed in a final batch within thirty (30) days.</p>
    <h3>C8.7 No Securities, No Investment</h3>
    <p class="legal-caps">REWARDS EARNED IN CHAMPION OF FREQUENCIES, INCLUDING POINTS AND XFT, ARE PROMOTIONAL ENGAGEMENT REWARDS. THEY ARE NOT SECURITIES, NOT INVESTMENT CONTRACTS, AND NOT WAGERING WINNINGS. PLAYERS SHOULD NOT PARTICIPATE IN CHAMPION OF FREQUENCIES WITH AN EXPECTATION OF FINANCIAL RETURN.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sc-s9">
    <h2>C9. General Provisions</h2>
    <h3>C9.1 Modification of This Feature Addendum</h3>
    <p>IMU reserves the right to modify this Feature Addendum at any time. We will provide at least thirty (30) days' notice of material changes by posting the updated Feature Addendum on the Service and, where possible, by email notification. Continued participation in any Feature after the effective date constitutes acceptance.</p>
    <h3>C9.2 Suspension and Termination of Features</h3>
    <p>IMU reserves the right to suspend or permanently terminate any Feature at any time. Upon termination of a Feature, any pending undistributed rewards above the applicable minimum threshold will be distributed in a final batch within thirty (30) days, except as otherwise stated in this Feature Addendum. Pending rewards below the minimum threshold or unredeemed Points (in the case of Tasks &amp; Rewards termination) are forfeited unless a wind-down period is provided per Section C5.2.4.</p>
    <h3>C9.3 Disclaimers and Limitation of Liability</h3>
    <p class="legal-caps">THE FEATURES ARE PROVIDED "AS IS" AND "AS AVAILABLE." IMU MAKES NO WARRANTIES, EXPRESS OR IMPLIED, REGARDING THE FEATURES, INCLUDING WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, OR NON-INFRINGEMENT.</p>
    <p class="legal-caps">IMU SHALL NOT BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES ARISING FROM YOUR PARTICIPATION IN ANY FEATURE, INCLUDING BUT NOT LIMITED TO: LOSS OF VALUE OF DISTRIBUTED REWARDS, FAILURE TO RECEIVE EXPECTED REWARDS, BURN TRANSACTION FAILURES, DESIGN REJECTIONS, PROGRAM TERMINATIONS, OR CHANGES TO REWARD MECHANICS.</p>
    <p class="legal-caps">IMU'S TOTAL AGGREGATE LIABILITY RELATED TO THE FEATURES SHALL NOT EXCEED THE FAIR MARKET VALUE OF REWARDS ACTUALLY DISTRIBUTED TO YOU IN THE TWELVE (12) MONTHS PRECEDING THE CLAIM, OR ONE HUNDRED DOLLARS ($100), WHICHEVER IS GREATER.</p>
    <h3>C9.4 Governing Law and Disputes</h3>
    <p>This Feature Addendum is governed by the laws of the State of Florida. Any dispute arising from a Feature is subject to the dispute resolution and arbitration provisions of the IMU Terms of Service, Section 34, including the binding arbitration clause and class action waiver.</p>
    <h3>C9.5 Contact</h3>
    <div class="legal-contact-box">
        <h3>Schedule C &mdash; Feature Addendum</h3>
        <p><strong>Legal &amp; Compliance:</strong> <a href="mailto:legal@imutv.tv">legal@imutv.tv</a></p>
        <p><strong>Creator Support:</strong> <a href="mailto:creators@imutv.tv">creators@imutv.tv</a></p>
        <p><strong>General:</strong> <a href="mailto:contact@imutv.tv">contact@imutv.tv</a></p>
    </div>
    <div class="legal-footer-note" style="margin-top:1.5rem;">Last Updated: February 22, 2026 &nbsp;&middot;&nbsp; <a href="#toc">&#8593; Back to Table of Contents</a></div>
</div>

<!-- ===================== SCHEDULE D ===================== -->
<div class="legal-exhibit-header" id="schedule-d">
    <div class="exhibit-label">Schedule &quot;D&quot; &mdash; Attached to IMU Terms of Service for IM Collectibles</div>
    <h2>XFT Streaming Rewards Program Terms</h2>
    <p>Distribution of XFT tokens to eligible viewers and Creators based on streaming activity across IMUTV and IMUP3 &mdash; Effective Date: April 13, 2026</p>
</div>

<div class="legal-section" id="sd-s1">
    <h2>D1. Program Description</h2>
    <p><strong>D1.1</strong> IMU operates an XFT Streaming Rewards Program ("Rewards Program") that distributes XFT tokens to eligible viewers and Creators based on streaming activity across the IMUTV and IMUP3 platforms. The Rewards Program is designed to incentivize platform engagement and reward both content consumers and content producers.</p>
    <p><strong>D1.2</strong> These XFT Streaming Rewards Program Terms ("Reward Terms") supplement and are incorporated into the IMU Terms of Service. Participation in the Rewards Program constitutes acceptance of these Reward Terms. Capitalized terms used but not defined herein have the meanings assigned in the Terms of Service.</p>
    <p><strong>D1.3</strong> The Rewards Program is separate from, but may operate alongside, other IMU reward mechanisms including the Frequency Fountain, Tasks &amp; Rewards, and any promotional airdrop programs. Each such program is governed by its own terms.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sd-s2">
    <h2>D2. Nature of XFT</h2>
    <p class="legal-caps">XFT IS AN ISSUED CURRENCY (IOU) ON THE XRP LEDGER, ISSUED BY IMU, LLC. XFT IS A UTILITY REWARD TOKEN DISTRIBUTED AS AN INCENTIVE FOR PLATFORM ENGAGEMENT.</p>
    <p class="legal-caps">XFT IS NOT A SECURITY, INVESTMENT CONTRACT, COMMODITY, SWAP, OR FINANCIAL INSTRUMENT AS DEFINED UNDER U.S. FEDERAL OR STATE SECURITIES LAWS, INCLUDING THE SECURITIES ACT OF 1933, THE SECURITIES EXCHANGE ACT OF 1934, THE INVESTMENT COMPANY ACT OF 1940, OR THE HOWEY TEST AS ARTICULATED BY THE U.S. SUPREME COURT IN SEC V. W.J. HOWEY CO. (1946).</p>
    <p>Specifically, XFT does not satisfy the elements of an investment contract under the Howey Test because: (a) XFT is distributed as a reward for active platform engagement (streaming), not in exchange for an investment of money; (b) XFT rewards are earned through individual user activity, not from a common enterprise; (c) XFT holders do not have a reasonable expectation of profits derived from the efforts of IMU or any third party; and (d) IMU makes no representations regarding the future value, appreciation, or market demand of XFT.</p>
    <p class="legal-caps">IMU DOES NOT OFFER, SOLICIT, OR PROMOTE XFT AS AN INVESTMENT. IMU MAKES NO REPRESENTATIONS OR GUARANTEES REGARDING THE MONETARY VALUE, MARKET DEMAND, EXCHANGE RATE, LIQUIDITY, OR FUTURE UTILITY OF XFT TOKENS. XFT MAY HAVE ZERO MONETARY VALUE. YOU SHOULD NOT PARTICIPATE IN THE REWARDS PROGRAM WITH ANY EXPECTATION OF PROFIT OR FINANCIAL RETURN.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sd-s3">
    <h2>D3. Viewer Eligibility and Rewards</h2>
    <h3>D3.1 Eligibility</h3>
    <p>All registered Users may earn XFT for time spent watching or listening to content on IMUTV and IMUP3, subject to the following requirements:</p>
    <ul>
        <li>(a) You must be logged into your IMU account during the streaming session.</li>
        <li>(b) You must have an XRPL wallet address connected to your IMU account.</li>
        <li>(c) Your streaming session must maintain regular heartbeat signals (automatic &mdash; no user action required beyond actively playing content).</li>
        <li>(d) Anonymous or logged-out streaming does not earn XFT rewards.</li>
    </ul>
    <h3>D3.2 Viewer Reward Rate</h3>
    <p>Viewers earn XFT at a rate of 0.25 XFT per ten (10) minutes of qualifying streaming time (the "Viewer Rate"). The Viewer Rate is an initial rate and is subject to change at IMU's sole discretion pursuant to Section D9.</p>
    <h3>D3.3 Trustline Delivery</h3>
    <p>A trustline to the XFT issuer on the XRPL is required to accrue rewards. However, at payout time: (a) if a trustline is set, rewards are delivered via a direct XRPL Payment; (b) if no trustline is set, rewards are thereby forfeited. The trustline enables seamless, automatic delivery and is the responsibility of the user to set-up.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sd-s4">
    <h2>D4. Music Artist Eligibility and Royalties</h2>
    <h3>D4.1 Eligible Plans</h3>
    <p>Artists on Beginner, Streamer, or Devoted plans earn XFT when their content is streamed by other Users. Hobby plan Creators are NOT eligible for streaming royalties. If an artist downgrades to Hobby, royalty accrual ceases immediately; however, already-accrued unpaid XFT remains payable per Section D5.</p>
    <h3>D4.2 Artist Reward Rate</h3>
    <p>Creators earn XFT at a rate of 1.0 XFT per ten (10) minutes of their content being streamed by other Users (the "Creator Rate"). The Creator Rate is an initial rate and is subject to change at IMU's sole discretion pursuant to Section D9.</p>
    <h3>D4.3 Wallet Requirement</h3>
    <p>Creators must have an XRPL wallet address connected to their Artist profile to receive payouts.</p>
    <h3>D4.4 Self-Play Exclusion</h3>
    <p>Creators do NOT earn Creator-side XFT royalties when they play their own content. For clarity, if a Creator streams their own content, their Viewer-side accrual (under Section D3) for the same session is unaffected &mdash; only the Creator-side royalty is excluded.</p>
    <h3>D4.5 Accrual Basis</h3>
    <p>Creator royalty accrual is based on ALL logged-in viewer streams of their content, regardless of whether those viewers have XRPL wallets connected. A Creator whose content is streamed by 1,000 viewers earns from all 1,000 streams, even if only some of those viewers have wallets and are earning their own viewer rewards.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sd-s5">
    <h2>D5. Payout Mechanics</h2>
    <h3>D5.1 Accrual Frequency</h3>
    <p>XFT rewards accrue hourly based on qualifying streaming seconds recorded during each hour.</p>
    <h3>D5.2 Payout Frequency</h3>
    <p>Payouts are processed monthly via automated XRPL transactions. The daily payout batch processes all accrued rewards from the prior month that meet the minimum payout threshold.</p>
    <h3>D5.3 Minimum Payout Threshold</h3>
    <p>A minimum payout threshold of 1.0 XFT applies. Balances below the threshold carry over to subsequent payout cycles.</p>
    <h3>D5.4 Carryover Expiry</h3>
    <p>Pending accruals that have not reached the minimum payout threshold within ninety (90) days are forfeited ("Carryover Expiry"). IMU will send an email notification at sixty (60) days advising the User that their pending balance will expire in thirty (30) days unless the minimum threshold is met. This prevents indefinite accumulation of micro-balances. Carryover Expiry applies to both Viewer and Creator accruals.</p>
    <h3>D5.5 Payout Failures</h3>
    <p>If a payout transaction fails due to a technical issue (e.g., insufficient XRPL reserve in the recipient's wallet, expired trustline), IMU will retry the payout in the next daily batch. If three (3) consecutive payout attempts fail, the accrued balance will be held until the User resolves the issue. Held balances are not subject to Carryover Expiry while in a failed-payout state.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sd-s6">
    <h2>D6. Anti-Gaming and Fraud Prevention</h2>
    <h3>D6.1 Prohibited Activities</h3>
    <p>The following activities are prohibited and may result in immediate forfeiture of all accrued rewards, account suspension, or permanent account termination:</p>
    <ul>
        <li>Artificially inflating streaming time through bot farming, automated scripts, or idle streaming without active engagement.</li>
        <li>Creating multiple accounts to farm rewards.</li>
        <li>Manipulating or spoofing heartbeat signals.</li>
        <li>Colluding with other Users to artificially inflate streaming metrics.</li>
        <li>Using any device, software, or technique to circumvent anti-gaming protections.</li>
        <li>Any other scheme, arrangement, or conduct designed to earn rewards fraudulently.</li>
    </ul>
    <h3>D6.2 Idle Streaming Detection</h3>
    <p>If no heartbeat signal is received for more than ninety (90) seconds during a play session, the gap period does not count toward reward accrual. Accrual resumes automatically when a heartbeat signal is detected.</p>
    <h3>D6.3 Wallet Timing</h3>
    <p>The XRPL wallet must be connected to the User's account at the time of streaming. Connecting a wallet after a streaming session does not retroactively generate rewards for past sessions.</p>
    <h3>D6.4 Investigation and Forfeiture</h3>
    <p>IMU reserves the right to investigate any suspected fraudulent activity. During an investigation, reward accrual and payouts may be suspended. If fraud is confirmed, all accrued rewards may be permanently forfeited, and the User's account may be terminated.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sd-s7">
    <h2>D7. Tax Responsibility</h2>
    <p><strong>D7.1</strong> You are solely responsible for any tax obligations arising from the receipt of XFT tokens, including determination of Fair Market Value at the time of receipt. The IRS and other tax authorities may treat the receipt of XFT tokens as taxable income. IMU will include XFT distributions in IRS Form 1099 reporting in accordance with Schedule A, Section 11 of the Terms of Service.</p>
    <p><strong>D7.2</strong> IMU does not provide tax, legal, or financial advice regarding XFT tokens. Consult a qualified tax professional regarding your specific obligations.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sd-s8">
    <h2>D8. Relationship to Other Reward Programs</h2>
    <h3>D8.1 No Double-Counting</h3>
    <p>For clarity, a single streaming session may simultaneously generate both Viewer rewards (for the viewer) and Creator rewards (for the content owner). This is not double-counting &mdash; it is two separate reward streams for two separate participants.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sd-s9">
    <h2>D9. Modification, Suspension, and Termination</h2>
    <p><strong>D9.1</strong> IMU reserves the right to modify, suspend, or terminate the Rewards Program at any time, with or without notice. This includes the right to change:</p>
    <ul>
        <li>Viewer and Artist reward rates.</li>
        <li>Eligibility criteria (plan requirements, wallet requirements, minimum streaming thresholds).</li>
        <li>Payout frequency, minimum thresholds, and Carryover Expiry periods.</li>
        <li>Anti-gaming rules and detection methods.</li>
        <li>Any other aspect of the Rewards Program.</li>
    </ul>
    <p><strong>D9.2</strong> Rate changes and eligibility changes apply prospectively and do not affect previously accrued rewards. If the Rewards Program is terminated entirely, any accrued rewards above the minimum payout threshold will be distributed in a final payout within thirty (30) days of termination. Accrued rewards below the minimum payout threshold at the time of program termination are forfeited.</p>
    <p><strong>D9.3</strong> IMU will use commercially reasonable efforts to provide advance notice of material changes to the Rewards Program, but is not obligated to do so. Your continued participation in the Rewards Program after any change constitutes acceptance of the modified terms.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sd-s10">
    <h2>D10. Disclaimers and Limitation of Liability</h2>
    <p class="legal-caps">THE REWARDS PROGRAM IS PROVIDED "AS IS" AND "AS AVAILABLE." IMU MAKES NO WARRANTIES, EXPRESS OR IMPLIED, REGARDING THE REWARDS PROGRAM, INCLUDING WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, OR NON-INFRINGEMENT.</p>
    <p class="legal-caps">IMU SHALL NOT BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES ARISING FROM YOUR PARTICIPATION IN THE REWARDS PROGRAM, INCLUDING BUT NOT LIMITED TO: LOSS OF XFT VALUE, FAILURE TO RECEIVE EXPECTED REWARDS, PAYOUT DELAYS, WALLET ISSUES, BLOCKCHAIN NETWORK FAILURES, OR CHANGES TO REWARD RATES.</p>
    <p class="legal-caps">IMU'S TOTAL AGGREGATE LIABILITY RELATED TO THE REWARDS PROGRAM SHALL NOT EXCEED THE FAIR MARKET VALUE OF XFT ACTUALLY DISTRIBUTED TO YOU IN THE TWELVE (12) MONTHS PRECEDING THE CLAIM, OR ONE HUNDRED DOLLARS ($100), WHICHEVER IS GREATER.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sd-s11">
    <h2>D11. Governing Law and Disputes</h2>
    <p>These Reward Terms are governed by the laws of the State of Florida. Any dispute arising from the Rewards Program is subject to the dispute resolution and arbitration provisions of the IMU Terms of Service, Section 34, including the binding arbitration clause and class action waiver.</p>
    <a class="legal-back-top" href="#terms-top">&#8593; Back to top</a>
</div>

<div class="legal-section" id="sd-s12">
    <h2>D12. Contact</h2>
    <p>For questions about the Rewards Program, XFT tokens, or reward payouts:</p>
    <div class="legal-contact-box">
        <h3>Schedule D &mdash; XFT Streaming Rewards Program</h3>
        <p><strong>Email:</strong> <a href="mailto:legal@imutv.tv">legal@imutv.tv</a></p>
        <p><strong>Website:</strong> www.imutv.tv</p>
    </div>
    <div class="legal-footer-note" style="margin-top:1.5rem;">Last Updated: April 13, 2026 &nbsp;&middot;&nbsp; <a href="#toc">&#8593; Back to Table of Contents</a></div>
</div>


</div><!-- /.legal-container -->
</div><!-- /.legal-page-wrap -->

<?php get_footer(); ?>