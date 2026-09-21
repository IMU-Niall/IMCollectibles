<?php
/*
Template Name: User Profile
P2 (User Profiles, Aug 2026). Public identity page at /user/{slug-or-r-address}.
Server-rendered; identity mutations go through artist-profile-handler (token-authed
since Phase 1). Creators additionally link out to their /creators/{slug} storefront.
Visibility: all sections public by default; per-section hide via section_visibility
JSON (self always sees everything, hidden sections carry a "hidden" chip).
*/
get_header();
global $wpdb;

$theme_uri = get_stylesheet_directory_uri();
$handler   = $theme_uri . '/xrpl-nft-marketplace/backend/artist-profile-handler.php';
$handler_follows = $theme_uri . '/xrpl-nft-marketplace/backend/follows-handler.php';
$listings_ep = $theme_uri . '/xrpl-nft-marketplace/backend/listings-handler.php';
$nonce     = wp_create_nonce('xrpl_marketplace_nonce');

/* ---- 1. Resolve identifier: slug OR raw r-address ------------------------- */
$ident = sanitize_text_field(get_query_var('imc_user_slug') ?: '');
$account = '';
$not_found = false;

if (preg_match('/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/', $ident)) {
    $account = $ident;
} elseif ($ident !== '' && function_exists('imc_artist_account_by_slug')) {
    $account = (string) imc_artist_account_by_slug(sanitize_title($ident));
}
if ($account === '') { $not_found = true; }

/* ---- 2. Blacklist gate: hidden profile = hard 404 ------------------------- */
if (!$not_found) {
    $xp = $wpdb->prefix . 'xaman_profiles';
    if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $xp)) === $xp) {
        $bl = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(blacklisted,0) FROM {$xp} WHERE xrpl_account = %s", $account
        ));
        if ($bl === 1) { $not_found = true; }
    }
}

/* ---- 3. Load profile row (may be absent: r-address identity) -------------- */
$profile = null;
if (!$not_found && function_exists('imc_artist_profiles_ensure_table')) {
    imc_artist_profiles_ensure_table();
    $pt = imc_artist_profiles_table();
    $profile = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$pt} WHERE artist_account = %s LIMIT 1", $account
    ), ARRAY_A);
}

$self_wallet = function_exists('imc_session_resolve_wallet') ? imc_session_resolve_wallet() : '';
$is_self = ($self_wallet !== '' && $self_wallet === $account);

/* ---- 4. Derived identity -------------------------------------------------- */
$display_name = trim((string) ($profile['display_name'] ?? ''));
$wallet_short = function_exists('imc_wallet_short') ? imc_wallet_short($account) : (substr($account, 0, 6) . '...' . substr($account, -4));
if ($display_name === '') { $display_name = $wallet_short; }

$pfp = (string) ($profile['profile_image_url'] ?? '');
$banner_d = (string) ($profile['banner_desktop_url'] ?? '');
$banner_m = (string) ($profile['banner_mobile_url'] ?? '');
if ($banner_m === '') { $banner_m = $banner_d; } // agreed fallback: mobile borrows desktop, centre-cover crop
$bio = (string) ($profile['bio'] ?? '');
$slug = (string) ($profile['slug'] ?? '');
$profile_url_id = $slug !== '' ? $slug : $account;

$social = [];
if (!empty($profile['social_links'])) {
    $dec = json_decode($profile['social_links'], true);
    if (is_array($dec)) { $social = $dec; }
}
$custom_links = (isset($social['custom_links']) && is_array($social['custom_links'])) ? $social['custom_links'] : [];

$listings_table = $wpdb->prefix . 'imc_listings';
$is_creator = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$listings_table} WHERE artist_account = %s LIMIT 1", $account
)) > 0;

$is_verified    = function_exists('imc_is_verified_creator') && imc_is_verified_creator($account);
$is_verified_ai = function_exists('imc_is_verified_ai_creator') && imc_is_verified_ai_creator($account);
$verified_user  = (int) ($profile['verified_user'] ?? 0) === 1;
$is_team        = (int) ($profile['is_team'] ?? 0) === 1;

/* P6 progression badges — Collector "The Vault" + Creator "The Forge".
   Module defines functions only; guarded require so the page never fatals. */
$imc_badges_file = get_stylesheet_directory() . '/inc/imc-badges.php';
if (file_exists($imc_badges_file)) { require_once $imc_badges_file; }
$collector_badge = function_exists('imc_badge_collector') ? imc_badge_collector($account) : null;
$creator_badge   = function_exists('imc_badge_creator')   ? imc_badge_creator($account)   : null;

/* Follows (table exists from P0; zero rows until P3 UI ships) */
$followers = 0; $following = 0;
if (function_exists('imc_follows_table')) {
    $ft = imc_follows_table();
    if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ft)) === $ft) {
        $followers = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$ft} WHERE followed_account = %s", $account));
        $following = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$ft} WHERE follower_account = %s", $account));
    }
}

/* ---- 5. Visibility (all public by default; self sees everything) ---------- */
$vis = ['created' => true, 'collected' => true, 'history' => true, 'favourites' => true, 'links' => true, 'followers' => true];
if (!empty($profile['section_visibility'])) {
    $vdec = json_decode($profile['section_visibility'], true);
    if (is_array($vdec)) { foreach ($vdec as $k => $v) { if (isset($vis[$k])) { $vis[$k] = (bool) $v; } } }
}
function imc_up_show($key, $vis, $is_self) { return $is_self || !empty($vis[$key]); }

/* ---- 6. OG / SEO (v644 pattern: fill $imc_og_data, functions.php emits) --- */
if (!$not_found) {
    global $imc_og_data;
    $_og_img = '';
    if ($pfp !== '' && strpos($pfp, 'data:') !== 0) {
        if (strpos($pfp, 'ipfs://') === 0 || strpos($pfp, 'mypinata.cloud') !== false || strpos($pfp, 'ipfs.io') !== false) {
            $_og_img = 'https://metadata.imcollectibles.io/img.php?url=' . urlencode($pfp);
        } else { $_og_img = $pfp; }
    }
    if ($_og_img === '') { $_og_img = defined('IMC_OG_DEFAULT_IMAGE') ? IMC_OG_DEFAULT_IMAGE : ''; }
    $imc_og_data = [
        'title' => $display_name . ' — IMCollectibles',
        'description' => $bio !== '' ? wp_trim_words($bio, 30) : ($display_name . ' on IMCollectibles.'),
        'image' => $_og_img,
        'url'   => home_url('/user/' . rawurlencode($profile_url_id) . '/'),
    ];
}

/* ---- 7. History + Favourites data (server-side, cheap indexed reads) ------ */
$history_rows = [];
if (!$not_found && imc_up_show('history', $vis, $is_self)) {
    $purch = $wpdb->prefix . 'imc_purchases';
    if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $purch)) === $purch) {
        $history_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.listing_id, p.edition_number, p.tier_name, p.price_xrp, p.price_currency, p.nftoken_id,
                    p.mint_status, p.created_at, l.nft_name AS listing_title, l.artist_name AS listing_artist
             FROM {$purch} p
             LEFT JOIN {$listings_table} l ON l.id = p.listing_id
             WHERE p.buyer_account = %s
               AND p.mint_status IN ('paid','minting','minted','claimed')
             ORDER BY p.created_at DESC
             LIMIT 60", $account
        ), ARRAY_A) ?: [];
    }
}
$fav_rows = [];
if (!$not_found && imc_up_show('favourites', $vis, $is_self)) {
    $wl = $wpdb->prefix . 'xaman_watchlist';
    if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wl)) === $wl) {
        $fav_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT nft_id, nft_name, created_at FROM {$wl} WHERE account = %s ORDER BY created_at DESC LIMIT 60", $account
        ), ARRAY_A) ?: [];
        /* Enrich names locally for IMC-minted NFTs (purchases -> listings), independent of the VPS store */
        if ($fav_rows) {
            $fav_ids = array_values(array_filter(array_column($fav_rows, 'nft_id'), function ($i) { return preg_match('/^[A-Fa-f0-9]{64}$/', (string) $i); }));
            if ($fav_ids) {
                $purch = $wpdb->prefix . 'imc_purchases';
                $fav_ph = implode(',', array_fill(0, count($fav_ids), '%s'));
                $fav_meta = $wpdb->get_results($wpdb->prepare(
                    "SELECT p.nftoken_id, p.edition_number, l.nft_name
                     FROM {$purch} p LEFT JOIN {$listings_table} l ON l.id = p.listing_id
                     WHERE p.nftoken_id IN ({$fav_ph})", $fav_ids
                ), ARRAY_A) ?: [];
                $fav_names = [];
                foreach ($fav_meta as $fm) {
                    if (!empty($fm['nft_name'])) {
                        $fav_names[strtoupper($fm['nftoken_id'])] = $fm['nft_name'] . ((int) $fm['edition_number'] > 0 ? ' #' . (int) $fm['edition_number'] : '');
                    }
                }
                foreach ($fav_rows as &$fr) {
                    $frk = strtoupper((string) $fr['nft_id']);
                    if (isset($fav_names[$frk]) && ($fr['nft_name'] === '' || $fr['nft_name'] === 'NFT')) { $fr['nft_name'] = $fav_names[$frk]; }
                }
                unset($fr);
            }
        }
    }
}

/* ---- D2: IMC Collected — platform purchases grouped by collection (local-first,
   store-independent; sold-elsewhere pieces are verified out client-side via batch). */
$imc_collected = [];
$imc_dedupe_pairs = [];
if (!$not_found && imc_up_show('collected', $vis, $is_self)) {
    $d2_p = $wpdb->prefix . 'imc_purchases';
    $d2_l = $wpdb->prefix . 'imc_listings';
    $d2_t = $wpdb->prefix . 'imc_listing_tiers';
    if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $d2_p)) === $d2_p) {
        $d2_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.nftoken_id, p.edition_number, l.id AS listing_id, l.nft_name, l.nft_type,
                    l.collection_name, l.collection_taxon, l.artist_account, l.artist_name,
                    l.cover_ipfs AS listing_cover, t.cover_ipfs AS tier_cover
             FROM {$d2_p} p
             JOIN {$d2_l} l ON l.id = p.listing_id
             LEFT JOIN {$d2_t} t ON t.id = p.tier_id
             WHERE p.buyer_account = %s
               AND p.mint_status IN ('minted','claimed')
             ORDER BY p.created_at DESC
             LIMIT 500", $account
        ), ARRAY_A) ?: [];
        foreach ($d2_rows as $d2r) {
            $d2_key = $d2r['artist_account'] . '|' . (int) $d2r['collection_taxon'];
            if (!isset($imc_collected[$d2_key])) {
                $imc_collected[$d2_key] = [
                    'name'    => ($d2r['collection_name'] !== '' ? $d2r['collection_name'] : $d2r['nft_name']),
                    'artist'  => $d2r['artist_account'],
                    'artist_name' => (string) ($d2r['artist_name'] ?? ''),
                    'taxon'   => (int) $d2r['collection_taxon'],
                    'cover'   => (string) ($d2r['tier_cover'] ?: $d2r['listing_cover']),
                    'type'    => $d2r['nft_type'],
                    'types'   => [],
                    'count'   => 0,
                    'ids'     => [],
                    'items'   => [],
                ];
            }
            $imc_collected[$d2_key]['count']++;
            if (!empty($d2r['nft_type'])) { $imc_collected[$d2_key]['types'][$d2r['nft_type']] = 1; }
            if (!empty($d2r['nftoken_id']) && preg_match('/^[A-Fa-f0-9]{64}$/', $d2r['nftoken_id'])) {
                $imc_collected[$d2_key]['ids'][] = strtoupper($d2r['nftoken_id']);
                $imc_collected[$d2_key]['items'][] = [
                    'i' => strtoupper($d2r['nftoken_id']),
                    'n' => $d2r['nft_name'] . ((int) $d2r['edition_number'] > 0 ? ' #' . (int) $d2r['edition_number'] : ''),
                ];
            }
            if ($imc_collected[$d2_key]['cover'] === '' && ($d2r['tier_cover'] ?: $d2r['listing_cover'])) {
                $imc_collected[$d2_key]['cover'] = (string) ($d2r['tier_cover'] ?: $d2r['listing_cover']);
            }
        }
        /* Dedupe pairs for the XRPL-wide section: artist wallets + the platform
           authorised minter (F19 misfiling files platform mints under it). */
        foreach ($imc_collected as $d2c) {
            $imc_dedupe_pairs[$d2c['artist'] . '|' . $d2c['taxon']] = 1;
            $imc_dedupe_pairs['riMCiWg8QBej6Y3osDVt3u8FJWo2ephuN|' . $d2c['taxon']] = 1;
        }
    }
}

$social_labels = ['imutv' => 'IMUTV', 'imup3' => 'IMUP3', 'website' => 'Website', 'x' => 'X', 'instagram' => 'Instagram', 'discord' => 'Discord', 'youtube' => 'YouTube'];
?>
<style>
body.has-imp-header{background:radial-gradient(1000px 520px at 50% -8%,rgba(212,175,55,.06),transparent 72%),linear-gradient(160deg,#08080e 0%,#0e0e16 55%,#141420 100%) !important;background-attachment:fixed !important;background-repeat:no-repeat !important;}
.imc-up-wrap{max-width:1500px;margin:0 auto;padding:0 16px 64px;color:#e8e8f0}
.imc-up-banner{position:relative;border-radius:14px;background:radial-gradient(1000px 400px at 50% -30%,rgba(212,175,55,.08),transparent 72%),linear-gradient(160deg,#08080e 0%,#0e0e16 55%,#141420 100%);border:1px solid #20202e;aspect-ratio:3/1;min-height:120px}
.imc-up-banner img{width:100%;height:100%;object-fit:cover;object-position:center;display:block;border-radius:14px}
.imc-up-bbadges{position:absolute;bottom:-14px;right:16px;display:flex;flex-wrap:wrap;justify-content:flex-end;gap:6px;max-width:70%;z-index:3}
.imc-up-head{display:flex;gap:18px;align-items:flex-end;margin-top:-64px;padding:0 18px;position:relative;flex-wrap:wrap}
.imc-up-pfp{width:176px;height:176px;border-radius:50%;border:5px solid #0f0f18;object-fit:cover;background:#1c1c2e}
.imc-up-idcol{flex:1;min-width:220px;padding-bottom:6px;display:flex;flex-direction:column;align-items:flex-start}
.imc-up-name{font-size:1.55rem;font-weight:700;margin:0;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.imc-up-badge{font-size:.68rem;font-weight:700;letter-spacing:.4px;padding:3px 9px;border-radius:20px;text-transform:uppercase}
.imc-up-badge.creator{background:#3d2a63;color:#d3b8ff}.imc-up-badge.verified{background:#144d33;color:#7bf1b6}
.imc-up-badge.ai{background:#1c3a5e;color:#8ecbff}.imc-up-badge.vuser{background:#4d4414;color:#f1e07b}.imc-up-badge.team{background:#5e1c1c;color:#ff9d9d}
.imc-up-badge.forge{background:#3a2410;color:#ffcf99}.imc-up-badge.vault{background:#10263a;color:#99d4ff}
.imc-up-badge.forge.t6,.imc-up-badge.vault.t6{box-shadow:0 0 0 1px rgba(255,255,255,.25) inset}
.imc-up-badge.forge.t7,.imc-up-badge.vault.t7{background:linear-gradient(90deg,#b8860b,#ffe8a3,#b8860b);color:#2a1e00;box-shadow:0 0 8px rgba(255,215,120,.5)}
.imc-up-wallet{display:inline-flex;align-items:center;gap:7px;font-size:.85rem;color:#b8b8cf;background:#16162400;margin-top:7px}
.imc-up-walletaddr{color:#9a9ab4}
.imc-up-copybtn{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:7px;border:1px solid #32324a;background:#1b1b2c;color:#c9c9de;cursor:pointer;padding:0;font-size:.9rem;line-height:1}
.imc-up-copybtn:hover{border-color:#6c3fd1;color:#fff}
.imc-up-balance{display:flex;align-items:center;gap:8px;font-size:.85rem;color:#b8b8cf;margin-top:6px}
.imc-up-balance b{color:#d4af37;font-weight:700}
.imc-up-wbplus{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:7px;border:1px solid rgba(212,175,55,.5);background:rgba(212,175,55,.12);color:#d4af37;cursor:pointer;padding:0;font-size:1rem;line-height:1;font-weight:700}
.imc-up-wbplus:hover{background:rgba(212,175,55,.25)}
.imc-up-wbmodal{position:fixed;inset:0;z-index:100000}
.imc-up-wbback{position:absolute;inset:0;background:rgba(8,8,14,.72)}
.imc-up-wbcard{position:relative;max-width:380px;margin:12vh auto 0;background:#17172a;border:1px solid #2b2b40;border-radius:14px;box-shadow:0 12px 40px rgba(0,0,0,.6);overflow:hidden;width:calc(100vw - 32px)}
.imc-up-wbhead{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid #2b2b40}
.imc-up-wbhead h3{margin:0;font-size:1rem;color:#d4af37}
.imc-up-wbx{background:none;border:none;color:#8a8aa4;font-size:1.3rem;cursor:pointer;padding:0 2px;line-height:1}
.imc-up-wbx:hover{color:#fff}
.imc-up-wbbody{max-height:52vh;overflow-y:auto;padding:10px 16px 14px}
.imc-up-wbline{display:flex;justify-content:space-between;gap:12px;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:.85rem}
.imc-up-wbline:last-child{border-bottom:none}
.imc-up-wbmuted{color:#8a8aa4}
.imc-up-counts{display:flex;gap:16px;font-size:.85rem;color:#b8b8cf;margin-top:5px}
.imc-up-counts b{color:#fff}
.imc-up-actions{display:flex;gap:10px;padding-bottom:8px}
.imc-up-btn{background:#6c3fd1;color:#fff;border:none;border-radius:9px;padding:9px 16px;font-weight:600;cursor:pointer;text-decoration:none;font-size:.9rem}
.imc-up-btn.ghost{background:transparent;border:1px solid #494966;color:#c9c9de}
.imc-up-bio{margin:16px auto 0;max-width:760px;color:#c5c5da;white-space:pre-line;text-align:center}
.imc-up-bio.clamped{display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}
.imc-up-biomore{display:none;margin:8px auto 0;background:none;border:none;color:#b48bff;font-size:.85rem;font-weight:600;cursor:pointer;padding:2px 6px}
.imc-up-biomore:hover{text-decoration:underline}
.imc-up-biowrap{text-align:center}
.imc-up-links{display:flex;flex-wrap:wrap;gap:9px;margin:16px 18px 0;justify-content:center}
.imc-up-link{background:#1b1b2c;border:1px solid #32324a;border-radius:22px;padding:7px 15px;font-size:.86rem;color:#dcdcf0;text-decoration:none}
.imc-up-link:hover{border-color:#6c3fd1}
.imc-up-tabs{display:flex;gap:4px;margin:26px 0 0;border-bottom:1px solid #2b2b40;flex-wrap:wrap}
.imc-up-tab{background:none;border:none;color:#9a9ab4;padding:11px 17px;font-weight:600;cursor:pointer;border-bottom:2px solid transparent;font-size:.93rem}
.imc-up-tab.active{color:#fff;border-bottom-color:#6c3fd1}
.imc-up-panel{display:none;padding:22px 4px}
.imc-up-panel.active{display:block}
.imc-up-empty{color:#8a8aa4;padding:26px 4px}
.imc-up-hiddenchip{font-size:.68rem;background:#33334c;color:#b5b5cc;border-radius:12px;padding:2px 9px;margin-left:7px;vertical-align:middle}
.imc-up-table{width:100%;border-collapse:collapse;font-size:.88rem}
.imc-up-table th{text-align:left;color:#8a8aa4;font-weight:600;padding:8px 10px;border-bottom:1px solid #2b2b40}
.imc-up-table td{padding:9px 10px;border-bottom:1px solid #1f1f30}
.imc-up-tablewrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
@media(min-width:769px){.imc-up-wrap{box-sizing:border-box;min-width:min(1500px,calc(100vw - 40px))}
/* P2H-b B1: idcol anchors at its TOP — extra rows (balance) grow DOWNWARD, pushing
   followers/bio down instead of lifting the name into the banner. Desktop only;
   mobile keeps its column layout untouched. */
.imc-up-idcol{align-self:flex-start;margin-top:78px}}
.imc-up-viewbtn{display:inline-block;padding:5px 12px;border-radius:8px;border:1px solid #3a3a55;background:#1b1b2c;color:#d3b8ff;text-decoration:none;font-size:.8rem;font-weight:600;white-space:nowrap}
.imc-up-viewbtn:hover{background:#242438;color:#e5d2ff}
.imc-up-claimbtn{background:linear-gradient(135deg,#d4af37,#f1d97a);border-color:#d4af37;color:#141414;font-weight:700}
.imc-up-claimbtn:hover{background:linear-gradient(135deg,#e3c14d,#f7e49a);color:#141414}
.imc-up-typebar{display:flex;flex-wrap:wrap;gap:8px;margin:2px 0 16px}
.imc-up-typetab{padding:5px 13px;border-radius:16px;border:1px solid rgba(201,168,76,0.28);background:rgba(0,0,0,0.3);color:#bbb;cursor:pointer;font-size:12px;font-weight:500;transition:all 0.2s}
.imc-up-typetab:hover{border-color:#c9a84c;color:#fff}
.imc-up-typetab.active{background:rgba(201,168,76,0.18);border-color:#c9a84c;color:#c9a84c;font-weight:600}
.imc-up-ownpill{position:absolute;top:8px;right:8px;background:linear-gradient(135deg,#d4af37,#f1d97a);color:#141414;font-size:.7rem;font-weight:700;padding:3px 10px;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.45);z-index:2}
.imc-up-colcard{position:relative}
.imc-up-drill{display:none}
.imc-up-drill.open{display:block}
.imc-up-drillhead{display:flex;align-items:center;flex-wrap:wrap;gap:12px;margin:2px 0 18px}
.imc-up-drillhead h3{margin:0;font-size:1.15rem;color:#e8e8f0;flex:1 1 auto}
.imc-up-drillhead h3 span{color:#8a8aa4;font-weight:500}
.imc-up-backbtn,.imc-up-viewcolbtn{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:9px;border:1px solid #3a3a55;background:#1b1b2c;color:#d3b8ff;text-decoration:none;font-size:.85rem;font-weight:600;cursor:pointer}
.imc-up-backbtn:hover,.imc-up-viewcolbtn:hover{background:#242438;color:#e5d2ff}
@media(max-width:768px){.imc-up-drillhead{flex-direction:column;align-items:stretch;text-align:center}.imc-up-drillhead h3{order:-1}}
.imc-up-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:16px}
.imc-up-editor{background:#15152288;border:1px solid #2e2e46;border-radius:14px;padding:20px;margin-top:22px;display:none}
.imc-up-editor.open{display:block}
.imc-up-edmodal{position:fixed;inset:0;z-index:100000}
.imc-up-edback{position:absolute;inset:0;background:rgba(8,8,14,.72)}
.imc-up-edcard{position:relative;max-width:640px;width:calc(100vw - 32px);margin:6vh auto 0;background:#17172a;border:1px solid #2e2e46;border-radius:14px;box-shadow:0 12px 40px rgba(0,0,0,.6);max-height:84vh;display:flex;flex-direction:column}
.imc-up-edx{position:absolute;top:10px;right:12px;background:none;border:none;color:#8a8aa4;font-size:1.4rem;cursor:pointer;z-index:2;line-height:1;padding:0}
.imc-up-edx:hover{color:#fff}
.imc-up-edbody{overflow-y:auto}
.imc-up-edbody .imc-up-editor{margin-top:0;border:none;background:transparent}
.imc-up-editor label{display:block;font-size:.8rem;color:#9a9ab4;margin:13px 0 4px}
.imc-up-editor input[type=text],.imc-up-editor input[type=url],.imc-up-editor textarea{width:100%;background:#101020;border:1px solid #32324a;border-radius:8px;color:#eee;padding:9px}
.imc-up-vis{display:flex;gap:14px;flex-wrap:wrap;margin-top:6px}
.imc-up-vis label{display:flex;align-items:center;gap:6px;margin:0;font-size:.83rem;color:#c5c5da}
.imc-up-savemsg{margin-left:12px;font-size:.85rem;color:#7bf1b6}
.imc-up-countlink{cursor:pointer}.imc-up-countlink:hover b{color:#b48bff}
.imc-up-modal{display:none;position:fixed;inset:0;background:#000a;z-index:9999;align-items:center;justify-content:center}
.imc-up-modal.open{display:flex}
.imc-up-modal-box{background:#14141f;border:1px solid #2e2e46;border-radius:14px;width:min(420px,92vw);max-height:74vh;display:flex;flex-direction:column;overflow:hidden}
.imc-up-modal-head{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #2b2b40;font-weight:700}
.imc-up-modal-x{background:none;border:none;color:#9a9ab4;font-size:1.5rem;cursor:pointer;line-height:1}
.imc-up-modal-list{overflow-y:auto;padding:8px}
.imc-up-mrow{display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:9px;text-decoration:none;color:#e8e8f0}
.imc-up-mrow:hover{background:#1e1e30}
.imc-up-mrow img{width:38px;height:38px;border-radius:50%;object-fit:cover;background:#1c1c2e}
.imc-up-mrow .mtag{margin-left:auto;font-size:.66rem;font-weight:700;text-transform:uppercase;padding:2px 8px;border-radius:12px}
.imc-up-mrow .mtag.creator{background:#3d2a63;color:#d3b8ff}.imc-up-mrow .mtag.collector{background:#2b2b40;color:#b8b8cf}
@media(max-width:768px){
  .imc-up-tablewrap .imc-up-table{min-width:620px}
  /* badges bottom-right straddle, wrap to a 2nd row when multiple */
  .imc-up-bbadges{position:absolute;top:calc(100% - 16px);bottom:auto;right:12px;left:auto;margin:0;display:flex;flex-direction:column;flex-wrap:nowrap;align-items:flex-end;gap:6px;max-height:none;max-width:52%;z-index:3}
  .imc-up-pfp{width:132px;height:132px}
  .imc-up-head{position:relative;align-items:flex-start}
  /* idcol (name/wallet/counts) keeps clear of the right-pinned primary button */
  .imc-up-idcol{padding-right:130px}
  /* name reclaims the reserved right space — the button sits lower (wallet/counts), not the name row */
  /* centre the display name across the full profile width — reclaim the pfp+gap on the left
     and the button-reserve on the right, then centre. pfp/wallet/counts unchanged. */
  /* Mobile hero: stacked + centred. pfp on its own line, then name/wallet/counts centred full-width. */
  .imc-up-head{flex-direction:column;align-items:center;text-align:center}
  /* pfp back on the left (clear of the right-side badges); name/wallet/counts stay centred */
  .imc-up-pfp{align-self:flex-start}
  .imc-up-idcol{width:100%;flex-basis:auto;padding-right:0;align-items:center}
  .imc-up-name{width:100%;justify-content:center;text-align:center}
  .imc-up-idmeta{align-items:center}
  .imc-up-wallet,.imc-up-counts{justify-content:center}
  /* primary action (Follow/Edit): pinned to the RIGHT, vertically centred against the wallet+counts block */
  .imc-up-actions{position:static}
  .imc-up-actions .imc-up-btn:not(.ghost){position:static;transform:none;margin:0;white-space:nowrap}
  /* storefront: centred on its own row below the head */
  .imc-up-actions .imc-up-btn.ghost{margin:0}
  /* tabs: all 4 on one row */
  .imc-up-tabs{flex-wrap:nowrap;gap:2px;justify-content:space-between}
  .imc-up-tab{padding:10px 6px;font-size:.8rem;flex:1;text-align:center;white-space:nowrap}
  /* actions row breaks full-width under the head so storefront centres cleanly */
  .imc-up-head .imc-up-actions{width:100%;justify-content:center;gap:10px;margin-top:14px}
  /* cards 2 per row, tighter gap */
  .imc-up-grid{grid-template-columns:repeat(2,1fr);gap:8px}
}
/* Badge tooltip (tap on mobile, hover on desktop) */
.imc-up-badge{position:relative;cursor:pointer}
.imc-up-tip{position:fixed;z-index:9999;background:#1b1b2c;color:#e8e8f0;border:1px solid #3a3a55;border-radius:9px;padding:8px 12px;font-size:.8rem;font-weight:500;text-transform:none;letter-spacing:0;max-width:240px;box-shadow:0 8px 24px rgba(0,0,0,.5);pointer-events:none;opacity:0;transform:translateY(4px);transition:opacity .12s,transform .12s;line-height:1.35}
.imc-up-tip.show{opacity:1;transform:translateY(0)}
</style>

<div class="imc-up-wrap">
<?php if ($not_found): ?>
  <?php status_header(404); ?>
  <div class="imc-up-empty" style="padding:70px 0;text-align:center">
    <h1>Profile not found</h1>
    <p>This profile doesn't exist or isn't available.</p>
    <a class="imc-up-btn" href="<?php echo esc_url(home_url('/')); ?>">Back to the marketplace</a>
  </div>
<?php else: ?>

  <div class="imc-up-banner">
    <?php if ($banner_d !== '' || $banner_m !== ''): ?>
      <picture>
        <img src="<?php echo esc_url($banner_d !== '' ? $banner_d : $banner_m); ?>" alt="">
      </picture>
    <?php endif; ?>
    <div class="imc-up-bbadges">
      <?php if (!$is_creator): ?><span class="imc-up-badge" style="background:#2b2b40;color:#b8b8cf" title="Collector on IMCollectibles">Collector</span><?php endif; ?>
      <?php if ($is_verified): ?><span class="imc-up-badge verified" title="Verified creator">Verified</span><?php endif; ?>
      <?php if ($is_verified_ai): ?><span class="imc-up-badge ai" title="AI-verified creator">AI Verified</span><?php endif; ?>
      <?php if ($verified_user): ?><span class="imc-up-badge vuser" title="Verified user">Verified User</span><?php endif; ?>
      <?php if ($is_team): ?><span class="imc-up-badge team" title="IMU team member">IMU Team</span><?php endif; ?>
      <?php if ($creator_badge): ?><span class="imc-up-badge forge <?php echo esc_attr($creator_badge['class']); ?>" title="Creator tier &mdash; <?php echo esc_attr($creator_badge['name'] . (!empty($creator_badge['flavour']) ? ': ' . $creator_badge['flavour'] : '')); ?>">🔨 <?php echo esc_html($creator_badge['name']); ?></span><?php endif; ?>
      <?php if ($collector_badge): ?><span class="imc-up-badge vault <?php echo esc_attr($collector_badge['class']); ?>" title="Collector tier &mdash; <?php echo esc_attr($collector_badge['name'] . (!empty($collector_badge['flavour']) ? ': ' . $collector_badge['flavour'] : '')); ?>">💎 <?php echo esc_html($collector_badge['name']); ?></span><?php endif; ?>
    </div>
  </div>

  <div class="imc-up-head">
    <img class="imc-up-pfp" src="<?php echo esc_url($pfp !== '' ? $pfp : '/wp-content/uploads/fallback-nft.svg'); ?>" alt="" onerror="this.src='/wp-content/uploads/fallback-nft.svg'">
    <div class="imc-up-idcol">
      <h1 class="imc-up-name">
        <?php echo esc_html($display_name); ?>
      </h1>
      <div class="imc-up-idmeta">
      <div class="imc-up-wallet">
        <span class="imc-up-walletaddr"><?php echo esc_html($wallet_short); ?></span>
        <button type="button" class="imc-up-copybtn" title="Copy wallet address" onclick="navigator.clipboard&&navigator.clipboard.writeText('<?php echo esc_js($account); ?>');var t=this.querySelector('.ci');if(t){t.textContent='✓';setTimeout(function(){t.textContent='⧉';},1200);}"><span class="ci">⧉</span></button>
      </div>
      <?php if ($is_self): ?>
      <div class="imc-up-balance" id="imcUpWbLine" style="display:none">
        <span>Available: <b id="imcUpWbXrp">&hellip;</b></span>
        <button type="button" class="imc-up-wbplus" id="imcUpWbPlus" title="View all holdings">+</button>
      </div>
      <div class="imc-up-wbmodal" id="imcUpWbModal" style="display:none" role="dialog" aria-modal="true">
        <div class="imc-up-wbback" data-wbclose="1"></div>
        <div class="imc-up-wbcard">
          <div class="imc-up-wbhead"><h3>Wallet Holdings</h3><button type="button" class="imc-up-wbx" data-wbclose="1">&times;</button></div>
          <div class="imc-up-wbbody" id="imcUpWbBody"></div>
        </div>
      </div>
      <?php endif; ?>
      <?php if (imc_up_show('followers', $vis, $is_self)): ?>
      <div class="imc-up-counts">
        <span class="imc-up-countlink" data-list="followers"><b id="imcUpFollowersCount"><?php echo (int) $followers; ?></b> Followers</span>
        <span class="imc-up-countlink" data-list="following"><b><?php echo (int) $following; ?></b> Following</span>
        <?php if ($is_self && empty($vis['followers'])): ?><span class="imc-up-hiddenchip">hidden</span><?php endif; ?>
      </div>
      <?php endif; ?>
      </div>
    </div>
    <div class="imc-up-actions">
      <?php if ($is_creator && $slug !== ''): ?>
        <a class="imc-up-btn ghost" href="<?php echo esc_url(home_url('/creators/' . rawurlencode($slug) . '/')); ?>">🛒 Storefront</a>
      <?php endif; ?>
      <?php if ($is_self): ?>
        <button class="imc-up-btn ghost" id="imcUpEditBtn" type="button" title="Edit Profile" aria-label="Edit Profile">⚙️</button>
        <a class="imc-up-btn ghost" href="<?php echo esc_url(home_url('/trading-hub-dashboard/')); ?>">📊 Offers Hub</a>
      <?php elseif ($self_wallet !== ''): ?>
        <button class="imc-up-btn" id="imcUpFollowBtn" type="button"
                data-handler="<?php echo esc_attr($handler_follows); ?>"
                data-target="<?php echo esc_attr($account); ?>"
                data-nonce="<?php echo esc_attr($nonce); ?>">Follow</button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($bio !== ''): ?><div class="imc-up-biowrap"><div class="imc-up-bio clamped" id="imcUpBio"><?php echo esc_html($bio); ?></div><button type="button" class="imc-up-biomore" id="imcUpBioMore">Read more</button></div><?php endif; ?>

  <?php if (imc_up_show('links', $vis, $is_self) && (!empty($social) || !empty($custom_links))): ?>
  <div class="imc-up-links">
    <?php foreach ($social_labels as $sk => $lab) { if (!empty($social[$sk])) {
        echo '<a class="imc-up-link" target="_blank" rel="noopener nofollow" href="' . esc_url($social[$sk]) . '">' . esc_html($lab) . '</a>';
    } }
    foreach ($custom_links as $cl) { if (!empty($cl['url'])) {
        echo '<a class="imc-up-link" target="_blank" rel="noopener nofollow" href="' . esc_url($cl['url']) . '">' . esc_html($cl['label'] ?: 'Link') . '</a>';
    } } ?>
    <?php if ($is_self && empty($vis['links'])): ?><span class="imc-up-hiddenchip">hidden</span><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($is_self): ?>
  <!-- ======================= SELF-ONLY EDITOR ======================= -->
  <div class="imc-up-editor" id="imcUpEditor"
       data-handler="<?php echo esc_attr($handler); ?>" data-nonce="<?php echo esc_attr($nonce); ?>"
       data-social='<?php echo esc_attr(wp_json_encode($social)); ?>'>
    <h3 style="margin:0 0 4px">Edit Profile</h3>
    <form id="imcUpFormMain">
      <label>Display name</label>
      <input type="text" name="display_name" maxlength="120" value="<?php echo esc_attr($profile['display_name'] ?? ''); ?>">
      <label>Bio (max 1000)</label>
      <textarea name="bio" rows="4" maxlength="1000"><?php echo esc_textarea($bio); ?></textarea>
      <?php foreach ($social_labels as $sk => $lab): ?>
        <label><?php echo esc_html($lab); ?> URL</label>
        <input type="url" name="social_<?php echo esc_attr($sk); ?>" value="<?php echo esc_attr($social[$sk] ?? ''); ?>" placeholder="https://">
      <?php endforeach; ?>
      <label>Section visibility (unticked = hidden from your public profile)</label>
      <div class="imc-up-vis">
        <?php foreach (['created'=>'Created','collected'=>'Collected','history'=>'History','favourites'=>'Favourites','links'=>'Links','followers'=>'Followers'] as $vk=>$vl): ?>
          <label><input type="checkbox" class="imcUpVis" value="<?php echo esc_attr($vk); ?>" <?php checked(!empty($vis[$vk])); ?>> <?php echo esc_html($vl); ?></label>
        <?php endforeach; ?>
      </div>
      <p><button class="imc-up-btn" type="submit">Save Profile</button><span class="imc-up-savemsg" id="imcUpMsgMain"></span></p>
    </form>
    <hr style="border-color:#2b2b40;margin:18px 0">
    <label>Profile picture (max 4MB)</label>
    <input type="file" id="imcUpPfp" accept="image/*"> <button class="imc-up-btn ghost" type="button" data-imgsave="profile_image" data-file="imcUpPfp">Save picture</button><span class="imc-up-savemsg" data-imgmsg="profile_image"></span>
    <label>Banner — 3:1 recommended, e.g. 1500×500 (max 6MB). One banner, every device.</label>
    <input type="file" id="imcUpBanD" accept="image/*"> <button class="imc-up-btn ghost" type="button" data-imgsave="banner_desktop" data-file="imcUpBanD">Save banner</button><span class="imc-up-savemsg" data-imgmsg="banner_desktop"></span>
  </div>
  <?php endif; ?>

  <!-- ============================ TABS ============================ -->
  <div class="imc-up-tabs" id="imcUpTabs">
    <?php if ($is_creator && imc_up_show('created', $vis, $is_self)): ?><button class="imc-up-tab active" data-tab="created">Created<?php if ($is_self && empty($vis['created'])) echo '<span class="imc-up-hiddenchip">hidden</span>'; ?></button><?php endif; ?>
    <button class="imc-up-tab<?php echo (!$is_creator || !imc_up_show('created', $vis, $is_self)) ? ' active' : ''; ?>" data-tab="collected">Collected</button>
    <?php if (imc_up_show('history', $vis, $is_self)): ?><button class="imc-up-tab" data-tab="history">History<?php if ($is_self && empty($vis['history'])) echo '<span class="imc-up-hiddenchip">hidden</span>'; ?></button><?php endif; ?>
    <?php if (imc_up_show('favourites', $vis, $is_self)): ?><button class="imc-up-tab" data-tab="favourites">Favourites<?php if ($is_self && empty($vis['favourites'])) echo '<span class="imc-up-hiddenchip">hidden</span>'; ?></button><?php endif; ?>
  </div>

  <?php if ($is_creator && imc_up_show('created', $vis, $is_self)): ?>
  <div class="imc-up-panel active" data-panel="created"
       id="imcUpCreated" data-listings="<?php echo esc_attr($listings_ep); ?>"
       data-account="<?php echo esc_attr($account); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
    <div class="imc-up-grid" id="imcUpCreatedGrid"><div class="imc-up-empty">Loading collections…</div></div>
  </div>
  <?php endif; ?>

  <div class="imc-up-panel<?php echo (!$is_creator || !imc_up_show('created', $vis, $is_self)) ? ' active' : ''; ?>" data-panel="collected"
       id="imcUpCollected" data-owner="<?php echo esc_attr($account); ?>"
       data-imc-pairs="<?php echo esc_attr(wp_json_encode(array_keys($imc_dedupe_pairs))); ?>">
    <div class="imc-up-typebar" id="imcUpTypeBar">
      <button class="imc-up-typetab active" data-type="all" type="button">All</button>
      <button class="imc-up-typetab" data-type="art" type="button">🎨 Art</button>
      <button class="imc-up-typetab" data-type="music" type="button">🎵 Music</button>
      <button class="imc-up-typetab" data-type="musicvideo" type="button">🎬 Music Video</button>
      <button class="imc-up-typetab" data-type="album" type="button">💿 Album</button>
      <button class="imc-up-typetab" data-type="film" type="button">🎥 Film</button>
    </div>
    <?php if (!empty($imc_collected)): ?>
    <div class="imc-up-grid" id="imcUpImcGrid" style="margin-bottom:16px">
      <?php foreach ($imc_collected as $c):
          $c_img = $c['cover'] !== '' ? ('https://metadata.imcollectibles.io/img.php?url=' . urlencode(strpos($c['cover'], 'ipfs://') === 0 ? $c['cover'] : ('ipfs://' . str_replace('ipfs://', '', $c['cover']))) . '&thumb=1') : '/wp-content/uploads/fallback-nft.svg';
          $c_multi = !(count($c['ids']) === 1 && $c['count'] === 1);
          $c_col_url = home_url('/collections/?issuer=' . rawurlencode($c['artist']) . '&taxon=' . (int) $c['taxon']);
          $c_href = $c_multi ? $c_col_url : home_url('/nft/' . rawurlencode($c['ids'][0]) . '/');
      ?>
        <a class="imc-up-colcard" style="display:block;background:#17172a;border:1px solid #2b2b40;border-radius:12px;overflow:hidden;text-decoration:none;color:#e8e8f0"
           href="<?php echo esc_url($c_href); ?>"
           <?php if ($c_multi): ?>data-drill="imc" data-colname="<?php echo esc_attr($c['name'] !== '' ? $c['name'] : 'Untitled'); ?>"
           data-artist="<?php echo esc_attr($c['artist_name'] !== '' ? $c['artist_name'] : $c['artist']); ?>"
           data-colurl="<?php echo esc_url($c_col_url); ?>"<?php endif; ?>
           data-types="<?php echo esc_attr(implode(',', array_keys($c['types']))); ?>"
           data-imc-ids="<?php echo esc_attr(implode(',', array_slice($c['ids'], 0, 100))); ?>"
           data-imc-items="<?php echo esc_attr(wp_json_encode(array_slice($c['items'], 0, 100))); ?>">
          <div style="aspect-ratio:1/1;background:#101020"><img loading="lazy" style="width:100%;height:100%;object-fit:cover"
               src="<?php echo esc_url($c_img); ?>" onerror="this.onerror=null;this.src='/wp-content/uploads/fallback-nft.svg'"></div>
          <div style="padding:9px 11px"><div style="font-weight:600;font-size:.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo esc_html($c['name'] !== '' ? $c['name'] : 'Untitled'); ?></div>
          <div class="imc-up-owncount" style="color:#8a8aa4;font-size:.75rem"><?php echo (int) $c['count']; ?> owned</div></div>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="imc-up-grid" id="imcUpCollectedGrid"><div class="imc-up-empty">Loading collection…</div></div>
    <div id="imcUpCollectedMore" style="text-align:center;margin-top:18px;display:none;color:#8a8aa4;font-size:.85rem">Loading more&hellip;</div>
    <div class="imc-up-drill" id="imcUpDrill">
      <div class="imc-up-drillhead">
        <button class="imc-up-backbtn" id="imcUpDrillBack" type="button">&larr; Back to Collected</button>
        <h3 id="imcUpDrillTitle"></h3>
        <a class="imc-up-viewcolbtn" id="imcUpDrillViewCol" href="#">View Collection</a>
      </div>
      <div class="imc-up-grid" id="imcUpDrillGrid"></div>
    </div>
  </div>

  <?php if (imc_up_show('history', $vis, $is_self)): ?>
  <div class="imc-up-panel" data-panel="history">
    <?php if (empty($history_rows)): ?>
      <div class="imc-up-empty">No purchases yet.</div>
    <?php else: ?>
      <div class="imc-up-tablewrap"><table class="imc-up-table"><thead><tr><th>Item</th><th>Edition</th><th>Price</th><th>Status</th><th>Date</th><th></th></tr></thead><tbody>
      <?php foreach ($history_rows as $h): ?>
        <tr>
          <td><?php echo esc_html($h['listing_title'] ?: ('Listing #' . (int) $h['listing_id'])); ?><?php if (!empty($h['tier_name'])): ?> <span style="color:#8a8aa4">· <?php echo esc_html($h['tier_name']); ?></span><?php endif; ?></td>
          <td><?php echo $h['edition_number'] ? '#' . (int) $h['edition_number'] : '—'; ?></td>
          <td><?php echo esc_html(rtrim(rtrim(number_format((float) $h['price_xrp'], 6, '.', ''), '0'), '.') . ' ' . ($h['price_currency'] ?: 'XRP')); ?></td>
          <td><?php echo esc_html(ucfirst($h['mint_status'])); ?></td>
          <td><?php echo esc_html(mysql2date('j M Y', $h['created_at'])); ?></td>
          <td><?php if (!empty($h['nftoken_id']) && preg_match('/^[A-Fa-f0-9]{64}$/', $h['nftoken_id'])): ?><a class="imc-up-viewbtn" href="<?php echo esc_url(home_url('/nft/' . rawurlencode($h['nftoken_id']) . '/')); ?>">View NFT</a><?php else: ?><span style="color:#55556e">&mdash;</span><?php endif; ?><?php if ($is_self && ($h['mint_status'] ?? '') === 'minted'): ?> <a class="imc-up-viewbtn imc-up-claimbtn" href="<?php echo esc_url(home_url('/trading-hub-dashboard/#section-pending-claims')); ?>">Claim</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (imc_up_show('favourites', $vis, $is_self)): ?>
  <div class="imc-up-panel" data-panel="favourites">
    <?php if (empty($fav_rows)): ?>
      <div class="imc-up-empty">No favourites yet.</div>
    <?php else: ?>
      <div class="imc-up-grid" id="imcUpFavGrid">
      <?php foreach ($fav_rows as $f): if (!preg_match('/^[A-Fa-f0-9]{64}$/', $f['nft_id'])) continue; ?>
        <a style="display:block;background:#17172a;border:1px solid #2b2b40;border-radius:12px;overflow:hidden;text-decoration:none;color:#e8e8f0"
           href="<?php echo esc_url(home_url('/nft/' . rawurlencode($f['nft_id']) . '/')); ?>" data-fav-id="<?php echo esc_attr($f['nft_id']); ?>">
          <div style="aspect-ratio:1/1;background:#101020"><img loading="lazy" style="width:100%;height:100%;object-fit:cover"
               src="<?php echo esc_url('https://metadata.imcollectibles.io/img.php?nft=' . rawurlencode($f['nft_id']) . '&thumb=1'); ?>"
               onerror="this.onerror=null;this.src='/wp-content/uploads/fallback-nft.svg'"></div>
          <div style="padding:9px 11px"><div class="imc-up-favname" style="font-weight:600;font-size:.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo esc_html(($f['nft_name'] !== '' && $f['nft_name'] !== 'NFT') ? $f['nft_name'] : (substr($f['nft_id'], 0, 10) . '…')); ?></div>
          <div style="color:#8a8aa4;font-size:.75rem"><?php echo esc_html(mysql2date('j M Y', $f['created_at'])); ?></div></div>
        </a>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

<?php if (!$not_found): ?>
<div class="imc-up-modal" id="imcUpModal" data-handler="<?php echo esc_attr($handler_follows); ?>"
     data-account="<?php echo esc_attr($account); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
  <div class="imc-up-modal-box">
    <div class="imc-up-modal-head"><span id="imcUpModalTitle">Followers</span>
      <button type="button" id="imcUpModalClose" class="imc-up-modal-x">&times;</button></div>
    <div class="imc-up-modal-list" id="imcUpModalList"><div class="imc-up-empty">Loading…</div></div>
  </div>
</div>
<?php endif; ?>

<?php endif; /* not_found */ ?>
</div>

<script>
(function(){
  // ---- Tabs ----
  var tabs = document.getElementById('imcUpTabs');
  if (tabs) tabs.addEventListener('click', function(e){
    var b = e.target.closest('.imc-up-tab'); if (!b) return;
    tabs.querySelectorAll('.imc-up-tab').forEach(function(t){ t.classList.remove('active'); });
    document.querySelectorAll('.imc-up-panel').forEach(function(p){ p.classList.remove('active'); });
    b.classList.add('active');
    var p = document.querySelector('.imc-up-panel[data-panel="' + b.dataset.tab + '"]');
    if (p) p.classList.add('active');
  });

  // ---- Created grid (creator only): group get_by_artist listings by collection ----
  var created = document.getElementById('imcUpCreated');
  if (created) {
    fetch(created.dataset.listings + '?action=get_by_artist&account=' + encodeURIComponent(created.dataset.account) + '&_=' + Date.now(), {credentials:'include'})
      .then(function(r){ return r.json(); })
      .then(function(d){
        var grid = document.getElementById('imcUpCreatedGrid');
        var listings = (d && d.success && d.data && d.data.listings) ? d.data.listings : (d && d.listings ? d.listings : []);
        if (!listings.length) { grid.innerHTML = '<div class="imc-up-empty">No collections yet.</div>'; return; }
        var cols = {};
        listings.forEach(function(l){
          var key = (l.collection_name || l.nft_name || '') + '|' + (l.collection_taxon || 0);
          if (!cols[key]) cols[key] = { name:(l.collection_name || l.nft_name || 'Untitled'), cover:(l.cover_ipfs || ''), n:0, taxon:(l.collection_taxon || 0) };
          cols[key].n++;
        });
        var esc = function(s){ var d2 = document.createElement('div'); d2.textContent = (s == null ? '' : String(s)); return d2.innerHTML; };
        grid.innerHTML = Object.keys(cols).map(function(k){
          var c = cols[k];
          var img = c.cover ? ('https://metadata.imcollectibles.io/img.php?url=' + encodeURIComponent(c.cover.indexOf('ipfs://') === 0 ? c.cover : ('ipfs://' + String(c.cover).replace('ipfs://', ''))) + '&thumb=1') : '/wp-content/uploads/fallback-nft.svg'; /* L3b: card grid — thumb */
          var href = '/collections/?issuer=' + encodeURIComponent(created.dataset.account) + '&taxon=' + encodeURIComponent(c.taxon);
          return '<a style="display:block;background:#17172a;border:1px solid #2b2b40;border-radius:12px;overflow:hidden;text-decoration:none;color:#e8e8f0" href="' + href + '">'
               + '<div style="aspect-ratio:1/1;background:#101020"><img loading="lazy" style="width:100%;height:100%;object-fit:cover" src="' + img + '" onerror="this.style.display=\'none\'"></div>'
               + '<div style="padding:10px 12px"><div style="font-weight:600">' + esc(c.name) + '</div><div style="color:#8a8aa4;font-size:.8rem">' + c.n + ' listing' + (c.n === 1 ? '' : 's') + '</div></div></a>';
        }).join('');
      })
      .catch(function(){ var g = document.getElementById('imcUpCreatedGrid'); if (g) g.innerHTML = '<div class="imc-up-empty">Could not load collections.</div>'; });
  }

  // ---- Favourites: hydrate real NFT names from the VPS store (batch) ----
  var favGrid = document.getElementById('imcUpFavGrid');
  if (favGrid) {
    var favCards = Array.prototype.slice.call(favGrid.querySelectorAll('[data-fav-id]'));
    var favIds = favCards.map(function(a){ return a.dataset.favId; }).slice(0, 100);
    if (favIds.length) {
      fetch('https://metadata.imcollectibles.io/indexer.php?action=batch', {method:'POST', credentials:'omit', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ids: favIds})})
        .then(function(r){ return r.json(); })
        .then(function(d){
          if (!d || !d.success || !d.nfts) return;
          var byId = {};
          d.nfts.forEach(function(n){ if (n && n.nft_token_id) byId[n.nft_token_id] = n; });
          favCards.forEach(function(a){
            var n = byId[a.dataset.favId];
            if (n && n.name) { var el = a.querySelector('.imc-up-favname'); if (el) el.textContent = n.name; }
          });
        })
        .catch(function(){ /* stored names remain */ });
    }
  }

  // ---- D2: IMC section — verify current ownership via the store (sold pieces drop out) ----
  var imcGrid = document.getElementById('imcUpImcGrid');
  if (imcGrid) {
    var imcCards = Array.prototype.slice.call(imcGrid.querySelectorAll('[data-imc-ids]'));
    var imcAllIds = [];
    imcCards.forEach(function(a){ (a.dataset.imcIds || '').split(',').forEach(function(i){ if (i) imcAllIds.push(i); }); });
    if (imcAllIds.length) {
      fetch('https://metadata.imcollectibles.io/indexer.php?action=batch', {method:'POST', credentials:'omit', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ids: imcAllIds.slice(0, 100), any: '1'})}) /* P1g: pending rows carry owner - exactly what this check reads */
        .then(function(r){ return r.json(); })
        .then(function(d){
          if (!d || !d.success || !d.nfts) return;
          var ocEl = document.getElementById('imcUpCollected');
          var acct = (ocEl && ocEl.dataset) ? ocEl.dataset.owner : '';
          var soldSet = window.__imcSold = window.__imcSold || {};
          d.nfts.forEach(function(n){
            // verified-not-owned ONLY: a missing/unknown owner keeps the piece (fail-open — unindexed platform mints)
            if (n && n.nft_token_id && n.owner && acct && n.owner !== acct) soldSet[n.nft_token_id] = 1;
          });
          imcCards.forEach(function(a){
            var ids = (a.dataset.imcIds || '').split(',').filter(Boolean);
            if (!ids.length) return;
            var kept = ids.filter(function(i){ return !soldSet[i]; });
            if (kept.length === ids.length) return;
            if (kept.length === 0) { a.remove(); }
            else {
              var lbl = a.querySelector('.imc-up-owncount');
              if (lbl) lbl.textContent = kept.length + ' owned';
              if (kept.length === 1) a.href = '/nft/' + encodeURIComponent(kept[0]) + '/';
            }
          });
          if (!imcGrid.children.length) { imcGrid.remove(); }
        })
        .catch(function(){ /* fail-open: purchases view stands */ });
    }
  }

  // ---- Collected tab (D3): XRPL holdings as COLLECTION cards + unified drill-in ----
  // ---- P2I-b: hero wallet balance ($is_self) — line + holdings popup.
  //      Self-contained; markup only renders for the owner, so absence = no-op. ----
  (function(){
    var wbLine = document.getElementById('imcUpWbLine');
    if (!wbLine) return;
    var wbEp = '<?php echo esc_js(get_stylesheet_directory_uri()); ?>/xrpl-nft-marketplace/backend/offer-handler.php';
    var wbNonce = '<?php echo esc_js($nonce); ?>';
    var wbData = null;
    function wbFmt(v){ return Number(v).toLocaleString(undefined, {maximumFractionDigits: 2}); }
    function wbEsc(s){ var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }
    fetch(wbEp + '?action=wallet_balances&nonce=' + encodeURIComponent(wbNonce))
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d || !d.success || !d.xrp) return;
        wbData = d;
        var el = document.getElementById('imcUpWbXrp');
        if (el) el.textContent = wbFmt(d.xrp.available) + ' XRP';
        wbLine.style.display = '';
      })
      .catch(function(){ /* line stays hidden */ });
    var wbModal = document.getElementById('imcUpWbModal');
    var wbBody = document.getElementById('imcUpWbBody');
    var wbPlus = document.getElementById('imcUpWbPlus');
    if (wbPlus) wbPlus.addEventListener('click', function(){
      if (!wbData || !wbModal || !wbBody) return;
      var h = '';
      h += '<div class="imc-up-wbline"><span class="imc-up-wbmuted">Balance</span><span>' + wbFmt(wbData.xrp.balance) + ' XRP</span></div>';
      h += '<div class="imc-up-wbline"><span class="imc-up-wbmuted">Reserve</span><span>' + wbFmt(wbData.xrp.reserve) + ' XRP</span></div>';
      h += '<div class="imc-up-wbline"><span class="imc-up-wbmuted">Available</span><span><b style="color:#d4af37">' + wbFmt(wbData.xrp.available) + ' XRP</b></span></div>';
      (wbData.tokens || []).forEach(function(t){
        h += '<div class="imc-up-wbline"><span>' + wbEsc(t.ticker || t.currency) + '</span><span>' + wbFmt(t.balance) + '</span></div>';
      });
      if (wbData.scam_lines > 0) { h += '<div class="imc-up-wbline imc-up-wbmuted"><span>&#9888; ' + wbData.scam_lines + ' flagged line' + (wbData.scam_lines === 1 ? '' : 's') + ' hidden</span><span></span></div>'; }
      wbBody.innerHTML = h;
      wbModal.style.display = '';
    });
    if (wbModal) wbModal.addEventListener('click', function(e){
      if (e.target.closest('[data-wbclose="1"]')) { wbModal.style.display = 'none'; }
    });
  })();

  var collected = document.getElementById('imcUpCollected');
  if (collected) {
    var cOwner = collected.dataset.owner;
    var OFFER_EP = '<?php echo esc_js(get_stylesheet_directory_uri()); ?>/xrpl-nft-marketplace/backend/offer-handler.php';
    var IMC_NONCE = '<?php echo esc_js($nonce); ?>';
    var cOffset = 0, cLimit = 200, cLoading = false, cLoaded = false, cTotal = 0; /* P2H-b U1: server-cap pages */
    var cGrid = document.getElementById('imcUpCollectedGrid');
    var cMore = document.getElementById('imcUpCollectedMore');
    // P2H-S2: ONE grid — relocate PHP-rendered IMC cards into the shared grid
    // (IMC-first by construction). The D2 verify script captured its card
    // references synchronously before this runs, so sold-pruning survives;
    // its trailing shell-removal no-ops on the detached element.
    var s2Shell = document.getElementById('imcUpImcGrid');
    if (s2Shell && cGrid) {
      var s2Load = cGrid.querySelector('.imc-up-empty');
      if (s2Load) s2Load.remove();
      while (s2Shell.firstElementChild) { cGrid.appendChild(s2Shell.firstElementChild); }
      s2Shell.remove();
    }
    var cEsc = function(s){ var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; };
    var OWNED_EP = 'https://metadata.imcollectibles.io/indexer.php';
    var cPairs = {};
    try { (JSON.parse(collected.dataset.imcPairs || '[]') || []).forEach(function(k){ cPairs[k] = 1; }); } catch (e) {}

    // -- drill machinery (shared by IMC + XRPL cards) --
    var drill = document.getElementById('imcUpDrill');
    var drillTitle = document.getElementById('imcUpDrillTitle');
    var drillView = document.getElementById('imcUpDrillViewCol');
    var drillGrid = document.getElementById('imcUpDrillGrid');
    var drillBack = document.getElementById('imcUpDrillBack');
    function collRoots() {
      return Array.prototype.slice.call(collected.children).filter(function(el){ return el !== drill; });
    }
    function drillNftCard(id, name) {
      return '<a data-nftid="' + cEsc(id) + '" style="display:block;position:relative;background:#17172a;border:1px solid #2b2b40;border-radius:12px;overflow:hidden;text-decoration:none;color:#e8e8f0" href="/nft/' + encodeURIComponent(id) + '/">'
           + '<span class="imc-up-ownpill imc-up-offerpill" style="display:none"></span>'
           + '<div style="aspect-ratio:1/1;background:#101020"><img loading="lazy" style="width:100%;height:100%;object-fit:cover" src="https://metadata.imcollectibles.io/img.php?nft=' + encodeURIComponent(id) + '&thumb=1" onerror="this.onerror=null;this.src=\'/wp-content/uploads/fallback-nft.svg\'"></div>'
           + '<div style="padding:9px 11px"><div style="font-weight:600;font-size:.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + cEsc(name || 'Unnamed NFT') + '</div></div></a>';
    }
    // P2H-D1b: batch active-buy-offer counts for drill cards (hide-at-zero)
    function loadOfferCounts(ids) {
      ids = (ids || []).filter(function(i){ return /^[0-9A-Fa-f]{64}$/.test(i); }).slice(0, 100);
      if (!ids.length) return;
      fetch(OFFER_EP + '?action=nft_offer_counts&nonce=' + encodeURIComponent(IMC_NONCE) + '&ids=' + encodeURIComponent(ids.join(',')))
        .then(function(r){ return r.json(); })
        .then(function(d){
          var counts = (d && d.success && d.counts) ? d.counts : null;
          if (!counts) return;
          Object.keys(counts).forEach(function(id){
            var n = parseInt(counts[id], 10) || 0;
            if (n <= 0) return;
            var pill = drillGrid.querySelector('a[data-nftid="' + id + '"] .imc-up-offerpill');
            if (pill) { pill.textContent = n + (n === 1 ? ' offer' : ' offers'); pill.style.display = ''; }
          });
        })
        .catch(function(){ /* pills stay hidden */ });
    }
    function openDrill(colName, artistDisp, colUrl) {
      drillTitle.innerHTML = cEsc(colName) + (artistDisp ? ' <span>&mdash; ' + cEsc(artistDisp) + '</span>' : '');
      if (colUrl) { drillView.href = colUrl; drillView.style.display = ''; } else { drillView.style.display = 'none'; }
      drillGrid.innerHTML = '<div class="imc-up-empty">Loading&hellip;</div>';
      collRoots().forEach(function(el){ el.dataset.prevDisplay = el.style.display || ''; el.style.display = 'none'; });
      drill.classList.add('open');
      drill.scrollIntoView({behavior:'smooth', block:'start'});
    }
    function closeDrill() {
      drill.classList.remove('open');
      collRoots().forEach(function(el){ el.style.display = el.dataset.prevDisplay || ''; delete el.dataset.prevDisplay; });
    }
    if (drillBack) drillBack.addEventListener('click', closeDrill);

    // IMC cards: multi-owned opens the drill from embedded items (no fetch)
    collected.addEventListener('click', function(e){
      var a = e.target.closest('a[data-drill="imc"]');
      if (!a || !collected.contains(a)) return;
      e.preventDefault();
      var items = [];
      try { items = JSON.parse(a.dataset.imcItems || '[]') || []; } catch (err) {}
      var sold = window.__imcSold || {};
      items = items.filter(function(it){ return it && it.i && !sold[it.i]; });
      openDrill(a.dataset.colname || 'Collection', a.dataset.artist || '', a.dataset.colurl || '');
      drillGrid.innerHTML = items.length
        ? items.map(function(it){ return drillNftCard(it.i, it.n); }).join('')
        : '<div class="imc-up-empty">No pieces to show.</div>';
      if (items.length) loadOfferCounts(items.map(function(it){ return it.i; }));
    });

    // XRPL cards: multi-owned opens the drill via the owned endpoint
    function openXrplDrill(issuer, taxon, colName, colUrl) {
      openDrill(colName, issuer.slice(0, 6) + '\u2026' + issuer.slice(-4), colUrl);
      fetch(OWNED_EP + '?action=owned&owner=' + encodeURIComponent(cOwner) + '&issuer=' + encodeURIComponent(issuer) + '&taxon=' + encodeURIComponent(taxon) + '&limit=200', {credentials:'omit'})
        .then(function(r){ return r.json(); })
        .then(function(d){
          var nfts = (d && d.success && d.nfts) ? d.nfts : [];
          drillGrid.innerHTML = nfts.length
            ? nfts.map(function(n){ return drillNftCard(n.nft_token_id, n.name); }).join('')
            : '<div class="imc-up-empty">Could not load pieces.</div>';
          if (nfts.length) loadOfferCounts(nfts.map(function(n){ return n.nft_token_id; }));
        })
        .catch(function(){ drillGrid.innerHTML = '<div class="imc-up-empty">Could not load pieces.</div>'; });
    }

    function cRender(cols) {
      return cols.filter(function(c){ return !cPairs[(c.issuer || '') + '|' + (c.taxon != null ? c.taxon : '')]; }).map(function(c){
        var img = c.image_proxy || '/wp-content/uploads/fallback-nft.svg';
        var noimg = c.image_proxy ? '' : ' data-noimg="1"'; /* P2H-b U2 */
        var n = c.owned_count || 0;
        var single = (n === 1 && c.cover_nft_id);
        var href = single ? ('/nft/' + encodeURIComponent(c.cover_nft_id) + '/') : ('/collections/?issuer=' + encodeURIComponent(c.issuer) + '&taxon=' + encodeURIComponent(c.taxon));
        return '<a class="imc-up-colcard"' + noimg + ' style="display:block;background:#17172a;border:1px solid #2b2b40;border-radius:12px;overflow:hidden;text-decoration:none;color:#e8e8f0" href="' + href + '"'
             + ' data-ct="' + cEsc(c.content_type || 'image') + '" data-ismusic="' + (c.is_music ? '1' : '0') + '"'
             + (single ? '' : ' data-xdrill="1" data-issuer="' + cEsc(c.issuer) + '" data-taxon="' + cEsc(c.taxon) + '" data-colname="' + cEsc(c.name || 'Collection') + '"')
             + '>'
             + '<div style="aspect-ratio:1/1;background:#101020"><img loading="lazy" style="width:100%;height:100%;object-fit:cover" src="' + cEsc(img) + '" onerror="this.onerror=null;this.src=\'/wp-content/uploads/fallback-nft.svg\';var a=this.closest(\'.imc-up-colcard\');if(a&&a.parentElement){a.dataset.broken=\'1\';a.parentElement.appendChild(a);}"></div>'
             + '<div style="padding:9px 11px"><div style="font-weight:600;font-size:.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + cEsc(c.name || 'Collection') + '</div>'
             + '<div style="color:#8a8aa4;font-size:.75rem">' + n + ' owned</div></div></a>';
      }).join('');
    }

    // P2H-b U2: one global pass — cards with no cover (data-noimg) or a failed
    // load (data-broken) move to the grid tail. appendChild MOVES nodes, so
    // listeners and relative order among the demoted survive.
    function cDemoteBroken() {
      if (!cGrid) return;
      Array.prototype.slice.call(cGrid.querySelectorAll('[data-noimg="1"], [data-broken="1"]')).forEach(function(a){
        cGrid.appendChild(a);
      });
    }

    // -- D4: type filter (All/Art/Music/Music Video/Album/Film), my-nfts parity --
    var typeBar = document.getElementById('imcUpTypeBar');
    var typeState = 'all';
    function xrplMatches(ct, isMusic, want) {
      if (want === 'all') return true;
      if (want === 'music') return isMusic || ct === 'audio';
      if (want === 'art') return ct === 'image' && !isMusic;
      if (want === 'musicvideo' || want === 'film') return ct === 'video';
      return false; /* album: IMC-only */
    }
    function applyTypeFilter() {
      var ig = document.getElementById('imcUpImcGrid');
      if (ig) Array.prototype.forEach.call(ig.children, function(a){
        var types = (a.dataset.types || '').split(',').filter(Boolean);
        a.style.display = (typeState === 'all' || types.indexOf(typeState) !== -1) ? '' : 'none';
      });
      Array.prototype.forEach.call(cGrid.children, function(a){
        if (!a.dataset) return;
        if (a.dataset.types !== undefined) { /* P2H-S2: relocated IMC cards keep their own type semantics */
          var t2 = (a.dataset.types || '').split(',').filter(Boolean);
          a.style.display = (typeState === 'all' || t2.indexOf(typeState) !== -1) ? '' : 'none';
        } else if (a.dataset.ct !== undefined || a.dataset.ismusic !== undefined) {
          a.style.display = xrplMatches(a.dataset.ct || 'image', a.dataset.ismusic === '1', typeState) ? '' : 'none';
        }
      });
    }
    if (typeBar) typeBar.addEventListener('click', function(e){
      var b = e.target.closest('.imc-up-typetab'); if (!b) return;
      typeBar.querySelectorAll('.imc-up-typetab').forEach(function(t){ t.classList.remove('active'); });
      b.classList.add('active');
      typeState = b.dataset.type || 'all';
      applyTypeFilter();
    });

    collected.addEventListener('click', function(e){
      var a = e.target.closest('a[data-xdrill="1"]');
      if (!a || !collected.contains(a)) return;
      e.preventDefault();
      openXrplDrill(a.dataset.issuer, a.dataset.taxon, a.dataset.colname || 'Collection', a.href);
    });

    function cLoad() {
      if (cLoading) return;
      cLoading = true;
      fetch(OWNED_EP + '?action=owned_collections&owner=' + encodeURIComponent(cOwner) + '&limit=' + cLimit + '&offset=' + cOffset, {credentials:'omit'})
        .then(function(r){ return r.json(); })
        .then(function(d){
          if (!d || !d.success) { if (cOffset === 0 && !cGrid.querySelector('.imc-up-colcard')) cGrid.innerHTML = '<div class="imc-up-empty">Could not load collections.</div>'; return; }
          cTotal = d.total || 0;
          var cols = d.collections || [];
          cols.sort(function(a,b){ return (a.image_proxy ? 0 : 1) - (b.image_proxy ? 0 : 1); }); /* P2H-S1: broken-media last */
          if (cOffset === 0) { var s2l = cGrid.querySelector('.imc-up-empty'); if (s2l) s2l.remove(); }
          if (cOffset === 0 && cols.length === 0) {
            if (!cGrid.querySelector('.imc-up-colcard')) cGrid.innerHTML = '<div class="imc-up-empty">No collections held yet.</div>';
            return;
          }
          cGrid.insertAdjacentHTML('beforeend', cRender(cols));
          cOffset += cols.length;
          applyTypeFilter();
          /* P2H-b U1: load-all — auto-chain pages (150ms breather); the old
             Load-more div is now a passive progress indicator. */
          cMore.style.display = (cOffset < cTotal) ? 'block' : 'none';
          if (cOffset < cTotal) { setTimeout(cLoad, 150); }
          else { cDemoteBroken(); } /* P2H-b U2: one global pass on completion */
        })
        .catch(function(){ if (cOffset === 0 && !cGrid.querySelector('.imc-up-colcard')) cGrid.innerHTML = '<div class="imc-up-empty">Could not load collections.</div>'; })
        .finally(function(){ cLoading = false; });
    }

    // lazy-load only when the Collected tab is first shown (it may not be the default)
    var cTabBtn = document.querySelector('.imc-up-tab[data-tab="collected"]');
    function cMaybeLoad(){ if (!cLoaded) { cLoaded = true; cLoad(); } }
    if (cTabBtn) cTabBtn.addEventListener('click', cMaybeLoad);
    // if Collected is the active panel on load (non-creator default), load now
    if (collected.classList.contains('active')) cMaybeLoad();
  }

  // ---- Self editor ----
  var editBtn = document.getElementById('imcUpEditBtn');
  var editor  = document.getElementById('imcUpEditor');
  if (editBtn && editor) {
    /* P2H-b U4: the editor NODE relocates into a fixed modal shell (the proven
       relocation pattern) — every save/upload listener inside survives; only
       the shell's visibility is ever toggled. */
    var edModal = document.createElement('div');
    edModal.className = 'imc-up-edmodal';
    edModal.style.display = 'none';
    edModal.innerHTML = '<div class="imc-up-edback" data-edclose="1"></div>'
      + '<div class="imc-up-edcard"><button type="button" class="imc-up-edx" data-edclose="1" aria-label="Close">&times;</button><div class="imc-up-edbody"></div></div>';
    document.body.appendChild(edModal);
    edModal.querySelector('.imc-up-edbody').appendChild(editor);
    editor.classList.add('open');
    editBtn.addEventListener('click', function(){ edModal.style.display = ''; });
    edModal.addEventListener('click', function(e){ if (e.target.closest('[data-edclose="1"]')) { edModal.style.display = 'none'; } });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && edModal.style.display !== 'none') { edModal.style.display = 'none'; } });
    var handler = editor.dataset.handler, nonce = editor.dataset.nonce;
    var social  = {}; try { social = JSON.parse(editor.dataset.social || '{}') || {}; } catch(e){}

    // Main (text) save — custom_links preserved from the stored social JSON.
    document.getElementById('imcUpFormMain').addEventListener('submit', function(ev){
      ev.preventDefault();
      var fd = new FormData(ev.target);
      fd.append('action', 'set_profile'); fd.append('nonce', nonce);
      if (social.custom_links) fd.append('custom_links', JSON.stringify(social.custom_links));
      var vis = {};
      document.querySelectorAll('.imcUpVis').forEach(function(cb){ vis[cb.value] = cb.checked; });
      fd.append('section_visibility', JSON.stringify(vis));
      var msg = document.getElementById('imcUpMsgMain'); msg.textContent = 'Saving…';
      fetch(handler + '?action=set_profile', { method:'POST', body:fd, credentials:'include' })
        .then(function(r){ return r.json(); })
        .then(function(d){ msg.textContent = (d && d.success) ? 'Saved ✓ (refresh to see changes)' : ('Error: ' + ((d && (d.error || (d.data && d.data.error))) || 'save failed')); })
        .catch(function(){ msg.textContent = 'Network error'; });
    });

    // Image saves — ONE file per request by design (server post_max_size).
    document.querySelectorAll('[data-imgsave]').forEach(function(btn){
      btn.addEventListener('click', function(){
        var field = btn.dataset.imgsave;
        var input = document.getElementById(btn.dataset.file);
        var msg = document.querySelector('[data-imgmsg="' + field + '"]');
        if (!input || !input.files || !input.files[0]) { if (msg) msg.textContent = 'Choose a file first'; return; }
        var fd = new FormData();
        fd.append('action', 'set_profile'); fd.append('nonce', nonce);
        fd.append(field, input.files[0]);
        if (msg) msg.textContent = 'Uploading…';
        fetch(handler + '?action=set_profile', { method:'POST', body:fd, credentials:'include' })
          .then(function(r){ return r.json(); })
          .then(function(d){ if (msg) msg.textContent = (d && d.success) ? 'Saved ✓ (refresh to see it)' : ('Error: ' + ((d && (d.error || (d.data && d.data.error))) || 'upload failed')); })
          .catch(function(){ if (msg) msg.textContent = 'Network error'; });
      });
    });
  }

  // ---- Follow / Unfollow ----
  var followBtn = document.getElementById('imcUpFollowBtn');
  if (followBtn) {
    var fH = followBtn.dataset.handler, fTarget = followBtn.dataset.target, fNonce = followBtn.dataset.nonce;
    // hydrate initial state
    fetch(fH + '?action=status&target=' + encodeURIComponent(fTarget) + '&nonce=' + encodeURIComponent(fNonce), {credentials:'include'})
      .then(function(r){ return r.json(); })
      .then(function(d){ if (d && d.success && d.data && d.data.following) { followBtn.textContent = 'Following'; followBtn.classList.add('ghost'); } })
      .catch(function(){});
    followBtn.addEventListener('click', function(){
      var isFollowing = followBtn.classList.contains('ghost');
      var act = isFollowing ? 'unfollow' : 'follow';
      followBtn.disabled = true;
      var fd = new FormData(); fd.append('action', act); fd.append('target', fTarget); fd.append('nonce', fNonce);
      fetch(fH + '?action=' + act, { method:'POST', body:fd, credentials:'include' })
        .then(function(r){ return r.json(); })
        .then(function(d){
          if (d && d.success) {
            if (act === 'follow') { followBtn.textContent = 'Following'; followBtn.classList.add('ghost'); }
            else { followBtn.textContent = 'Follow'; followBtn.classList.remove('ghost'); }
            var fc = document.getElementById('imcUpFollowersCount');
            if (fc && d.data && typeof d.data.followers !== 'undefined') { fc.textContent = d.data.followers; }
          }
        })
        .catch(function(){})
        .finally(function(){ followBtn.disabled = false; });
    });
  }

  // ---- Followers / Following modal ----
  var modal = document.getElementById('imcUpModal');
  if (modal) {
    var mH = modal.dataset.handler, mAcct = modal.dataset.account, mNonce = modal.dataset.nonce;
    var mList = document.getElementById('imcUpModalList'), mTitle = document.getElementById('imcUpModalTitle');
    var esc = function(s){ var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; };
    function openList(kind){
      mTitle.textContent = (kind === 'followers') ? 'Followers' : 'Following';
      mList.innerHTML = '<div class="imc-up-empty">Loading\u2026</div>';
      modal.classList.add('open');
      var act = (kind === 'followers') ? 'list_followers' : 'list_following';
      fetch(mH + '?action=' + act + '&account=' + encodeURIComponent(mAcct) + '&nonce=' + encodeURIComponent(mNonce), {credentials:'include'})
        .then(function(r){ return r.json(); })
        .then(function(d){
          var rows = (d && d.success && d.data && d.data.list) || [];
          if (!rows.length) { mList.innerHTML = '<div class="imc-up-empty">Nobody yet.</div>'; return; }
          mList.innerHTML = rows.map(function(u){
            var href = '/user/' + encodeURIComponent(u.slug || u.account) + '/';
            var img = u.pfp ? esc(u.pfp) : '/wp-content/uploads/fallback-nft.svg';
            var tag = u.is_creator ? '<span class="mtag creator">Creator</span>' : '<span class="mtag collector">Collector</span>';
            return '<a class="imc-up-mrow" href="' + href + '"><img src="' + img + '" onerror="this.src=\'/wp-content/uploads/fallback-nft.svg\'"><span>' + esc(u.display_name) + '</span>' + tag + '</a>';
          }).join('');
        })
        .catch(function(){ mList.innerHTML = '<div class="imc-up-empty">Could not load.</div>'; });
    }
    document.querySelectorAll('.imc-up-countlink').forEach(function(el){
      el.addEventListener('click', function(){ openList(el.dataset.list); });
    });
    document.getElementById('imcUpModalClose').addEventListener('click', function(){ modal.classList.remove('open'); });
    modal.addEventListener('click', function(e){ if (e.target === modal) modal.classList.remove('open'); });
  }
})();
/* Badge tooltips: tap (mobile) or hover (desktop) shows the tier flavour. Reads title=, decodes &mdash;. */
(function(){
  var tipEl=null, hideT=null;
  function ensureTip(){ if(!tipEl){ tipEl=document.createElement('div'); tipEl.className='imc-up-tip'; document.body.appendChild(tipEl); } return tipEl; }
  function decode(t){ var d=document.createElement('textarea'); d.innerHTML=t; return d.value; }
  function showTip(badge){
    var txt=badge.getAttribute('data-tip')||badge.getAttribute('title'); if(!txt) return;
    // stash title into data-tip and remove title so the native tooltip doesn't also fire
    if(badge.getAttribute('title')){ badge.setAttribute('data-tip', badge.getAttribute('title')); badge.removeAttribute('title'); }
    var el=ensureTip(); el.textContent=decode(txt);
    var r=badge.getBoundingClientRect(); el.style.left='0px'; el.style.top='0px'; el.classList.add('show');
    var tw=el.offsetWidth, th=el.offsetHeight;
    var left=r.left + r.width/2 - tw/2; left=Math.max(8, Math.min(left, window.innerWidth-tw-8));
    var top=r.top - th - 8; if(top<8){ top=r.bottom+8; }
    el.style.left=left+'px'; el.style.top=top+'px';
    clearTimeout(hideT); hideT=setTimeout(hideTip, 3000);
  }
  function hideTip(){ if(tipEl){ tipEl.classList.remove('show'); } }
  document.addEventListener('click', function(e){
    var badge=e.target.closest && e.target.closest('.imc-up-badge');
    if(badge){ e.stopPropagation(); showTip(badge); } else { hideTip(); }
  });
  if (window.matchMedia && window.matchMedia('(hover:hover) and (pointer:fine)').matches) {
    document.querySelectorAll('.imc-up-badge').forEach(function(b){
      b.addEventListener('mouseenter', function(){ showTip(b); });
      b.addEventListener('mouseleave', function(){ hideTip(); });
    });
  }
})();
/* Position the Follow/Edit button vertically centred on the wallet+counts block (mobile only). */
(function(){
  function place(){
    var head=document.querySelector('.imc-up-head');
    var meta=document.querySelector('.imc-up-idmeta');
    if(!head||!meta) return;
    if(!window.matchMedia||!window.matchMedia('(max-width:768px)').matches){ head.style.removeProperty('--imc-followtop'); return; }
    // Button BOTTOM aligns with the bottom of the followers/counts row (fallback: idmeta bottom).
    var ref=head.querySelector('.imc-up-counts')||meta;
    var hr=head.getBoundingClientRect(), rr=ref.getBoundingClientRect();
    var bottom=(rr.bottom - hr.top);
    head.style.setProperty('--imc-followtop', bottom+'px');
  }
  if(document.readyState!=='loading'){ place(); } else { document.addEventListener('DOMContentLoaded', place); }
  window.addEventListener('resize', place);
  window.addEventListener('load', place);
})();
/* Bio: clamp to 4 lines, reveal 'Read more' only when it overflows. */
(function(){
  var bio=document.getElementById('imcUpBio'), more=document.getElementById('imcUpBioMore');
  if(!bio||!more) return;
  function overflowing(){ return bio.scrollHeight > bio.clientHeight + 2; }
  function check(){ if(bio.classList.contains('clamped') && overflowing()){ more.style.display='block'; } else if(bio.classList.contains('clamped')){ more.style.display='none'; } }
  more.addEventListener('click', function(){
    if(bio.classList.contains('clamped')){ bio.classList.remove('clamped'); more.textContent='Read less'; }
    else { bio.classList.add('clamped'); more.textContent='Read more'; check(); }
  });
  if(document.readyState!=='loading'){ check(); } else { document.addEventListener('DOMContentLoaded', check); }
  window.addEventListener('load', check);
})();
</script>

<?php if (function_exists('imc_xrplto_attribution')) imc_xrplto_attribution(); ?>
<?php get_footer(); ?>