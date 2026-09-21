<?php
/**
 * Template Name: IMU Privacy Policy
 * File: page-privacy.php
 * Path: /wp-content/themes/astra/page-privacy.php
 * Privacy Policy — IMU, LLC | Effective: February 22, 2026
 */
global $imc_og_data;
$imc_og_data = [
    'title'       => 'Privacy Policy | IMCollectibles',
    'description' => 'Privacy Policy for IMU, LLC — governing the collection, use, storage, and disclosure of personal information across IMUTV, IMUP3, IM Collectibles, and IMU Merch Store.',
    'image'       => defined('IMC_OG_DEFAULT_IMAGE') ? IMC_OG_DEFAULT_IMAGE : 'https://imcollectibles.io/wp-content/uploads/og-default.png',
    'url'         => home_url('/privacy/'),
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

<div class="legal-page-wrap" id="privacy-top">
<div class="legal-container">

<!-- Hero -->
<div class="legal-hero">
    <div class="legal-eyebrow">IMU, LLC &mdash; Legal</div>
    <h1>Privacy Policy</h1>
    <p class="legal-subtitle">Governing the collection, use, storage, and disclosure of personal information across all IMU platforms: IMUTV, IMUP3, IM Collectibles, and IMU Merch Store.</p>
    <div class="legal-meta">
        <span><strong>Effective Date:</strong> February 22, 2026</span>
        <span><strong>Last Updated:</strong> February 22, 2026</span>
        <span><strong>Entity:</strong> IMU, LLC &mdash; A Florida Limited Liability Company</span>
    </div>
</div>

<!-- TOC -->
<div class="legal-toc" id="toc">
    <div class="legal-toc-title">Table of Contents</div>
    <div class="legal-toc-grid">
        <div class="legal-toc-item"><span class="legal-toc-num">1.</span><a href="#p-intro">Introduction</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">9.</span><a href="#p-children">Children's Privacy</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">2.</span><a href="#p-collect">Information We Collect</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">10.</span><a href="#p-transfers">International Data Transfers</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">3.</span><a href="#p-use">How We Use Your Information</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">11.</span><a href="#p-cookies">Cookie Policy</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">4.</span><a href="#p-gdpr">Legal Bases for Processing (GDPR)</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">12.</span><a href="#p-blockchain">Blockchain &amp; Digital Collectible Privacy</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">5.</span><a href="#p-share">How We Share Your Information</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">13.</span><a href="#p-thirdparty">Third-Party Links and Services</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">6.</span><a href="#p-retention">Data Retention</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">14.</span><a href="#p-donotsell">Do Not Sell or Share My Personal Information</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">7.</span><a href="#p-security">Data Security</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">15.</span><a href="#p-changes">Changes to This Privacy Policy</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">8.</span><a href="#p-rights">Your Rights</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">16.</span><a href="#p-contact">Contact Us</a></div>
    </div>
</div>

<!-- 1. Introduction -->
<div class="legal-section" id="p-intro">
    <h2>1. Introduction</h2>
    <p>IMU, LLC ("IMU," "we," "us," or "our"), a Florida limited liability company operating under the trade names IMUTV, IMUP3, and IM Collectibles, is committed to protecting the privacy and security of your personal information. This Privacy Policy ("Policy") describes how we collect, use, store, share, and protect information when you access or use our websites, applications, and services (collectively, the "Service"), including:</p>
    <ul>
        <li><strong>IMUTV</strong> (<a href="https://www.imutv.tv" target="_blank" rel="noopener">www.imutv.tv</a>) — interactive video streaming and live broadcast platform</li>
        <li><strong>IMUP3</strong> — interactive audio streaming and podcast application</li>
        <li><strong>IM Collectibles</strong> (<a href="https://www.imcollectibles.io" target="_blank" rel="noopener">www.imcollectibles.io</a>) — digital collectibles and NFT marketplace on the XRP Ledger</li>
        <li><strong>IMU Merch Store</strong> — physical merchandise e-commerce platform</li>
    </ul>
    <p>This Policy applies to all users worldwide, including Users, Creators, purchasers, and visitors. By accessing or using the Service, you acknowledge that you have read, understood, and agree to the practices described in this Policy. If you do not agree, please do not use the Service.</p>
    <div class="legal-notice"><strong>Important:</strong> The Service is restricted to individuals aged 18 and older. We do not knowingly collect personal information from individuals under 18 years of age. See Section 9 (Children's Privacy) for details.</div>
    <a class="legal-back-top" href="#privacy-top">&#8593; Back to top</a>
</div>

<!-- 2. Information We Collect -->
<div class="legal-section" id="p-collect">
    <h2>2. Information We Collect</h2>
    <p>We collect information in three categories: information you provide directly, information collected automatically, and information from third-party sources.</p>
    <h3>2.1 Information You Provide Directly</h3>
    <h4>Account Registration</h4>
    <ul>
        <li>Email address</li>
        <li>Username / display name</li>
        <li>Password (stored in encrypted/hashed form)</li>
        <li>Date of birth (for age verification 18+ requirement)</li>
        <li>Country / region of residence</li>
    </ul>
    <h4>Creator Profile Information</h4>
    <ul>
        <li>Artist or brand name, biography, profile photo</li>
        <li>Genre and content category preferences</li>
        <li>Tax identification information (W-9 or W-8BEN for payout processing)</li>
        <li>Payment information for receiving payouts (bank account details or XRPL wallet address)</li>
        <li>PRO affiliation and songwriter/publisher credits</li>
    </ul>
    <h4>Content and Metadata</h4>
    <ul>
        <li>Audio files, video files, images, podcasts, and other creative works submitted by Creators</li>
        <li>Associated metadata: track titles, artist names, ISRCs, UPCs, songwriter credits, cue sheets, album art, content descriptions, and content ratings</li>
    </ul>
    <h4>Transaction Information</h4>
    <ul>
        <li>Purchase history (subscriptions, pay-per-view, merchandise, digital collectibles)</li>
        <li>Billing information (processed by Stripe; we do not store full credit card numbers)</li>
        <li>XRPL wallet addresses for cryptocurrency transactions (public blockchain data)</li>
        <li>XRP dynamic pricing data: Price Quotes (USD amount, XRP/USD exchange rate, calculated XRP amount), Exchange Rate Oracle source and rate at time of transaction, XRPL transaction hashes, and settlement confirmation data</li>
        <li>Shipping addresses for merchandise orders</li>
        <li>RLUSD and XFT transaction data: payment amounts, USD-equivalent values, internal XFT valuation rates at time of transaction, settlement confirmation data, and XRPL transaction hashes</li>
        <li>XFT Streaming Rewards data: per-hour streaming accrual records (qualifying seconds, XFT earned, viewer or creator role), payout transaction hashes, wallet address snapshots at time of accrual, batch payout logs, and Carryover Expiry records</li>
        <li>IM Collectibles Feature data: Burn 2 Earn submissions (design descriptions, X handles, email addresses, reference images, burn transaction hashes), Frequency Fountain snapshot records, Tasks &amp; Rewards point balances and conversion records, Champion of Frequencies gameplay statistics, and IMU Airdrop claim records</li>
        <li>Creator Plan and billing data: Plan tier selection (Hobby, Beginner, Streamer, Devoted), billing status, plan change history, storage add-on purchase records, Subscriber Tier configurations, and Devoted Plan custom configuration details</li>
        <li>Tipping records: tip amounts, payment method (Stripe or XRPL), recipient and sender wallet addresses for XRPL tips, and timestamps</li>
    </ul>
    <h4>Communications</h4>
    <ul>
        <li>Customer support inquiries and correspondence</li>
        <li>DMCA notices, counter-notifications, and rights dispute communications</li>
        <li>Feedback, surveys, and promotional responses</li>
    </ul>
    <h3>2.2 Information Collected Automatically</h3>
    <h4>Usage and Streaming Data</h4>
    <ul>
        <li>Stream and play data: What content was played (track/video title, ISRC), when it was played, duration of play, skip events, and which account initiated the stream. This data is collected to fulfill our legal obligations to report to Performing Rights Organizations (ASCAP, BMI, SESAC, GMR, SOCAN), The Mechanical Licensing Collective (MLC), and other rights administration bodies.</li>
        <li>Browsing behavior: pages visited, search queries, content interactions (likes, saves, playlist additions)</li>
        <li>Live stream viewing data: join/leave times, chat participation</li>
    </ul>
    <h4>Device and Technical Information</h4>
    <ul>
        <li>Device type and model (e.g., Roku, Fire TV, iPhone, Android)</li>
        <li>Operating system and version</li>
        <li>Browser type and version (for web access)</li>
        <li>Screen resolution and display capabilities</li>
        <li>Unique device identifiers (device ID, advertising ID)</li>
        <li>App version</li>
    </ul>
    <h4>Network and Location Information</h4>
    <ul>
        <li>IP address</li>
        <li>Approximate geographic location derived from IP address (city/region level used for content licensing compliance, royalty reporting by territory, and OFAC sanctions screening)</li>
        <li>Internet service provider</li>
        <li>Connection type and speed</li>
    </ul>
    <h4>Cookies and Tracking Technologies</h4>
    <ul>
        <li><strong>Essential Cookies:</strong> Required for Service functionality (authentication, session management, security). Cannot be disabled.</li>
        <li><strong>Analytics Cookies:</strong> Help us understand how Users interact with the Service (page views, feature usage, performance metrics). Can be disabled.</li>
        <li><strong>Advertising Cookies:</strong> Used by our advertising partners to serve relevant ads on the AVOD (free, ad-supported) tier. Can be disabled.</li>
    </ul>
    <p>For details on managing cookies, see <a href="#p-cookies">Section 11 (Cookie Policy)</a>.</p>
    <h3>2.3 Information from Third-Party Sources</h3>
    <ul>
        <li><strong>Payment Processors:</strong> Stripe may provide us with transaction confirmation data, fraud risk assessments, and payment status updates.</li>
        <li><strong>Blockchain Data:</strong> XRPL transaction data is publicly available on the blockchain. We may access public wallet transaction histories to verify Digital Collectible ownership and transaction status.</li>
        <li><strong>App Store Platforms:</strong> Apple, Google, Roku, and Amazon may share limited information related to app downloads, subscriptions managed through their platforms, and crash/performance reports.</li>
        <li><strong>Analytics Providers:</strong> Third-party analytics services may provide aggregated or de-identified usage insights.</li>
    </ul>
    <a class="legal-back-top" href="#privacy-top">&#8593; Back to top</a>
</div>

<!-- 3. How We Use Your Information -->
<div class="legal-section" id="p-use">
    <h2>3. How We Use Your Information</h2>
    <p>We use the information we collect for the following purposes:</p>
    <h3>3.1 Service Operations</h3>
    <ul>
        <li>To create and manage your account</li>
        <li>To deliver, personalize, and improve the Service</li>
        <li>To process transactions, subscriptions, and merchandise orders</li>
        <li>To distribute Creator content across platforms</li>
        <li>To facilitate XRPL-based transactions for IM Collectibles</li>
    </ul>
    <h3>3.2 Licensing and Royalty Compliance</h3>
    <p>This is a critical function of our data collection. We collect and process streaming/play data (including ISRCs, play counts, and territory information) to:</p>
    <ul>
        <li>Report usage to Performing Rights Organizations (ASCAP and BMI) for public performance royalties</li>
        <li>Submit monthly usage reports to The Mechanical Licensing Collective (MLC) for mechanical royalties</li>
        <li>Report to international Collective Management Organizations (CMOs) as required by territory</li>
        <li>Calculate and distribute Creator subscription and pay-per-view revenue based on actual usage</li>
        <li>Maintain records required by the Music Modernization Act and applicable copyright statutes</li>
    </ul>
    <div class="legal-notice">We retain streaming data for a minimum of <strong>seven (7) years</strong> to comply with statutory audit and reporting requirements.</div>
    <h3>3.3 Communications</h3>
    <ul>
        <li>To respond to inquiries, support requests, and DMCA/rights disputes</li>
        <li>To send transactional notifications (payment confirmations, subscription renewals, content status updates)</li>
        <li>To send promotional communications (with your consent; you may opt out at any time)</li>
    </ul>
    <h3>3.4 Safety, Security, and Legal Compliance</h3>
    <ul>
        <li>To detect, prevent, and respond to fraud, abuse, security incidents, and technical issues</li>
        <li>To enforce our Terms of Service and Acceptable Use Policy</li>
        <li>To comply with legal obligations, including OFAC sanctions screening, AML monitoring, tax reporting (1099/W-9), and law enforcement requests</li>
        <li>To protect the rights, property, and safety of IMU, our Users, Creators, and the public</li>
    </ul>
    <h3>3.5 Analytics and Improvement</h3>
    <ul>
        <li>To analyze usage patterns and improve Service features, content recommendations, and user experience</li>
        <li>To conduct internal research and development</li>
        <li>To measure advertising effectiveness on the AVOD tier</li>
    </ul>
    <h3>3.6 Advertising</h3>
    <ul>
        <li>To serve advertisements on the free, ad-supported (AVOD) tier of the Service</li>
        <li>To provide aggregated, de-identified audience insights to advertisers (we do NOT sell your personal data to advertisers)</li>
    </ul>
    <div class="legal-notice"><strong>Important:</strong> IMU does not sell personal data. We do not operate as a data brokerage. See <a href="#p-rights">Section 8 (Your Rights)</a> for details.</div>
    <h3>3.7 XFT Rewards and Feature Program Calculation</h3>
    <p>We use streaming, play, and engagement data (content played or watched, duration, user account, device type, heartbeat signals, NFT holdings, and connected wallet addresses) to calculate, distribute, and audit XFT rewards across the XFT Streaming Rewards Program, Frequency Fountain, Tasks &amp; Rewards, Champion of Frequencies, Burn 2 Earn, IMU Airdrops, and Redeem (when activated). This processing is necessary for the operation of these promotional reward programs and is based on our legitimate interest in operating the programs, your participation in them, and our obligation to maintain accurate records for tax reporting and compliance purposes. We also use this data for fraud detection and anti-gaming enforcement. Specific uses include: (a) calculating viewer and creator XFT accruals based on qualifying streaming time; (b) generating daily Frequency Fountain snapshot records based on wallet contents; (c) tracking points earned and converted to XFT through Tasks &amp; Rewards and Champion of Frequencies; (d) processing Burn 2 Earn submissions and matching them to subsequent custom NFT mints; (e) maintaining airdrop eligibility lists and claim records; and (f) calculating Redeem program distributions if and when the program becomes active.</p>
    <a class="legal-back-top" href="#privacy-top">&#8593; Back to top</a>
</div>

<!-- 4. Legal Bases -->
<div class="legal-section" id="p-gdpr">
    <h2>4. Legal Bases for Processing (GDPR / International Users)</h2>
    <p>For Users in the European Economic Area (EEA), United Kingdom, and other jurisdictions that require a legal basis for processing personal data, we process your information based on the following:</p>
    <div class="legal-table-wrap">
        <table class="legal-table">
            <thead><tr><th>Legal Basis</th><th>Processing Activity</th><th>Examples</th></tr></thead>
            <tbody>
                <tr><td><strong>Contract Performance</strong></td><td>Processing necessary to perform our agreement with you</td><td>Account management, content delivery, payment processing, subscription management</td></tr>
                <tr><td><strong>Legal Obligation</strong></td><td>Processing required to comply with applicable laws</td><td>Royalty reporting to PROs/MLC, tax reporting (1099), OFAC screening, AML compliance, law enforcement requests</td></tr>
                <tr><td><strong>Legitimate Interests</strong></td><td>Processing necessary for our legitimate business interests, balanced against your rights</td><td>Fraud prevention, security, analytics, service improvement, enforcing Terms of Service</td></tr>
                <tr><td><strong>Consent</strong></td><td>Processing based on your freely given consent</td><td>Marketing emails, non-essential cookies, advertising personalization</td></tr>
            </tbody>
        </table>
    </div>
    <p>You may withdraw consent at any time without affecting the lawfulness of processing performed prior to withdrawal.</p>
    <a class="legal-back-top" href="#privacy-top">&#8593; Back to top</a>
</div>

<!-- 5. How We Share -->
<div class="legal-section" id="p-share">
    <h2>5. How We Share Your Information</h2>
    <p>We do not sell your personal information. We share information only in the following circumstances:</p>
    <h3>5.1 Rights Administration Bodies</h3>
    <p>We share streaming/play data with the following organizations as required by law to ensure proper royalty distribution:</p>
    <ul>
        <li><strong>Performing Rights Organizations:</strong> ASCAP, BMI, SESAC, GMR (U.S.), SOCAN (Canada), and other international CMOs as applicable</li>
        <li><strong>The Mechanical Licensing Collective (MLC):</strong> Monthly usage reports required under the Music Modernization Act</li>
        <li><strong>International CMOs:</strong> CMRRA/CSI (Canada), PRS/MCPS (UK), and others as we expand internationally</li>
    </ul>
    <p>Data shared with these organizations consists of streaming metadata (ISRCs, play counts, territories) and does not include personal User account information (email, name, payment details).</p>
    <h3>5.2 Payment Processors</h3>
    <ul>
        <li><strong>Stripe:</strong> Processes fiat currency transactions. Stripe receives payment card information directly and is PCI-DSS compliant. See Stripe's Privacy Policy at <a href="https://stripe.com/privacy" target="_blank" rel="noopener">stripe.com/privacy</a>.</li>
        <li><strong>XRPL:</strong> XRP, RLUSD, and XFT transactions are recorded on the public XRP Ledger blockchain. Wallet addresses and transaction amounts are publicly visible and immutable. XRPL wallet addresses connected to your IMU account are used for: (a) processing XRPL payment transactions; (b) delivering XFT Streaming Rewards Program payouts; (c) delivering Frequency Fountain daily rewards; (d) processing Burn 2 Earn burn transactions and Firepit Protector deliveries; (e) processing Tasks &amp; Rewards XFT conversions; (f) processing IMU Airdrop claims; and (g) processing Champion of Frequencies game reward distributions. IMU stores a snapshot of your connected wallet address at the time of each accrual or qualifying event for audit, compliance, and tax reporting purposes. IMU does not custody your XRP, RLUSD, XFT, or any other tokens, and does not store your private keys or seed phrases.</li>
    </ul>
    <h3>5.3 Service Providers</h3>
    <p>We engage third-party service providers who process data on our behalf under contractual obligations:</p>
    <ul>
        <li>Cloud hosting and content delivery network (CDN) providers</li>
        <li>Email and notification service providers</li>
        <li>Analytics and performance monitoring tools</li>
        <li>Customer support platforms</li>
        <li>Fraud detection and prevention services</li>
        <li>Merchandise fulfillment and shipping partners</li>
    </ul>
    <h3>5.4 Advertising Partners</h3>
    <p>On the free, ad-supported (AVOD) tier, advertising partners may use cookies and similar technologies to serve relevant ads. We share:</p>
    <ul>
        <li>Aggregated, de-identified audience demographics</li>
        <li>Contextual information (content genre, platform)</li>
    </ul>
    <p>We do <strong>NOT</strong> share your name, email address, payment information, or any personally identifiable information with advertisers.</p>
    <h3>5.5 App Store Platforms</h3>
    <p>Apple, Google, Roku, and Amazon receive limited data necessary for app distribution, in-app purchase processing, and crash reporting, subject to their respective privacy policies.</p>
    <h3>5.6 Legal and Safety Disclosures</h3>
    <p>We may disclose information when we believe in good faith that disclosure is necessary to:</p>
    <ul>
        <li>Comply with applicable law, regulation, legal process, or governmental request</li>
        <li>Enforce our Terms of Service or investigate potential violations</li>
        <li>Detect, prevent, or address fraud, security, or technical issues</li>
        <li>Protect the rights, property, or safety of IMU, our Users, or the public</li>
    </ul>
    <h3>5.7 Business Transfers</h3>
    <p>In the event of a merger, acquisition, reorganization, bankruptcy, or sale of all or substantially all of our assets, your information may be transferred to the successor entity, subject to the commitments made in this Privacy Policy.</p>
    <a class="legal-back-top" href="#privacy-top">&#8593; Back to top</a>
</div>

<!-- 6. Data Retention -->
<div class="legal-section" id="p-retention">
    <h2>6. Data Retention</h2>
    <p>We retain your information only as long as necessary to fulfill the purposes described in this Policy, comply with legal obligations, and resolve disputes:</p>
    <div class="legal-table-wrap">
        <table class="legal-table">
            <thead><tr><th>Data Category</th><th>Retention Period</th><th>Reason</th></tr></thead>
            <tbody>
                <tr><td>Account Information</td><td>Duration of account + 1 year after deletion</td><td>Service operation; post-deletion grace period for reactivation requests and dispute resolution</td></tr>
                <tr><td>Streaming / Play Data</td><td>7 years minimum</td><td>Statutory requirement: Music Modernization Act audit provisions, PRO and MLC reporting, royalty dispute resolution</td></tr>
                <tr><td>Transaction / Payment Records</td><td>7 years</td><td>Tax reporting (IRS), financial audit compliance, chargeback/dispute resolution</td></tr>
                <tr><td>Creator Content &amp; Metadata</td><td>Duration of distribution + 3 years after removal</td><td>Royalty reconciliation, rights dispute resolution, legal compliance</td></tr>
                <tr><td>DMCA / Copyright Records</td><td>5 years</td><td>DMCA safe harbor compliance, repeat infringer policy enforcement</td></tr>
                <tr><td>Server Logs / IP Data</td><td>90 days</td><td>Security, fraud detection, abuse prevention</td></tr>
                <tr><td>Marketing Preferences</td><td>Until opt-out or account deletion</td><td>Honoring communication preferences</td></tr>
                <tr><td>Blockchain Transaction Data (XRPL)</td><td>Permanent (on-chain)</td><td>Immutable public blockchain record. IMU cannot delete on-chain data</td></tr>
                <tr><td>XRP Transaction Records (Price Quotes, exchange rates, XRPL hashes)</td><td>7 years</td><td>Tax reporting (IRS fair market value documentation), financial audit, AML/KYC compliance, royalty calculation verification</td></tr>
            </tbody>
        </table>
    </div>
    <p><strong>Additional Retention Categories.</strong> In addition to the categories listed above, the following data categories are subject to specific retention periods: (a) XFT Streaming Rewards Program records (accrual logs, payout transaction hashes, wallet snapshots, Carryover Expiry records) and other XFT distribution records (Frequency Fountain, Tasks &amp; Rewards, Burn 2 Earn, Champion of Frequencies, Airdrops, Redeem) are retained for seven (7) years for financial audit compliance, tax reporting (fair market value documentation), fraud investigation, and reward dispute resolution. (b) Creator Plan and Subscription Data (plan tier history, billing status, plan transition records, storage add-on purchase records, Subscriber Tier configurations) is retained for the duration of the account plus one (1) year for service operation, billing dispute resolution, and plan transition audit purposes. (c) Tipping records (sender/recipient identifiers, amounts, payment method, timestamps) are retained for seven (7) years for tax reporting and dispute resolution. (d) Burn 2 Earn design submissions and reference images are retained for the lifetime of the corresponding Firepit Protector NFT plus three (3) years.</p>
    <p>When retention periods expire, data is either deleted or irreversibly anonymized. Anonymized data that cannot reasonably be used to identify any individual may be retained indefinitely for analytics purposes.</p>
    <a class="legal-back-top" href="#privacy-top">&#8593; Back to top</a>
</div>

<!-- 7. Data Security -->
<div class="legal-section" id="p-security">
    <h2>7. Data Security</h2>
    <p>We implement administrative, technical, and organizational safeguards designed to protect your personal information:</p>
    <h3>7.1 Technical Safeguards</h3>
    <ul>
        <li>Encryption of data in transit (TLS/SSL) and at rest (AES-256)</li>
        <li>Secure password hashing (bcrypt or equivalent)</li>
        <li>Regular security assessments and vulnerability scanning</li>
        <li>Access controls and role-based permissions for internal systems</li>
        <li>Intrusion detection and monitoring systems</li>
        <li>Secure API authentication and rate limiting</li>
    </ul>
    <h3>7.2 Organizational Safeguards</h3>
    <ul>
        <li>Employee and contractor confidentiality obligations</li>
        <li>Privacy and security training for personnel handling personal data</li>
        <li>Incident response procedures for data breaches</li>
        <li>Vendor security assessments for third-party service providers</li>
    </ul>
    <h3>7.3 Payment Security</h3>
    <p>Credit card and payment information is processed by Stripe, which is PCI-DSS Level 1 certified. IMU does not store, process, or have access to full credit card numbers. XRPL transactions are secured by the blockchain's native cryptographic protocols.</p>
    <div class="legal-notice"><strong>No Guarantee:</strong> While we implement commercially reasonable security measures, no method of transmission over the Internet or electronic storage is 100% secure. We cannot guarantee absolute security.</div>
    <a class="legal-back-top" href="#privacy-top">&#8593; Back to top</a>
</div>

<!-- 8. Your Rights -->
<div class="legal-section" id="p-rights">
    <h2>8. Your Rights</h2>
    <p>Depending on your jurisdiction, you may have the following rights regarding your personal information:</p>
    <h3>8.1 Rights Available to All Users</h3>
    <ul>
        <li><strong>Access:</strong> Request a copy of the personal information we hold about you.</li>
        <li><strong>Correction:</strong> Request correction of inaccurate or incomplete personal information.</li>
        <li><strong>Deletion:</strong> Request deletion of your personal information, subject to legal retention requirements.</li>
        <li><strong>Opt-Out of Marketing:</strong> Unsubscribe from promotional emails at any time using the "unsubscribe" link in any marketing email or by contacting <a href="mailto:privacy@imutv.tv">privacy@imutv.tv</a>.</li>
        <li><strong>Cookie Preferences:</strong> Manage cookie preferences through our cookie consent banner or browser settings.</li>
    </ul>
    <h3>8.2 Additional Rights for California Residents (CCPA/CPRA)</h3>
    <p>If you are a California resident, you have the following additional rights under the California Consumer Privacy Act (CCPA) as amended by the California Privacy Rights Act (CPRA):</p>
    <ul>
        <li><strong>Right to Know:</strong> Request the categories and specific pieces of personal information collected about you, the sources, the business purpose, and the categories of third parties with whom it is shared.</li>
        <li><strong>Right to Delete:</strong> Request deletion of personal information, subject to exceptions (legal obligations, completing transactions, security, internal analytics).</li>
        <li><strong>Right to Opt-Out of Sale/Sharing:</strong> IMU does not sell personal information. IMU does not share personal information for cross-context behavioral advertising. If this practice changes, we will provide a "Do Not Sell or Share My Personal Information" link.</li>
        <li><strong>Right to Limit Use of Sensitive Information:</strong> Request that we limit use of sensitive personal information to purposes necessary to perform the Service.</li>
        <li><strong>Right to Non-Discrimination:</strong> We will not discriminate against you for exercising your privacy rights.</li>
    </ul>
    <p><strong>How to Exercise:</strong> Submit requests to <a href="mailto:privacy@imutv.tv">privacy@imutv.tv</a>. We will verify your identity before processing requests and respond within 45 days (extendable by an additional 45 days with notice).</p>
    <h3>8.3 Additional Rights for Virginia, Colorado, Connecticut, and Other State Residents</h3>
    <p>Residents of Virginia (VCDPA), Colorado (CPA), Connecticut (CTDPA), Utah, Montana, Oregon, Texas, and other states with comprehensive privacy laws may have additional rights including:</p>
    <ul>
        <li>Right to access, correct, and delete personal data</li>
        <li>Right to data portability</li>
        <li>Right to opt out of targeted advertising, sale of personal data, and profiling</li>
        <li>Right to appeal a denial of a privacy request</li>
    </ul>
    <p>To exercise these rights, contact <a href="mailto:privacy@imutv.tv">privacy@imutv.tv</a>. Appeals may be submitted to the same address.</p>
    <h3>8.4 Additional Rights for EEA, UK, and International Users (GDPR)</h3>
    <p>If you are located in the European Economic Area (EEA), United Kingdom, or other jurisdictions with GDPR-equivalent laws, you have additional rights:</p>
    <ul>
        <li><strong>Data Portability:</strong> Receive your personal data in a structured, commonly used, machine-readable format.</li>
        <li><strong>Restriction of Processing:</strong> Request that we restrict processing of your data in certain circumstances.</li>
        <li><strong>Object to Processing:</strong> Object to processing based on legitimate interests, including direct marketing.</li>
        <li><strong>Withdraw Consent:</strong> Withdraw consent at any time for processing based on consent, without affecting prior processing.</li>
        <li><strong>Lodge a Complaint:</strong> File a complaint with your local Data Protection Authority (DPA).</li>
    </ul>
    <h3>8.5 Additional Rights for Canadian Users (PIPEDA)</h3>
    <p>Canadian users have rights under the Personal Information Protection and Electronic Documents Act (PIPEDA), including the right to access, correct, and challenge compliance. Contact <a href="mailto:privacy@imutv.tv">privacy@imutv.tv</a> to exercise these rights or to file a complaint with the Office of the Privacy Commissioner of Canada.</p>
    <a class="legal-back-top" href="#privacy-top">&#8593; Back to top</a>
</div>

<!-- 9. Children's Privacy -->
<div class="legal-section" id="p-children">
    <h2>9. Children's Privacy</h2>
    <p>The Service is restricted to individuals aged <strong>18 and older</strong>. We do not knowingly collect, use, or disclose personal information from anyone under the age of 18. We implement age verification at account creation to enforce this requirement.</p>
    <p>If we discover that we have inadvertently collected personal information from an individual under 18, we will promptly delete such information. If you believe a minor has provided us with personal information, please contact us immediately at <a href="mailto:privacy@imutv.tv">privacy@imutv.tv</a>.</p>
    <div class="legal-notice"><strong>COPPA Notice:</strong> Because the Service is restricted to adults (18+) and we do not knowingly collect data from children under 13 or teens under 18, the Children's Online Privacy Protection Act (COPPA), as amended by the COPPA 2026 Rule (effective April 22, 2026), does not apply to the Service. The Service is not directed at, designed for, or intended to attract children or teens. We maintain strict 18+ age-gating mechanisms at registration and do not knowingly collect, use, or disclose personal information from anyone under 18. If we discover that we have collected personal information from a minor under 18, we will promptly delete such information and terminate the associated account. If COPPA or state child privacy obligations are triggered by any future changes to our Service, we will update this Policy accordingly.</div>
    <a class="legal-back-top" href="#privacy-top">&#8593; Back to top</a>
</div>

<!-- 10. International Data Transfers -->
<div class="legal-section" id="p-transfers">
    <h2>10. International Data Transfers</h2>
    <p>IMU is based in the United States (Florida). If you access the Service from outside the United States, your information will be transferred to, stored in, and processed in the United States, where data protection laws may differ from those in your country.</p>
    <h3>10.1 EEA/UK Transfers</h3>
    <p>For transfers of personal data from the EEA or UK to the United States, we rely on:</p>
    <ul>
        <li>Standard Contractual Clauses (SCCs) approved by the European Commission</li>
        <li>The EU-U.S. Data Privacy Framework, where applicable</li>
        <li>Your explicit consent where no other mechanism is available</li>
    </ul>
    <h3>10.2 Canadian Transfers</h3>
    <p>We process data in the United States under contractual safeguards consistent with PIPEDA requirements for cross-border transfers. Canadian users acknowledge that their personal information may be processed in the United States.</p>
    <p>By using the Service, you consent to the transfer of your information to the United States and the application of U.S. law to the processing of your data, subject to the protections described in this Policy.</p>
    <a class="legal-back-top" href="#privacy-top">&#8593; Back to top</a>
</div>

<!-- 11. Cookie Policy -->
<div class="legal-section" id="p-cookies">
    <h2>11. Cookie Policy</h2>
    <p>We use cookies and similar tracking technologies on our websites and applications. This section describes what cookies we use and how you can manage them.</p>
    <h3>11.1 What Are Cookies</h3>
    <p>Cookies are small text files stored on your device when you visit a website. Similar technologies include web beacons, pixels, local storage, and device fingerprinting.</p>
    <h3>11.2 Types of Cookies We Use</h3>
    <div class="legal-table-wrap">
        <table class="legal-table">
            <thead><tr><th>Category</th><th>Purpose</th><th>Can You Disable?</th></tr></thead>
            <tbody>
                <tr><td><strong>Essential</strong></td><td>Authentication, session management, security, load balancing, cookie consent preferences</td><td>No — required for the Service to function. Disabling these may prevent access to the Service.</td></tr>
                <tr><td><strong>Analytics</strong></td><td>Understanding how Users interact with the Service: page views, feature usage, performance metrics, error reporting</td><td>Yes — via cookie consent banner or browser settings</td></tr>
                <tr><td><strong>Advertising</strong></td><td>Serving relevant ads on the AVOD tier, measuring ad performance, frequency capping</td><td>Yes — via cookie consent banner, browser settings, or "Do Not Track" signals</td></tr>
                <tr><td><strong>Functional</strong></td><td>Remembering preferences (language, region, display settings), enhancing user experience</td><td>Yes — but may reduce Service functionality</td></tr>
            </tbody>
        </table>
    </div>
    <h3>11.3 Managing Cookies</h3>
    <ul>
        <li><strong>Browser Settings:</strong> Most browsers allow you to block or delete cookies through their settings menu.</li>
        <li><strong>Do Not Track:</strong> We honor "Do Not Track" (DNT) signals sent by your browser. When DNT is enabled, we will not use non-essential tracking cookies.</li>
        <li><strong>Mobile Devices:</strong> You can manage advertising identifiers through your device settings (iOS: Settings &gt; Privacy &gt; Tracking; Android: Settings &gt; Privacy &gt; Ads).</li>
    </ul>
    <a class="legal-back-top" href="#privacy-top">&#8593; Back to top</a>
</div>

<!-- 12. Blockchain Privacy -->
<div class="legal-section" id="p-blockchain">
    <h2>12. Blockchain and Digital Collectible Privacy</h2>
    <p>IM Collectibles operates on the XRP Ledger (XRPL), a public, decentralized blockchain. Users should be aware of the following unique privacy considerations:</p>
    <ul>
        <li><strong>Public Ledger:</strong> XRPL transactions (wallet addresses, transaction amounts, NFT minting/transfers) are recorded on a public, immutable blockchain. This data is permanently visible to anyone and cannot be deleted or modified by IMU or any party.</li>
        <li><strong>Wallet Address Pseudonymity:</strong> Your XRPL wallet address is pseudonymous. While it does not inherently contain your name or personal details, it may be linked to your identity if you have associated it with your IMU account or if third parties perform blockchain analysis.</li>
        <li><strong>No Deletion of On-Chain Data:</strong> Deletion rights under CCPA, GDPR, and other privacy laws apply to data held in IMU's databases. They do NOT apply to data recorded on the XRPL blockchain, which is outside IMU's control. If you delete your IMU account, the association between your account and your wallet address will be removed from our systems, but on-chain transaction history will remain on the blockchain.</li>
        <li><strong>Metadata Hosting:</strong> Digital content associated with NFTs may be hosted on IMU servers or decentralized storage (IPFS). If hosted by IMU, the content may be removed upon account deletion or rights dispute, but the NFT token itself will remain on-chain.</li>
    </ul>
    <a class="legal-back-top" href="#privacy-top">&#8593; Back to top</a>
</div>

<!-- 13. Third-Party Links -->
<div class="legal-section" id="p-thirdparty">
    <h2>13. Third-Party Links and Services</h2>
    <p>The Service may contain links to third-party websites, apps, or services not operated by IMU. We are not responsible for the privacy practices of third parties. We encourage you to review the privacy policies of any third-party service before providing personal information. Key third-party services include:</p>
    <ul>
        <li><strong>Stripe</strong> (payment processing): <a href="https://stripe.com/privacy" target="_blank" rel="noopener">stripe.com/privacy</a></li>
        <li><strong>Apple App Store</strong> (app distribution): <a href="https://apple.com/privacy" target="_blank" rel="noopener">apple.com/privacy</a></li>
        <li><strong>Google Play</strong> (app distribution): <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">policies.google.com/privacy</a></li>
        <li><strong>Roku</strong> (CTV distribution): <a href="https://roku.com/legal/privacy-policy" target="_blank" rel="noopener">roku.com/legal/privacy-policy</a></li>
        <li><strong>Amazon</strong> (Fire TV distribution): <a href="https://amazon.com/privacy" target="_blank" rel="noopener">amazon.com/privacy</a></li>
    </ul>
    <a class="legal-back-top" href="#privacy-top">&#8593; Back to top</a>
</div>

<!-- 14. Do Not Sell -->
<div class="legal-section" id="p-donotsell">
    <h2>14. Do Not Sell or Share My Personal Information</h2>
    <p>IMU does not sell personal information. IMU does not share personal information for cross-context behavioral advertising as defined under the CCPA/CPRA.</p>
    <p>If our practices change in the future, we will update this Policy, provide notice, and offer a clear opt-out mechanism, including a "Do Not Sell or Share My Personal Information" link accessible from every page of our website and applications.</p>
    <p>To submit a privacy-related request, contact <a href="mailto:privacy@imutv.tv">privacy@imutv.tv</a>.</p>
    <a class="legal-back-top" href="#privacy-top">&#8593; Back to top</a>
</div>

<!-- 15. Changes -->
<div class="legal-section" id="p-changes">
    <h2>15. Changes to This Privacy Policy</h2>
    <p>We may update this Privacy Policy from time to time to reflect changes in our practices, legal requirements, or the Service. When we make material changes:</p>
    <ul>
        <li>We will post the updated Policy on this page with a new "Last Updated" date.</li>
        <li>We will provide notice via email (to the address associated with your account) at least thirty (30) days before material changes take effect.</li>
        <li>We will display a prominent notice within the Service (app banner or in-app notification).</li>
    </ul>
    <p>Your continued use of the Service after the effective date of any changes constitutes your acceptance of the updated Policy. If you do not agree with the changes, you should stop using the Service and delete your account.</p>
    <a class="legal-back-top" href="#privacy-top">&#8593; Back to top</a>
</div>

<!-- 16. Contact -->
<div class="legal-section" id="p-contact">
    <h2>16. Contact Us</h2>
    <p>If you have questions, concerns, or requests regarding this Privacy Policy or our data practices, please contact us:</p>
    <div class="legal-contact-box">
        <h3>IMU, LLC</h3>
        <p><strong>Privacy Inquiries:</strong> <a href="mailto:privacy@imutv.tv">privacy@imutv.tv</a></p>
        <p><strong>General Legal:</strong> <a href="mailto:legal@imutv.tv">legal@imutv.tv</a></p>
        <p><strong>Mailing Address:</strong> 4651 Babcock St., NE, STE 18 Box 112, Palm Bay, FL 32905</p>
        <p><strong>Website:</strong> <a href="https://www.imutv.tv" target="_blank" rel="noopener">www.imutv.tv</a> and <a href="https://www.imcollectibles.io" target="_blank" rel="noopener">www.IMCollectibles.io</a></p>
        <div class="legal-contact-divider">
            <p><strong>For California Residents:</strong> You may also submit a verifiable consumer request through <a href="mailto:privacy@imutv.tv">privacy@imutv.tv</a>.</p>
            <p><strong>For EEA/UK Residents:</strong> If you are unsatisfied with our response, you have the right to lodge a complaint with your local Data Protection Authority.</p>
            <p><strong>For Canadian Residents:</strong> You may file a complaint with the Office of the Privacy Commissioner of Canada at <a href="https://priv.gc.ca" target="_blank" rel="noopener">priv.gc.ca</a>.</p>
        </div>
    </div>
    <div class="legal-footer-note">Last Updated: February 22, 2026 &nbsp;&middot;&nbsp; <a href="#toc">&#8593; Back to Table of Contents</a></div>
</div>

</div><!-- /.legal-container -->
</div><!-- /.legal-page-wrap -->

<?php get_footer(); ?>