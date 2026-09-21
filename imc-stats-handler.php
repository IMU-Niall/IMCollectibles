<?php
/**
 * IMCollectibles Platform Stats Handler
 * File: imc-stats-handler.php
 * Path: /wp-content/themes/astra/xrpl-nft-marketplace/backend/imc-stats-handler.php
 *
 * Platform-specific stats from our own DB — zero external API calls.
 * Replaces XRPL-wide Bithomp stats with complete, accurate data.
 *
 * Actions (GET):
 *   overview        — Summary cards
 *   top_collections — Top N drops by mints sold
 *   top_artists     — Top N artists by XRP volume
 *   recent_sales    — Last N completed mints
 *
 * Common params: period (all|30d|7d|24h), limit (5–20), nonce
 * @version v255
 */

$wp_paths = [
    dirname(__DIR__, 5) . '/wp-load.php',
    dirname(__DIR__, 4) . '/wp-load.php',
    $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php',
];
foreach ($wp_paths as $p) { if (file_exists($p)) { require_once $p; break; } }
if (!defined('ABSPATH')) { http_response_code(500); die(json_encode(['success'=>false,'error'=>'WP not loaded'])); }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

if (!wp_verify_nonce(sanitize_text_field($_GET['nonce'] ?? ''), 'xrpl_marketplace_nonce')) {
    wp_send_json(['success' => false, 'error' => 'Invalid nonce'], 403);
}

global $wpdb;
$lt = $wpdb->prefix . 'imc_listings';
$pt = $wpdb->prefix . 'imc_purchases';
$tt = $wpdb->prefix . 'imc_listing_tiers'; // v290: per-tier cover images
$gt = $wpdb->prefix . 'imc_purchase_groups'; // v709 (Step F2): true paid amount + currency

$action = sanitize_text_field($_GET['action'] ?? '');
$period = sanitize_text_field($_GET['period'] ?? 'all');
$limit  = min(20, max(5, absint($_GET['limit'] ?? 10)));
if (!in_array($period, ['all','30d','7d','24h'], true)) $period = 'all';

/* Period WHERE clause — always appended after an existing WHERE/AND */
function imc_period(string $p): string {
    switch ($p) {
        case '24h': return "AND p.minted_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        case '7d':  return "AND p.minted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        case '30d': return "AND p.minted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        default:    return '';
    }
}

function imc_img(string $c): string {
    if (!$c) return '/wp-content/uploads/fallback-nft.svg';
    // v289 FIX: Use VPS img.php?url= instead of Pinata public gateway.
    // Pinata rate-limits and is slow on mobile. img.php?url=ipfs://CID fetches from
    // our known IPFS gateways in priority order, caches on VPS disk, serves sub-10ms.
    // Same fix applied to my-nfts-handler, page-nft-single, and offer-handler.
    $ipfs_url = (strpos($c, 'ipfs://') === 0) ? $c : 'ipfs://' . ltrim($c, 'ipfs:/');
    return 'https://metadata.imcollectibles.io/img.php?url=' . urlencode($ipfs_url);
}

/**
 * v709 (Step F2): format an amount in ITS OWN currency -- never converted to XRP.
 *
 * imc_xrp() below hardcodes ' XRP' and 2dp, which is right for XRP but wrong for tokens:
 * real sales span many orders of magnitude, and one live ticker ('KEDAS BREW COIN')
 * contains spaces. XRP formatting is left EXACTLY as it was so the existing display does
 * not shift; only non-XRP amounts take the new abbreviated form.
 */
function imc_amount_fmt(float $v, string $cur): string {
    $cur = trim($cur);
    if ($cur === '') { $cur = 'XRP'; }
    if ($cur === 'XRP') {
        return number_format($v, 2).' XRP'; // unchanged legacy behaviour
    }
    if ($v >= 1000000) { return number_format($v / 1000000, 1).'M '.$cur; }
    if ($v >= 1000)    { return number_format($v / 1000, 1).'K '.$cur; }
    // Small token amounts: keep precision, drop trailing zeros (0.400000 -> 0.4).
    $s = number_format($v, 6, '.', ',');
    if (strpos($s, '.') !== false) { $s = rtrim(rtrim($s, '0'), '.'); }
    if ($s === '' || $s === '-') { $s = '0'; }
    return $s.' '.$cur;
}

function imc_xrp(float $v): string {
    if ($v >= 1_000_000) return number_format($v/1_000_000, 1).'M XRP';
    if ($v >= 1_000)     return number_format($v/1_000, 1).'K XRP';
    return number_format($v, 2).' XRP';
}

function imc_ttl(string $p): int {
    switch ($p) {
        case '24h': return  5 * MINUTE_IN_SECONDS;
        case '7d':  return 15 * MINUTE_IN_SECONDS;
        case '30d': return 30 * MINUTE_IN_SECONDS;
        default:    return      HOUR_IN_SECONDS;
    }
}

function imc_ago(string $ts): string {
    $d = max(0, time() - strtotime($ts));
    if ($d < 60)      return 'Just now';
    if ($d < 3600)    return floor($d/60).'m ago';
    if ($d < 86400)   return floor($d/3600).'h ago';
    if ($d < 604800)  return floor($d/86400).'d ago';
    return floor($d/604800).'w ago';
}

switch ($action) {

    // ── Overview ─────────────────────────────────────────────────────────────
    case 'overview':
        $ck = 'imc_stats_ov_'.$period;
        if (($c = get_transient($ck)) !== false) { wp_send_json(['success'=>true,'cached'=>true,'data'=>$c]); break; }

        $pc = imc_period($period);
        $total  = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$pt} p WHERE p.mint_status IN ('minted','claimed') {$pc}");
        $vols   = $wpdb->get_results("SELECT UPPER(p.price_currency) AS cur, SUM(p.price_xrp) AS tot FROM {$pt} p WHERE p.mint_status IN ('minted','claimed') {$pc} GROUP BY p.price_currency");
        $xrp = $xft = 0.0;
        foreach ($vols as $v) { if ($v->cur==='XRP') $xrp=(float)$v->tot; if ($v->cur==='XFT') $xft=(float)$v->tot; }
        $lc     = $wpdb->get_row("SELECT COUNT(CASE WHEN status='active' THEN 1 END) AS active, COUNT(CASE WHEN status='sold_out' THEN 1 END) AS sold_out FROM {$lt}");
        $buyers = (int)$wpdb->get_var("SELECT COUNT(DISTINCT p.buyer_account)  FROM {$pt} p WHERE p.mint_status IN ('minted','claimed') {$pc}");
        $arts   = (int)$wpdb->get_var("SELECT COUNT(DISTINCT p.artist_account) FROM {$pt} p WHERE p.mint_status IN ('minted','claimed') {$pc}");

        $data = [
            'total_mints'     => $total,
            'xrp_volume'      => round($xrp,6),
            'xrp_volume_fmt'  => imc_xrp($xrp),
            'xft_volume'      => round($xft,6),
            'xft_volume_fmt'  => number_format($xft,0).' XFT',
            'active_listings' => (int)($lc->active   ?? 0),
            'sold_out'        => (int)($lc->sold_out ?? 0),
            'unique_buyers'   => $buyers,
            'unique_artists'  => $arts,
        ];
        set_transient($ck, $data, imc_ttl($period));
        wp_send_json(['success'=>true,'cached'=>false,'data'=>$data]);
        break;

    // ── Top Collections ───────────────────────────────────────────────────────
    case 'top_collections':
        $ck = 'imc_stats_col_v3_'.$period.'_'.$limit; // v683: bumped -- v1 payloads pre-date the is_hidden filter
        if (($c = get_transient($ck)) !== false) { wp_send_json(['success'=>true,'cached'=>true,'collections'=>$c]); break; }

        $pc   = imc_period($period);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT MAX(l.id) AS lid,
                    COALESCE(NULLIF(TRIM(MAX(l.collection_name)),''), MAX(l.nft_name)) AS dname,
                    MAX(l.nft_type) AS nft_type, MAX(l.cover_ipfs) AS cover_ipfs, l.artist_account, MAX(l.artist_name) AS artist_name, l.collection_taxon,
                    COUNT(p.id) AS mints,
                    SUM(CASE WHEN UPPER(p.price_currency)='XRP' THEN p.price_xrp ELSE 0 END) AS xrp,
                    COUNT(DISTINCT p.buyer_account) AS buyers
             FROM {$lt} l INNER JOIN {$pt} p ON p.listing_id=l.id
             WHERE p.mint_status IN ('minted','claimed') {$pc}
               AND l.is_hidden = 0
               AND l.hide_from_leaderboard = 0
             GROUP BY l.artist_account, l.collection_taxon
             ORDER BY mints DESC, xrp DESC LIMIT %d", $limit
        ));

        $out = []; $rank = 1;
        foreach ($rows as $r) {
            $acct = substr($r->artist_account,0,6).'…'.substr($r->artist_account,-4);
            $icon = match($r->nft_type??'') { 'music'=>'🎵','musicvideo'=>'🎬','art'=>'🎨','film'=>'🎞️','album'=>'💿','audiobook'=>'🎧','ebook'=>'📖',default=>'🎵' };
            $out[] = [
                'rank'           => $rank++,
                'listing_id'     => (int)$r->lid,
                'name'           => $r->dname ?: 'Untitled',
                'nft_type'       => $r->nft_type ?: 'music',
                'type_icon'      => $icon,
                'image'          => imc_img($r->cover_ipfs ?? ''),
                'artist_account' => $r->artist_account,
                'artist_display' => (function_exists('imc_resolve_artist_name') ? imc_resolve_artist_name($r->artist_account, $r->artist_name ?? '', '') : ($r->artist_name ?: $acct)),
                'taxon'          => (int)$r->collection_taxon,
                'total_mints'    => (int)$r->mints,
                'xrp_vol'        => round((float)$r->xrp, 6),
                'xrp_vol_fmt'    => imc_xrp((float)$r->xrp),
                'unique_buyers'  => (int)$r->buyers,
            ];
        }
        set_transient($ck, $out, imc_ttl($period));
        wp_send_json(['success'=>true,'cached'=>false,'period'=>$period,'collections'=>$out]);
        break;

    // ── Top Artists ───────────────────────────────────────────────────────────
    case 'top_artists':
        $ck = 'imc_stats_art_v3_'.$period.'_'.$limit; // v683: bumped -- v1 payloads pre-date the is_hidden filter
        if (($c = get_transient($ck)) !== false) { wp_send_json(['success'=>true,'cached'=>true,'artists'=>$c]); break; }

        $pc   = imc_period($period);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.artist_account,
                    MAX(l.artist_name) AS aname, MAX(l.cover_ipfs) AS cover,
                    COUNT(p.id) AS mints,
                    SUM(CASE WHEN UPPER(p.price_currency)='XRP' THEN p.price_xrp ELSE 0 END) AS xrp,
                    COUNT(DISTINCT p.buyer_account) AS buyers, COUNT(DISTINCT p.listing_id) AS listings
             FROM {$pt} p INNER JOIN {$lt} l ON l.id=p.listing_id
             WHERE p.mint_status IN ('minted','claimed') {$pc}
               AND l.is_hidden = 0
               AND l.hide_from_leaderboard = 0
             GROUP BY p.artist_account
             ORDER BY xrp DESC, mints DESC LIMIT %d", $limit
        ));

        $out = []; $rank = 1;
        foreach ($rows as $r) {
            $acct = substr($r->artist_account,0,6).'…'.substr($r->artist_account,-4);
            $out[] = [
                'rank'           => $rank++,
                'artist_account' => $r->artist_account,
                'artist_display' => (function_exists('imc_resolve_artist_name') ? imc_resolve_artist_name($r->artist_account, $r->aname ?? '', '') : ($r->aname ?: $acct)),
                'sample_cover'   => imc_img($r->cover ?? ''),
                'profile_slug'   => (function_exists('imc_artist_profile_get') ? (imc_artist_profile_get($r->artist_account)['slug'] ?? '') : ''),
                'total_mints'    => (int)$r->mints,
                'xrp_vol'        => round((float)$r->xrp, 6),
                'xrp_vol_fmt'    => imc_xrp((float)$r->xrp),
                'unique_buyers'  => (int)$r->buyers,
                'listings'       => (int)$r->listings,
            ];
        }
        set_transient($ck, $out, imc_ttl($period));
        wp_send_json(['success'=>true,'cached'=>false,'period'=>$period,'artists'=>$out]);
        break;

    // ── Recent Sales ──────────────────────────────────────────────────────────
    case 'recent_sales':
        $rl = min(20, max(5, absint($_GET['limit'] ?? 15)));
        $ck = 'imc_stats_recent_v4_'.$rl; // v709: bumped -- v3 payloads carry XRP amounts on token sales
        if (($c = get_transient($ck)) !== false) { wp_send_json(['success'=>true,'cached'=>true,'sales'=>$c]); break; }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.buyer_account, p.price_xrp, p.price_currency, p.tier_name, p.minted_at,
                    g.payment_currency AS g_currency,
                    g.payment_amount   AS g_amount,
                    g.quantity         AS g_qty,
                    l.nft_name, l.nft_type, l.artist_account, l.artist_name,
                    l.cover_ipfs AS listing_cover,
                    COALESCE(NULLIF(t.cover_ipfs,''), l.cover_ipfs) AS cover_ipfs,
                    COALESCE(NULLIF(TRIM(l.collection_name),''), l.nft_name) AS cname
             FROM {$pt} p
             INNER JOIN {$lt} l ON l.id = p.listing_id
             LEFT JOIN {$tt} t ON t.id = p.tier_id
             LEFT JOIN {$gt} g ON g.id = p.group_id
             WHERE p.mint_status IN ('minted','claimed') AND l.is_hidden = 0
               AND l.hide_from_leaderboard = 0
             ORDER BY p.minted_at DESC LIMIT %d", $rl
        ));

        $out = [];
        foreach ($rows as $r) {
            $buyer  = $r->buyer_account  ? substr($r->buyer_account,0,6).'…'.substr($r->buyer_account,-4)  : 'Unknown';
            $artist = $r->artist_name    ?: (substr($r->artist_account,0,6).'…'.substr($r->artist_account,-4));
            // v709 (Step F2): resolve the currency. Prefer the purchases row (populated by
            // Step C going forward, and by the backfill for history); fall back to the group
            // row so historic token sales already read correctly BEFORE the backfill runs.
            $cur = strtoupper(trim((string)($r->price_currency ?? '')));
            if ($cur === '' || $cur === 'XRP') {
                $gc = strtoupper(trim((string)($r->g_currency ?? '')));
                if ($gc !== '' && $gc !== 'XRP') { $cur = $gc; }
            }
            if ($cur === '') { $cur = 'XRP'; }

            // v709: the AMOUNT. p.price_xrp holds the XRP-equivalent LIST price, so on a token
            // sale it is the wrong number -- the truth is the group's payment_amount, which is
            // the GROUP total, hence / quantity for the per-NFT figure. Verified against live
            // data: a minority of token groups, zero with rows != quantity, zero with payment_amount = 0.
            // XRP deliberately KEEPS price_xrp: a share of XRP groups have payment_amount = 0, so
            // routing XRP through the group row would zero out those sales.
            $amt = (float)$r->price_xrp;
            if ($cur !== 'XRP') {
                $g_amt = (float)($r->g_amount ?? 0);
                $g_qty = max(1, (int)($r->g_qty ?? 1));
                if ($g_amt > 0) { $amt = $g_amt / $g_qty; }
            }
            $icon   = match($r->nft_type??'') { 'music'=>'🎵','musicvideo'=>'🎬','art'=>'🎨','film'=>'🎞️','album'=>'💿','audiobook'=>'🎧','ebook'=>'📖',default=>'🎵' };
            $out[]  = [
                'nft_name'        => $r->nft_name ?: 'Unknown NFT',
                'collection_name' => $r->cname ?: ($r->nft_name ?: 'Unknown'),
                'tier_name'       => $r->tier_name ?: null,
                'cover_url'       => imc_img($r->cover_ipfs ?? ''),
                'buyer_short'     => $buyer,
                'artist_display'  => $artist,
                'price_xrp'       => (float)$r->price_xrp, // unchanged: XRP-equivalent list price
                'price_amount'    => $amt,                 // v709: ADDITIVE -- amount actually paid
                'price_currency'  => $cur,
                'price_fmt'       => imc_amount_fmt($amt, $cur),
                'nft_type'        => $r->nft_type ?: 'music',
                'type_icon'       => $icon,
                'time_ago'        => imc_ago($r->minted_at ?? ''),
            ];
        }
        set_transient($ck, $out, 2 * MINUTE_IN_SECONDS);
        wp_send_json(['success'=>true,'cached'=>false,'sales'=>$out]);
        break;

    default:
        wp_send_json(['success'=>false,'error'=>'Invalid action'], 400);
}