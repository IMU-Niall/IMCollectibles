<?php
/**
 * ============================================================================
 * TEMPLATE: page-artist.php  —  Public artist profile  (/artists/{slug})
 * PATH: theme root (astra-child)
 * ============================================================================
 * Loaded by the artist_slug route in functions.php (Phase 4 foundation).
 * Resolves slug -> artist_account -> profile, renders the hero + collections,
 * and a tip flow (XRP + matched tokens) via tip-handler.php / Xaman.
 * Read-only & public. Verified badge is deferred (column inert this phase).
 * ============================================================================
 */
if (!defined('ABSPATH')) { exit; }

$artist_slug    = sanitize_title(get_query_var('artist_slug'));
$artist_account = ($artist_slug !== '' && function_exists('imc_artist_account_by_slug'))
    ? imc_artist_account_by_slug($artist_slug) : '';
$profile = ($artist_account !== '' && function_exists('imc_artist_profile_get'))
    ? imc_artist_profile_get($artist_account) : null;

$not_found = ($artist_account === '' || empty($profile));
$is_verified = (!$not_found && function_exists('imc_is_verified_creator')) ? imc_is_verified_creator($artist_account) : false;
$is_ai_verified = (!$not_found && !$is_verified && function_exists('imc_is_verified_ai_creator')) ? imc_is_verified_ai_creator($artist_account) : false;

$display_name = '';
$bio = '';
$social = [];
if (!$not_found) {
    $display_name = ($profile['display_name'] ?? '') !== ''
        ? $profile['display_name']
        : (function_exists('imc_wallet_short') ? imc_wallet_short($artist_account) : $artist_account);
    $bio    = $profile['bio'] ?? '';
    $social = !empty($profile['social_links']) && is_array($profile['social_links']) ? $profile['social_links'] : [];
}

// --- Stats (server-side, indexed lookups) -----------------------------------
$stat_collections = $stat_minted = $stat_holders = 0;
if (!$not_found) {
    global $wpdb;
    $lt = $wpdb->prefix . 'imc_listings';
    $pt = $wpdb->prefix . 'imc_purchases';
    $stat_collections = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT collection_taxon) FROM {$lt} WHERE artist_account = %s AND is_hidden = 0", $artist_account));
    $stat_minted = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$pt} WHERE artist_account = %s AND mint_status IN ('minted','claimed')", $artist_account));
    $stat_holders = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT buyer_account) FROM {$pt} WHERE artist_account = %s AND mint_status IN ('minted','claimed')", $artist_account));
}

// --- Closing Soon: this artist's active Open Editions (server-side) ----------
// v647 (additive, READ-ONLY): emit the full eligible OE queue, soonest-ending
// first, so the client can advance in-place when one closes (no reload/refetch).
// Eligibility mirrors the OE cron + collections drop-rail exactly. This path
// performs NO writes (unlike the collections lazy auto-close) — filter only.
$imc_cs_oes = [];
if (!$not_found) {
    global $wpdb;
    $cs_lt   = $wpdb->prefix . 'imc_listings';
    $cs_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, nft_name, collection_taxon, cover_ipfs, price_xrp, price_usd, pricing_mode,
                accepted_currencies,
                minted_count, open_edition_ends_at
           FROM {$cs_lt}
          WHERE artist_account = %s
            AND is_hidden = 0
            AND edition_type = 'open'
            AND status = 'active'
            AND open_edition_closed_at IS NULL
            AND open_edition_ends_at IS NOT NULL
            AND open_edition_ends_at > UTC_TIMESTAMP()
            AND NOT (launch_type = 'scheduled' AND launch_at IS NOT NULL AND launch_at > UTC_TIMESTAMP())
          ORDER BY open_edition_ends_at ASC, id ASC",
        $artist_account
    ), ARRAY_A);

    if ($cs_rows) {
        foreach ($cs_rows as $cs_r) {
            // Cover via VPS img proxy (thumb) — matches the drop-rail convention.
            $cs_cover = '/wp-content/uploads/fallback-nft.svg';
            if (!empty($cs_r['cover_ipfs'])) {
                $cs_cid   = str_replace('ipfs://', '', $cs_r['cover_ipfs']);
                $cs_cover = 'https://metadata.imcollectibles.io/img.php?url=' . urlencode('ipfs://' . $cs_cid) . '&thumb=1';
            }
            // Price string — mirrors collections.php $bx_price (static vs dynamic/USD).
            $cs_pusd  = !empty($cs_r['price_usd']) ? floatval($cs_r['price_usd']) : null;
            // v665: token-agnostic primary price (see functions.php).
            $cs_price = imc_primary_display_price($cs_r);
            // Collection URL — mirrors trading-hub carousel (pretty slug, issuer-taxon fallback).
            $cs_slug = function_exists('imu_get_collection_slug')
                ? imu_get_collection_slug($artist_account, $cs_r['collection_taxon'])
                : ($artist_account . '-' . (int) $cs_r['collection_taxon']);
            $imc_cs_oes[] = [
                'id'     => (int) $cs_r['id'],
                'name'   => ($cs_r['nft_name'] !== '' && $cs_r['nft_name'] !== null) ? $cs_r['nft_name'] : 'Untitled',
                'cover'  => $cs_cover,
                'price'  => $cs_price,
                'minted' => (int) $cs_r['minted_count'],
                'ends'   => $cs_r['open_edition_ends_at'], // naive UTC DATETIME
                'href'   => home_url('/collections/' . $cs_slug . '/'),
            ];
        }
    }
}

// --- Avatar (default reuses the dashboard's gold-"?" SVG; emitted raw) -------
$default_pfp = 'data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22160%22 height=%22160%22><rect width=%22160%22 height=%22160%22 fill=%22%231a1a2e%22/><text x=%2280%22 y=%22100%22 font-size=%2264%22 fill=%22%23d4af37%22 text-anchor=%22middle%22>?</text></svg>';
$pfp_raw     = (!$not_found && !empty($profile['profile_image_url'])) ? $profile['profile_image_url'] : '';

// --- JS config --------------------------------------------------------------
$xrpl_account = function_exists('imc_session_wallet') ? imc_session_wallet() : '';
$theme_uri    = get_stylesheet_directory_uri();
$endpoints    = [
    'listings' => $theme_uri . '/xrpl-nft-marketplace/backend/listings-handler.php',
    'tips'     => $theme_uri . '/xrpl-nft-marketplace/backend/tip-handler.php',
    'collections' => 'https://metadata.imcollectibles.io/',
];
$nonce = wp_create_nonce('xrpl_marketplace_nonce');

// --- Open Graph (share preview) ---------------------------------------------
// v644: set $imc_og_data so imc_output_og_seo_meta() (functions.php, wp_head:2)
// emits ONE complete, de-duplicated tag set (og + twitter:image + og:url +
// canonical). Previously this hooked wp_head directly and left $imc_og_data
// empty, so the functions.php resolver ALSO fired and appended the generic
// site-default tags (default image, generic title) — conflicting duplicate cards.
if (!$not_found) {
    global $imc_og_data;

    // Profile image — crawler-safe. Route IPFS/Pinata through img.php (unwrapped to a
    // direct gateway at output by imc_normalise_og_image, exactly like collection covers).
    // Skip data: URIs (the default SVG avatar); floor to the branded default so a
    // creator share link never renders empty.
    $_ao_img = '';
    if ($pfp_raw !== '' && strpos($pfp_raw, 'data:') !== 0) {
        if (strpos($pfp_raw, 'ipfs://') === 0 || strpos($pfp_raw, 'mypinata.cloud') !== false || strpos($pfp_raw, 'ipfs.io') !== false) {
            $_ao_img = 'https://metadata.imcollectibles.io/img.php?url=' . urlencode($pfp_raw);
        } else {
            $_ao_img = $pfp_raw; // already an absolute https image
        }
    }
    if ($_ao_img === '') {
        $_ao_img = defined('IMC_OG_DEFAULT_IMAGE') ? IMC_OG_DEFAULT_IMAGE : '';
    }

    $_ao_desc = $bio !== '' ? wp_trim_words($bio, 30) : ('Collect ' . $display_name . ' on IMCollectibles.');

    $imc_og_data = [
        'title'       => esc_attr($display_name) . ' | IMCollectibles',
        'description' => esc_attr(wp_strip_all_tags($_ao_desc)),
        'image'       => esc_url($_ao_img),
        'url'         => esc_url(home_url('/artists/' . $artist_slug . '/')),
        'type'        => 'profile',
    ];
}

// Social link config: key => [label, host-hint]
$social_labels = [
    'imutv'     => 'IMUTV',
    'imup3'     => 'IMUP3',
    'website'   => 'Website',
    'x'         => 'X',
    'instagram' => 'Instagram',
    'discord'   => 'Discord',
    'youtube'   => 'YouTube',
];

get_header();
?>
<style>
body.has-imp-header{background:radial-gradient(1000px 520px at 50% -8%,rgba(212,175,55,.06),transparent 72%),linear-gradient(160deg,#08080e 0%,#0e0e16 55%,#141420 100%) !important;background-attachment:fixed !important;background-repeat:no-repeat !important;}
.imc-artist-page{max-width:1100px;margin:0 auto;padding:28px 18px 64px;color:#e8e8e8;}
.imc-artist-empty{text-align:center;padding:80px 20px;color:#cfcfcf;}
.imc-artist-empty h1{color:#d4af37;font-size:1.8rem;margin-bottom:10px;}
.imc-artist-hero{position:relative;text-align:center;background:linear-gradient(180deg,#15151f 0%,#0f0f17 100%);border:1px solid #2a2a3a;border-radius:18px;padding:32px 30px;overflow:hidden;}
.imc-artist-hero::before{content:"";position:absolute;inset:0;background:radial-gradient(1100px 300px at 50% -70%,rgba(212,175,55,.10),transparent 70%);pointer-events:none;}
.imc-hero-id{position:relative;display:flex;flex-direction:column;align-items:center;}
.imc-pfp{width:120px;height:120px;border-radius:50%;object-fit:cover;background:#1a1a2e;border:2px solid #d4af37;box-shadow:0 0 0 4px rgba(212,175,55,.10);}
.imc-hero-name{margin-top:16px;}
.imc-hero-name h1{margin:0;font-size:1.95rem;line-height:1.15;color:#fff;}
.imc-hero-vbadge{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:linear-gradient(180deg,#ffe066,#c8962f);color:#0f0f17;font-size:13px;font-weight:900;box-shadow:0 0 0 2px rgba(212,175,55,.18);}
.imc-verified-row{margin-top:9px;display:inline-flex;align-items:center;gap:7px;font-size:.92rem;font-weight:700;letter-spacing:.4px;color:var(--imu-gold, #d6ba66);}
.imc-hero-ai-vbadge{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:linear-gradient(180deg,#7b5cff,#4a2fd0);color:#fff;font-size:13px;font-weight:900;box-shadow:0 0 0 2px rgba(123,92,255,.22);}
.imc-ai-row{margin-top:9px;display:inline-flex;align-items:center;gap:7px;font-size:.92rem;font-weight:700;letter-spacing:.4px;color:#b9a6ff;}
.imc-wallet{display:inline-flex;align-items:center;gap:8px;margin-top:12px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.84rem;color:#9aa0aa;background:#0e0e16;border:1px solid #242433;border-radius:9px;padding:6px 12px;}
.imc-wallet button{background:none;border:none;color:#9aa0aa;cursor:pointer;padding:0;display:inline-flex;line-height:1;}
.imc-wallet button:hover{color:#d4af37;}
.imc-tip-btn{position:absolute;top:24px;right:24px;z-index:2;background:linear-gradient(180deg,#e9c25a,#d4af37);color:#241b04;border:none;border-radius:12px;padding:11px 20px;font-weight:700;font-size:.96rem;cursor:pointer;display:inline-flex;align-items:center;gap:9px;transition:transform .12s ease,filter .12s ease;}
.imc-tip-btn:hover{filter:brightness(1.06);transform:translateY(-1px);}
.imc-stats{position:relative;display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:26px;}
.imc-stat{background:#12121b;border:1px solid #262636;border-radius:12px;padding:16px;text-align:center;}
.imc-stat .v{font-size:1.6rem;font-weight:700;color:#fff;line-height:1;}
.imc-stat .l{font-size:.72rem;color:#8a8f99;text-transform:uppercase;letter-spacing:.06em;margin-top:6px;}
.imc-bio{position:relative;margin:24px auto 0;color:#c7ccd4;line-height:1.7;font-size:.96rem;max-width:60ch;white-space:pre-line;text-align:center;}
.imc-links{position:relative;display:flex;flex-wrap:wrap;gap:10px;margin-top:20px;justify-content:center;}
.imc-links a{display:inline-flex;align-items:center;gap:7px;background:#16161f;border:1px solid #2a2a3a;color:#d7dbe2;text-decoration:none;padding:8px 16px;border-radius:10px;font-size:.86rem;transition:border-color .12s,color .12s;}
.imc-links a:hover{border-color:#d4af37;color:#d4af37;}
.imc-section-h{display:flex;align-items:center;gap:10px;margin:38px 0 16px;}
.imc-section-h::before{content:"";width:4px;height:22px;background:#d4af37;border-radius:2px;}
.imc-section-h h2{margin:0;font-size:1.3rem;color:#fff;}
.imc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:18px;}
.imc-card{background:#12121b;border:1px solid #242433;border-radius:14px;overflow:hidden;text-decoration:none;color:inherit;display:flex;flex-direction:column;transition:transform .12s ease,border-color .12s ease;}
.imc-card:hover{transform:translateY(-3px);border-color:#3a3a52;}
.imc-card-cover{aspect-ratio:1/1;background:#0e0e16;position:relative;overflow:hidden;}
.imc-card-cover img{width:100%;height:100%;object-fit:cover;display:block;}
.imc-card-sold{position:absolute;top:10px;left:10px;background:rgba(190,40,40,.92);color:#fff;font-size:.68rem;font-weight:700;padding:4px 9px;border-radius:6px;letter-spacing:.03em;}
.imc-card-b{padding:12px 13px;}
.imc-card-b h3{margin:0;font-size:.98rem;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.imc-card-b .ca{font-size:.8rem;color:#d4af37;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.imc-bar{height:5px;background:#22222e;border-radius:3px;overflow:hidden;margin-top:11px;}
.imc-bar > i{display:block;height:100%;background:linear-gradient(90deg,#d4af37,#e9c25a);}
.imc-card-meta{display:flex;justify-content:space-between;margin-top:7px;font-size:.74rem;color:#8a8f99;}
.imc-empty-grid{color:#8a8f99;padding:30px 0;}
.imc-modal{position:fixed;inset:0;background:rgba(6,6,10,.78);display:none;align-items:center;justify-content:center;z-index:99999;padding:18px;}
.imc-modal.open{display:flex;}
.imc-modal-box{background:#13131c;border:1px solid #2c2c3e;border-radius:18px;width:100%;max-width:420px;padding:24px;position:relative;}
.imc-modal-box h3{margin:0 0 4px;color:#fff;font-size:1.2rem;}
.imc-modal-box .sub{color:#8a8f99;font-size:.84rem;margin-bottom:18px;}
.imc-modal-close{position:absolute;top:14px;right:16px;background:none;border:none;color:#8a8f99;font-size:1.4rem;cursor:pointer;line-height:1;}
.imc-field{margin-bottom:14px;}
.imc-field label{display:block;font-size:.78rem;color:#9aa0aa;margin-bottom:6px;text-transform:uppercase;letter-spacing:.04em;}
.imc-field select,.imc-field input{width:100%;background:#0e0e16;border:1px solid #2c2c3e;color:#fff;border-radius:10px;padding:11px 12px;font-size:.95rem;}
.imc-field .hint{font-size:.74rem;color:#7a7f89;margin-top:5px;}
.imc-send{width:100%;background:linear-gradient(180deg,#e9c25a,#d4af37);color:#241b04;border:none;border-radius:12px;padding:13px;font-weight:700;font-size:1rem;cursor:pointer;}
.imc-send:disabled{opacity:.5;cursor:not-allowed;}
.imc-qr-wrap{text-align:center;}
.imc-qr-wrap img{width:210px;height:210px;background:#fff;border-radius:12px;padding:8px;}
.imc-qr-wrap .dl{display:inline-block;margin-top:14px;color:#d4af37;text-decoration:none;font-weight:600;}
.imc-status{margin-top:14px;font-size:.9rem;color:#c7ccd4;text-align:center;}
.imc-status.ok{color:#5dca8e;}
.imc-status.err{color:#e26b6b;}
.imc-note{font-size:.82rem;color:#8a8f99;background:#0e0e16;border:1px solid #242433;border-radius:10px;padding:10px 12px;}
@media(max-width:600px){.imc-artist-hero{padding:24px 16px;}.imc-tip-btn{position:static;margin:18px auto 0;}.imc-hero-name h1{font-size:1.55rem;}.imc-stat{padding:12px 8px;}.imc-stat .v{font-size:1.3rem;}.imc-stat .l{font-size:.64rem;}}

/* --- Closing Soon banner (v647; v648 green-strobe restyle) --------------- */
.imc-closing-soon{margin:26px 0 0;}
.imc-cs-card{display:flex;align-items:center;gap:15px;background:linear-gradient(135deg,#0f1410 0%,#0d0d12 60%);border:2px solid #00ff00;border-radius:14px;padding:14px 16px;text-decoration:none;color:inherit;position:relative;animation:imc-cs-strobe 1.1s ease-in-out infinite alternate;transition:transform .12s ease;}
.imc-cs-card:hover{transform:translateY(-2px);}
@keyframes imc-cs-strobe{
  from{border-color:rgba(0,255,0,.32);box-shadow:0 0 5px rgba(0,255,0,.18),inset 0 0 4px rgba(0,255,0,.06);}
  to  {border-color:#00ff00;box-shadow:0 0 20px rgba(0,255,0,.80),inset 0 0 11px rgba(0,255,0,.22);}
}
@media (prefers-reduced-motion:reduce){.imc-cs-card{animation:none;border-color:#00ff00;box-shadow:0 0 12px rgba(0,255,0,.5);}}
.imc-cs-cover{flex:0 0 auto;width:74px;height:74px;border-radius:10px;overflow:hidden;background:#0e0e16;}
.imc-cs-cover img{width:100%;height:100%;object-fit:cover;display:block;}
.imc-cs-body{flex:1 1 auto;min-width:0;}
.imc-cs-hero{display:inline-flex;align-items:center;gap:6px;font-size:.74rem;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:#00ff00;text-shadow:0 0 8px rgba(0,255,0,.45);margin-bottom:6px;}
.imc-cs-titlerow{display:flex;align-items:baseline;flex-wrap:wrap;gap:8px;}
.imc-cs-name{font-size:1.02rem;font-weight:700;color:#fff;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.imc-cs-price{color:var(--imu-gold, #d6ba66);font-weight:600;font-size:.9rem;}
.imc-cs-minted{color:#8a8f99;font-size:.9rem;}
.imc-cs-minted strong{color:#c7ccd4;}
.imc-cs-dot{color:#3a3a4a;}
.imc-cs-countdown{display:flex;align-items:center;gap:9px;margin-top:9px;flex-wrap:wrap;}
.imc-cs-cd-label{font-size:.66rem;text-transform:uppercase;letter-spacing:.05em;color:#8a8f99;}
.imc-cs-units{display:inline-flex;gap:5px;}
.imc-cs-units .u{display:inline-flex;align-items:baseline;gap:2px;background:#0e0e16;border:1px solid #1e3a1e;border-radius:7px;padding:3px 7px;}
.imc-cs-units .u b{font-variant-numeric:tabular-nums;font-size:.9rem;line-height:1;color:#fff;font-weight:700;min-width:1.5ch;text-align:center;}
.imc-cs-units .u em{font-style:normal;font-size:.6rem;color:#8a8f99;text-transform:uppercase;}
.imc-cs-more{flex:0 0 auto;align-self:center;background:#0e0e16;border:1px solid #1e3a1e;color:#7fe07f;font-size:.72rem;font-weight:700;padding:5px 11px;border-radius:20px;white-space:nowrap;}
.imc-cs-ended .imc-cs-card{opacity:.7;animation:none;border-color:#555;box-shadow:none;}
.imc-cs-ended .imc-cs-hero{color:#8a8f99;text-shadow:none;}
@media (max-width:560px){
  .imc-cs-card{flex-wrap:wrap;gap:12px;}
  .imc-cs-cover{width:58px;height:58px;}
  .imc-cs-more{order:3;align-self:flex-start;}
}
</style>

<div class="imc-artist-page">
<?php if ($not_found): ?>
  <div class="imc-artist-empty">
    <h1>Artist not found</h1>
    <p>This profile doesn&rsquo;t exist yet, or the artist hasn&rsquo;t set one up.</p>
    <p style="margin-top:18px;"><a class="imc-tip-btn" href="<?php echo esc_url(home_url('/collections/')); ?>">Browse collections</a></p>
  </div>
<?php else: ?>
  <section class="imc-artist-hero">
    <button type="button" class="imc-tip-btn" id="imc-open-tip">&#9889; Tip artist</button>
    <div class="imc-hero-id">
      <img class="imc-pfp" alt="<?php echo esc_attr($display_name); ?>"
           onerror="this.onerror=null;this.src='<?php echo esc_js($default_pfp); ?>'"
           src="<?php echo $pfp_raw !== '' ? esc_url($pfp_raw) : $default_pfp; ?>">
      <div class="imc-hero-name"><h1><?php echo esc_html($display_name); ?></h1><?php if ($is_verified): ?><div class="imc-verified-row"><span class="imc-hero-vbadge" title="Verified Artist">&#10003;</span> Verified Artist</div><?php elseif ($is_ai_verified): ?><div class="imc-ai-row"><span class="imc-hero-ai-vbadge" title="Verified AI Artist">&#129302;</span> Verified AI Artist</div><?php endif; ?></div>
      <div class="imc-wallet">
        <span id="imc-wallet-text" data-full="<?php echo esc_attr($artist_account); ?>"><?php
          echo esc_html(function_exists('imc_wallet_short') ? imc_wallet_short($artist_account) : $artist_account); ?></span>
        <button type="button" id="imc-copy-wallet" title="Copy wallet address" aria-label="Copy wallet address">&#x2398;</button>
      </div>
    </div>

    <div class="imc-stats">
      <div class="imc-stat"><div class="v"><?php echo number_format_i18n($stat_collections); ?></div><div class="l">Collections</div></div>
      <div class="imc-stat"><div class="v"><?php echo number_format_i18n($stat_minted); ?></div><div class="l">NFTs minted</div></div>
      <div class="imc-stat"><div class="v"><?php echo number_format_i18n($stat_holders); ?></div><div class="l">Holders</div></div>
    </div>

    <?php if ($bio !== ''): ?>
      <div class="imc-bio"><?php echo esc_html($bio); ?></div>
    <?php endif; ?>

    <?php if (!empty($social)):
      $links_html = '';
      foreach ($social_labels as $k => $label) {
          if (!empty($social[$k])) {
              $url = esc_url($social[$k]);
              if ($url !== '') {
                  $links_html .= '<a href="' . $url . '" target="_blank" rel="noopener nofollow">' . esc_html($label) . '</a>';
              }
          }
      }
      // v579: custom creator links (free-form label+url) folded into the same row.
      if (!empty($social['custom_links']) && is_array($social['custom_links'])) {
          foreach ($social['custom_links'] as $cl) {
              if (empty($cl['url']) || empty($cl['label'])) { continue; }
              $curl = esc_url($cl['url']);
              if ($curl === '') { continue; }
              $links_html .= '<a href="' . $curl . '" target="_blank" rel="noopener nofollow">' . esc_html($cl['label']) . '</a>';
          }
      }
      if ($links_html !== ''): ?>
        <div class="imc-links"><?php echo $links_html; ?></div>
    <?php endif; endif; ?>
  </section>

  <?php if (!empty($imc_cs_oes)):
      $cs_first = $imc_cs_oes[0];
      $cs_more  = count($imc_cs_oes) - 1;
  ?>
  <section class="imc-closing-soon" id="imc-closing-soon"
           data-queue="<?php echo esc_attr(wp_json_encode($imc_cs_oes)); ?>">
    <a class="imc-cs-card" id="imc-cs-card" href="<?php echo esc_url($cs_first['href']); ?>"
       aria-label="View closing-soon open edition">
      <div class="imc-cs-cover">
        <img id="imc-cs-cover" src="<?php echo esc_url($cs_first['cover']); ?>"
             alt="<?php echo esc_attr($cs_first['name']); ?>" loading="lazy"
             onerror="this.onerror=null;this.src='/wp-content/uploads/fallback-nft.svg';">
      </div>
      <div class="imc-cs-body">
        <div class="imc-cs-hero">&#9203; Mint Closing Soon</div>
        <div class="imc-cs-titlerow">
          <span class="imc-cs-name" id="imc-cs-name"><?php echo esc_html($cs_first['name']); ?></span>
          <span class="imc-cs-dot">&bull;</span>
          <span class="imc-cs-price" id="imc-cs-price"><?php echo esc_html($cs_first['price']); ?></span>
          <span class="imc-cs-dot">&bull;</span>
          <span class="imc-cs-minted"><strong id="imc-cs-minted"><?php echo (int) $cs_first['minted']; ?></strong> minted</span>
        </div>
        <div class="imc-cs-countdown" id="imc-cs-countdown" data-ends="<?php echo esc_attr($cs_first['ends']); ?>">
          <span class="imc-cs-cd-label">Mint closes in</span>
          <span class="imc-cs-units">
            <span class="u"><b data-u="d">--</b><em>d</em></span>
            <span class="u"><b data-u="h">--</b><em>h</em></span>
            <span class="u"><b data-u="m">--</b><em>m</em></span>
            <span class="u"><b data-u="s">--</b><em>s</em></span>
          </span>
        </div>
      </div>
      <div class="imc-cs-more" id="imc-cs-more"<?php echo $cs_more > 0 ? '' : ' style="display:none;"'; ?>>+<?php echo (int) $cs_more; ?> more open</div>
    </a>
  </section>
  <script>
  (function(){
    var root = document.getElementById('imc-closing-soon');
    if (!root) return;
    var queue;
    try { queue = JSON.parse(root.getAttribute('data-queue') || '[]'); } catch (e) { queue = []; }
    if (!queue.length) return;

    var card    = document.getElementById('imc-cs-card');
    var coverEl = document.getElementById('imc-cs-cover');
    var nameEl  = document.getElementById('imc-cs-name');
    var priceEl = document.getElementById('imc-cs-price');
    var mintEl  = document.getElementById('imc-cs-minted');
    var cdEl    = document.getElementById('imc-cs-countdown');
    var moreEl  = document.getElementById('imc-cs-more');
    var lblEl   = cdEl.querySelector('.imc-cs-cd-label');
    var dEl = cdEl.querySelector('[data-u="d"]'),
        hEl = cdEl.querySelector('[data-u="h"]'),
        mEl = cdEl.querySelector('[data-u="m"]'),
        sEl = cdEl.querySelector('[data-u="s"]');

    var idx = 0, endsMs = 0, timer = null;
    function pad(n){ return String(n).padStart(2, '0'); }
    // DB stores UTC — parse the naive DATETIME as UTC so the visitor's clock
    // agrees with the OE cron's close time regardless of their local zone.
    function toMs(ends){ return new Date(String(ends).replace(' ', 'T') + 'Z').getTime(); }

    function paint(oe){
      coverEl.src = oe.cover; coverEl.alt = oe.name;
      nameEl.textContent  = oe.name;
      priceEl.textContent = oe.price;
      mintEl.textContent  = oe.minted;
      card.setAttribute('href', oe.href);
      cdEl.setAttribute('data-ends', oe.ends);
      endsMs = toMs(oe.ends);
      var remaining = queue.length - idx - 1;
      if (moreEl) {
        if (remaining > 0) { moreEl.textContent = '+' + remaining + ' more open'; moreEl.style.display = ''; }
        else { moreEl.style.display = 'none'; }
      }
    }

    function showEnded(){
      dEl.textContent = hEl.textContent = mEl.textContent = sEl.textContent = '00';
      if (lblEl) lblEl.textContent = 'Mint ended';
      root.classList.add('imc-cs-ended');
      // Deep-link intentionally stays active (per spec) — href unchanged.
    }

    // Advance to the next not-yet-expired OE; skip co-expired ties. If none
    // remain, flip to ended. No reload (mirrors the v476 terminal-state fix).
    function advance(){
      idx++;
      while (idx < queue.length && toMs(queue[idx].ends) <= Date.now()) { idx++; }
      if (idx >= queue.length) { if (timer) { clearInterval(timer); timer = null; } showEnded(); return; }
      paint(queue[idx]);
    }

    function tick(){
      var diff = endsMs - Date.now();
      if (diff <= 0) { advance(); return; }
      var s = Math.floor(diff / 1000);
      dEl.textContent = pad(Math.floor(s / 86400));
      hEl.textContent = pad(Math.floor((s % 86400) / 3600));
      mEl.textContent = pad(Math.floor((s % 3600) / 60));
      sEl.textContent = pad(s % 60);
    }

    // Skip any that expired between server render and script execution.
    while (idx < queue.length && toMs(queue[idx].ends) <= Date.now()) { idx++; }
    if (idx >= queue.length) { showEnded(); return; }
    if (idx !== 0) { paint(queue[idx]); } else { endsMs = toMs(queue[0].ends); }
    tick();
    timer = setInterval(tick, 1000);
  })();
  </script>
  <?php endif; ?>

  <div class="imc-section-h"><h2>Collections</h2></div>
  <div class="imc-grid" id="imc-artist-grid"
       data-account="<?php echo esc_attr($artist_account); ?>"
       data-endpoints="<?php echo esc_attr(wp_json_encode($endpoints)); ?>">
    <div class="imc-empty-grid">Loading collections&hellip;</div>
  </div>

  <div class="imc-section-h" id="imc-external-h" style="display:none;"><h2>Other XRPL Collections</h2></div>
  <div class="imc-grid" id="imc-artist-external" style="display:none;"></div>

  <!-- Tip modal -->
  <div class="imc-modal" id="imc-tip-modal"
       data-account="<?php echo esc_attr($artist_account); ?>"
       data-self="<?php echo esc_attr($xrpl_account); ?>"
       data-nonce="<?php echo esc_attr($nonce); ?>"
       data-tips="<?php echo esc_attr($endpoints['tips']); ?>">
    <div class="imc-modal-box">
      <button type="button" class="imc-modal-close" id="imc-tip-close" aria-label="Close">&times;</button>
      <h3>Tip <?php echo esc_html($display_name); ?></h3>
      <div class="sub">Sent directly to the artist&rsquo;s wallet. You approve it in Xaman.</div>
      <div id="imc-tip-form">
        <div class="imc-field">
          <label for="imc-tip-cur">Currency</label>
          <select id="imc-tip-cur"><option value="XRP" data-issuer="" data-avail="">XRP</option></select>
          <div class="hint" id="imc-tip-avail"></div>
        </div>
        <div class="imc-field">
          <label for="imc-tip-amt">Amount</label>
          <input type="number" id="imc-tip-amt" min="0" step="0.000001" placeholder="0.00" inputmode="decimal">
        </div>
        <button type="button" class="imc-send" id="imc-tip-send">Send tip</button>
        <div class="imc-status" id="imc-tip-msg"></div>
      </div>
      <div id="imc-tip-qr" style="display:none;">
        <div class="imc-qr-wrap">
          <img id="imc-tip-qr-img" alt="Scan with Xaman">
          <div><a class="dl" id="imc-tip-deeplink" href="#" target="_blank" rel="noopener">Open in Xaman &rarr;</a></div>
        </div>
        <div class="imc-status" id="imc-tip-status">Waiting for signature&hellip;</div>
      </div>
    </div>
  </div>
<?php endif; ?>
</div>

<?php if (!$not_found): ?>
<script>
(function(){
  var grid = document.getElementById('imc-artist-grid');
  if(!grid) return;
  var account   = grid.dataset.account;
  var endpoints = {}; try { endpoints = JSON.parse(grid.dataset.endpoints||'{}'); } catch(e){}
  var esc = function(s){ var d=document.createElement('div'); d.textContent=(s==null?'':String(s)); return d.innerHTML; };
  var ipfs = function(u){ if(!u) return '/wp-content/uploads/fallback-nft.svg';
    return 'https://metadata.imcollectibles.io/img.php?url='+encodeURIComponent(u.indexOf('ipfs://')===0?u:('ipfs://'+u.replace('ipfs://','')))+'&thumb=1'; }; /* L3b: sole caller is the collections-grid card */

  // ---- Collections grid (group get_by_artist listings by collection) ----
  function loadCollections(){
    fetch(endpoints.listings+'?action=get_by_artist&account='+encodeURIComponent(account)+'&_='+Date.now(),{credentials:'include'})
      .then(function(r){return r.json();})
      .then(function(d){
        var listings=(d&&d.success&&d.data&&d.data.listings)?d.data.listings:(d&&d.listings?d.listings:[]);
        if(!listings.length){ grid.innerHTML='<div class="imc-empty-grid">No collections yet.</div>'; loadExternalCollections({}); return; }
        var map={};
        listings.forEach(function(l){
          var key=(l.collection_name||l.nft_name||'')+'|'+(l.collection_taxon||0);
          if(!map[key]){ map[key]={name:l.collection_name||l.nft_name||'Untitled',artist:l.artist_name||'',cover:l.cover_ipfs,taxon:l.collection_taxon||0,
            minted:0,total:0,price:l.price_xrp,status:l.status}; }
          map[key].minted+=parseInt(l.minted_count||0,10);
          map[key].total +=parseInt(l.total_editions||0,10);
        });
        var html=Object.keys(map).map(function(k){
          var c=map[k];
          var soldOut=(c.total>0 && c.minted>=c.total)||c.status==='sold_out';
          var pct=c.total>0?Math.min(100,Math.round(c.minted/c.total*100)):0;
          var href='/collections/?issuer='+encodeURIComponent(account)+'&taxon='+encodeURIComponent(c.taxon);
          var meta=c.total>0?(c.minted+' / '+c.total):'Open';
          return '<a class="imc-card" href="'+href+'">'+
            '<div class="imc-card-cover">'+
              '<img loading="lazy" src="'+ipfs(c.cover)+'" alt="'+esc(c.name)+'" onerror="this.style.display=\'none\'"></div>'+
            '<div class="imc-card-b"><h3>'+esc(c.name)+'</h3>'+
              (c.artist?'<div class="ca">'+esc(c.artist)+'</div>':'')+
              '<div class="imc-bar"><i style="width:'+pct+'%"></i></div>'+
              '<div class="imc-card-meta"><span></span><span>'+esc(meta)+'</span></div>'+
            '</div></a>';
        }).join('');
        grid.innerHTML=html;
        var imcTax={}; Object.keys(map).forEach(function(k){ imcTax[map[k].taxon]=1; });
        loadExternalCollections(imcTax);
      })
      .catch(function(){ grid.innerHTML='<div class="imc-empty-grid">Couldn&rsquo;t load collections.</div>'; });
  }

  // ---- Other XRPL collections (VPS-discovered, excluding IMC taxons) ----
  function loadExternalCollections(imcTax){
    var ext=document.getElementById('imc-artist-external');
    var extH=document.getElementById('imc-external-h');
    if(!ext||!endpoints.collections) return;
    fetch(endpoints.collections+'?action=collections&issuer='+encodeURIComponent(account)+'&limit=100&_='+Date.now(),{credentials:'include'})
      .then(function(r){return r.json();})
      .then(function(d){
        var cols=(d&&d.success&&d.collections)?d.collections:[];
        var out=cols.filter(function(c){ return !imcTax[c.taxon]; });
        if(!out.length) return;
        var html=out.map(function(c){
          var href='/collections/?issuer='+encodeURIComponent(c.issuer)+'&taxon='+encodeURIComponent(c.taxon);
          var cover=c.image_proxy||'/wp-content/uploads/fallback-nft.svg';
          var floor=(c.floor_xrp&&c.floor_xrp>0)?('Floor '+c.floor_xrp+' XRP'):'\u2014';
          var items=(c.total_supply||0)+' item'+((c.total_supply==1)?'':'s');
          return '<a class="imc-card" href="'+href+'">'+
            '<div class="imc-card-cover"><img loading="lazy" src="'+cover+'" alt="'+esc(c.name)+'" onerror="this.style.display=\'none\'"></div>'+
            '<div class="imc-card-b"><h3>'+esc(c.name)+'</h3>'+
              '<div class="imc-card-meta"><span>'+floor+'</span><span>'+esc(items)+'</span></div>'+
            '</div></a>';
        }).join('');
        ext.innerHTML=html;
        ext.style.display=''; if(extH) extH.style.display='';
      })
      .catch(function(){ /* external section stays hidden on error */ });
  }
  loadCollections();

  // ---- Copy wallet ----
  var copyBtn=document.getElementById('imc-copy-wallet');
  if(copyBtn){ copyBtn.addEventListener('click',function(){
    var full=document.getElementById('imc-wallet-text').dataset.full;
    if(navigator.clipboard){ navigator.clipboard.writeText(full); }
    var o=this.innerHTML; this.innerHTML='&#10003;'; var self=this; setTimeout(function(){self.innerHTML=o;},1200);
  }); }

  // ---- Tip modal ----
  var modal=document.getElementById('imc-tip-modal');
  var openBtn=document.getElementById('imc-open-tip');
  var closeBtn=document.getElementById('imc-tip-close');
  var curSel=document.getElementById('imc-tip-cur');
  var availEl=document.getElementById('imc-tip-avail');
  var amtEl=document.getElementById('imc-tip-amt');
  var sendBtn=document.getElementById('imc-tip-send');
  var msgEl=document.getElementById('imc-tip-msg');
  var formWrap=document.getElementById('imc-tip-form');
  var qrWrap=document.getElementById('imc-tip-qr');
  var qrImg=document.getElementById('imc-tip-qr-img');
  var deeplink=document.getElementById('imc-tip-deeplink');
  var statusEl=document.getElementById('imc-tip-status');
  var tipsUrl=modal.dataset.tips, self=modal.dataset.self, nonce=modal.dataset.nonce, artist=modal.dataset.account;
  var pollTimer=null;
  // -- v591 mobile UX (additive): same-tab Xaman for tips + instant resume on return --
  var tipIsMobileUA = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent||'');
  var tipCurrentUuid = null, tipReturnBusy = false;


  function resetModal(){ formWrap.style.display=''; qrWrap.style.display='none'; msgEl.textContent=''; msgEl.className='imc-status';
    if(pollTimer){clearInterval(pollTimer);pollTimer=null;} }
  function showAvail(){ var o=curSel.options[curSel.selectedIndex]; var a=o?o.dataset.avail:'';
    availEl.textContent=(a!==''&&a!=null)?('Available: '+a+' '+o.value):''; }

  function loadCurrencies(){
    curSel.innerHTML='<option value="XRP" data-issuer="" data-avail="">XRP</option>';
    if(!self){ availEl.textContent='Connect your wallet to see token options.'; return; }
    fetch(tipsUrl+'?action=get_tip_currencies&artist='+encodeURIComponent(artist)+'&sender='+encodeURIComponent(self)+'&_='+Date.now(),{credentials:'include'})
      .then(function(r){return r.json();})
      .then(function(d){
        if(!d||!d.success||!d.currencies) return;
        curSel.innerHTML='';
        d.currencies.forEach(function(c){
          var opt=document.createElement('option');
          opt.value=c.currency; opt.dataset.issuer=c.issuer||''; opt.dataset.avail=(c.available==null?'':c.available);
          opt.textContent=(c.name||c.ticker)+(c.verified?'':' (unverified)');
          curSel.appendChild(opt);
        });
        showAvail();
      }).catch(function(){});
  }

  if(openBtn){ openBtn.addEventListener('click',function(){ resetModal(); loadCurrencies(); modal.classList.add('open'); }); }
  if(closeBtn){ closeBtn.addEventListener('click',function(){ modal.classList.remove('open'); resetModal(); }); }
  modal.addEventListener('click',function(e){ if(e.target===modal){ modal.classList.remove('open'); resetModal(); } });
  curSel.addEventListener('change',showAvail);

  function poll(uuid){
    pollTimer=setInterval(function(){
      fetch(tipsUrl+'?action=tip_status&uuid='+encodeURIComponent(uuid)+'&_='+Date.now(),{credentials:'include'})
        .then(function(r){return r.json();})
        .then(function(d){
          if(!d||!d.success) return;
          if(d.resolved){
            clearInterval(pollTimer); pollTimer=null;
            if(d.signed){ statusEl.textContent='Tip sent. Thank you!'; statusEl.className='imc-status ok';
              if(d.txid){ var a=document.createElement('a'); a.href='https://bithomp.com/explorer/'+d.txid; a.target='_blank'; a.rel='noopener';
                a.className='dl'; a.textContent='View transaction \u2192'; statusEl.appendChild(document.createElement('br')); statusEl.appendChild(a); } }
            else { statusEl.textContent='Tip was declined or expired.'; statusEl.className='imc-status err'; }
          }
        }).catch(function(){});
    },3500);
  }

  // v591 (additive): when the signer returns to this tab, re-check tip_status at once.
  // tip_status resolves the pending wp_imc_tips row server-side, so this updates the UI AND
  // heals the record even if the 3.5s poll was throttled while the tab was backgrounded.
  document.addEventListener('visibilitychange', function(){
    if(document.hidden || tipReturnBusy || !tipCurrentUuid) return;
    if(!qrWrap || qrWrap.style.display==='none') return;
    tipReturnBusy=true;
    fetch(tipsUrl+'?action=tip_status&uuid='+encodeURIComponent(tipCurrentUuid)+'&_='+Date.now(),{credentials:'include'})
      .then(function(r){return r.json();})
      .then(function(d){
        tipReturnBusy=false;
        if(d && d.success && d.resolved){
          if(pollTimer){clearInterval(pollTimer);pollTimer=null;}
          if(d.signed){ statusEl.textContent='Tip sent. Thank you!'; statusEl.className='imc-status ok';
            if(d.txid){ var a=document.createElement('a'); a.href='https://bithomp.com/explorer/'+d.txid; a.target='_blank'; a.rel='noopener';
              a.className='dl'; a.textContent='View transaction \u2192'; statusEl.appendChild(document.createElement('br')); statusEl.appendChild(a); } }
          else { statusEl.textContent='Tip was declined or expired.'; statusEl.className='imc-status err'; }
        }
      }).catch(function(){tipReturnBusy=false;});
  });

  if(sendBtn){ sendBtn.addEventListener('click',function(){
    msgEl.className='imc-status'; msgEl.textContent='';
    if(!self){ msgEl.textContent='Connect your wallet first.'; msgEl.className='imc-status err'; return; }
    var amt=parseFloat(amtEl.value); var o=curSel.options[curSel.selectedIndex];
    if(!(amt>0)){ msgEl.textContent='Enter an amount.'; msgEl.className='imc-status err'; return; }
    var avail=o?parseFloat(o.dataset.avail):NaN;
    if(!isNaN(avail)&&amt>avail){ msgEl.textContent='Amount exceeds your available balance.'; msgEl.className='imc-status err'; return; }
    sendBtn.disabled=true; sendBtn.textContent='Creating request\u2026';
    // --- Joey branch (v553, additive): sign tip Payment locally, then verify on-chain. ---
    if (window.__joeySession && window.__joeySession.live && window.imuWallet) {
      if (self !== window.__joeySession.account) { msgEl.textContent='Connected wallet does not match your account. Please reconnect.'; msgEl.className='imc-status err'; sendBtn.disabled=false; sendBtn.textContent='Send tip'; return; }
      (async function(){
        try {
          var jfd=new FormData();
          jfd.append('action','create_tip'); jfd.append('nonce',nonce); jfd.append('artist',artist);
          jfd.append('amount',String(amt)); jfd.append('currency',o.value); jfd.append('issuer',o.dataset.issuer||''); jfd.append('wallet','joey');
          var jr=await fetch(tipsUrl,{method:'POST',body:jfd,credentials:'include'});
          var jd=await jr.json();
          if(!jd||!jd.success||!jd.txjson){ throw new Error((jd&&jd.error)||'Could not create the tip.'); }
          if(statusEl){ statusEl.textContent='Check your wallet to sign\u2026'; }
          var jsigned;
          try { jsigned = await (window.imuJoeySign||window.imuWallet.sign)(jd.txjson); }
          catch(e){ var em=(e&&e.message)||''; throw new Error(/timed out|timeout/i.test(em)?'Signing timed out. Please try again.':/cancel/i.test(em)?'Tip cancelled.':'Tip rejected.'); }
          var jtx = jsigned && (jsigned.hash || jsigned.tx_hash);
          if(!jtx){ throw new Error('No transaction hash returned from your wallet.'); }
          var vfd=new FormData();
          vfd.append('action','joey_verify_tip'); vfd.append('nonce',nonce); vfd.append('artist',artist); vfd.append('tx_hash',jtx);
          var vr=await fetch(tipsUrl,{method:'POST',body:vfd,credentials:'include'});
          var vd=await vr.json();
          if(!vd||!vd.success){ throw new Error((vd&&vd.error)||'Tip verification failed'); }
          formWrap.style.display='none'; qrWrap.style.display='';
          statusEl.textContent='Tip sent! Thank you \ud83c\udf89'; statusEl.className='imc-status ok';
          sendBtn.disabled=false; sendBtn.textContent='Send tip';
        } catch(err){ msgEl.textContent=err.message; msgEl.className='imc-status err'; sendBtn.disabled=false; sendBtn.textContent='Send tip'; }
      })();
      return;
    }
    var fd=new FormData();
    fd.append('action','create_tip'); fd.append('nonce',nonce); fd.append('artist',artist);
    fd.append('amount',String(amt)); fd.append('currency',o.value); fd.append('issuer',o.dataset.issuer||'');
    fetch(tipsUrl,{method:'POST',body:fd,credentials:'include'})
      .then(function(r){return r.json();})
      .then(function(d){
        sendBtn.disabled=false; sendBtn.textContent='Send tip';
        if(!d||!d.success){ msgEl.textContent=(d&&d.error)?d.error:'Could not create the tip.'; msgEl.className='imc-status err'; return; }
        // v684: server-decided Joey response reaching the Xaman path (xrpl_wallet_type authority,
        // v683). No qr/uuid here, so swap to the QR view would strand the user. Button is already
        // re-enabled above, so a plain message + return is a clean bounce.
        if(d.wallet==='joey' && d.txjson){ msgEl.textContent='Wallet still connecting \u2014 please try again in a moment.'; msgEl.className='imc-status err'; return; }
        formWrap.style.display='none'; qrWrap.style.display='';
        qrImg.src=d.qr||''; qrImg.onerror=function(){this.style.display='none';};
        deeplink.href=d.deeplink||'#';
        if(tipIsMobileUA) deeplink.setAttribute('target','_self'); // v591 mobile: same-tab Xaman (tip) -- visibilitychange re-poll resolves the row on return
        statusEl.textContent='Waiting for signature\u2026'; statusEl.className='imc-status';
        tipCurrentUuid=d.uuid;
        poll(d.uuid);
      })
      .catch(function(){ sendBtn.disabled=false; sendBtn.textContent='Send tip'; msgEl.textContent='Network error.'; msgEl.className='imc-status err'; });
  }); }
})();
</script>
<?php endif; ?>

<?php if (function_exists('imc_xrplto_attribution')) imc_xrplto_attribution(); ?>
<?php get_footer(); ?>