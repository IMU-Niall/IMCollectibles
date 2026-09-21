<?php
/**
 * Bithomp NFT API Handler
 * File: bithomp-handler.php
 * Path: /wp-content/themes/astra/xrpl-nft-marketplace/backend/bithomp-handler.php
 * 
 * Actions: stats, recent_mints, collection, search_collections
 */

// WordPress bootstrap
$wp_paths = [
    dirname(__DIR__, 5) . '/wp-load.php',
    dirname(__DIR__, 4) . '/wp-load.php',
    $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php'
];
foreach ($wp_paths as $p) { if (file_exists($p)) { require_once $p; break; } }

if (!defined('ABSPATH')) {
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'WordPress not loaded']));
}

/**
 * Phase 4C: active scam-issuer set (VPS-sourced; own copy - separate endpoint scope).
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
header('Cache-Control: no-cache');

// Logging
$log_dir = __DIR__ . '/logs';
if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
function bh_log($m) { global $log_dir; @file_put_contents($log_dir . '/bithomp-handler.log', date('[Y-m-d H:i:s] ') . $m . "\n", FILE_APPEND); }

// Config
$BITHOMP_TOKEN = defined('BITHOMP_API_KEY') ? BITHOMP_API_KEY : getenv('BITHOMP_API_KEY');
if (!$BITHOMP_TOKEN) { wp_send_json(['success' => false, 'error' => 'API key missing'], 500); }

// Nonce check
if (!wp_verify_nonce(sanitize_text_field($_GET['nonce'] ?? ''), 'xrpl_marketplace_nonce')) {
    wp_send_json(['success' => false, 'error' => 'Invalid nonce'], 403);
}

$action = sanitize_text_field($_GET['action'] ?? '');
// Phase 4C: scam-issuer set + collection filter (discovery hardening; fail-open)
$scam_5 = imc_get_scam_issuers();
$scam_filter_5 = function($arr) use ($scam_5) {
    if (empty($scam_5) || !is_array($arr)) return $arr;
    return array_values(array_filter($arr, fn($c) => !in_array(strtolower((string)($c['issuer'] ?? '')), $scam_5)));
};

// API helper
function bithomp_get($endpoint, $params = [], $cache_key = '', $cache_ttl = 300, $timeout = 15) {
    global $BITHOMP_TOKEN;
    
    if ($cache_key && ($cached = get_transient($cache_key)) !== false) {
        return ['data' => $cached, 'cached' => true];
    }
    
    $url = 'https://bithomp.com/api/v2/' . $endpoint;
    if ($params) $url .= '?' . http_build_query($params);
    
    bh_log("API: $url");
    
    $res = wp_remote_get($url, ['headers' => ['x-bithomp-token' => $BITHOMP_TOKEN], 'timeout' => $timeout]);
    
    if (is_wp_error($res)) {
        bh_log("Error: " . $res->get_error_message());
        return ['data' => null, 'error' => $res->get_error_message()];
    }
    
    if (wp_remote_retrieve_response_code($res) !== 200) {
        bh_log("HTTP " . wp_remote_retrieve_response_code($res));
        return ['data' => null, 'error' => 'HTTP ' . wp_remote_retrieve_response_code($res)];
    }
    
    $data = json_decode(wp_remote_retrieve_body($res), true);
    if ($cache_key && $data) set_transient($cache_key, $data, $cache_ttl);
    
    return ['data' => $data, 'cached' => false];
}

function drops_to_xrp($d) { return round((float)$d / 1000000, 2); }
function format_xrp($a) { 
    if ($a >= 1000000) return number_format($a / 1000000, 1) . 'M';
    if ($a >= 1000) return number_format($a / 1000, 1) . 'K';
    return number_format($a, 2);
}
function time_ago($ts) {
    if (!$ts) return '';
    $d = time() - $ts;
    if ($d < 60) return 'Just now';
    if ($d < 3600) return floor($d / 60) . 'm ago';
    if ($d < 86400) return floor($d / 3600) . 'h ago';
    if ($d < 604800) return floor($d / 86400) . 'd ago';
    return floor($d / 604800) . 'w ago';
}

switch ($action) {

    // =========================================================================
    // STATS - Top collections by volume
    // =========================================================================
    case 'stats':
        $period = sanitize_text_field($_GET['period'] ?? '24h');
        $limit = min(100, max(5, absint($_GET['limit'] ?? 10))); // Allow up to 100 for full stats page
        
        $period_map = ['24h' => 'day', '7d' => 'week', '30d' => 'month', 'all' => 'all'];
        $api_period = $period_map[$period] ?? 'day';
        
        $cache_ttl = ($api_period === 'day') ? 5 * MINUTE_IN_SECONDS : 15 * MINUTE_IN_SECONDS;
        
        $result = bithomp_get('nft-volumes-extended', [
            'list' => 'collections',
            'period' => $api_period,
            'sortCurrency' => 'xrp',
            'statistics' => 'true',
            'floorPrice' => 'true',
            'assets' => 'true',
            'limit' => $limit
        ], "bh_stats_{$api_period}_{$limit}", $cache_ttl);
        
        if (!$result['data']) {
            $stale = get_transient("bh_stats_{$api_period}_{$limit}_stale");
            if (is_array($stale)) $stale = $scam_filter_5($stale);
            if ($stale) wp_send_json(['success' => true, 'cached' => true, 'stale' => true, 'collections' => $stale]);
            wp_send_json(['success' => false, 'error' => $result['error'] ?? 'API failed'], 500);
        }
        
        $collections = [];
        $rank = 1;
        foreach (($result['data']['collections'] ?? []) as $col) {
            $details = $col['collectionDetails'] ?? [];
            
            // Volume (XRP)
            $vol = 0;
            foreach (($col['volumes'] ?? []) as $v) {
                if (!isset($v['currency']) || $v['currency'] === 'XRP') { $vol = (float)($v['amount'] ?? 0); break; }
            }
            
            // Floor
            $floor = 0;
            foreach (($col['floorPrices'] ?? []) as $fp) {
                if (isset($fp['open']['amount'])) { $floor = (float)$fp['open']['amount']; break; }
            }
            
            // Image
            $img = $details['assets']['preview'] ?? $details['assets']['image'] ?? $details['image'] ?? '';
            if (!$img || strpos($img, 'ipfs://') === 0) {
                $hash = str_replace('ipfs://', '', $img ?: '');
                $img = $hash ? "https://ipfs.io/ipfs/$hash" : '/wp-content/uploads/fallback-nft.svg';
            }
            
            $collections[] = [
                'rank' => $rank++,
                'collection_id' => $col['collection'] ?? '',
                'issuer' => $details['issuer'] ?? '',
                'taxon' => (int)($details['taxon'] ?? 0),
                'name' => $details['name'] ?? 'Unknown',
                'image' => $img,
                'volume_xrp' => drops_to_xrp($vol),
                'volume_display' => format_xrp(drops_to_xrp($vol)) . ' XRP',
                'sales' => (int)($col['sales'] ?? 0),
                'floor_xrp' => drops_to_xrp($floor),
                'floor_display' => drops_to_xrp($floor) > 0 ? drops_to_xrp($floor) . ' XRP' : '—',
                'owners' => (int)($col['statistics']['owners'] ?? 0),
                'nfts' => (int)($col['statistics']['nfts'] ?? 0)
            ];
            
            // Backfill issuer/taxon from collection ID when collectionDetails was sparse
            $last = &$collections[count($collections) - 1];
            if (empty($last['issuer']) && !empty($col['collection'])) {
                $parts = explode(':', $col['collection'], 2);
                if (count($parts) === 2) {
                    $last['issuer'] = $parts[0];
                    $last['taxon'] = (int)$parts[1];
                }
            }
            unset($last);
            
            // Stop after reaching requested limit
            if ($rank > $limit) break;
        }
        
        // Ensure we only return requested limit
        $collections = array_slice($collections, 0, $limit);
        
        // ─── ENRICHMENT: Fill in missing names and images ───
        // Bithomp nft-volumes-extended has volume/floor data but sparse collectionDetails.
        // We enrich from TWO sources: VPS indexer + Bithomp nft-collections endpoint.
        
        // Helper: is this a real name or a generic/missing one?
        $is_generic_name = function($name) {
            if (empty($name) || $name === 'Unknown') return true;
            if (preg_match('/^Collection\s*#\d+$/i', $name)) return true;
            return false;
        };
        
        $needs_enrichment = false;
        foreach ($collections as $col) {
            if ($is_generic_name($col['name']) || $col['image'] === '/wp-content/uploads/fallback-nft.svg') {
                $needs_enrichment = true;
                break;
            }
        }
        
        if ($needs_enrichment) {
            // Build unified name map from both sources (cached 30 min)
            $name_map = get_transient('bh_stats_name_map_v2');
            
            if ($name_map === false) {
                $name_map = [];
                
                // SOURCE 1: VPS Indexer (your own decoded metadata — largest coverage)
                $vps_url = 'https://metadata.imcollectibles.io/?action=collections&limit=5000&include_empty=true';
                $vps_res = wp_remote_get($vps_url, ['timeout' => 8]);
                
                if (!is_wp_error($vps_res) && wp_remote_retrieve_response_code($vps_res) === 200) {
                    $vps_data = json_decode(wp_remote_retrieve_body($vps_res), true);
                    
                    foreach (($vps_data['collections'] ?? []) as $vc) {
                        $key = ($vc['issuer'] ?? '') . ':' . ($vc['taxon'] ?? 0);
                        $vps_name = $vc['name'] ?? '';
                        
                        // Only store REAL names (skip VPS "Collection #N" fallbacks)
                        if (!empty($vps_name) && !preg_match('/^Collection\s*#\d+$/i', $vps_name)) {
                            $name_map[$key] = [
                                'name'  => $vps_name,
                                'image' => $vc['image_proxy'] ?? $vc['image'] ?? null
                            ];
                        } elseif (!isset($name_map[$key])) {
                            // Store image even if name is generic
                            $img = $vc['image_proxy'] ?? $vc['image'] ?? null;
                            if ($img) {
                                $name_map[$key] = ['name' => null, 'image' => $img];
                            }
                        }
                    }
                    bh_log("VPS enrichment: " . count($name_map) . " collections loaded");
                }
                
                // P1F (Aug 2026): SOURCE 2 (Bithomp nft-collections) deleted - its
                // assets=true parameter is free-tier-rejected, so it contributed zero
                // names on every 30-minute refresh while costing 10s. Name gaps are
                // the meta-pass / naming-polish concern (P3e).
                set_transient('bh_stats_name_map_v2', $name_map, 30 * MINUTE_IN_SECONDS);
            }
            
            // Apply enrichment
            if (!empty($name_map)) {
                foreach ($collections as &$col) {
                    $key = $col['issuer'] . ':' . $col['taxon'];
                    if (isset($name_map[$key])) {
                        // Enrich name if generic
                        if ($is_generic_name($col['name']) && !empty($name_map[$key]['name'])) {
                            $col['name'] = $name_map[$key]['name'];
                        }
                        // Enrich image if fallback
                        if ($col['image'] === '/wp-content/uploads/fallback-nft.svg' && !empty($name_map[$key]['image'])) {
                            $col['image'] = $name_map[$key]['image'];
                        }
                    }
                }
                unset($col);
            }
        }
        
        // ─── TARGETED LOOKUPS: VPS XRPL-direct resolution for remaining unknowns ───
        // Instead of slow Bithomp API calls, use our VPS which queries the XRPL ledger
        // directly via Clio, fetches NFT metadata from IPFS/Arweave, and extracts
        // collection.name. This is how XPMarket, xrp.cafe, etc. resolve 100% of names.
        $still_unknown = [];
        foreach ($collections as $idx => $col) {
            if ($is_generic_name($col['name'])) {
                $cid = !empty($col['collection_id']) 
                    ? $col['collection_id'] 
                    : ($col['issuer'] . ':' . $col['taxon']);
                if (!empty($cid) && $cid !== ':' && $cid !== ':0') {
                    $still_unknown[$idx] = $cid;
                }
            }
        }
        
        // Phase 1: Apply any cached results from previous VPS lookups (instant)
        $needs_resolve = [];
        if (!empty($still_unknown)) {
            foreach ($still_unknown as $idx => $cid) {
                $cache_key = 'bh_col_name_' . md5($cid);
                $cached_name = get_transient($cache_key);
                
                if ($cached_name !== false) {
                    if ($cached_name !== '__none__') {
                        $collections[$idx]['name'] = $cached_name;
                    }
                } else {
                    $needs_resolve[$idx] = $cid;
                }
            }
        }
        
        // Phase 2: Batch-resolve uncached unknowns via VPS (XRPL-direct)
        // VPS endpoint queries Clio → gets NFT URI → fetches IPFS metadata → extracts name
        if (!empty($needs_resolve)) {
            $cid_list = implode(',', array_values($needs_resolve));
            $vps_url = 'https://metadata.imcollectibles.io/?action=resolve_collections&cids=' . urlencode($cid_list);
            
            bh_log("VPS resolve: requesting " . count($needs_resolve) . " collections");
            
            // Run in background after response is sent
            $bg_needs = $needs_resolve;
            $bg_vps_url = $vps_url;
            $bg_collections = $collections;
            $bg_period = $api_period;
            $bg_limit = $limit;
            
            register_shutdown_function(function() use ($bg_needs, $bg_vps_url, $bg_collections, $bg_period, $bg_limit) {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } elseif (function_exists('litespeed_finish_request')) {
                    litespeed_finish_request();
                }
                
                // Call VPS resolver (generous timeout — we're in background)
                $vps_res = wp_remote_get($bg_vps_url, ['timeout' => 45]);
                
                if (is_wp_error($vps_res)) {
                    bh_log("BG VPS error: " . $vps_res->get_error_message());
                    return;
                }
                
                $vps_data = json_decode(wp_remote_retrieve_body($vps_res), true);
                
                if (!($vps_data['success'] ?? false)) {
                    bh_log("BG VPS failed: " . ($vps_data['error'] ?? 'unknown'));
                    return;
                }
                
                $resolved = 0;
                $updated = $bg_collections;
                $vps_results = $vps_data['collections'] ?? [];
                
                foreach ($bg_needs as $idx => $cid) {
                    $cache_key = 'bh_col_name_' . md5($cid);
                    $vps_entry = $vps_results[$cid] ?? null;
                    $name = $vps_entry['name'] ?? null;
                    $image = $vps_entry['image'] ?? null;
                    
                    if (!empty($name)) {
                        set_transient($cache_key, $name, 12 * HOUR_IN_SECONDS);
                        $updated[$idx]['name'] = $name;
                        $resolved++;
                        bh_log("BG VPS resolved: $cid -> '$name'");
                        
                        if (!empty($image) && $updated[$idx]['image'] === '/wp-content/uploads/fallback-nft.svg') {
                            $updated[$idx]['image'] = $image;
                        }
                    } else {
                        set_transient($cache_key, '__none__', 2 * HOUR_IN_SECONDS);
                        $error = $vps_entry['error'] ?? 'no name in metadata';
                        bh_log("BG VPS unresolved: $cid ($error)");
                    }
                }
                
                if ($resolved > 0) {
                    set_transient("bh_stats_{$bg_period}_{$bg_limit}_stale", $updated, HOUR_IN_SECONDS);
                    bh_log("BG VPS updated stale transient: $resolved new names");
                }
                
                bh_log("BG VPS complete: {$vps_data['resolved']}/{$vps_data['total']} resolved ({$vps_data['api_calls']} API calls)");
            });
            
            bh_log("Deferred " . count($needs_resolve) . " collections to VPS XRPL resolver");
        }
        // ─── END ENRICHMENT ───
        
        $collections = $scam_filter_5($collections);
        set_transient("bh_stats_{$api_period}_{$limit}_stale", $collections, HOUR_IN_SECONDS);
        
        wp_send_json([
            'success' => true,
            'cached' => $result['cached'],
            'period' => $period,
            'collections' => $collections
        ]);
        break;

    // =========================================================================
    // RECENT MINTS
    // =========================================================================
    case 'recent_mints':
        $limit = min(50, max(5, absint($_GET['limit'] ?? 20)));
        
        $result = bithomp_get('nfts', [
            'order' => 'mintedNew',
            'hasImage' => 'true',
            'hasMetadata' => 'true',
            'limit' => $limit
        ], "bh_mints_$limit", 2 * MINUTE_IN_SECONDS);
        
        if (!$result['data']) {
            $stale = get_transient("bh_mints_{$limit}_stale");
            if ($stale) wp_send_json(['success' => true, 'stale' => true, 'nfts' => $stale]);
            wp_send_json(['success' => false, 'error' => 'API failed'], 500);
        }
        
        $nfts = [];
        foreach (($result['data']['nfts'] ?? []) as $n) {
            $img = $n['assets']['preview'] ?? $n['assets']['image'] ?? $n['assets']['thumbnail'] ?? '';
            if (!$img && isset($n['uri'])) {
                $uri = $n['uri'];
                if (strpos($uri, 'ipfs://') === 0) $img = 'https://ipfs.io/ipfs/' . substr($uri, 7);
                elseif (preg_match('/^(Qm|bafy)/', $uri)) $img = 'https://ipfs.io/ipfs/' . $uri;
            }
            if (!$img) $img = '/wp-content/uploads/fallback-nft.svg';
            
            $name = $n['metadata']['name'] ?? '';
            if (!$name) $name = 'NFT #' . substr($n['nftokenID'] ?? '', -8);
            
            $nfts[] = [
                'nftokenID' => $n['nftokenID'] ?? '',
                'name' => $name,
                'image' => $img,
                'issuer' => $n['issuer'] ?? '',
                'owner' => $n['owner'] ?? '',
                'taxon' => (int)($n['nftokenTaxon'] ?? 0),
                'issued_at' => (int)($n['issuedAt'] ?? 0),
                'time_ago' => time_ago($n['issuedAt'] ?? 0),
                'collection_name' => $n['metadata']['collection']['name'] ?? ''
            ];
        }
        
        $nfts = $scam_filter_5($nfts);
        set_transient("bh_mints_{$limit}_stale", $nfts, 30 * MINUTE_IN_SECONDS);
        
        wp_send_json(['success' => true, 'cached' => $result['cached'], 'nfts' => $nfts]);
        break;

    // =========================================================================
    // COLLECTION DETAILS
    // =========================================================================
    case 'collection':
        $issuer = sanitize_text_field($_GET['issuer'] ?? '');
        $taxon = absint($_GET['taxon'] ?? 0);
        if (!$issuer) wp_send_json(['success' => false, 'error' => 'Missing issuer'], 400);
        
        $cid = $issuer . ':' . $taxon;
        $result = bithomp_get('nft-collection/' . urlencode($cid), ['statistics' => 'true', 'floorPrice' => 'true'], 'bh_col_' . md5($cid), 10 * MINUTE_IN_SECONDS);
        
        if (!$result['data']) wp_send_json(['success' => false, 'error' => 'Not found'], 404);
        
        $c = $result['data'];
        $floor = 0;
        foreach (($c['floorPrices'] ?? []) as $fp) { if (isset($fp['open']['amount'])) { $floor = (float)$fp['open']['amount']; break; } }
        
        wp_send_json(['success' => true, 'collection' => [
            'issuer' => $c['issuer'] ?? $issuer,
            'taxon' => (int)($c['taxon'] ?? $taxon),
            'name' => $c['name'] ?? 'Unknown',
            'description' => $c['description'] ?? '',
            'floor_xrp' => drops_to_xrp($floor),
            'owners' => (int)($c['statistics']['owners'] ?? 0),
            'nfts' => (int)($c['statistics']['nfts'] ?? 0)
        ]]);
        break;

    // =========================================================================
    // COLLECTION SALES / ACTIVITY (Proxy — API key stays server-side)
    // v132: Added for collections.php activity feed
    // =========================================================================
    case 'sales':
        $issuer = sanitize_text_field($_GET['issuer'] ?? '');
        $taxon = absint($_GET['taxon'] ?? 0);
        $limit = min(20, max(1, absint($_GET['limit'] ?? 10)));
        if (!$issuer) wp_send_json(['success' => false, 'error' => 'Missing issuer'], 400);
        
        $cid = $issuer . ':' . $taxon;
        $result = bithomp_get('nft-sales', ['collection' => $cid, 'limit' => $limit], 'bh_sales_' . md5($cid . $limit), 5 * MINUTE_IN_SECONDS);
        
        if (!$result['data']) {
            wp_send_json(['success' => true, 'sales' => []]);
        }
        
        $sales = $result['data']['sales'] ?? $result['data']['nfts'] ?? $result['data'] ?? [];
        wp_send_json(['success' => true, 'sales' => $sales]);
        break;

    // =========================================================================
    // UNIFIED SEARCH (Collections + NFT ID lookup)
    // Searches BOTH Bithomp API AND VPS platform database
    // =========================================================================
    case 'search':
        $q = trim(sanitize_text_field($_GET['q'] ?? ''));
        $limit = min(20, max(5, absint($_GET['limit'] ?? 8)));
        // P3-A: per-lane result caps (collections wider, people tighter).
        $cap_collections = 15;
        $cap_users = 10;
        
        if (strlen($q) < 2) {
            wp_send_json(['success' => false, 'error' => 'Query too short'], 400);
        }
        
        $response = [
            'success' => true,
            'query' => $q,
            'collections' => [],
            'nft' => null
        ];
        
        $q_lower = strtolower($q);
        $seen_keys = []; // Track issuer:taxon to avoid duplicates
        
        // Check if query looks like an NFT ID (64 hex chars)
        if (preg_match('/^[0-9A-Fa-f]{64}$/', $q)) {
            bh_log("NFT ID lookup: $q");
            $nft_id_upper = strtoupper($q);
            
            // ── TRY VPS INDEXER FIRST (fast, free, uses img.php cache) ──
            $vps_url = 'https://metadata.imcollectibles.io/?action=get&id=' . $nft_id_upper;
            $vps_res = wp_remote_get($vps_url, ['timeout' => 3]);
            
            if (!is_wp_error($vps_res) && wp_remote_retrieve_response_code($vps_res) === 200) {
                $vps_data = json_decode(wp_remote_retrieve_body($vps_res), true);
                if (!empty($vps_data['success']) && !empty($vps_data['nft'])) {
                    $n = $vps_data['nft'];
                    $meta = $n['metadata'] ?? [];
                    
                    // Prefer image_proxy (routes through img.php cache), fallback to resolved image
                    $img = $n['image_proxy'] ?? $meta['image'] ?? $n['image_url'] ?? '';
                    if (strpos($img, 'ipfs://') === 0) {
                        $img = 'https://ipfs.io/ipfs/' . substr($img, 7);
                    }
                    
                    $response['nft'] = [
                        'nftokenID' => $n['nftokenID'],
                        'name'      => $meta['name'] ?? ('NFT #' . substr($n['nftokenID'], -8)),
                        'image'     => $img ?: '/wp-content/uploads/fallback-nft.svg',
                        'issuer'    => $n['issuer'] ?? '',
                        'owner'     => $n['owner'] ?? '',
                        'collection'=> ''
                    ];
                    bh_log("NFT found via VPS: " . ($response['nft']['name'] ?? 'Unknown'));
                    wp_send_json($response);
                    break;
                }
            }
            
            // ── FALLBACK: Bithomp (NFT not in VPS index yet) ──
            $nft_result = bithomp_get('nft/' . $nft_id_upper, [], '', 0);
            
            if ($nft_result['data'] && isset($nft_result['data']['nftokenID'])) {
                $n = $nft_result['data'];
                $meta = $n['metadata'] ?? [];
                
                $img = $meta['image'] ?? $n['uri'] ?? '';
                if (strpos($img, 'ipfs://') === 0) {
                    $img = 'https://ipfs.io/ipfs/' . substr($img, 7);
                } elseif (preg_match('/^(Qm|bafy)/', $img)) {
                    $img = 'https://ipfs.io/ipfs/' . $img;
                }
                
                $response['nft'] = [
                    'nftokenID' => $n['nftokenID'],
                    'name' => $meta['name'] ?? ('NFT #' . substr($n['nftokenID'], -8)),
                    'image' => $img ?: '/wp-content/uploads/fallback-nft.svg',
                    'issuer' => $n['issuer'] ?? '',
                    'owner' => $n['owner'] ?? '',
                    'collection' => $meta['collection']['name'] ?? ''
                ];
                bh_log("NFT found via Bithomp: " . ($response['nft']['name'] ?? 'Unknown'));
            }
            
            wp_send_json($response);
            break;
        }
        
        bh_log("Collection search: $q");
        
        // =====================================================================
        // STEP 1: Search VPS Platform Database (your indexed collections)
        // =====================================================================
        // P3-A: server-side name search over the FULL store (was a 200-row client window).
        $vps_url = 'https://metadata.imcollectibles.io/?action=collections&limit=100&search=' . rawurlencode($q);
        $vps_response = wp_remote_get($vps_url, ['timeout' => 10]);
        
        if (!is_wp_error($vps_response) && wp_remote_retrieve_response_code($vps_response) === 200) {
            $vps_data = json_decode(wp_remote_retrieve_body($vps_response), true);
            $vps_collections = [];
            
            // Handle different VPS response formats
            if (isset($vps_data['collections'])) {
                $vps_collections = $vps_data['collections'];
            } elseif (is_array($vps_data) && isset($vps_data[0])) {
                $vps_collections = $vps_data;
            }
            
            // Filter by search query
            foreach ($vps_collections as $col) {
                $name = $col['name'] ?? $col['collection_name'] ?? '';
                if (!$name) continue;
                
                // Case-insensitive partial match
                if (stripos($name, $q) !== false) {
                    $issuer = $col['issuer'] ?? '';
                    $taxon = (int)($col['taxon'] ?? 0);
                    $key = $issuer . ':' . $taxon;
                    
                    if (isset($seen_keys[$key])) continue;
                    $seen_keys[$key] = true;
                    
                    // Get image
                    $img = $col['image'] ?? $col['preview_image'] ?? '';
                    if (strpos($img, 'ipfs://') === 0) {
                        $img = 'https://ipfs.io/ipfs/' . substr($img, 7);
                    }
                    if (!$img) {
                        $img = '/wp-content/uploads/fallback-nft.svg';
                    }
                    
                    $response['collections'][] = [
                        'issuer' => $issuer,
                        'taxon' => $taxon,
                        'name' => $name,
                        'image' => $img,
                        'floor_xrp' => 0,
                        'nfts' => (int)($col['indexed_count'] ?? $col['total_supply'] ?? $col['nft_count'] ?? 0),
                        'source' => 'vps'
                    ];
                }
            }
            bh_log("VPS search found " . count($response['collections']) . " matches");
        }
        
        // =====================================================================
        // STEP 2: Search Bithomp API (global XRPL collections)
        // =====================================================================
        $result = bithomp_get('nft-collections', [
            'search' => $q, 
            'floorPrice' => 'true', 
            'statistics' => 'true',
            'limit' => $limit
        ], 'bh_search_' . md5($q . $limit), 5 * MINUTE_IN_SECONDS);
        
        if ($result['data'] && isset($result['data']['collections'])) {
            foreach ($result['data']['collections'] as $c) {
                $issuer = $c['issuer'] ?? '';
                $taxon = (int)($c['taxon'] ?? 0);
                $key = $issuer . ':' . $taxon;
                
                // Skip if already found in VPS
                if (isset($seen_keys[$key])) continue;
                $seen_keys[$key] = true;
                
                $floor = isset($c['floorPrices'][0]['open']['amount']) 
                    ? drops_to_xrp((float)$c['floorPrices'][0]['open']['amount']) 
                    : 0;
                
                $img = $c['image'] ?? $c['assets']['image'] ?? '';
                if (strpos($img, 'ipfs://') === 0) {
                    $img = 'https://ipfs.io/ipfs/' . substr($img, 7);
                } elseif (preg_match('/^(Qm|bafy)/', $img)) {
                    $img = 'https://ipfs.io/ipfs/' . $img;
                }
                if (!$img) {
                    $img = '/wp-content/uploads/fallback-nft.svg';
                }
                
                $response['collections'][] = [
                    'issuer' => $issuer,
                    'taxon' => $taxon,
                    'name' => $c['name'] ?? 'Unknown',
                    'image' => $img,
                    'floor_xrp' => $floor,
                    'nfts' => (int)($c['statistics']['nfts'] ?? $c['nfts'] ?? 0),
                    'source' => 'bithomp'
                ];
            }
            bh_log("Bithomp search added " . count($result['data']['collections']) . " (after dedup: " . count($response['collections']) . " total)");
        }
        
        // Sort: VPS results first (platform collections), then by name
        usort($response['collections'], function($a, $b) {
            // VPS (platform) collections first
            if (($a['source'] ?? '') === 'vps' && ($b['source'] ?? '') !== 'vps') return -1;
            if (($a['source'] ?? '') !== 'vps' && ($b['source'] ?? '') === 'vps') return 1;
            // Then alphabetical
            return strcasecmp($a['name'], $b['name']);
        });
        
        // Limit results
        $response['collections'] = array_slice($response['collections'], 0, $cap_collections);
        
        // Remove source field from output (internal use only)
        foreach ($response['collections'] as &$col) {
            unset($col['source']);
        }
        
        bh_log("Final search results: " . count($response['collections']) . " collections for '$q'");
        if (!empty($response['collections']) && is_array($response['collections'])) $response['collections'] = $scam_filter_5($response['collections']);
        
        // ── USERS (profiles) branch — creators + collectors, tagged, blacklist-excluded ──
        // Added for the profiles phase. Reads our own tables (no external call).
        $response['users'] = [];
        global $wpdb;
        $ap_tbl  = $wpdb->prefix . 'imc_artist_profiles';
        $xp_tbl  = $wpdb->prefix . 'xaman_profiles';
        $lst_tbl = $wpdb->prefix . 'imc_listings';
        $like = '%' . $wpdb->esc_like($q) . '%';
        $seen_accts = [];

        // 1) Named creator/user profiles from imc_artist_profiles - display_name or slug match
        $ap_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT artist_account, display_name, slug, profile_image_url"
            . " FROM $ap_tbl WHERE display_name LIKE %s OR slug LIKE %s OR artist_account LIKE %s LIMIT 20",
            $like, $like, $like), ARRAY_A);

        // 2) Legacy named users from xaman_profiles - name match, skipping blacklisted
        $xp_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT xrpl_account, name, profile_pic_url FROM $xp_tbl"
            . " WHERE (name LIKE %s OR xrpl_account LIKE %s) AND (blacklisted IS NULL OR blacklisted = 0) LIMIT 20",
            $like, $like), ARRAY_A);

        // Blacklist set (so artist-profile matches are filtered too)
        $bl = [];
        $bl_rows = $wpdb->get_col("SELECT xrpl_account FROM $xp_tbl WHERE blacklisted = 1");
        foreach ((array)$bl_rows as $b) { $bl[$b] = true; }

        $push_user = function($account, $name, $slug, $pfp) use (&$response, &$seen_accts, $bl, $wpdb, $lst_tbl) {
            if (!$account || isset($seen_accts[$account]) || isset($bl[$account])) return;
            $seen_accts[$account] = true;
            // Creator = has at least one listing; else Collector
            $is_creator = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $lst_tbl WHERE artist_account = %s LIMIT 1", $account)) > 0;
            $response['users'][] = [
                'account' => $account,
                'name'    => $name ?: (substr($account, 0, 6) . '...' . substr($account, -4)),
                'slug'    => $slug ?: $account,
                'pfp'     => $pfp ?: null,
                'tag'     => $is_creator ? 'Creator' : 'Collector',
            ];
        };

        foreach ((array)$ap_rows as $r) { $push_user($r['artist_account'], $r['display_name'], $r['slug'], $r['profile_image_url']); }
        foreach ((array)$xp_rows as $r) { $push_user($r['xrpl_account'], $r['name'], null, $r['profile_pic_url']); }

        // Creators first, then collectors; cap at $limit
        usort($response['users'], function($a, $b) {
            if ($a['tag'] === $b['tag']) return 0;
            return $a['tag'] === 'Creator' ? -1 : 1;
        });
        $response['users'] = array_slice($response['users'], 0, $cap_users);
        bh_log("Search users: " . count($response['users']) . " for '$q'");
        // ── DROPS (platform listings) branch — S1 ──
        // Name search over wp_imc_listings (our own table, no external call).
        //
        // SEPARATE QUERIES, NEVER A JOIN. wp_imc_listings is created with a HARDCODED
        // "DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci" (listings-handler.php:719),
        // while wp_imc_artist_profiles takes $wpdb->get_charset_collate(). Nothing in the
        // codebase joins those two tables today, so compatibility is UNPROVEN, and a
        // column-vs-column join on artist_account across differing collations raises
        // ERROR 1271 -- get_results() returns null and this lane would silently return
        // ZERO FOREVER. Exactly the hazard already documented at
        // mint-on-demand-handler.php:2551 and task-handler.php:690, and the same reason
        // the app login path reaches for a lone IN() instead of a profiles JOIN.
        // Every comparison below is column-vs-literal, so the two collations never meet.
        //
        // STATUS: 'active' + 'sold_out' only. A drop box (the #drop-{id} anchor target)
        // is rendered ONLY for status === 'active' (collections.php:3033-3038), and
        // 'paused' listings never reach the collection page at all (its query at
        // collections.php:1531 is active/sold_out). Including 'paused' would emit dead
        // anchors. 'sold_out' is included deliberately for discovery -- it lands on the
        // collection page and the front-end handler no-ops when the box is absent, which
        // also covers the render-time Open-Edition auto-close at collections.php:1612
        // that can flip an 'active' row to sold_out between this search and the page load.
        //
        // URL: ?issuer=&taxon= (collections.php:239-240 accepts it; renderCollectionItem()
        // already ships this form). imu_get_collection_slug() is deliberately NOT called
        // here -- on a cache miss it makes a 3s VPS round-trip, and 5 of those on a
        // 300ms-debounced typeahead would stall the dropdown for seconds.
        $response['listings'] = [];
        $cap_listings = 5;
        $s1_lst_tbl   = $wpdb->prefix . 'imc_listings';
        $s1_ap_tbl    = $wpdb->prefix . 'imc_artist_profiles';
        $s1_xp_tbl    = $wpdb->prefix . 'xaman_profiles';
        $s1_like      = '%' . $wpdb->esc_like($q) . '%';
        $s1_prefix    = $wpdb->esc_like($q) . '%';
        $s1_bl        = (isset($bl) && is_array($bl)) ? $bl : [];

        $s1_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, nft_name, nft_type, artist_account, artist_name,"
            . " collection_name, collection_taxon, cover_ipfs, status,"
            . " price_xrp, pricing_mode, edition_type, open_edition_closed_at"
            . " FROM $s1_lst_tbl"
            . " WHERE (nft_name LIKE %s OR collection_name LIKE %s)"
            . " AND status IN ('active','sold_out') AND is_hidden = 0"
            . " ORDER BY (status = 'active') DESC,"
            . " (nft_name LIKE %s) DESC,"
            . " (collection_name LIKE %s) DESC,"
            . " created_at DESC"
            . " LIMIT %d",
            $s1_like, $s1_like, $s1_prefix, $s1_prefix, ($cap_listings + 10)), ARRAY_A);

        if (!empty($s1_rows) && is_array($s1_rows)) {
            // Follow-up lookups: display names, keyed by artist_account.
            $s1_accts = [];
            foreach ($s1_rows as $s1_r) {
                $s1_a = trim((string)($s1_r['artist_account'] ?? ''));
                if ($s1_a !== '') { $s1_accts[$s1_a] = true; }
            }
            // Display names come from the profile tables because listings.artist_name is
            // populated on only a handful of rows. Same two sources, same precedence as
            // the users lane above. Both are IN (...) lists of LITERALS -- column-vs-literal,
            // so neither touches the collation hazard described at the top of this branch.
            $s1_names = [];   // imc_artist_profiles.display_name  (preferred)
            $s1_names2 = [];  // xaman_profiles.name               (fallback)
            if (!empty($s1_accts)) {
                $s1_keys = array_keys($s1_accts);
                $s1_ph   = implode(',', array_fill(0, count($s1_keys), '%s'));
                $s1_nrows = $wpdb->get_results($wpdb->prepare(
                    "SELECT artist_account, display_name FROM $s1_ap_tbl WHERE artist_account IN ($s1_ph)",
                    $s1_keys), ARRAY_A);
                foreach ((array)$s1_nrows as $s1_nr) {
                    $s1_dn = trim((string)($s1_nr['display_name'] ?? ''));
                    if ($s1_dn !== '') { $s1_names[$s1_nr['artist_account']] = $s1_dn; }
                }
                $s1_xrows = $wpdb->get_results($wpdb->prepare(
                    "SELECT xrpl_account, name FROM $s1_xp_tbl WHERE xrpl_account IN ($s1_ph)",
                    $s1_keys), ARRAY_A);
                foreach ((array)$s1_xrows as $s1_xr) {
                    $s1_xn = trim((string)($s1_xr['name'] ?? ''));
                    if ($s1_xn !== '') { $s1_names2[$s1_xr['xrpl_account']] = $s1_xn; }
                }
            }

            foreach ($s1_rows as $s1_r) {
                if (count($response['listings']) >= $cap_listings) break;

                $s1_acct = trim((string)($s1_r['artist_account'] ?? ''));
                if ($s1_acct === '' || isset($s1_bl[$s1_acct])) continue; // blacklisted creators excluded

                // Thumbnail: cover_ipfs is the per-NFT cover and is populated on every
                // active/sold_out row today. impSearchImg() on the client re-routes
                // /ipfs/ URLs through img.php, so this gateway form is the right output.
                $s1_img = trim((string)($s1_r['cover_ipfs'] ?? ''));
                if (strpos($s1_img, 'ipfs://') === 0) {
                    $s1_img = 'https://ipfs.io/ipfs/' . substr($s1_img, 7);
                } elseif ($s1_img !== '' && preg_match('/^(Qm|bafy)/', $s1_img)) {
                    $s1_img = 'https://ipfs.io/ipfs/' . $s1_img;
                }
                if ($s1_img === '') { $s1_img = '/wp-content/uploads/fallback-nft.svg'; }

                $s1_taxon = (int)($s1_r['collection_taxon'] ?? 0);
                $s1_url   = home_url('/collections/')
                          . '?issuer=' . rawurlencode($s1_acct)
                          . '&taxon=' . $s1_taxon
                          . '#drop-' . (int)$s1_r['id'];

                $s1_oe = ((($s1_r['edition_type'] ?? 'fixed') === 'open')
                          && empty($s1_r['open_edition_closed_at']));

                $response['listings'][] = [
                    'id'         => (int)$s1_r['id'],
                    'name'       => (string)($s1_r['nft_name'] ?: 'Untitled'),
                    // display_name -> listing's own artist_name -> xaman profile name -> short wallet
                    'artist'     => (string)($s1_names[$s1_acct]
                                        ?? (trim((string)($s1_r['artist_name'] ?? ''))
                                            ?: ($s1_names2[$s1_acct]
                                                ?? (substr($s1_acct, 0, 6) . '...' . substr($s1_acct, -4))))),
                    'collection' => (string)($s1_r['collection_name'] ?? ''),
                    'type'       => (string)($s1_r['nft_type'] ?? ''),
                    'image'      => $s1_img,
                    'url'        => $s1_url,
                    'status'     => (string)($s1_r['status'] ?? ''),
                    'price_xrp'  => (float)($s1_r['price_xrp'] ?? 0),
                    'dynamic'    => ((($s1_r['pricing_mode'] ?? 'static') === 'dynamic')),
                    'is_oe'      => $s1_oe,
                ];
            }
        }
        bh_log("Search drops: " . count($response['listings']) . " for '$q'");
        
        wp_send_json($response);
        break;

    // =========================================================================
    // SEARCH COLLECTIONS (legacy - kept for backwards compatibility)
    // =========================================================================
    case 'search_collections':
        $q = sanitize_text_field($_GET['q'] ?? '');
        $limit = min(20, max(5, absint($_GET['limit'] ?? 10)));
        if (strlen($q) < 2) wp_send_json(['success' => false, 'error' => 'Query too short'], 400);
        
        $result = bithomp_get('nft-collections', ['search' => $q, 'floorPrice' => 'true', 'limit' => $limit], 'bh_search_' . md5($q . $limit), 5 * MINUTE_IN_SECONDS);
        
        if (!$result['data']) wp_send_json(['success' => false, 'error' => 'Search failed'], 500);
        
        $collections = [];
        foreach (($result['data']['collections'] ?? []) as $c) {
            $floor = isset($c['floorPrices'][0]['open']['amount']) ? drops_to_xrp((float)$c['floorPrices'][0]['open']['amount']) : 0;
            $collections[] = [
                'issuer' => $c['issuer'] ?? '',
                'taxon' => (int)($c['taxon'] ?? 0),
                'name' => $c['name'] ?? 'Unknown',
                'floor_xrp' => $floor
            ];
        }
        
        $collections = $scam_filter_5($collections);
        wp_send_json(['success' => true, 'query' => $q, 'collections' => $collections]);
        break;

    default:
        wp_send_json(['success' => false, 'error' => 'Invalid action'], 400);
}