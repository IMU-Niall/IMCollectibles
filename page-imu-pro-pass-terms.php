<?php
/**
 * Template Name: IMU Pro Pass Terms
 * File: page-imu-pro-pass-terms.php
 * Path: /wp-content/themes/astra/page-imu-pro-pass-terms.php
 * IMU Pro Pass — Pre-Sale & Membership Terms — IMU, LLC
 * Reuses the shared IMU legal-page branding (matches page-terms.php / page-privacy.php).
 */
global $imc_og_data;
$imc_og_data = [
    'title'       => 'IMU Pro Pass — Pre-Sale & Membership Terms | IMCollectibles',
    'description' => 'Pre-sale and membership terms for the IMU Pro Pass NFT collection — pre-paid IMU Pro access across IMUTV and IMUP3.',
    'image'       => defined('IMC_OG_DEFAULT_IMAGE') ? IMC_OG_DEFAULT_IMAGE : 'https://imcollectibles.io/wp-content/uploads/og-default.png',
    'url'         => home_url('/IMU-Pro-Pass-NFT-Terms/'),
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
<div class="legal-page-wrap" id="pp-top">
<div class="legal-container">

<!-- Hero -->
<div class="legal-hero">
    <div class="legal-eyebrow">IMU, LLC &mdash; IMU Pro Pass</div>
    <h1>IMU Pro Pass<br>Pre-Sale &amp; Membership Terms</h1>
    <p class="legal-subtitle">IMU Pro Passes are pre-paid memberships to IMU Pro &mdash; the premium tier across IMUTV and IMUP3 &mdash; sold as an open edition through the pre-launch window. Each Pass grants a fixed period of full Pro access from launch, followed by a long-term renewal discount.</p>
    <div class="legal-meta">
        <span><strong>IMU Pro launch (scheduled):</strong> 1 December 2026</span>
        <span><strong>Refund backstop:</strong> 31 January 2027</span>
        <span><strong>Entity:</strong> IMU, LLC</span>
    </div>
</div>

<div class="legal-notice">
    These terms are specific to the IMU Pro Pass mint. General purchase, wallet, custody, network-fee, artwork/IP and marketplace terms are governed by the <a href="https://imcollectibles.io/terms/">IMCollectibles site and minting terms</a>, which apply in addition to these.
</div>

<!-- TOC -->
<div class="legal-toc" id="toc">
    <div class="legal-toc-title">Table of Contents</div>
    <div class="legal-toc-grid">
        <div class="legal-toc-part">Overview</div>
        <div class="legal-toc-item"><span class="legal-toc-num">&bull;</span><a href="#pp-passes">The Three Passes</a></div>
        <div class="legal-toc-item"></div>
        <div class="legal-toc-part">Pre-Sale &amp; Membership Terms</div>
        <div class="legal-toc-item"><span class="legal-toc-num">1.</span><a href="#pp-s1">What an IMU Pro Pass is</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">7.</span><a href="#pp-s7">Pricing (dynamic, XRP-denominated)</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">2.</span><a href="#pp-s2">Pre-sale status &amp; launch</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">8.</span><a href="#pp-s8">Pro benefits &amp; offline playback</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">3.</span><a href="#pp-s3">Start of access</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">9.</span><a href="#pp-s9">Transfer, resale &amp; effect on access</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">4.</span><a href="#pp-s4">Refund backstop</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">10.</span><a href="#pp-s10">No investment / no profit expectation</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">5.</span><a href="#pp-s5">Open edition &amp; supply</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">11.</span><a href="#pp-s11">Dependencies</a></div>
        <div class="legal-toc-item"><span class="legal-toc-num">6.</span><a href="#pp-s6">No minting limit</a></div>
        <div class="legal-toc-item"></div>
    </div>
</div>

<!-- Overview: the three passes -->
<div class="legal-section" id="pp-passes">
    <h2>The Three Passes</h2>
    <p>A Pro Pass is the simplest way to lock in IMU Pro early at a saving on the standard $3/month price. Three editions &mdash; Prelude, Crescendo and Encore &mdash; differ in how many months of Pro they include and how long the renewal discount lasts. All include offline downloads and playback across IMUTV and IMUP3, plus unlimited IMUP3 playlists. Pro access begins when IMU Pro goes live.</p>
    <div class="legal-table-wrap">
        <table class="legal-table">
            <thead><tr><th>Edition</th><th>Active Pro</th><th>Then 50% off</th><th>Price</th><th>Hard value</th></tr></thead>
            <tbody>
                <tr><td><strong>Prelude</strong></td><td>12 mo (Dec 1 &rsquo;26 &ndash; Dec 1 &rsquo;27)</td><td>24 mo (to Dec 1 &rsquo;29)</td><td>$30</td><td>$36</td></tr>
                <tr><td><strong>Crescendo</strong></td><td>18 mo (Dec 1 &rsquo;26 &ndash; Jun 1 &rsquo;28)</td><td>36 mo (to Jun 1 &rsquo;31)</td><td>$45</td><td>$54</td></tr>
                <tr><td><strong>Encore</strong></td><td>24 mo (Dec 1 &rsquo;26 &ndash; Dec 1 &rsquo;28)</td><td>49 mo (to Jan 1 &rsquo;33)</td><td>$60</td><td>$72</td></tr>
            </tbody>
        </table>
    </div>

    <h3>Encore Edition &mdash; $60</h3>
    <p>The most generous IMU Pro Pass: 24 months of full IMU Pro from launch, then 50% off Pro renewals through to January 2033. Including offline downloads and playback across IMUTV and IMUP3, plus unlimited IMUP3 playlists. The definitive front-row pass for the IMU ecosystem.</p>
    <div class="legal-table-wrap"><table class="legal-table"><thead><tr><th>Trait</th><th>Value</th></tr></thead><tbody>
        <tr><td>IMU Pro Pass</td><td>Pro Pass active between 1 December 2026 &ndash; 1 December 2028</td></tr>
        <tr><td>IMU Encore Pro Pass Discount</td><td>50% discount on any IMU Pro Pass between 1 December 2028 &ndash; 1 January 2033</td></tr>
        <tr><td>IMU Pro Benefit 1</td><td>Offline downloads &amp; playback of content across IMUTV + IMUP3</td></tr>
        <tr><td>IMU Pro Benefit 2</td><td>Unlimited IMUP3 playlists</td></tr>
    </tbody></table></div>

    <h3>Crescendo Edition &mdash; $45</h3>
    <p>The mid-tier IMU Pro Pass: 18 months of full IMU Pro from launch, then 50% off Pro renewals for a further three years. Including offline downloads and playback across IMUTV and IMUP3, plus unlimited IMUP3 playlists. A balanced pre-paid membership with a long discount window to carry you well beyond it.</p>
    <div class="legal-table-wrap"><table class="legal-table"><thead><tr><th>Trait</th><th>Value</th></tr></thead><tbody>
        <tr><td>IMU Pro Pass</td><td>Pro Pass active between 1 December 2026 &ndash; 1 June 2028</td></tr>
        <tr><td>IMU Crescendo Pro Pass Discount</td><td>50% discount on any IMU Pro Pass between 1 June 2028 &ndash; 1 June 2031</td></tr>
        <tr><td>IMU Pro Benefit 1</td><td>Offline downloads &amp; playback of content across IMUTV + IMUP3</td></tr>
        <tr><td>IMU Pro Benefit 2</td><td>Unlimited IMUP3 playlists</td></tr>
    </tbody></table></div>

    <h3>Prelude Edition &mdash; $30</h3>
    <p>The entry IMU Pro Pass: 12 months of full IMU Pro from launch, then 50% off Pro renewals for two more years. Including offline downloads and playback across IMUTV and IMUP3, plus unlimited IMUP3 playlists. The simplest way to step into IMU Pro early, at a saving on the standard monthly price.</p>
    <div class="legal-table-wrap"><table class="legal-table"><thead><tr><th>Trait</th><th>Value</th></tr></thead><tbody>
        <tr><td>IMU Pro Pass</td><td>Pro Pass active between 1 December 2026 &ndash; 1 December 2027</td></tr>
        <tr><td>IMU Prelude Pro Pass Discount</td><td>50% discount on any IMU Pro Pass between 1 December 2027 &ndash; 1 December 2029</td></tr>
        <tr><td>IMU Pro Benefit 1</td><td>Offline downloads &amp; playback of content across IMUTV + IMUP3</td></tr>
        <tr><td>IMU Pro Benefit 2</td><td>Unlimited IMUP3 playlists</td></tr>
    </tbody></table></div>

    <div class="legal-notice">Each edition is an <strong>open edition</strong> with unlimited supply available to purchase until IMU Pro goes live (expected 1 December 2026), after which the mint permanently closes. Passes are priced in USD and settled in XRP at the live rate at the moment of mint (see &sect;7).</div>
</div>

<!-- Full terms -->
<div class="legal-section" id="pp-s1">
    <h2>1. What an IMU Pro Pass is</h2>
    <p>An IMU Pro Pass is a pre-paid membership to IMU Pro, the premium subscription tier across IMUTV and IMUP3. Each Pass entitles its holder to the period of IMU Pro access, the renewal discount, and the Pro benefits stated in that Pass&rsquo;s edition and on-chain traits. A Pass is a membership utility token, not an investment (see section 10).</p>
</div>

<div class="legal-section" id="pp-s2">
    <h2>2. Pre-sale status &amp; launch</h2>
    <p>IMU Pro is not yet live. Passes are sold ahead of launch as a pre-order. IMU Pro is scheduled to go live on <strong>1 December 2026</strong>. Each Pass&rsquo;s active period and discount window run for the fixed calendar dates stated in its traits.</p>
</div>

<div class="legal-section" id="pp-s3">
    <h2>3. Start of access</h2>
    <p>IMU Pro access and all included benefits begin when IMU Pro launches and run through the fixed dates specified in each Pass&rsquo;s traits. The stated dates assume launch on 1 December 2026.</p>
</div>

<div class="legal-section" id="pp-s4">
    <h2>4. Refund backstop</h2>
    <div class="legal-notice"><strong>If IMU Pro has not gone live by 31 January 2027</strong>, holders may request a refund of the purchase price via the process published on IMCollectibles. This backstop is the holder&rsquo;s protection against non-delivery of the service.</div>
</div>

<div class="legal-section" id="pp-s5">
    <h2>5. Open edition &amp; supply</h2>
    <p>Passes are issued as an <strong>open edition with no fixed supply</strong>. Minting remains open until IMU Pro launches (expected 1 December 2026), after which the mint permanently closes. No further Passes of these editions will be issued after close.</p>
</div>

<div class="legal-section" id="pp-s6">
    <h2>6. No minting limit</h2>
    <p>There is no per-wallet limit. A holder may mint multiple Passes &mdash; for example, to gift to family or friends. Each Pass is an independent membership and confers benefits only on its current verified owner.</p>
</div>

<div class="legal-section" id="pp-s7">
    <h2>7. Pricing (dynamic, XRP-denominated)</h2>
    <p>Passes are priced in USD ($30 / $45 / $60 by edition) and settled in XRP. The XRP amount is calculated in real time at point of purchase using IMCollectibles&rsquo; dynamic pricing, so the XRP payable reflects the live exchange rate at the moment of mint and will vary with the market. The USD figure is the reference price; the XRP charged is its real-time equivalent.</p>
</div>

<div class="legal-section" id="pp-s8">
    <h2>8. Pro benefits &amp; offline playback</h2>
    <h3>8a. Included benefits</h3>
    <p>Each Pass includes, for its active period: offline downloads and playback across IMUTV and IMUP3, and unlimited IMUP3 playlists. IMU Pro may expand over time, but only the benefits expressly listed in a Pass&rsquo;s traits are guaranteed.</p>
    <h3>8b. Offline playback &mdash; standard content</h3>
    <p>During the active period, holders may download and play standard catalogue content for offline playback within the IMU apps.</p>
    <h3>8c. Offline playback &mdash; NFT-gated content</h3>
    <p>Offline playback also extends to NFT-gated content the holder is entitled to. Access to gated content offline is enabled by locally caching the entitlement result (see 8d), allowing playback without a live connection between verification checks.</p>
    <h3>8d. Entitlement caching &amp; daily re-verification</h3>
    <p>Because Passes are NFT-ownership-based and freely transferable, entitlement is verified on a rolling basis. On verification, an entitlement result is cached locally for up to <strong>24 hours</strong>, during which downloaded content remains playable offline. Entitlement is then re-verified &mdash; at least once every 24 hours when a connection is available &mdash; against current on-chain ownership. Offline access between checks is a function of this caching interval and is not a guarantee of uninterrupted access independent of ownership.</p>
</div>

<div class="legal-section" id="pp-s9">
    <h2>9. Transfer, resale &amp; effect on access</h2>
    <p>A Pass may be transferred or sold; the membership and all benefits move with ownership. On transfer, the previous owner&rsquo;s access ends at the next entitlement re-verification (within the caching interval described in 8d) &mdash; access does not terminate instantly at the moment of transfer, and any content cached by the previous holder ceases to be authorised at the next check. The new owner gains access from their next successful verification.</p>
</div>

<div class="legal-section" id="pp-s10">
    <h2>10. No investment / no profit expectation</h2>
    <p class="legal-caps">IMU Pro Passes are sold solely for their membership utility. They are not investments, securities, or financial instruments, and are not offered with any expectation of profit, appreciation, or financial return.</p>
    <p>IMU does not promote, facilitate, guarantee, or imply any resale or secondary-market value. Any secondary transfer is between holders, at their sole discretion and risk.</p>
</div>

<div class="legal-section" id="pp-s11">
    <h2>11. Dependencies</h2>
    <p>Benefits require a functioning IMU account, continued operation of IMUTV and IMUP3, and verifiable ownership of the Pass. Benefits are delivered through IMU&rsquo;s entitlement system.</p>
    <div class="legal-footer-note" style="margin-top:2.5rem;">These pre-sale terms are specific to IMU Pro Passes and apply alongside the IMCollectibles site and minting terms. They are provided for transparency and do not constitute legal or financial advice. &nbsp;&middot;&nbsp; <a href="#toc">&#8593; Back to Table of Contents</a></div>
</div>

</div><!-- /.legal-container -->
</div><!-- /.legal-page-wrap -->

<?php get_footer(); ?>