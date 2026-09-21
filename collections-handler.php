<?php
/**
 * File: collections-handler.php (v2.0 — VPS-first with randomization)
 * Purpose: Build a UI-friendly collections list from VPS indexer.
 * Strategy:
 *  - Primary: VPS indexer API (?action=collections) — our own data, no rate limits
 *  - Fallback: Bithomp API — only if VPS fails or returns no data
 *  - Featured IMU collections always pinned first
 *  - Support sort=random for fair rotation in carousels
 */

require_once dirname(__DIR__, 5) . '/wp-load.php';
if (!defined('ABSPATH')) exit;

/**
 * Phase 4C: active scam-issuer set (VPS-sourced; own copy — separate endpoint scope).
 * 30-min transient. Kill-switch: define('IMC_SCAM_FILTER_DISABLED', true). Fail-open.
 */
if (!function_exists('imc_get_scam_issuers')) {
function imc_get_scam_issuers() {
    if (defined('IMC_SCAM_FILTER_DISABLED') && IMC_SCAM_FILTER_DISABLED) return [];
    $cached = get_transient('imc_scam_issuers');
    if (is_array($cached)) return $cached;
    $resp = wp_remote_get('https://metadata.imcollectibles.io/?action=scam_issuers', ['timeout' => 3]);
    if (is_wp_error($resp) || (int)wp_remote_retrieve_response_code($resp) !== 200) {
        $last = get_transient('imc_scam_issuers'); return is_array($last) ? $last : [];
    }
    $json = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($json) || empty($json['issuers']) || !is_array($json['issuers'])) {
        $last = get_transient('imc_scam_issuers'); return is_array($last) ? $last : [];
    }
    $list = [];
    foreach ($json['issuers'] as $i) {
        $i = strtolower(trim((string)$i));
        if (preg_match('/^r[1-9a-hj-np-za-km-z]{24,34}$/i', $i)) $list[] = $i;
    }
    set_transient('imc_scam_issuers', $list, 1800); // 30 min
    return $list;
}
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');

$log_dir  = __DIR__ . '/logs';
$log_file = $log_dir . '/collections-handler.log';
if (!is_dir($log_dir)) { @mkdir($log_dir, 0755, true); }
ini_set('log_errors', 1);
ini_set('error_log', $log_file);

$nonce = sanitize_text_field($_GET['nonce'] ?? '');
if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
    status_header(400);
    wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
}

error_log('Collections handler v2.0 started');

try {
    $sort     = sanitize_text_field($_GET['sort'] ?? 'random');
    $filter   = sanitize_text_field($_GET['filter'] ?? '');
    $search   = sanitize_text_field($_GET['search'] ?? '');
    $limit    = max(10, min(200, (int)($_GET['limit'] ?? 100)));
    $cache_ttl = 15 * MINUTE_IN_SECONDS;
    // Phase 4C: scam-issuer set + collection filter (discovery hardening; fail-open)
    $scam_5 = imc_get_scam_issuers();
    $scam_filter_5 = function($arr) use ($scam_5) {
        if (empty($scam_5) || !is_array($arr)) return $arr;
        return array_values(array_filter($arr, fn($c) => !in_array(strtolower((string)($c['issuer'] ?? '')), $scam_5)));
    };

    $is_random = ($sort === 'random' || $sort === 'shuffle');
    $transient_key = $is_random
        ? null
        : 'vps_collections_v2_' . md5($sort . '_' . $filter . '_' . $search . '_' . $limit);

    if ($transient_key && ($cached = get_transient($transient_key))) {
        error_log("Serving cached: $transient_key");
        $cached = $scam_filter_5($cached);
        wp_send_json(['success' => true, 'collections' => $cached, 'source' => 'cache']);
    }

    // ———————————————————————————————————————————
    // Featured IMU collections (always first)
    // ———————————————————————————————————————————
    $featured = [
        ['issuer'=>'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR','taxon'=>0,        'name'=>'Guardians','slug'=>'guardians'],
        ['issuer'=>'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga','taxon'=>717825,   'name'=>'Frequencies','slug'=>'frequencies'],
        ['issuer'=>'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt','taxon'=>1056369418,'name'=>'Ledger','slug'=>'ledger'],
        ['issuer'=>'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR','taxon'=>777,      'name'=>'Las Vegas','slug'=>'lasvegas'],
        ['issuer'=>'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR','taxon'=>666,      'name'=>'Firepit','slug'=>'firepit'],
    ];
    $featured_keys = [];
    foreach ($featured as $f) {
        $featured_keys[$f['issuer'] . ':' . $f['taxon']] = $f;
    }

    // ———————————————————————————————————————————
    // PRIMARY: Fetch from VPS indexer
    // ———————————————————————————————————————————
    $collections = [];
    $vps_success = false;
    $page_limit = 200;
    $offset = 0;
    $max_pages = 10;

    for ($page = 0; $page < $max_pages; $page++) {
        $vps_url = 'https://metadata.imcollectibles.io/?action=collections'
            . '&limit=' . $page_limit
            . '&offset=' . $offset
            . '&sort=' . ($is_random ? 'health' : urlencode($sort))
            . ($filter ? '&filter=' . urlencode($filter) : '')
            . ($search ? '&search=' . urlencode($search) : '')
            . '&min_health=0';

        $ch = curl_init($vps_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => ['Accept: application/json']
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            error_log("VPS fetch failed at offset $offset: HTTP $httpCode");
            break;
        }

        $data = json_decode($response, true);
        $batch = $data['collections'] ?? [];

        if (empty($batch)) break;

        foreach ($batch as $col) {
            $cid = ($col['issuer'] ?? '') . ':' . ($col['taxon'] ?? 0);
            $featuredData = $featured_keys[$cid] ?? null;
            $name = $col['name'] ?: ($featuredData['name'] ?? ('Collection #' . ($col['taxon'] ?? '?')));
            // v351: slug = slugify(name) + '-' + taxon  — unique across artists with same name
            // IMU's 5 collections keep their registered pretty slugs (guardians, frequencies etc.)
            $slug = $featuredData['slug'] ?? (strtolower(trim(preg_replace('/[^a-z0-9\s\-]/i', '', $name))) 
                ? (preg_replace('/[\s\-]+/', '-', strtolower(trim(preg_replace('/[^a-z0-9\s\-]/i', '', $name)))) . '-' . (int)($col['taxon'] ?? 0))
                : ('collection-' . (int)($col['taxon'] ?? 0)));

            $collections[] = [
                'issuer'       => $col['issuer'] ?? '',
                'taxon'        => (int)($col['taxon'] ?? 0),
                'name'         => $name,
                'slug'         => $slug,
                'image'        => $col['image'] ?? '',  // v329: empty not SVG — JS filters nulls
                'content_type' => $col['content_type'] ?? 'image',
                'is_imu'       => (bool)($col['is_imu'] ?? false),
                'is_music'     => (bool)($col['is_music'] ?? false),
                'total_supply' => (int)($col['total_supply'] ?? 0),
                'indexed_count'=> (int)($col['indexed_count'] ?? 0),
                'health_score' => (float)($col['health_score'] ?? 0),
                'healthy_count'=> (int)($col['healthy_count'] ?? 0),
                'owner_count'  => (int)($col['owner_count'] ?? 0),
                'floor_xrp'    => (float)($col['floor_xrp'] ?? 0),
                'floor_price'  => (int)(($col['floor_xrp'] ?? 0) * 1000000),
                'nfts'         => (int)($col['indexed_count'] ?? $col['total_supply'] ?? 0),
                'owners'       => (int)($col['owner_count'] ?? 0),
                'sales'        => (int)($col['sales_24h'] ?? 0),
                'volume'       => 0,
                'priority'     => isset($featured_keys[$cid]) ? true : false,
            ];
        }

        $vps_success = true;
        if (count($batch) < $page_limit) break;
        $offset += $page_limit;
    }

    error_log("VPS fetch: " . count($collections) . " collections, success=$vps_success");

    // ———————————————————————————————————————————
    // FALLBACK: Bithomp if VPS returned nothing
    // ———————————————————————————————————————————
    if (!$vps_success || count($collections) === 0) {
        // P1F (Aug 2026): the Bithomp disaster-fallback here sent assets=true
        // (free-tier-rejected) - even in a store outage it returned nothing after
        // a 15s burn. Deleted; the alarm below is what actually matters, and the
        // DB merge that follows still surfaces marketplace collections.
        error_log('CRITICAL: VPS collections listing empty - store outage? Serving DB-merge only.');
    }

    // ———————————————————————————————————————————
    // v425/3A: MARKETPLACE DB MERGE
    // Enrich VPS/Bithomp collections with authoritative data from our DB.
    // Adds marketplace collections (Access NFTs) that VPS may not have indexed.
    // Corrects names, stats, and content types from wp_imc_collections + wp_imc_listings.
    // ———————————————————————————————————————————
    global $wpdb;
    $imc_col_table = $wpdb->prefix . 'imc_collections';
    $imc_list_table = $wpdb->prefix . 'imc_listings';
    $imc_purch_table = $wpdb->prefix . 'imc_purchases';
    $has_imc_col = ($wpdb->get_var("SHOW TABLES LIKE '$imc_col_table'") === $imc_col_table);

    if ($has_imc_col) {
        // Get all marketplace collections with listing stats
        $mkt_collections = $wpdb->get_results(
            "SELECT c.artist_account, c.collection_name, c.collection_description,
                    c.collection_taxon, c.cover_image_ipfs, c.nft_count
             FROM $imc_col_table c
             WHERE c.collection_name IS NOT NULL AND c.collection_name != ''
             ORDER BY c.updated_at DESC",
            ARRAY_A
        );

        // Enrich with listing stats (minted counts, cover, nft_type)
        $has_imc_list = ($wpdb->get_var("SHOW TABLES LIKE '$imc_list_table'") === $imc_list_table);
        $listing_stats = [];
        if ($has_imc_list && !empty($mkt_collections)) {
            $ls_rows = $wpdb->get_results(
                "SELECT artist_account, collection_taxon,
                        SUM(minted_count) AS total_minted,
                        SUM(total_editions) AS total_editions,
                        MAX(cover_ipfs) AS listing_cover,
                        MAX(nft_type) AS nft_type
                 FROM $imc_list_table
                 GROUP BY artist_account, collection_taxon",
                ARRAY_A
            );
            foreach ($ls_rows as $ls) {
                $ls_key = $ls['artist_account'] . ':' . $ls['collection_taxon'];
                $listing_stats[$ls_key] = $ls;
            }
        }

        // Build index of existing collections for fast lookup
        $existing_idx = [];
        foreach ($collections as $idx => $c) {
            $key = ($c['issuer'] ?? '') . ':' . ($c['taxon'] ?? 0);
            $existing_idx[$key] = $idx;
        }

        $merged = 0;
        $added = 0;
        foreach ($mkt_collections as $mc) {
            $mc_issuer = $mc['artist_account'] ?? '';
            $mc_taxon = (int)($mc['collection_taxon'] ?? 0);
            $mc_key = $mc_issuer . ':' . $mc_taxon;
            $mc_name = $mc['collection_name'] ?? '';
            $ls = $listing_stats[$mc_key] ?? null;

            // Best cover: collection cover → listing cover, resolved to Pinata
            $cover_cid = $mc['cover_image_ipfs'] ?? '';
            if (empty($cover_cid) && $ls) {
                $cover_cid = $ls['listing_cover'] ?? '';
            }
            $cover_url = '';
            if ($cover_cid) {
                $cid = preg_replace('#^ipfs://#', '', $cover_cid);
                $cover_url = 'https://<your-pinata-gateway>/ipfs/' . $cid;
            }

            // Best NFT count
            $minted = $ls ? (int)($ls['total_minted'] ?? 0) : 0;
            $editions = $ls ? (int)($ls['total_editions'] ?? 0) : 0;
            $nft_count = max((int)($mc['nft_count'] ?? 0), $minted);

            // Content type from listing nft_type
            // v468: album added — maps to 'audio' (albums are multi-track audio NFTs)
            // and flagged is_music=true so audio-styled presentation is applied downstream
            $nft_type = $ls['nft_type'] ?? 'art';
            // F1: an AudioBook is audio (it plays); an eBook is image (its cover is the
            // display media). Both previously fell to the 'image' default, so AudioBook
            // collections were presented as image collections here, on /collections/,
            // on the user page filter and in the XRPLAYR app payload.
            $content_type = match($nft_type) {
                'music' => 'audio', 'musicvideo' => 'video', 'film' => 'video', 'album' => 'audio',
                'audiobook' => 'audio', 'ebook' => 'image', default => 'image'
            };
            $is_music = in_array($nft_type, ['music', 'musicvideo', 'album', 'audiobook']);

            // Slug for marketplace collections
            $mc_slug = preg_replace('/[\s\-]+/', '-', strtolower(trim(preg_replace('/[^a-z0-9\s\-]/i', '', $mc_name))));
            $mc_slug = $mc_slug ? ($mc_slug . '-' . $mc_taxon) : ('collection-' . $mc_taxon);

            if (isset($existing_idx[$mc_key])) {
                // ENRICH existing VPS/Bithomp entry with authoritative DB data
                $idx = $existing_idx[$mc_key];
                // Authoritative name (DB always wins over VPS generic names)
                if ($mc_name && (empty($collections[$idx]['name']) || preg_match('/^Collection #\d+$/', $collections[$idx]['name']))) {
                    $collections[$idx]['name'] = $mc_name;
                    $collections[$idx]['slug'] = $mc_slug;
                }
                // Content type from DB (VPS defaults everything to 'image')
                if ($content_type !== 'image') {
                    $collections[$idx]['content_type'] = $content_type;
                    $collections[$idx]['is_music'] = $is_music;
                }
                // Stats: use DB count if higher than VPS (VPS may be stale)
                if ($nft_count > ($collections[$idx]['nfts'] ?? 0)) {
                    $collections[$idx]['nfts'] = $nft_count;
                    $collections[$idx]['total_supply'] = max($editions, $nft_count);
                    $collections[$idx]['indexed_count'] = $nft_count;
                }
                // Mark as marketplace collection
                $collections[$idx]['is_marketplace'] = true;
                $merged++;
            } else {
                // ADD new marketplace collection not in VPS/Bithomp
                $collections[] = [
                    'issuer'        => $mc_issuer,
                    'taxon'         => $mc_taxon,
                    'name'          => $mc_name,
                    'slug'          => $mc_slug,
                    'image'         => $cover_url,
                    'content_type'  => $content_type,
                    'is_imu'        => false,
                    'is_music'      => $is_music,
                    'is_marketplace'=> true,
                    'total_supply'  => max($editions, $nft_count),
                    'indexed_count' => $nft_count,
                    'health_score'  => 0,
                    'healthy_count' => 0,
                    'owner_count'   => 0,
                    'floor_xrp'     => 0,
                    'floor_price'   => 0,
                    'nfts'          => $nft_count,
                    'owners'        => 0,
                    'sales'         => 0,
                    'volume'        => 0,
                    'priority'      => false,
                ];
                $added++;
            }
        }

        if ($merged > 0 || $added > 0) {
            error_log("Collections 3A: Merged $merged + added $added marketplace collections from DB");
        }
    }

    // ———————————————————————————————————————————
    // Sort: Featured first, then by requested sort
    // ———————————————————————————————————————————
    if ($is_random) {
        $feat = array_filter($collections, fn($c) => !empty($c['priority']));
        $rest = array_values(array_filter($collections, fn($c) => empty($c['priority'])));
        shuffle($rest);
        $collections = array_merge(array_values($feat), $rest);
    } else {
        usort($collections, function($a, $b) {
            if (!empty($a['priority']) && empty($b['priority'])) return -1;
            if (empty($a['priority']) && !empty($b['priority'])) return 1;
            return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
        });
    }

    // Phase 4C: drop scam-issuer collections from discovery output
    $collections = $scam_filter_5($collections);

    // Cache (not random)
    if ($transient_key) {
        set_transient($transient_key, $collections, $cache_ttl);
    }

    // Write to WP cache table for long-term storage
    global $wpdb;
    $table_name = 'wp_xumm_collection_cache';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
        foreach (array_slice($collections, 0, 500) as $col) {
            $wpdb->replace($table_name, [
                'issuer'      => $col['issuer'],
                'taxon'       => $col['taxon'],
                'name'        => $col['name'],
                'slug'        => $col['slug'],
                'sales'       => $col['sales'],
                'volume'      => $col['volume'],
                'floor_price' => $col['floor_price'],
                'owners'      => $col['owners'],
                'nfts'        => $col['nfts'],
                'image_url'   => $col['image'],
                'priority'    => !empty($col['priority']) ? 1 : 0,
            ], ['%s', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%d']);
        }
    }

    wp_send_json([
        'success'     => true,
        'collections' => $collections,
        'total'       => count($collections),
        'source'      => $vps_success ? 'vps' : 'bithomp-fallback',
        'sort'        => $sort,
    ]);

} catch (Exception $e) {
    error_log('Collections handler v2.0 failed: ' . $e->getMessage());
    wp_send_json(['success' => false, 'error' => 'Failed to fetch collections', 'collections' => []]);
}