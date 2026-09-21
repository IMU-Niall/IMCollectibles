<?php
/**
 * File: filter-handler.php (Production Ready)
 * Path: /wp-content/themes/astra/xrpl-nft-marketplace/backend/filter-handler.php
 * Description: Fetches & filters NFTs for collections (featured or specific), with pagination & sorting.
 * Notes:
 * - Uses xumm-proxy for collection paging & metadata. Batch metadata fetch when possible.
 * - Requires nonce for requests originating from your frontend.
 */

require_once dirname(__DIR__, 5) . '/wp-load.php';
if (!defined('ABSPATH')) exit;

header('Content-Type: application/json');

// --- Logging ---
$log_dir  = __DIR__ . '/logs';
$log_file = $log_dir . '/filter-handler.log';
if (!is_dir($log_dir)) { @mkdir($log_dir, 0755, true); }
function fh_log($msg) {
    global $log_file;
    @file_put_contents($log_file, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

// --- Input / Defaults ---
$issuer         = sanitize_text_field($_GET['issuer'] ?? '');
$taxon          = isset($_GET['taxon']) ? intval($_GET['taxon']) : null;
$search         = sanitize_text_field($_GET['search'] ?? '');
$sort           = sanitize_text_field($_GET['sort'] ?? 'recent'); // recent | price-asc | price-desc | rarity
$open_to_trades = isset($_GET['open_to_trades']) && $_GET['open_to_trades'] === '1';
$limit          = max(1, min(48, intval($_GET['limit'] ?? 18)));
$offset         = max(0, intval($_GET['offset'] ?? 0));
$nonce          = sanitize_text_field($_GET['nonce'] ?? '');

if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
    fh_log('Invalid nonce');
    wp_send_json_error(['error' => 'Invalid nonce'], 403);
}

// Use site-relative proxy endpoint (aligned with other handlers)
$proxy_url   = home_url('/xumm-proxy.php');
$max_retries = 2;

// Featured collections fallback (adjust as needed)
$featured_collections = [
    ['issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR', 'taxon' => 0],
    ['issuer' => 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga', 'taxon' => 717825],
    ['issuer' => 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt', 'taxon' => 1056369418],
    ['issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR', 'taxon' => 777],
];

// --- Helpers ---
function fh_fetch_collection_page($issuer, $taxon, $proxy_url, $limit, $offset) {
    $url = add_query_arg([
        'issuer' => $issuer,
        'taxon'  => $taxon,
        'limit'  => $limit,
        'offset' => $offset,
        't'      => time()
    ], $proxy_url);

    $res = wp_remote_get($url, ['timeout' => 18, 'headers' => ['Accept' => 'application/json']]);
    if (is_wp_error($res)) return ['nfts' => [], 'count' => 0];

    $body = json_decode(wp_remote_retrieve_body($res), true);
    if (!is_array($body)) return ['nfts' => [], 'count' => 0];

    // Expecting a structure like: ['result' => ['nfts' => [...]]]
    $nfts  = $body['result']['nfts'] ?? [];
    $count = $body['result']['count'] ?? 0;
    return ['nfts' => $nfts, 'count' => $count];
}

function fh_fetch_all_collection($issuer, $taxon, $proxy_url, $batch = 100, $max_pages = 30) {
    $all = [];
    $offset = 0;
    for ($i = 0; $i < $max_pages; $i++) {
        $page = fh_fetch_collection_page($issuer, $taxon, $proxy_url, $batch, $offset);
        $batch_nfts = $page['nfts'];
        if (!$batch_nfts) break;
        $all = array_merge($all, $batch_nfts);
        if (count($batch_nfts) < $batch) break;
        $offset += $batch;
    }
    return $all;
}

// --- Caching keys ---
$transient_key = 'nft_filter_' . md5(serialize([$issuer, $taxon, $search, $sort, $open_to_trades, $limit, $offset]));

// --- Serve from cache if available ---
if (($cached = get_transient($transient_key)) !== false) {
    echo wp_json_encode($cached);
    exit;
}

try {
    // Gather NFTs set
    $all_nfts = [];

    if ($issuer && $taxon !== null) {
        $cache_key = 'full_collection_' . md5($issuer . '|' . $taxon);
        $all_nfts = get_transient($cache_key);
        if ($all_nfts === false) {
            $all_nfts = fh_fetch_all_collection($issuer, $taxon, $proxy_url);
            set_transient($cache_key, $all_nfts, 10 * MINUTE_IN_SECONDS);
        }
    } else {
        foreach ($featured_collections as $c) {
            $cache_key = 'full_collection_' . md5($c['issuer'] . '|' . $c['taxon']);
            $col = get_transient($cache_key);
            if ($col === false) {
                $col = fh_fetch_all_collection($c['issuer'], $c['taxon'], $proxy_url);
                set_transient($cache_key, $col, 10 * MINUTE_IN_SECONDS);
            }
            $all_nfts = array_merge($all_nfts, $col);
        }
    }

    $nfts = $all_nfts;

    // --- Search filter (best-effort metadata query via proxy per batch) ---
    if ($search !== '') {
        $search_lc = mb_strtolower($search);
        $nfts = array_filter($nfts, function($nft) use ($search_lc, $proxy_url, $max_retries) {
            $id = $nft['NFTokenID'] ?? '';
            if (!$id) return false;

            $meta_key = 'nft_meta_single_' . md5($id);
            $metadata = get_transient($meta_key);

            if ($metadata === false) {
                for ($a = 0; $a < $max_retries; $a++) {
                    $meta_url = add_query_arg(['refresh_metadata' => $id, 't' => time()], $proxy_url);
                    $res = wp_remote_get($meta_url, ['timeout' => 12]);
                    if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
                        $body = json_decode(wp_remote_retrieve_body($res), true);
                        if (!empty($body['success']) && !empty($body['metadata'])) {
                            $metadata = $body['metadata'];
                            set_transient($meta_key, $metadata, 2 * HOUR_IN_SECONDS);
                            break;
                        }
                    }
                }
            }

            $metadata = $metadata ?: ['name' => '', 'description' => ''];
            $hay = mb_strtolower(($metadata['name'] ?? '') . ' ' . ($metadata['description'] ?? ''));
            return strpos($hay, $search_lc) !== false;
        });
    }

    // --- Optional: open_to_trades filter (may be heavy; cap the checks) ---
    if ($open_to_trades) {
        $max_check = 60; // safety cap
        $checked = 0;
        $nfts = array_values(array_filter($nfts, function($nft) use ($proxy_url, &$checked, $max_check) {
            if ($checked >= $max_check) return false;
            $checked++;
            $id = $nft['NFTokenID'] ?? '';
            if (!$id) return false;
            $url = add_query_arg(['check_offers' => $id, 't' => time()], $proxy_url);
            $res = wp_remote_get($url, ['timeout' => 12]);
            if (is_wp_error($res)) return false;
            $body = json_decode(wp_remote_retrieve_body($res), true);
            return !empty($body['success']) && !empty($body['offers']);
        }));
    }

    // --- Sorting (price queries can be heavy; we cap checks as well) ---
    if ($sort === 'price-asc' || $sort === 'price-desc') {
        $max_check = 60;
        $checked = 0;
        $with_price = [];
        foreach ($nfts as $n) {
            if ($checked >= $max_check) break;
            $id = $n['NFTokenID'] ?? '';
            if (!$id) continue;
            $url = add_query_arg(['check_offers' => $id, 't' => time()], $proxy_url);
            $res = wp_remote_get($url, ['timeout' => 12]);
            $checked++;
            if (!is_wp_error($res)) {
                $body = json_decode(wp_remote_retrieve_body($res), true);
                if (!empty($body['success']) && !empty($body['offers'])) {
                    // Choose a price field. Assuming 'amount' numeric/drops already normalized by proxy
                    $amounts = array_column($body['offers'], 'amount');
                    $n['current_price'] = is_array($amounts) && count($amounts) ? min($amounts) : null;
                    if ($n['current_price'] !== null) $with_price[] = $n;
                }
            }
        }
        usort($with_price, function($a, $b) use ($sort) {
            $pa = $a['current_price'] ?? PHP_INT_MAX;
            $pb = $b['current_price'] ?? PHP_INT_MAX;
            return ($sort === 'price-asc') ? ($pa <=> $pb) : ($pb <=> $pa);
        });
        $nfts = $with_price;
    } elseif ($sort === 'rarity') {
        $nfts = array_values(array_filter($nfts, function($n) {
            return isset($n['metadata']['rarity_score']) && is_numeric($n['metadata']['rarity_score']);
        }));
        usort($nfts, function($a, $b) {
            $ra = $a['metadata']['rarity_score'] ?? 0;
            $rb = $b['metadata']['rarity_score'] ?? 0;
            return $rb <=> $ra;
        });
    } else {
        // Default: 'recent' - use listed_at if present, else leave order as-is
        usort($nfts, function($a, $b) {
            $ta = intval($a['listed_at'] ?? 0);
            $tb = intval($b['listed_at'] ?? 0);
            return $tb <=> $ta;
        });
    }

    $total = count($nfts);
    $page_slice = array_slice($nfts, $offset, $limit);

    // --- Batch metadata resolve for page slice ---
    $ids = array_values(array_filter(array_map(fn($n) => $n['NFTokenID'] ?? null, $page_slice)));
    $meta_cache = [];
    $to_fetch = [];

    foreach ($ids as $id) {
        $k = 'nft_meta_' . $id;
        $m = get_transient($k);
        if ($m !== false && is_array($m)) {
            $meta_cache[$id] = $m;
        } else {
            $to_fetch[] = $id;
        }
    }

    if ($to_fetch) {
        $batch_key = 'bithomp_nft_batch_' . md5(implode(',', $to_fetch));
        $batch = get_transient($batch_key);
        if ($batch === false) {
            $meta_url = add_query_arg(['ids' => implode(',', $to_fetch), 't' => time()], $proxy_url);
            $res = wp_remote_get($meta_url, ['timeout' => 15]);
            if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
                $body = json_decode(wp_remote_retrieve_body($res), true);
                if (!empty($body['success']) && !empty($body['nfts'])) {
                    $batch = [];
                    foreach ($body['nfts'] as $mn) {
                        $nid = $mn['nftokenID'] ?? ($mn['NFTokenID'] ?? null);
                        if (!$nid) continue;
                        $m = $mn['metadata'] ?? [];
                        if (!empty($m)) {
                            set_transient('nft_meta_' . $nid, $m, DAY_IN_SECONDS);
                            $meta_cache[$nid] = $m;
                            $batch[$nid] = $m;
                        }
                    }
                    set_transient($batch_key, $batch, HOUR_IN_SECONDS);
                }
            }
        } else {
            foreach ($batch as $nid => $m) {
                if ($m) $meta_cache[$nid] = $m;
            }
        }
    }

    // Normalize output
    $out = array_map(function($n) use ($issuer, $meta_cache) {
        $id  = $n['NFTokenID'] ?? ('unknown_' . wp_generate_uuid4());
        $m   = $meta_cache[$id] ?? ($n['metadata'] ?? []);
        $img = $m['image'] ?? '';
        if (empty($img) || !preg_match('#^https?://#', $img)) {
            $img = '/wp-content/uploads/fallback-nft.svg';
        }
        return [
            'nftokenID'       => $id,
            'issuer'          => $n['Issuer'] ?? ($issuer ?: ''),
            'taxon'           => intval($n['NFTokenTaxon'] ?? 0),
            'metadata'        => [
                'name'        => $m['name'] ?? 'Unnamed NFT',
                'image'       => $img,
                'description' => $m['description'] ?? '',
                'attributes'  => $m['attributes'] ?? []
            ],
            'owner'           => $n['owner'] ?? '',
            'royalty_percent' => $n['royalty_percent'] ?? 0,
            'creator_wallet'  => $n['Issuer'] ?? ($issuer ?: ''),
            'current_price'   => $n['current_price'] ?? null
        ];
    }, $page_slice);

    $payload = [
        'success'  => true,
        'nfts'     => array_values($out),
        'total'    => $total,
        'page'     => (int) floor($offset / $limit) + 1,
        'per_page' => $limit
    ];

    set_transient($transient_key, $payload, 5 * MINUTE_IN_SECONDS);
    echo wp_json_encode($payload);
} catch (Exception $e) {
    fh_log('Error: ' . $e->getMessage());
    wp_send_json_error([
        'error'     => 'Failed to fetch filtered NFTs',
        'nfts'      => [],
        'total'     => 0,
        'page'      => (int) floor($offset / $limit) + 1,
        'per_page'  => $limit
    ], 500);
}
exit;
