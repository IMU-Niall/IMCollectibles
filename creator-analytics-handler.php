<?php
/**
 * ============================================================================
 * FILE: creator-analytics-handler.php
 * PATH: /wp-content/themes/astra/xrpl-nft-marketplace/backend/
 * ============================================================================
 *
 * D-B2 (13 Sep 2026) - read-only analytics for the Creator Dashboard.
 *
 * WHY A NEW FILE.
 * The obvious home was a new action on mint-on-demand-handler.php. That file
 * is the stabilised Phase 4e minting handler, the choke
 * point every mint on the platform passes through, and the outcome of three
 * rounds of throughput and correctness work. Redeploying it to add a reporting
 * feature is asymmetric risk for no gain. This file is SELECT-only, touches no
 * mint path, and reverting it means deleting it.
 *
 * TWO RULES BAKED IN:
 *
 *  1. STATUS SET. get_artist_purchases selects
 *     ('minted','claimed','paid','minting','failed'). Every measured figure in
 *     the audit came from ('minted','claimed','paid') only. Charting the wider
 *     set OVERSTATES every number, so the narrow set is used here.
 *
 *  2. NEVER SUM ACROSS CURRENCIES. price_xrp holds the amount in the row's OWN
 *     currency - a single creator's ledger routinely carries XRP, XFT and
 *     XMEME rows side by side. Adding those is adding unlike units. There
 *     is no honest common denominator without a price feed, and a converted
 *     total would be a snapshot presented as fact.
 *
 * ENDPOINT:
 *   GET ?action=summary&account=rXXX&window=24h|7d|30d|90d|1y|all
 *
 * @version 1.0.0
 */

require_once dirname(__DIR__, 5) . '/wp-load.php';
if (!defined('ABSPATH')) exit;
header('Content-Type: application/json; charset=utf-8');

$ca_allowed_origins = [
    'https://imcollectibles.xyz',
    'https://www.imcollectibles.xyz',
    'https://imcollectibles.io',
    'https://www.imcollectibles.io',
    'https://improtectors.com',
    'https://www.improtectors.com'
];
$ca_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($ca_origin, $ca_allowed_origins, true) ? $ca_origin : $ca_allowed_origins[0]));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

function ca_json($payload, $code = 200) {
    http_response_code($code);
    echo wp_json_encode($payload);
    exit;
}
function ca_error($msg, $code = 400) { ca_json(['success' => false, 'error' => $msg], $code); }

global $wpdb;
$purchases_table = $wpdb->prefix . 'imc_purchases';
$listings_table  = $wpdb->prefix . 'imc_listings';
// R-1b: the TRUE token amount lives here, not on the purchase row.
$groups_table    = $wpdb->prefix . 'imc_purchase_groups';

/**
 * R-1b - mirrors imc_amount_fmt() in imc-stats-handler.php so the two surfaces
 * read alike. XRP keeps 2dp; tokens compact, because token amounts are orders of
 * magnitude larger - token balances run orders of magnitude above XRP ones.
 */
function ca_amount_fmt(float $v, string $cur): string {
    $cur = trim($cur); if ($cur === '') { $cur = 'XRP'; }
    if ($cur === 'XRP')     { return number_format($v, 2) . ' XRP'; }
    if ($v >= 1000000)      { return number_format($v / 1000000, 1) . 'M ' . $cur; }
    if ($v >= 1000)         { return number_format($v / 1000, 1) . 'K ' . $cur; }
    $s = number_format($v, 6, '.', ',');
    if (strpos($s, '.') !== false) { $s = rtrim(rtrim($s, '0'), '.'); }
    if ($s === '' || $s === '-')   { $s = '0'; }
    return $s . ' ' . $cur;
}

$action = sanitize_text_field($_GET['action'] ?? '');
if ($action !== 'summary') ca_error('Unknown action');

$account = sanitize_text_field($_GET['account'] ?? '');
if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) ca_error('Invalid account');
// ============================================================================
// AUTHORISATION. This endpoint is session-gated: the caller must prove control
// of the creator wallet before any sales history, per-currency revenue or buyer
// list is returned. SELECT-only, but the data is commercial and the buyer list
// is third-party.
//
// Nonce + token-first session, matching the house pattern at
// collections-api-handler.php:277. imc_session_require_wallet() is available
// here the same way it is in the other ten backend files that use it -- via
// wp-load -> functions.php:395 -- so it is NOT required locally.
//
// wallet === account is EXACT, not merely conservative: the Creator Dashboard is
// the only caller and it renders solely the logged-in creator's own account
// (page-creator-dashboard.php:5 reads it from imc_session_wallet()). There is no
// admin-views-another-creator path to break.
// ============================================================================
$ca_nonce = sanitize_text_field($_GET['nonce'] ?? '');
if (!wp_verify_nonce($ca_nonce, 'xrpl_marketplace_nonce')) {
    ca_error('Session expired -- please refresh the page and try again', 403);
}
$ca_auth = function_exists('imc_session_require_wallet')
    ? imc_session_require_wallet($account)
    : array('ok' => false, 'wallet' => '', 'error' => 'auth');
if (empty($ca_auth['ok'])) {
    ca_error('Not authorised for this creator account', 403);
}

// Window -> (SQL interval, bucket format, bucket label). 'all' has no interval.
$window = sanitize_text_field($_GET['window'] ?? '30d');
// R-1 FIX (14 Sep 2026) - the %% escaping is LOAD-BEARING.
// $ca_fmt is interpolated into a $wpdb->prepare() string, and wpdb treats %d as
// an INTEGER PLACEHOLDER. A MySQL format of '%Y-%m-%d' therefore made wpdb count
// two placeholders (%d and the %s for account) against one supplied argument, so
// prepare() failed and the series came back EMPTY.
// Measured: 24h / 7d / 30d / 90d were all silently broken (they contain %d);
// 1y and 'all' worked, because '%Y-%m' contains none. That is exactly why a
// 30-day window reported "0 days with activity" beside a large mint count.
// %%Y-%%m-%%d survives prepare() and reaches MySQL as %Y-%m-%d.
$windows = [
    '24h' => ['INTERVAL 24 HOUR',  '%%Y-%%m-%%d %%H:00', 'hour'],
    '7d'  => ['INTERVAL 7 DAY',    '%%Y-%%m-%%d',        'day'],
    '30d' => ['INTERVAL 30 DAY',   '%%Y-%%m-%%d',        'day'],
    '90d' => ['INTERVAL 90 DAY',   '%%Y-%%m-%%d',        'day'],
    '1y'  => ['INTERVAL 365 DAY',  '%%Y-%%m',            'month'],
    'all' => [null,                '%%Y-%%m',            'month'],
];
if (!isset($windows[$window])) $window = '30d';
list($ca_interval, $ca_fmt, $ca_bucket) = $windows[$window];

// RULE 1 - the narrow status set. Anything wider overstates.
$status_sql = "p.mint_status IN ('minted','claimed','paid')";
// R-7: optional collection scope. Narrowed on the SAME expression the dashboard
// groups by at three sites - COALESCE(NULLIF(l.collection_name,''),'Uncategorized')
// - not on collection_taxon. Keying on taxon here would repeat the CP-T2 mistake
// of selecting by something the client does not group by.
//
// ⚠ THIS COULD NOT BE DONE IN THE BROWSER. Only two of the seven queries below
// joined the listings table, so a client-side narrow would have moved the KPI
// numbers and left the series, tiers and collectors issuer-wide - a filter that
// appears to work and does not. R-6 shipped exactly that shape of bug against a
// paginated feed; this is the same error, caught before it shipped.
$collection = sanitize_text_field($_GET['collection'] ?? '');
$ca_coll    = ($collection !== '' && strtolower($collection) !== 'all');
$coll_join  = $ca_coll ? "LEFT JOIN $listings_table l ON l.id = p.listing_id" : '';
$coll_where = $ca_coll ? " AND COALESCE(NULLIF(l.collection_name,''),'Uncategorized') = %s" : '';

$where  = "p.artist_account = %s AND $status_sql";
$args   = [$account];
if ($ca_interval !== null) {
    $where .= " AND p.created_at >= DATE_SUB(NOW(), $ca_interval)";
}
// ⚠ ARG ORDER IS POSITIONAL. The account placeholder is already in $args, and the
// collection placeholder is appended to the END of $where - so its value must be
// appended to the END of $args. Binding it earlier would match the collection name
// against the account column and return nothing, with no error.
if ($ca_coll) {
    $where .= $coll_where;
    $args[] = $collection;
}

// ---- headline totals -------------------------------------------------------
$totals = $wpdb->get_row($wpdb->prepare(
    "SELECT COUNT(*) AS mints,
            COUNT(DISTINCT p.buyer_account) AS holders,
            COUNT(DISTINCT p.listing_id)    AS listings,
            MIN(p.created_at) AS first_mint,
            MAX(p.created_at) AS last_mint
       FROM $purchases_table p
       $coll_join
      WHERE $where", $args), ARRAY_A);

// ---- mints per bucket ------------------------------------------------------
$series = $wpdb->get_results($wpdb->prepare(
    "SELECT DATE_FORMAT(p.created_at, '$ca_fmt') AS bucket, COUNT(*) AS mints
       FROM $purchases_table p
       $coll_join
      WHERE $where
      GROUP BY bucket ORDER BY bucket ASC", $args), ARRAY_A);

// ---- A2 - REVENUE OVER TIME, PER CURRENCY ---------------------------------
// The series above carries mints only - there is no amount in it. byCurrency has
// the amounts but no date dimension, so revenue-over-time cannot be assembled
// from what was already returned; hence a query rather than a client regroup.
//
// ⚠ The amount expression is COPIED VERBATIM from byCurrency below, not
// re-derived. RULE 2 (R-1b): XRP from the purchase, tokens from the group.
// A material share of XRP groups carry payment_amount = 0, so routing XRP through the group
// row zeroes them - the exact bug R-1 shipped and R-1b corrected.
//
// ⚠ $ca_fmt is interpolated into a prepare() string and is ALREADY the
// %%-escaped form. A bare %Y-%m-%d makes wpdb read %d as an integer placeholder
// and prepare() returns empty - which is how 24h/7d/30d/90d once reported
// "0 days with activity" beside a large mint count.
//
// Grouped by bucket AND currency: one line per currency, never a sum across
// them. amount_gaps rides along so a bucket the join could not answer reads as
// unknown rather than as zero revenue.
$seriesRevenue = $wpdb->get_results($wpdb->prepare(
    "SELECT DATE_FORMAT(p.created_at, '$ca_fmt') AS bucket,
            COALESCE(NULLIF(p.price_currency,''),'XRP') AS currency,
            COUNT(*) AS mints,
            ROUND(SUM(CASE WHEN COALESCE(NULLIF(p.price_currency,''),'XRP') = 'XRP'
                           THEN COALESCE(p.price_xrp,0)
                           ELSE COALESCE(g.payment_amount,0) / GREATEST(COALESCE(g.quantity,1),1)
                      END), 6) AS amount,
            SUM(CASE WHEN COALESCE(NULLIF(p.price_currency,''),'XRP') <> 'XRP'
                      AND (g.id IS NULL OR COALESCE(g.payment_amount,0) = 0)
                     THEN 1 ELSE 0 END) AS amount_gaps
       FROM $purchases_table p
       LEFT JOIN $groups_table g ON g.id = p.group_id
       $coll_join
      WHERE $where
      GROUP BY bucket, currency
      ORDER BY bucket ASC", $args), ARRAY_A);

// ---- RULE 2 (R-1b) - XRP from the purchase, TOKENS from the group --------
// R-1 concluded the token amount was unrecorded. That was WRONG - it is one join
// away, and the stats page solved this at v709. The rule, verified there and
// re-verified on the live tables 14 Sep:
//
//   XRP      -> p.price_xrp       (a material share of XRP groups have payment_amount = 0, so
//                                  routing XRP via the group would ZERO them)
//   non-XRP  -> g.payment_amount / g.quantity   (group TOTAL, hence the divide)
//
// Measured on a sample of token groups: zero quantity mismatches, zero zero-amounts, and
// every token purchase carries a group_id (no misses across the currencies
// platform-wide). For a measured creator the true token totals ran orders of
// magnitude above what the XRP list price was reporting.
//
// amount_gaps counts rows the join could NOT answer, so amount_known stays
// honest rather than assuming the join always lands.
// The column is price_xrp and it means exactly that: the price in XRP. For a
// token-priced mint it does NOT hold the token amount - measured on the export,
// every XFT row carries one of two fixed values, which are not XFT prices.
//
// wp_imc_purchases NEVER RECORDS WHAT WAS PAID IN TOKEN TERMS. The token price
// lives on the LISTING (accepted_currencies), so summing price_xrp and labelling
// it XFT reports XRP-denominated numbers under a token name.
//
// So: report the amount for XRP, and for every other currency report the MINT
// COUNT ONLY, with amount = null. Inventing a number here would be worse than
// admitting we do not have one. Recording paid_amount + paid_currency at mint
// time is the real fix and cannot be backfilled.
$byCurrency = $wpdb->get_results($wpdb->prepare(
    "SELECT COALESCE(NULLIF(p.price_currency,''),'XRP') AS currency,
            COUNT(*) AS mints,
            ROUND(SUM(CASE WHEN COALESCE(NULLIF(p.price_currency,''),'XRP') = 'XRP'
                           THEN COALESCE(p.price_xrp,0)
                           ELSE COALESCE(g.payment_amount,0) / GREATEST(COALESCE(g.quantity,1),1) END), 6) AS amount,
            SUM(CASE WHEN COALESCE(NULLIF(p.price_currency,''),'XRP') <> 'XRP'
                          AND (g.id IS NULL OR COALESCE(g.payment_amount,0) = 0)
                     THEN 1 ELSE 0 END) AS amount_gaps
       FROM $purchases_table p
       LEFT JOIN $groups_table g ON g.id = p.group_id
       $coll_join
      WHERE $where
      GROUP BY currency ORDER BY mints DESC", $args), ARRAY_A);

// ---- collectors ------------------------------------------------------------
// "holders" not "unique buyers": buyer_account is a WALLET. Wallets holding is
// literally true and claims no person-count we cannot prove (ruled 13 Sep).
$perWallet = $wpdb->get_results($wpdb->prepare(
    "SELECT p.buyer_account AS wallet, COUNT(*) AS mints
       FROM $purchases_table p
       $coll_join
      WHERE $where
      GROUP BY p.buyer_account ORDER BY mints DESC", $args), ARRAY_A);
$repeat = 0;
foreach ($perWallet as $w) { if ((int)$w['mints'] > 1) $repeat++; }

// ---- tier mix --------------------------------------------------------------
$tiers = $wpdb->get_results($wpdb->prepare(
    "SELECT COALESCE(NULLIF(p.tier_name,''),'Untiered') AS tier, COUNT(*) AS mints
       FROM $purchases_table p
       $coll_join
      WHERE $where
      GROUP BY tier ORDER BY mints DESC", $args), ARRAY_A);

// ---- per listing (+ last_activity, which B-e will consume) -----------------
$listings = $wpdb->get_results($wpdb->prepare(
    "SELECT p.listing_id AS id,
            COALESCE(l.nft_name,'Untitled') AS name,
            COUNT(*) AS mints,
            COUNT(DISTINCT p.buyer_account) AS holders,
            MAX(p.created_at) AS last_activity
       FROM $purchases_table p
       LEFT JOIN $listings_table l ON l.id = p.listing_id
      WHERE $where
      GROUP BY p.listing_id, l.nft_name
      ORDER BY mints DESC LIMIT 50", $args), ARRAY_A);

// ---- B-e · PER-COLLECTION -------------------------------------------------
// ⚠ GROUPING KEY. The dashboard groups collections with
//     const key = l.collection_name || 'Uncategorized';
// at three sites (renderCollections, updateStats and the detail filter). It does
// NOT group by collection_taxon, even though the card stores the taxon too.
//
// Grouping here by taxon - the instinctive choice, since taxon is the real
// on-ledger collection identity - would silently diverge in two real cases: a
// listing with no collection_name lands in 'Uncategorized' on the client but
// under its own taxon here; and two listings sharing a name but not a taxon
// merge on the client and split here. Cards would then show the wrong numbers or
// none at all. So we group on the SAME expression the client uses, and return the
// taxon only for reference.
//
// No LIMIT. The per-listing query above caps at 50, which is fine for a preview;
// a creator with 60 collections must not silently lose the tail.
$collKey = "COALESCE(NULLIF(l.collection_name,''),'Uncategorized')";

$collections = $wpdb->get_results($wpdb->prepare(
    "SELECT $collKey AS collection,
            MAX(l.collection_taxon) AS taxon,
            COUNT(*) AS mints,
            COUNT(DISTINCT p.buyer_account) AS holders,
            COUNT(DISTINCT p.listing_id)    AS listings,
            MAX(p.created_at) AS last_activity
       FROM $purchases_table p
       LEFT JOIN $listings_table l ON l.id = p.listing_id
      WHERE $where
      GROUP BY $collKey
      ORDER BY mints DESC", $args), ARRAY_A);

// Per collection AND currency. Never summed across currencies - price_xrp holds
// the amount in the row's OWN currency (XRP, XFT and XMEME rows sit side by
// side in the same table). Adding those is adding unlike units.
$collCurrency = $wpdb->get_results($wpdb->prepare(
    // R-1: same rule as byCurrency above - amount is XRP-only.
    "SELECT $collKey AS collection,
            COALESCE(NULLIF(p.price_currency,''),'XRP') AS currency,
            COUNT(*) AS mints,
            ROUND(SUM(CASE WHEN COALESCE(NULLIF(p.price_currency,''),'XRP') = 'XRP'
                           THEN COALESCE(p.price_xrp,0)
                           ELSE COALESCE(g.payment_amount,0) / GREATEST(COALESCE(g.quantity,1),1) END), 6) AS amount,
            SUM(CASE WHEN COALESCE(NULLIF(p.price_currency,''),'XRP') <> 'XRP'
                          AND (g.id IS NULL OR COALESCE(g.payment_amount,0) = 0)
                     THEN 1 ELSE 0 END) AS amount_gaps
       FROM $purchases_table p
       LEFT JOIN $listings_table l ON l.id = p.listing_id
       LEFT JOIN $groups_table   g ON g.id = p.group_id
      WHERE $where
      GROUP BY $collKey, currency
      ORDER BY mints DESC", $args), ARRAY_A);

// fold the currency rows into their collection
$collMap = [];
foreach ($collections as $c) {
    $collMap[$c['collection']] = [
        'collection'    => $c['collection'],
        'taxon'         => $c['taxon'] !== null ? (int)$c['taxon'] : null,
        'mints'         => (int)$c['mints'],
        'holders'       => (int)$c['holders'],
        'listings'      => (int)$c['listings'],
        'last_activity' => $c['last_activity'],
        'byCurrency'    => [],
    ];
}
foreach ($collCurrency as $r) {
    if (!isset($collMap[$r['collection']])) continue;   // cannot happen; cheap guard
    $collMap[$r['collection']]['byCurrency'][] = [
        'currency' => $r['currency'],
        'mints'    => (int)$r['mints'],
        'amount'       => (int)$r['amount_gaps'] === 0 ? (float)$r['amount'] : null,
        'amount_known' => (int)$r['amount_gaps'] === 0,
        'amount_fmt'   => (int)$r['amount_gaps'] === 0
                            ? ca_amount_fmt((float)$r['amount'], $r['currency']) : null,
    ];
}

ca_json([
    'success' => true,
    'account' => $account,
    'window'  => $window,
    // Echo the scope back so the client never has to assume what it received.
    'collection' => $ca_coll ? $collection : null,
    'bucket'  => $ca_bucket,
    'totals'  => [
        'mints'      => (int)($totals['mints'] ?? 0),
        'holders'    => (int)($totals['holders'] ?? 0),
        'listings'   => (int)($totals['listings'] ?? 0),
        'first_mint' => $totals['first_mint'] ?? null,
        'last_mint'  => $totals['last_mint'] ?? null,
    ],
    'series'     => array_map(function ($r) {
        return ['bucket' => $r['bucket'], 'mints' => (int)$r['mints']];
    }, $series ?: []),
    // A2: the same buckets as `series`, split by currency.
    'seriesRevenue' => array_map(function ($r) {
        $ok = ((int)$r['amount_gaps'] === 0);
        return [
            'bucket'       => $r['bucket'],
            'currency'     => $r['currency'],
            'mints'        => (int)$r['mints'],
            'amount'       => $ok ? (float)$r['amount'] : null,
            'amount_known' => $ok,
        ];
    }, $seriesRevenue ?: []),
    'byCurrency' => array_map(function ($r) {
        $ok = ((int)$r['amount_gaps'] === 0);
        return ['currency' => $r['currency'], 'mints' => (int)$r['mints'],
                'amount'       => $ok ? (float)$r['amount'] : null,
                'amount_known' => $ok,
                'amount_fmt'   => $ok ? ca_amount_fmt((float)$r['amount'], $r['currency']) : null];
    }, $byCurrency ?: []),
    'collectors' => [
        'holders' => count($perWallet ?: []),
        'repeat'  => $repeat,
        'top'     => array_map(function ($r) {
            return ['wallet' => $r['wallet'], 'mints' => (int)$r['mints']];
        }, array_slice($perWallet ?: [], 0, 10)),
    ],
    'tiers'    => array_map(function ($r) {
        return ['tier' => $r['tier'], 'mints' => (int)$r['mints']];
    }, $tiers ?: []),
    'listings' => array_map(function ($r) {
        return ['id' => (int)$r['id'], 'name' => $r['name'], 'mints' => (int)$r['mints'],
                'holders' => (int)$r['holders'], 'last_activity' => $r['last_activity']];
    }, $listings ?: []),
    // B-e: ADDITIVE. Every key above keeps its exact shape, so D-D's charts are
    // untouched by construction.
    'collections' => array_values($collMap),
]);