<?php
/**
 * IMC Progression Badges (P6) — Collector "The Vault" + Creator "The Forge".
 *
 * Two independent 7-tier ladders, computed live from existing data (no new schema):
 *   COLLECTOR — by distinct NFTs currently owned (VPS owned endpoint total).
 *               Top 2 tiers also require a minimum holding tenure.
 *   CREATOR   — by distinct works minted on IMC that have at least one sale
 *               (a listing counts only once it has sold >=1 edition).
 *               Top 2 tiers also require a minimum creator tenure.
 *
 * Tier 7 of each ladder is SECRET: not advertised anywhere, only revealed as a
 * chip once the wallet reaches it.
 *
 * Public API:
 *   imc_badge_collector($account)  -> tier array|null
 *   imc_badge_creator($account)    -> tier array|null
 * Each tier array: ['tier'=>int,'name'=>string,'secret'=>bool,'class'=>string]
 * Returns null when the wallet hasn't reached tier 1 (no badge to show).
 *
 * Safe/additive: this file defines functions only; nothing runs on include.
 */

if (!defined('ABSPATH')) { exit; }

if (!function_exists('imc_badge_collector')) {

/* ---- Ladder definitions -------------------------------------------------- */

/**
 * Collector ladder. threshold = min NFTs owned. tenure_months = min holding age
 * required (0 = none). secret = hidden until reached.
 */
function imc_badge_collector_ladder() {
    return [
        ['tier'=>1,'name'=>'Window Shopper',        'threshold'=>1,    'tenure_months'=>0, 'secret'=>false, 'flavour'=>'just browsing... allegedly'],
        ['tier'=>2,'name'=>'Enthusiast',            'threshold'=>10,   'tenure_months'=>0, 'secret'=>false, 'flavour'=>'caught the collecting bug'],
        ['tier'=>3,'name'=>'Hoarder',               'threshold'=>50,   'tenure_months'=>0, 'secret'=>false, 'flavour'=>'it is a habit now'],
        ['tier'=>4,'name'=>'Connoisseur',           'threshold'=>100,  'tenure_months'=>0, 'secret'=>false, 'flavour'=>'refined taste, real depth'],
        ['tier'=>5,'name'=>'Vaultkeeper',           'threshold'=>250,  'tenure_months'=>0, 'secret'=>false, 'flavour'=>'guardian of a serious trove'],
        ['tier'=>6,'name'=>'The Guardian',          'threshold'=>500,  'tenure_months'=>3, 'secret'=>false, 'flavour'=>'a protector of the collection'],
        ['tier'=>7,'name'=>'Diamond Hand Collector','threshold'=>1000, 'tenure_months'=>6, 'secret'=>true,  'flavour'=>'holds forever, never sells'],
    ];
}

/**
 * Creator ladder. threshold = min distinct SOLD works minted on IMC.
 */
function imc_badge_creator_ladder() {
    return [
        ['tier'=>1,'name'=>'Dip-A-Toer', 'threshold'=>1,   'tenure_months'=>0, 'secret'=>false, 'flavour'=>'dipped a toe into creation'],
        ['tier'=>2,'name'=>'Trailblazer','threshold'=>5,   'tenure_months'=>0, 'secret'=>false, 'flavour'=>'a rising creator finding their path'],
        ['tier'=>3,'name'=>'Forgesmith', 'threshold'=>10,  'tenure_months'=>0, 'secret'=>false, 'flavour'=>'a working artist with real output'],
        ['tier'=>4,'name'=>'Visionary',  'threshold'=>25,  'tenure_months'=>0, 'secret'=>false, 'flavour'=>'building something bigger'],
        ['tier'=>5,'name'=>'Headliner',  'threshold'=>50,  'tenure_months'=>0, 'secret'=>false, 'flavour'=>'a name that draws a crowd'],
        ['tier'=>6,'name'=>'Luminary',   'threshold'=>100, 'tenure_months'=>3, 'secret'=>false, 'flavour'=>'a light others follow'],
        ['tier'=>7,'name'=>'Architect',  'threshold'=>250, 'tenure_months'=>6, 'secret'=>true,  'flavour'=>'shaped a whole world'],
    ];
}

/* ---- Shared tier resolver ------------------------------------------------ */

/**
 * Given a ladder, a metric count, and the wallet's tenure in months, return the
 * highest tier the wallet qualifies for. A tenure-gated tier is only awarded if
 * BOTH count >= threshold AND tenure_months >= required; if the count qualifies
 * but tenure doesn't, the wallet stays at the highest tier it fully satisfies.
 * Returns the tier array (with 'class' added) or null.
 */
function imc_badge_resolve($ladder, $count, $tenure_months) {
    $count = (int) $count;
    $tenure_months = (float) $tenure_months;
    $earned = null;
    foreach ($ladder as $t) {
        if ($count >= (int) $t['threshold'] && $tenure_months >= (float) $t['tenure_months']) {
            $earned = $t;
        }
    }
    if ($earned === null) { return null; }
    $earned['class'] = imc_badge_tier_class($earned['tier']);
    return $earned;
}

/** Colour class per tier (1..7) for the chip styling. */
function imc_badge_tier_class($tier) {
    $map = [1=>'t1',2=>'t2',3=>'t3',4=>'t4',5=>'t5',6=>'t6',7=>'t7'];
    return $map[(int) $tier] ?? 't1';
}

/* ---- Metric queries ------------------------------------------------------ */

/**
 * Collector metric: distinct NFTs currently owned, from the VPS owned endpoint
 * (authoritative for XRPL-wide holdings, already used by the Collected tab).
 * Falls back to 0 on any error — badges never break the page.
 */
function imc_badge_collector_count($account) {
    $account = trim((string) $account);
    if ($account === '') { return 0; }
    $url = 'https://metadata.imcollectibles.io/indexer.php?action=owned&owner='
         . rawurlencode($account) . '&limit=1&offset=0';
    $resp = wp_remote_get($url, ['timeout' => 6]);
    if (is_wp_error($resp)) { return 0; }
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($body) || empty($body['success'])) { return 0; }
    return (int) ($body['total'] ?? 0);
}

/**
 * Collector tenure: months since the wallet's EARLIEST purchase on IMC.
 * Uses imc_purchases.created_at (indexed by buyer_account). 0 if none / no table.
 */
function imc_badge_collector_tenure_months($account) {
    global $wpdb;
    $pt = $wpdb->prefix . 'imc_purchases';
    $gt = $wpdb->prefix . 'imc_purchase_groups';   // 4k-2
    if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $pt)) !== $pt) { return 0; }
    // 4k-2 (5 Sep 2026): tenure must date from the wallet's own EARLIEST MINT, not from the
    // earliest NFT it happens to hold now. purchases.buyer_account is an ownership pointer --
    // the ownership listener rewrites it on every sale/transfer -- so today a wallet
    // RECEIVING an old NFT inherits an older "first purchase" and a wallet SELLING its first
    // loses tenure it earned. A minority of wallets carry a distorted date; some have a badge
    // inflated by a month or more, others have lost earned tenure. groups.buyer_account is immutable.
    // UNION ALL, not one OR: an OR spanning both tables makes the optimiser abandon idx_buyer and
    // full-scan. Two arms keep their indexes.
    // COLLATION: general_ci vs unicode_520_ci -- COALESCE(g.x,p.x) would raise ERROR 1271 and
    // silently return NULL. Every comparison here is column-vs-literal, so they never meet.
    // Arm 2 covers the orphan purchases (nullable group_id) -- counted, never dropped.
    $first = $wpdb->get_var($wpdb->prepare(
        "SELECT MIN(c) FROM (
             SELECT MIN(p.created_at) AS c
               FROM {$gt} g JOIN {$pt} p ON p.group_id = g.id
              WHERE g.buyer_account = %s
             UNION ALL
             SELECT MIN(p.created_at)
               FROM {$pt} p LEFT JOIN {$gt} g ON g.id = p.group_id
              WHERE p.buyer_account = %s AND g.id IS NULL
         ) x", $account, $account
    ));
    return imc_badge_months_since($first);
}

/**
 * Creator metric: distinct works minted on IMC that have >=1 sale.
 * A listing counts only once at least one edition has sold (minted/claimed) —
 * so unsold listings don't inflate the ladder, and a big open edition still
 * counts as ONE work (rewards sustained creation, not edition-count).
 */
/**
 * Count of distinct listings the creator has EVER created (regardless of sales).
 * Used to grant the entry tier (Dip-A-Toer) on first listing, so "Creator" status is
 * earned by listing — higher tiers still require actual sales (see imc_badge_creator_count).
 */
function imc_badge_creator_listing_count($account) {
    global $wpdb;
    $lt = $wpdb->prefix . 'imc_listings';
    if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $lt)) !== $lt) { return 0; }
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT id) FROM {$lt} WHERE artist_account = %s",
        $account
    ));
}

function imc_badge_creator_count($account) {
    global $wpdb;
    $lt = $wpdb->prefix . 'imc_listings';
    $pt = $wpdb->prefix . 'imc_purchases';
    if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $lt)) !== $lt) { return 0; }
    if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $pt)) !== $pt) { return 0; }
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT l.id)
           FROM {$lt} l
           INNER JOIN {$pt} p ON p.listing_id = l.id
          WHERE l.artist_account = %s
            AND p.mint_status IN ('minted','claimed')",
        $account
    ));
}

/**
 * Creator tenure: months since the wallet's EARLIEST listing on IMC.
 */
function imc_badge_creator_tenure_months($account) {
    global $wpdb;
    $lt = $wpdb->prefix . 'imc_listings';
    if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $lt)) !== $lt) { return 0; }
    // Guard the created_at column (tenure gate is graceful — 0 months if absent).
    if (!$wpdb->get_var("SHOW COLUMNS FROM {$lt} LIKE 'created_at'")) { return 0; }
    $first = $wpdb->get_var($wpdb->prepare(
        "SELECT MIN(created_at) FROM {$lt} WHERE artist_account = %s", $account
    ));
    return imc_badge_months_since($first);
}

/** Whole (float) months between a datetime string and now. 0 if unparseable. */
function imc_badge_months_since($datetime) {
    if (empty($datetime)) { return 0; }
    $then = strtotime($datetime);
    if ($then === false) { return 0; }
    $secs = time() - $then;
    if ($secs <= 0) { return 0; }
    return $secs / (60 * 60 * 24 * 30.4375); // avg month length
}

/* ---- Public entry points ------------------------------------------------- */

/** Collector badge for a wallet, or null if none earned. */
function imc_badge_collector($account) {
    $count  = imc_badge_collector_count($account);
    if ($count < 1) { return null; }
    $tenure = imc_badge_collector_tenure_months($account);
    return imc_badge_resolve(imc_badge_collector_ladder(), $count, $tenure);
}

/** Creator badge for a wallet, or null if none earned. */
function imc_badge_creator($account) {
    $sold = imc_badge_creator_count($account);            // distinct SOLD works (tiers 2-7)
    $listed = imc_badge_creator_listing_count($account);  // any listing grants the entry tier
    // Entry tier (Dip-A-Toer) unlocks on first listing; progression beyond it requires sales.
    $count = max($sold, ($listed >= 1 ? 1 : 0));
    if ($count < 1) { return null; }
    $tenure = imc_badge_creator_tenure_months($account);
    return imc_badge_resolve(imc_badge_creator_ladder(), $count, $tenure);
}

} // function_exists guard