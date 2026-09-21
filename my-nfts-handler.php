<?php
/**
 * File: my-nfts-handler.php (v4 - BULLETPROOF)
 * Path: /wp-content/themes/astra/xrpl-nft-marketplace/backend/my-nfts-handler.php
 * 
 * Shows ALL NFTs: IMU collections first, then other collections.
 */

// BULLETPROOF: Start output buffering and error handling FIRST
ob_start();

// Custom error handler to capture errors as JSON
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    // Find wp-load.php
    $wp_load_paths = [
        dirname(__DIR__, 5) . '/wp-load.php',
        dirname(__DIR__, 4) . '/wp-load.php',
        dirname(__DIR__, 3) . '/wp-load.php',
        $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php',
    ];
    
    $wp_loaded = false;
    foreach ($wp_load_paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $wp_loaded = true;
            break;
        }
    }
    
    if (!$wp_loaded || !defined('ABSPATH')) {
        throw new Exception('WordPress not found');
    }
    
} catch (Exception $e) {
    // Clear any output
    while (ob_get_level()) ob_end_clean();
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');
    echo json_encode([
        'success' => false,
        'error' => 'WordPress load failed: ' . $e->getMessage(),
        'debug' => [
            'dir' => __DIR__,
            'tried_paths' => $wp_load_paths ?? []
        ]
    ]);
    exit;
}

// Clear ALL output WordPress may have generated
while (ob_get_level()) ob_end_clean();

// Now set JSON headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');

// Restore default error handler
restore_error_handler();

// Validate nonce for security
$nonce = sanitize_text_field($_GET['nonce'] ?? $_POST['nonce'] ?? '');
if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
    status_header(403);
    echo json_encode(['success' => false, 'error' => 'Invalid or missing security nonce']);
    exit;
}

// IMU Collection definitions - MUST MATCH page-my-nfts.php!
$IMU_COLLECTIONS = [
    'guardians' => [
        'name' => 'Guardians of the Frequencies',
        'short_name' => 'Guardians',
        'issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR',
        'taxon' => 0
    ],
    'frequencies' => [
        'name' => 'Protectors of the Frequencies',
        'short_name' => 'Frequencies',
        'issuer' => 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga',
        'taxon' => 717825
    ],
    'ledger' => [
        'name' => 'Protectors of the Ledger',
        'short_name' => 'Ledger',
        'issuer' => 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt',
        'taxon' => 1056369418
    ],
    'lasvegas' => [
        'name' => 'Protectors of Las Vegas',
        'short_name' => 'Las Vegas',
        'issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR',
        'taxon' => 777
    ],
    'firepit' => [
        'name' => 'Firepit Protectors',
        'short_name' => 'Firepit',
        'issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR',
        'taxon' => 666
    ],
    'special' => [
        'name' => 'Special Edition',
        'short_name' => 'Special',
        'issuer' => 'rHsrif6nHTkmyh38W7JmYjairPWhq5P3AH',
        'taxon' => 0
    ]
];

// Helper: Send JSON error
function mn_json_error($msg, $code = 400) {
    status_header($code);
    wp_send_json(['success' => false, 'error' => $msg]);
}

// Helper: Match NFT to collection
function mn_get_collection_key($nft, $collections) {
    $issuer = $nft['Issuer'] ?? '';
    $taxon = intval($nft['NFTokenTaxon'] ?? -1);
    
    foreach ($collections as $key => $col) {
        if ($col['issuer'] === $issuer && $col['taxon'] === $taxon) {
            return $key;
        }
    }
    return null;
}

// Helper: Make XRPL RPC call
function mn_xrpl_call($method, $params = []) {
    $endpoints = [
        'https://xrplcluster.com/',
        'https://s1.ripple.com:51234/',
        'https://s2.ripple.com:51234/'
    ];
    
    $payload = json_encode([
        'method' => $method,
        'params' => [$params]
    ]);
    
    foreach ($endpoints as $endpoint) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['result'])) {
                return $data['result'];
            }
        }
    }
    
    return null;
}

// Helper: Get collection image using same function as other pages
function mn_get_collection_image($key) {
    // Use hardcoded fallback images (faster and more reliable than WordPress function)
    $fallbacks = [
        'guardians' => 'https://images.imcollectibles.io/guardians/guardian-1.png',
        'frequencies' => 'https://images.imcollectibles.io/frequencies/protector-1.png',
        'ledger' => 'https://images.imcollectibles.io/ledger/protector-1.png',
        'lasvegas' => 'https://imcollectibles.io/wp-content/uploads/lasvegas-fallback.jpg',
        'firepit' => 'https://imcollectibles.io/wp-content/uploads/2025/04/firepit-logo.png',
        'special' => 'https://imcollectibles.io/wp-content/uploads/2025/03/IMU-Special.png'
    ];
    
    return $fallbacks[$key] ?? '/wp-content/uploads/fallback-nft.svg';
}

// Helper: Fetch collection metadata from WordPress cache or VPS
function mn_get_other_collection_meta($issuer, $taxon) {
    global $wpdb;
    
    // v61 FIX: FIRST check IMC listings table for newly created collections
    $imc_listings = $wpdb->prefix . 'imc_listings';
    $imc_exists = $wpdb->get_var("SHOW TABLES LIKE '$imc_listings'") === $imc_listings;
    
    if ($imc_exists) {
        $imc_col = $wpdb->get_row($wpdb->prepare(
            "SELECT collection_name, cover_ipfs 
             FROM $imc_listings 
             WHERE artist_account = %s AND collection_taxon = %d 
             LIMIT 1",
            $issuer, $taxon
        ), ARRAY_A);
        
        if ($imc_col && !empty($imc_col['collection_name'])) {
            $cover = $imc_col['cover_ipfs'] ?? '';
            $img = null;
            if ($cover) {
                $cid = preg_replace('#^ipfs://#', '', $cover);
                $img = 'https://metadata.imcollectibles.io/img.php?url=' . urlencode('ipfs://' . $cid);
            }
            return [
                'name' => $imc_col['collection_name'],
                'image' => $img
            ];
        }
    }
    
    // Then try WordPress collection cache table
    $table_name = $wpdb->prefix . 'xumm_collection_cache';
    
    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    
    if ($table_exists) {
        $cached = $wpdb->get_row($wpdb->prepare(
            "SELECT name, image_url FROM $table_name WHERE issuer = %s AND taxon = %d",
            $issuer, $taxon
        ), ARRAY_A);
        
        if ($cached && !empty($cached['name'])) {
            return [
                'name' => $cached['name'],
                'image' => $cached['image_url'] ?: null
            ];
        }
    }
    
    // Fallback: Try VPS indexer for collection info
    $vps_url = 'https://metadata.imcollectibles.io/?action=collection&issuer=' . urlencode($issuer) . '&taxon=' . $taxon;
    
    $ch = curl_init($vps_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if ($data && isset($data['collection'])) {
            $col = $data['collection'];
            return [
                'name' => $col['name'] ?? null,
                'image' => $col['image'] ?? null
            ];
        }
    }
    
    return ['name' => null, 'image' => null];
}

// Helper: Fetch metadata from VPS Indexer (FAST - already indexed)
function mn_fetch_from_vps($nftokenID) {
    if (empty($nftokenID)) return null;
    
    // Use the VPS indexer API
    $vps_url = 'https://metadata.imcollectibles.io/?action=nft&id=' . urlencode($nftokenID);
    
    $ch = curl_init($vps_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3, // Fast timeout - it's cached
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if ($data && !isset($data['error']) && isset($data['name'])) {
            // Normalize to standard metadata format
            $image = $data['image_resolved'] ?? $data['image_url'] ?? $data['image'] ?? null;
            if ($image && strpos($image, 'ipfs://') === 0) {
                $image = 'https://ipfs.io/ipfs/' . substr($image, 7);
            }
            return [
                'name' => $data['name'] ?? 'Unnamed NFT',
                'description' => $data['description'] ?? '',
                'image' => $image,
                'animation_url' => $data['animation_resolved'] ?? $data['animation_url'] ?? null,
                'collection' => $data['collection_name'] ?? $data['collection'] ?? null,
                'attributes' => is_string($data['attributes'] ?? null) 
                    ? json_decode($data['attributes'], true) 
                    : ($data['attributes'] ?? [])
            ];
        }
    }
    
    return null;
}

// Helper: Fetch metadata LIVE (fallback - slower, for non-indexed NFTs)
function mn_fetch_metadata_live($uri) {
    if (empty($uri)) return null;
    
    // Convert IPFS to gateway URL
    if (strpos($uri, 'ipfs://') === 0) {
        $uri = 'https://ipfs.io/ipfs/' . substr($uri, 7);
    }
    
    // Decode hex URIs
    if (preg_match('/^[0-9A-Fa-f]+$/', $uri) && strlen($uri) > 20) {
        $decoded = @hex2bin($uri);
        if ($decoded && strpos($decoded, 'http') === 0) {
            $uri = $decoded;
        } elseif ($decoded && strpos($decoded, 'ipfs://') === 0) {
            $uri = 'https://ipfs.io/ipfs/' . substr($decoded, 7);
        } else {
            // Try VPS metadata endpoint for hex
            $uri = 'https://metadata.imcollectibles.io/nft/' . $uri;
        }
    }
    
    $ch = curl_init($uri);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $meta = json_decode($response, true);
        if ($meta) {
            // Normalize image field
            if (isset($meta['image']) && strpos($meta['image'], 'ipfs://') === 0) {
                $meta['image'] = 'https://ipfs.io/ipfs/' . substr($meta['image'], 7);
            }
            return $meta;
        }
    }
    
    return null;
}

// Helper: Smart metadata fetch - VPS first (fast), then live fallback
function mn_fetch_metadata($nftokenID, $uri = null, $is_imu = false) {
    // ALWAYS try VPS first - it's fast and covers all indexed NFTs
    $meta = mn_fetch_from_vps($nftokenID);
    if ($meta) return $meta;
    
    // Fallback to live fetch for non-indexed NFTs
    if ($uri) {
        return mn_fetch_metadata_live($uri);
    }
    
    return null;
}

// Main handler
$action = $_GET['action'] ?? '';

/**
 * Phase 4C: scam-issuer set + collection filter (own copies - separate endpoint scope).
 * 30-min transient; kill-switch define('IMC_SCAM_FILTER_DISABLED', true); fail-open.
 * Only applied to discovery/browse collection lists - never to a user's own holdings.
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
if (!function_exists('imc_filter_scam_collections')) {
function imc_filter_scam_collections($arr) {
    if (!is_array($arr)) return $arr;
    $scam = imc_get_scam_issuers();
    if (empty($scam)) return $arr;
    return array_values(array_filter($arr, function($c) use ($scam) {
        return !in_array(strtolower((string)($c['issuer'] ?? '')), $scam);
    }));
}
}

// v534/P6A: FAST Access NFTs — DB-only (imc_purchases by buyer_account). No XRPL / no Bithomp.
// Returns the exact object shape the My-NFTs frontend builds for _imcNftObjects so the Access
// tab can render instantly. The ledger-first get_my_nfts then runs in the background to
// reconcile (add received/transferred NFTs, prune sold ones, populate the All tab + counts).
if ($action === 'get_access_nfts') {
    global $wpdb;
    // ========================================================================
    // IDENTITY. These are the caller's own purchased access NFTs. Taken from
    // the session; ?account= is a public address and proves nothing. With no
    // session this returns an empty set rather than a 403, so a logged-out
    // visitor sees an empty shelf instead of someone else's.
    // ========================================================================
    $mn_auth = function_exists('imc_session_require_wallet')
        ? imc_session_require_wallet('')
        : array('ok' => false, 'wallet' => '');
    $account = !empty($mn_auth['ok']) ? (string) $mn_auth['wallet'] : '';
    if ($account === '' || !preg_match('/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/', $account)) {
        wp_send_json(['success' => true, 'access_nfts' => []]);
    }
    $p_table = $wpdb->prefix . 'imc_purchases';
    $l_table = $wpdb->prefix . 'imc_listings';
    $access = [];
    if ($wpdb->get_var("SHOW TABLES LIKE '$p_table'") === $p_table) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.nftoken_id   AS nid,
                    p.listing_id,
                    l.nft_name,
                    l.cover_ipfs,
                    l.artist_name,
                    l.artist_account,
                    l.collection_taxon,
                    l.nft_type
               FROM $p_table p
               LEFT JOIN $l_table l ON p.listing_id = l.id
              WHERE p.buyer_account = %s
                AND p.mint_status IN ('minted','claimed')
                AND p.nftoken_id IS NOT NULL
                AND p.nftoken_id != ''",
            $account
        ), ARRAY_A) ?: [];
        foreach ($rows as $r) {
            if (empty($r['nid'])) { continue; }
            $access[] = [
                'nftokenID'             => $r['nid'],
                'is_imc_purchase'       => true,
                'is_received_transfer'  => false,
                'imc_listing'           => [
                    'listing_id'       => intval($r['listing_id'] ?? 0),
                    'nft_name'         => $r['nft_name'] ?? '',
                    'cover_ipfs'       => $r['cover_ipfs'] ?? '',
                    'artist_name'      => (function_exists('imc_resolve_artist_name') ? imc_resolve_artist_name($r['artist_account'] ?? '', $r['artist_name'] ?? '', '') : ($r['artist_name'] ?? '')),
                    'collection_taxon' => intval($r['collection_taxon'] ?? 0),
                    'nft_type'         => $r['nft_type'] ?? '',
                    'is_received'      => false,
                ],
            ];
        }
    }
    wp_send_json(['success' => true, 'access_nfts' => $access]);
}

// ACTION: Get metadata for NFTs (proxy to VPS - avoids CORS)
if ($action === 'get_metadata') {
    // Read POST body
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    $nft_ids = $data['ids'] ?? [];
    
    // Validate
    if (empty($nft_ids) || !is_array($nft_ids)) {
        mn_json_error('No NFT IDs provided');
    }
    
    // Limit to prevent abuse
    $nft_ids = array_slice($nft_ids, 0, 500);
    
    $metadata = [];

    // ── v284: LOCAL DB FIRST ─────────────────────────────────────────────────
    // For IMC-minted NFTs (nftoken_id in imc_purchases), serve from local DB
    // without any external HTTP call. This is faster, more reliable on mobile,
    // and avoids unnecessary VPS/Pinata round-trips for content we already have.
    $imc_p = $wpdb->prefix . 'imc_purchases';
    $imc_l = $wpdb->prefix . 'imc_listings';
    $imc_t = $wpdb->prefix . 'imc_listing_tiers';

    if ($wpdb->get_var("SHOW TABLES LIKE '$imc_p'") === $imc_p && !empty($nft_ids)) {
        $upper_ids  = array_map('strtoupper', $nft_ids);
        $ph         = implode(',', array_fill(0, count($upper_ids), '%s'));
        $local_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.nftoken_id, p.edition_number, p.tier_id,
                        l.nft_name,
                        l.cover_ipfs  AS listing_cover,
                        l.preview_ipfs AS listing_preview,
                        t.cover_ipfs  AS tier_cover,
                        t.tier_name,
                        t.preview_ipfs AS tier_preview
                 FROM $imc_p p
                 JOIN $imc_l l ON p.listing_id = l.id
                 LEFT JOIN $imc_t t ON p.tier_id = t.id
                 WHERE UPPER(p.nftoken_id) IN ($ph)
                   AND p.mint_status IN ('minted','claimed')",
                ...$upper_ids
            ),
            ARRAY_A
        ) ?: [];

        foreach ($local_rows as $row) {
            $nft_id = strtoupper($row['nftoken_id']);

            // Build human-readable name
            $name = $row['nft_name'] ?? 'NFT';
            if (!empty($row['edition_number'])) $name .= ' #' . $row['edition_number'];
            if (!empty($row['tier_name']))       $name .= ' (' . $row['tier_name'] . ')';

            // Image: use VPS img proxy with the cover IPFS CID we own directly.
            // v285b FIX: Use img.php?url=ipfs://CID instead of img.php?nft=TOKEN_ID.
            // The ?nft= endpoint looks up the CID from the VPS SQLite indexer — which
            // hasn't scanned newly minted IMC NFTs yet, returning "Indexing in progress".
            // The ?url= endpoint bypasses the indexer entirely: it fetches from our own
            // cover_ipfs CID (stored in imc_listings/imc_listing_tiers), caches it on
            // the VPS by URL hash, and serves it instantly on subsequent loads.
            $cover = $row['tier_cover'] ?? $row['listing_cover'] ?? '';
            $image = null;
            if ($cover) {
                // Strip any existing ipfs:// prefix then rebuild cleanly
                $cover_cid = preg_replace('#^ipfs://#', '', trim($cover));
                if ($cover_cid) {
                    $image = 'https://metadata.imcollectibles.io/img.php?url=' .
                             urlencode('ipfs://' . $cover_cid);
                }
            }

            // Preview/animation URL via Cloudflare IPFS
            $preview = $row['tier_preview'] ?? $row['listing_preview'] ?? '';
            $animation_url = null;
            if ($preview) {
                $cid = preg_replace('#^ipfs://#', '', $preview);
                $animation_url = 'https://cloudflare-ipfs.com/ipfs/' . $cid;
            }

            $metadata[$nft_id] = [
                'name'          => $name,
                'description'   => '',
                'image'         => $image,
                'animation_url' => $animation_url,
                'is_local'      => true,
            ];
        }
        error_log('v284 local DB: Served ' . count($local_rows) . '/' . count($nft_ids) . ' NFTs from imc_purchases');
    }
    // ── END LOCAL DB FIRST ───────────────────────────────────────────────────

    // Only call VPS for NFTs not already resolved locally
    $remaining_ids = array_values(array_filter($nft_ids, fn($id) => !isset($metadata[strtoupper($id)])));

    // VPS batch endpoint limits to 100, so chunk if needed
    $chunks = array_chunk($remaining_ids, 100);

    foreach ($chunks as $chunk) {
        $vps_url = 'https://metadata.imcollectibles.io/?action=batch';
        
        $ch = curl_init($vps_url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            // P1g (Aug 2026): any-mode - pending rows return with owner + image_proxy.
            // Post-1d-2 there is NO competing enrichment for these ids (the Bithomp
            // loop is gone; the fast loop success-guards), so facts + proxy image
            // beat the historic nothing. Server machinery live since P1b.
            CURLOPT_POSTFIELDS => json_encode(['ids' => $chunk, 'any' => '1']),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            $vps_data = json_decode($response, true);
            $nfts_array = $vps_data['nfts'] ?? $vps_data['results'] ?? [];
            
            // Debug: log first NFT structure
            if (!empty($nfts_array) && empty($metadata)) {
                error_log('VPS batch first NFT structure: ' . json_encode(array_keys($nfts_array[0] ?? [])));
                error_log('VPS batch first NFT: ' . json_encode($nfts_array[0] ?? []));
            }
            
            if (is_array($nfts_array)) {
                foreach ($nfts_array as $nft) {
                    // Get NFT ID - check multiple possible field names
                    $nft_id = $nft['nftokenID'] ?? $nft['nft_token_id'] ?? $nft['NFTokenID'] ?? null;
                    if (!$nft_id) continue;
                    
                    // VPS returns nested metadata structure!
                    $nft_meta = $nft['metadata'] ?? [];
                    
                    // Extract image - check nested first, then top level
                    $image = $nft_meta['image'] ?? $nft['image_resolved'] ?? $nft['image_url'] ?? $nft['image'] ?? null;
                    if ($image && strpos($image, 'ipfs://') === 0) {
                        $image = 'https://ipfs.io/ipfs/' . substr($image, 7);
                    }
                    
                    // Extract name - check nested first, then top level
                    $name = $nft_meta['name'] ?? $nft['name'] ?? 'Unnamed NFT';
                    
                    // v350 FIX: Preserve image_proxy from VPS response (was silently dropped).
                    // burn-to-earn.js Priority 2 image resolution depends on this field.
                    $image_proxy = $nft['image_proxy'] ?? null;

                    $metadata[$nft_id] = [
                        'name'          => $name,
                        'description'   => $nft_meta['description'] ?? $nft['description'] ?? '',
                        'image'         => $image,
                        'image_proxy'   => $image_proxy,
                        'animation_url' => $nft_meta['animation_url'] ?? $nft['animation_resolved'] ?? $nft['animation_url'] ?? null
                    ];
                }
            }
        }
    }
    
    // v62 FIX: For any NFTs not found in VPS OR local DB, try local imc_purchases again
    // (catches edge cases like NFTs minted but not yet status='minted'/'claimed')
    $missing_ids = array_diff($nft_ids, array_map('strtolower', array_keys($metadata)));
    // Also try uppercase comparison
    $metadata_upper = array_combine(array_map('strtoupper', array_keys($metadata)), array_values($metadata));
    $missing_ids = array_filter($nft_ids, fn($id) => !isset($metadata_upper[strtoupper($id)]));
    $missing_ids = array_values($missing_ids);
    if (!empty($missing_ids)) {
        $imc_p = $wpdb->prefix . 'imc_purchases';
        $imc_l = $wpdb->prefix . 'imc_listings';
        $imc_t = $wpdb->prefix . 'imc_listing_tiers';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$imc_p'") === $imc_p) {
            $placeholders = implode(',', array_fill(0, count($missing_ids), '%s'));
            $local_nfts = $wpdb->get_results($wpdb->prepare(
                "SELECT p.nftoken_id, p.edition_number, p.tier_id, p.metadata_ipfs,
                        l.nft_name, l.cover_ipfs AS listing_cover, l.preview_ipfs AS listing_preview,
                        t.cover_ipfs AS tier_cover, t.tier_name, t.preview_ipfs AS tier_preview
                 FROM $imc_p p
                 JOIN $imc_l l ON p.listing_id = l.id
                 LEFT JOIN $imc_t t ON p.tier_id = t.id
                 WHERE p.nftoken_id IN ($placeholders)
                   AND p.mint_status IN ('minted', 'claimed')",
                array_values($missing_ids)
            ), ARRAY_A);
            
            foreach ($local_nfts as $nft) {
                $nft_id = $nft['nftoken_id'];
                
                // Use tier cover if available, else listing cover
                $cover = $nft['tier_cover'] ?? $nft['listing_cover'] ?? '';
                $image = null;
                if ($cover) {
                    $cid = preg_replace('#^ipfs://#', '', trim($cover));
                    // v285b FIX: Use img.php?url=ipfs://CID — bypasses the VPS SQLite indexer.
                    // The ?nft= endpoint served "Indexing in progress" for newly minted IMC NFTs
                    // because the background indexer hadn't scanned them yet.
                    // ?url= fetches from our known cover_ipfs CID directly, caches by URL hash.
                    if ($cid) {
                        $image = 'https://metadata.imcollectibles.io/img.php?url=' .
                                 urlencode('ipfs://' . $cid);
                    }
                }
                
                // Build name with edition
                $name = $nft['nft_name'] ?? 'NFT';
                if ($nft['edition_number']) {
                    $name .= ' #' . $nft['edition_number'];
                }
                if ($nft['tier_name']) {
                    $name .= ' (' . $nft['tier_name'] . ')';
                }
                
                // Preview URL - tier first, then listing
                $preview = $nft['tier_preview'] ?? $nft['listing_preview'] ?? '';
                $animation_url = null;
                if ($preview) {
                    $cid = preg_replace('#^ipfs://#', '', $preview);
                    // v283: Cloudflare IPFS for preview — faster than Pinata on mobile
                    $animation_url = "https://cloudflare-ipfs.com/ipfs/$cid";
                }
                
                $metadata[$nft_id] = [
                    'name' => $name,
                    'description' => '',
                    'image' => $image,
                    'animation_url' => $animation_url,
                    'is_local' => true
                ];
            }
            
            error_log("v62: Loaded " . count($local_nfts) . " NFTs from local DB (missing from VPS)");
        }
    }

    // v414/P2: BITHOMP BATCH FALLBACK — resolve NFTs still missing after DB + VPS.
    // For non-platform NFTs not yet in our VPS indexer (~75% of XRPL), Bithomp
    // is the most reliable metadata source. Uses 24hr transient cache per NFT
    // and max 15 API calls per request (progressive enrichment).
    $metadata_upper = array_combine(
        array_map('strtoupper', array_keys($metadata)),
        array_values($metadata)
    );
    $still_missing = array_values(array_filter($nft_ids, fn($id) => !isset($metadata_upper[strtoupper($id)])));

    if (!empty($still_missing)) {
        $bithomp_key = defined('BITHOMP_API_KEY') ? BITHOMP_API_KEY : '';
        if ($bithomp_key) {
            $bh_calls = 0;
            $bh_max   = 15;
            $bh_hits  = 0;

            foreach ($still_missing as $bh_id) {
                // Check 24hr cache first (no API call)
                $bh_ck = 'bh_meta_' . substr(md5($bh_id), 0, 12);
                $bh_cached = get_transient($bh_ck);
                if ($bh_cached !== false && is_array($bh_cached)) {
                    $metadata[$bh_id] = $bh_cached;
                    $bh_hits++;
                    continue;
                }

                if ($bh_calls >= $bh_max) continue;

                // P1d-2 (Aug 2026): store-first via the bounded fast miss-path
                // (write-back self-heals). Old call sent assets=true - free-tier
                // dead - so every one of these 15 slots was a guaranteed 6s miss.
                $bh_url = 'https://metadata.imcollectibles.io/?action=get&id=' . rawurlencode($bh_id) . '&fetch=fast';
                $bh_res = wp_remote_get($bh_url, [
                    'headers' => ['Accept' => 'application/json'],
                    'timeout' => 6
                ]);
                $bh_calls++;

                if (!is_wp_error($bh_res) && wp_remote_retrieve_response_code($bh_res) === 200) {
                    $bh_body = json_decode(wp_remote_retrieve_body($bh_res), true);
                    if (!empty($bh_body['success']) && !empty($bh_body['nft'])
                        && (empty($bh_body['nft']['decode_status']) || $bh_body['nft']['decode_status'] === 'success')) {
                        $vfn = $bh_body['nft'];
                        $bh_meta_arr = $vfn['metadata'] ?? [];
                        // img.php proxy first - resolves internally, never renders broken.
                        $bh_img = $vfn['image_proxy'] ?? $vfn['image_resolved'] ?? $vfn['image_url'] ?? ($bh_meta_arr['image'] ?? null);
                        if ($bh_img && strpos($bh_img, 'ipfs://') === 0) {
                            $bh_img = 'https://ipfs.io/ipfs/' . substr($bh_img, 7);
                        }
                        $bh_m = [
                            'name'          => $bh_meta_arr['name'] ?? 'Unnamed NFT',
                            'description'   => $bh_meta_arr['description'] ?? '',
                            'image'         => $bh_img,
                            'animation_url' => $bh_meta_arr['animation_url'] ?? $vfn['media_url'] ?? null,
                        ];
                        $metadata[$bh_id] = $bh_m;
                        set_transient($bh_ck, $bh_m, 86400);
                        continue;
                    }
                }
                // Cache misses for 1hr
                set_transient($bh_ck, ['name' => 'Unnamed NFT', 'image' => null], 3600);
            }

            if ($bh_calls > 0 || $bh_hits > 0) {
                error_log("P2 Bithomp fallback: $bh_calls API calls, $bh_hits cache hits, " . count($still_missing) . " needed");
            }
        }
    }
    
    wp_send_json([
        'success' => true,
        'metadata' => $metadata,
        'fetched' => count($metadata),
        'requested' => count($nft_ids)
    ]);
}

// ACTION: Get collections from VPS (proxy to avoid CORS)
// Now paginates to fetch ALL collections from VPS indexer
if ($action === 'get_vps_collections') {
    // Pass through sort/filter/search params from client
    $sort   = sanitize_text_field($_GET['sort'] ?? 'health');
    $filter = sanitize_text_field($_GET['filter'] ?? '');
    $search = sanitize_text_field($_GET['search'] ?? '');

    // Check WP transient cache first (5 minute TTL)
    $cache_key_wp = 'imc_vps_collections_' . md5($sort . $filter . $search);
    if (!isset($_GET['force'])) {
        $cached = get_transient($cache_key_wp);
        if ($cached !== false) {
            if (is_array($cached) && isset($cached['collections'])) $cached['collections'] = imc_filter_scam_collections($cached['collections']);
            wp_send_json($cached);
        }
    }

    // Single call to VPS — no pagination loop needed
    // VPS now supports limit up to 5000, and per-row queries are eliminated
    $vps_url = 'https://metadata.imcollectibles.io/?action=collections'
        . '&limit=2000'
        . '&offset=0'
        . '&sort=' . urlencode($sort)
        . ($filter ? '&filter=' . urlencode($filter) : '')
        . ($search ? '&search=' . urlencode($search) : '');
    
    $ch = curl_init($vps_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $all_collections = [];
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['collections']) && is_array($data['collections'])) {
            $all_collections = $data['collections'];
        } elseif (isset($data[0])) {
            $all_collections = $data;
        }
    }
    
    
    // v208: Merge Bithomp top collections to fill gaps while indexer grows
    $bithomp_key = defined('BITHOMP_API_KEY') ? BITHOMP_API_KEY : '';
    if ($bithomp_key && count($all_collections) >= 0) {
        $bh_cache_key = 'imc_bithomp_browse_collections';
        $bh_collections = get_transient($bh_cache_key);
        
        if ($bh_collections === false) {
            $bh_url = 'https://bithomp.com/api/v2/nft-collections?limit=200&assets=true&statistics=true&floorPrice=true';
            $bh_ch = curl_init($bh_url);
            curl_setopt_array($bh_ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'x-bithomp-token: ' . $bithomp_key
                ]
            ]);
            
            $bh_response = curl_exec($bh_ch);
            $bh_httpCode = curl_getinfo($bh_ch, CURLINFO_HTTP_CODE);
            curl_close($bh_ch);
            
            $bh_collections = [];
            if ($bh_httpCode === 200 && $bh_response) {
                $bh_data = json_decode($bh_response, true);
                foreach (($bh_data['collections'] ?? []) as $bc) {
                    $bh_issuer = $bc['issuer'] ?? '';
                    $bh_taxon = (int)($bc['taxon'] ?? 0);
                    if (!$bh_issuer) continue;
                    
                    $bh_assets = $bc['assets'] ?? [];
                    $bh_img = $bh_assets['preview'] ?? $bh_assets['image'] ?? $bc['image'] ?? '';
                    if (strpos($bh_img, 'ipfs://') === 0) {
                        $bh_img = 'https://ipfs.io/ipfs/' . substr($bh_img, 7);
                    }
                    
                    // Floor price
                    $bh_floor = 0;
                    foreach (($bc['floorPrices'] ?? []) as $fp) {
                        if (isset($fp['open']['amount'])) {
                            $bh_floor = round((float)$fp['open']['amount'] / 1000000, 2);
                            break;
                        }
                    }
                    
                    $bh_collections[] = [
                        'issuer'        => $bh_issuer,
                        'taxon'         => $bh_taxon,
                        'name'          => $bc['name'] ?? 'Unknown',
                        'description'   => $bc['description'] ?? '',
                        'image'         => $bh_img ?: '',  // v328: empty → JS filters out; never store fallback SVG as image
                        'image_proxy'   => $bh_img ?: '',  // v328: same
                        'indexed_count' => (int)($bc['statistics']['nfts'] ?? 0),
                        'total_supply'  => (int)($bc['statistics']['nfts'] ?? 0),
                        'owner_count'   => (int)($bc['statistics']['owners'] ?? 0),
                        'floor_drops'   => (int)($bh_floor * 1000000),
                        'content_type'  => 'image',
                        'is_bithomp'    => true
                    ];
                }
            }
            
            // Cache for 15 minutes
            set_transient($bh_cache_key, $bh_collections, 900);
            error_log("IMC Browse: Fetched " . count($bh_collections) . " Bithomp collections");
        }
        
        if (!empty($bh_collections)) {
            // Build dedup index from VPS collections
            $vps_keys = [];
            foreach ($all_collections as $vc) {
                $key = ($vc['issuer'] ?? '') . ':' . ($vc['taxon'] ?? 0);
                $vps_keys[$key] = true;
            }
            
            // Append Bithomp collections not already in VPS
            $added = 0;
            foreach ($bh_collections as $bc) {
                $key = $bc['issuer'] . ':' . $bc['taxon'];
                if (!isset($vps_keys[$key])) {
                    $all_collections[] = $bc;
                    $vps_keys[$key] = true;
                    $added++;
                }
            }
            

            // v209: Enrich existing VPS collections with missing images/names
            $bh_lookup = [];
            foreach ($bh_collections as $bc) {
                $key = $bc['issuer'] . ':' . $bc['taxon'];
                $bh_lookup[$key] = $bc;
            }
            $enriched = 0;
            foreach ($all_collections as &$vc) {
                $key = ($vc['issuer'] ?? '') . ':' . ($vc['taxon'] ?? 0);
                if (!isset($bh_lookup[$key])) continue;
                $bh_c = $bh_lookup[$key];
                if (empty($vc['image']) && !empty($bh_c['image'])) {
                    $vc['image'] = $bh_c['image'];
                    $vc['image_proxy'] = $bh_c['image'];
                    $enriched++;
                }
                if ((empty($vc['name']) || strpos($vc['name'], 'Collection #') === 0) && !empty($bh_c['name']) && $bh_c['name'] !== 'Unknown') {
                    $vc['name'] = $bh_c['name'];
                }
                if (empty($vc['description']) && !empty($bh_c['description'])) {
                    $vc['description'] = $bh_c['description'];
                }
                if (empty($vc['owner_count']) && !empty($bh_c['owner_count'])) {
                    $vc['owner_count'] = $bh_c['owner_count'];
                }
            }
            unset($vc);
            if ($enriched > 0) {
                error_log("IMC Browse: Enriched $enriched existing VPS collections with Bithomp data");
            }
            if ($added > 0) {
                error_log("IMC Browse: Merged $added Bithomp collections (total: " . count($all_collections) . ")");
            }
        }
    }

    // ── v329 1E: Server-side image verification ──────────────────────────────
    // Filter out collections whose image URL doesn't return a real image.
    // Uses per-URL WP transient cache (1h verified / 10min failed) so subsequent
    // page loads are instant. curl_multi fires all HEAD requests in parallel.
    // Blank/silent broken images (200 OK + wrong content-type) are caught here;
    // actual HTTP errors are the onerror fallback's job.
    function imc_verify_image_urls(array &$collections) {
        $to_check = []; // [ index => url ]
        foreach ($collections as $i => $col) {
            $url = $col['image'] ?? '';
            if (!$url || strpos($url, 'fallback-nft') !== false) {
                unset($collections[$i]);
                continue;
            }
            $cache_key = 'imc_imgv_' . md5($url);
            $cached = get_transient($cache_key);
            if ($cached === 'ok') continue;
            if ($cached === 'bad') { unset($collections[$i]); continue; }
            $to_check[$i] = $url;
        }

        if (empty($to_check)) {
            $collections = array_values($collections);
            return;
        }

        // Batch HEAD requests via curl_multi (parallel, 4s timeout)
        $mh      = curl_multi_init();
        $handles = [];
        foreach ($to_check as $i => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY         => true,   // HEAD only
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => 4,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 IMCollectibles/1.0',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }

        $running = null;
        do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.1); }
        while ($running > 0);

        foreach ($handles as $i => $ch) {
            $http_code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? '';
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            // Accept any 2xx response with an image/* content-type
            $is_ok = ($http_code >= 200 && $http_code < 300)
                     && stripos($content_type, 'image/') !== false;

            $cache_key = 'imc_imgv_' . md5($to_check[$i]);
            if ($is_ok) {
                set_transient($cache_key, 'ok', 3600);  // 1 hour
            } else {
                set_transient($cache_key, 'bad', 600);  // 10 min
                unset($collections[$i]);
            }
        }
        curl_multi_close($mh);

        $collections = array_values($collections);
        error_log("IMC Browse: image verification removed " . (count($to_check) - count(array_intersect_key($to_check, $collections))) . " bad-image collections");
    }

    imc_verify_image_urls($all_collections);
    // ─────────────────────────────────────────────────────────────────────────

    // ── v409: Enrich with has_imc_listing flag for client-side IMC prioritisation ──
    $imc_listings_table_vps = $wpdb->prefix . 'imc_listings';
    $imc_pairs_vps = [];
    if ($wpdb->get_var("SHOW TABLES LIKE '$imc_listings_table_vps'") === $imc_listings_table_vps) {
        $imc_rows_vps = $wpdb->get_results(
            "SELECT DISTINCT artist_account, collection_taxon FROM $imc_listings_table_vps WHERE status IN ('active', 'sold_out', 'paused')",
            ARRAY_A
        ) ?: [];
        foreach ($imc_rows_vps as $ir) {
            $imc_pairs_vps[$ir['artist_account'] . ':' . intval($ir['collection_taxon'])] = true;
        }
    }
    foreach ($all_collections as &$_vc) {
        $_vc['has_imc_listing'] = isset($imc_pairs_vps[($_vc['issuer'] ?? '') . ':' . ($_vc['taxon'] ?? 0)]);
    }
    unset($_vc);

    $all_collections = imc_filter_scam_collections($all_collections);

$response_data = [
        'success' => true,
        'collections' => $all_collections,
        'total' => count($all_collections),
        'pages_fetched' => 1
    ];
    
    // Cache for 5 minutes
    set_transient($cache_key_wp, $response_data, 300);
    
    wp_send_json($response_data);
}

// ─────────────────────────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────────────
// v331: ACTION: get_browse_collections
// Dedicated endpoint for the Browse Collections page.
// Returns ALL collections (VPS + Bithomp merged), server-side paginated.
// NO image verification — the browse page uses CSS order:9999 for broken images.
// Supports: page, limit, search, type (content_type), sort
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'get_browse_collections') {
    $page       = max(1, intval($_GET['page']   ?? 1));
    $limit      = min(100, max(10, intval($_GET['limit'] ?? 50)));
    $search     = strtolower(trim(sanitize_text_field($_GET['search'] ?? '')));
    $type       = sanitize_text_field($_GET['type']   ?? 'all');
    $sort       = sanitize_text_field($_GET['sort']   ?? 'shuffle');

    // ── Cached merged dataset (15 min) ────────────────────────────────────────
    $ds_cache_key = 'imc_browse_ds_v1';
    $all_collections = get_transient($ds_cache_key);

    if ($all_collections === false) {
        $all_collections = [];

        // 1. Fetch ALL from VPS (no image verification, no stripping)
        $vps_url = 'https://metadata.imcollectibles.io/?action=collections&limit=15000&offset=0&sort=health';
        $ch = curl_init($vps_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $vps_resp = curl_exec($ch);
        $vps_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($vps_code === 200 && $vps_resp) {
            $vps_data = json_decode($vps_resp, true);
            $vps_rows = $vps_data['collections'] ?? (isset($vps_data[0]) ? $vps_data : []);
            foreach ($vps_rows as $row) {
                if (empty($row['issuer'])) continue;
                $img = $row['image'] ?? '';
                if ($img && strpos($img, 'ipfs://') === 0) {
                    $img = 'https://ipfs.io/ipfs/' . substr($img, 7);
                }
                $all_collections[] = [
                    'issuer'        => $row['issuer'],
                    'taxon'         => (int)($row['taxon'] ?? 0),
                    'name'          => $row['name'] ?? '',
                    'slug'          => $row['slug'] ?? '',
                    'description'   => $row['description'] ?? '',
                    'image'         => ($img && strpos($img, 'fallback-nft') === false) ? $img : '',
                    'content_type'  => $row['content_type'] ?? 'image',
                    'is_imu'        => (bool)($row['is_imu'] ?? false),
                    'is_music'      => (bool)($row['is_music'] ?? false),
                    'indexed_count' => (int)($row['indexed_count'] ?? $row['nfts'] ?? 0),
                    'total_supply'  => (int)($row['total_supply'] ?? 0),
                    'owner_count'   => (int)($row['owner_count'] ?? $row['owners'] ?? 0),
                    'health_score'  => (float)($row['health_score'] ?? 0),
                    'floor_xrp'     => (float)($row['floor_xrp'] ?? 0),
                    'is_vps'        => true,
                ];
            }
        }

        // P3-F/G2 (2026-08): Bithomp off-platform merge REMOVED — store is 85%+ and
        // the off-platform tail is mass-production/scam we do not surface in discovery.

        set_transient($ds_cache_key, $all_collections, 900); // 15 min full dataset cache
        // P3-F/G2: companion seed rotates with the dataset window -> stable shuffle
        // across an accumulating (forever-scroll) fetch so pages never dup/skip.
        set_transient('imc_browse_ds_v1_seed', (string)mt_rand(1, 2000000000), 900);
        error_log('[Browse] Dataset built: ' . count($all_collections) . ' collections');
    }

    $all_collections = imc_filter_scam_collections($all_collections);

    // ── v409: Enrich with has_imc_listing flag ──────────────────────────────────
    // Cross-reference with wp_imc_listings to identify collections minted on IMC.
    // Runs every request (not cached) so new listings are immediately prioritised.
    $imc_listings_table = $wpdb->prefix . 'imc_listings';
    $imc_listing_pairs = [];
    if ($wpdb->get_var("SHOW TABLES LIKE '$imc_listings_table'") === $imc_listings_table) {
        $imc_rows = $wpdb->get_results(
            "SELECT DISTINCT artist_account, collection_taxon FROM $imc_listings_table WHERE status IN ('active', 'sold_out', 'paused')",
            ARRAY_A
        ) ?: [];
        foreach ($imc_rows as $ir) {
            $imc_listing_pairs[$ir['artist_account'] . ':' . intval($ir['collection_taxon'])] = true;
        }
    }
    foreach ($all_collections as &$_c) {
        $_c['has_imc_listing'] = isset($imc_listing_pairs[($_c['issuer'] ?? '') . ':' . ($_c['taxon'] ?? 0)]);
    }
    unset($_c);

    // ── Apply type filter ──────────────────────────────────────────────────────
    $filtered = $all_collections;
    if ($type !== 'all') {
        $filtered = array_values(array_filter($filtered, function($c) use ($type) {
            return ($c['content_type'] ?? 'image') === $type;
        }));
    }

    // ── Apply search (name, issuer partial match) ──────────────────────────────
    if ($search !== '') {
        $filtered = array_values(array_filter($filtered, function($c) use ($search) {
            return strpos(strtolower($c['name'] ?? ''), $search) !== false
                || strpos(strtolower($c['issuer'] ?? ''), $search) !== false
                || strpos(strtolower($c['description'] ?? ''), $search) !== false;
        }));
    }

    $total = count($filtered);

    // ── Sort: IMC-minted first, then working images, then by sort criteria ────
    // v409: Collections minted on IMCollectibles always appear before XRPL-wide collections
    usort($filtered, function($a, $b) use ($sort) {
        // Tier 1: IMC-minted collections first
        $aImc = !empty($a['has_imc_listing']) ? 1 : 0;
        $bImc = !empty($b['has_imc_listing']) ? 1 : 0;
        if ($aImc !== $bImc) return $bImc - $aImc;

        // Tier 2: Working images before broken/missing
        $aHasImg = !empty($a['image']) ? 1 : 0;
        $bHasImg = !empty($b['image']) ? 1 : 0;
        if ($aHasImg !== $bHasImg) return $bHasImg - $aHasImg;

        switch ($sort) {
            case 'health-desc':
                return ($b['health_score'] ?? 0) <=> ($a['health_score'] ?? 0);
            case 'count-desc':
                return ($b['indexed_count'] ?? 0) <=> ($a['indexed_count'] ?? 0);
            case 'count-asc':
                return ($a['indexed_count'] ?? 0) <=> ($b['indexed_count'] ?? 0);
            case 'name-asc':
                return strcmp($a['name'] ?? '', $b['name'] ?? '');
            default: // shuffle within groups
                return 0;
        }
    });

    // Shuffle within each group when sort=shuffle
    // v409: IMC-minted collections shuffled separately and shown first
    if ($sort === 'shuffle' || $sort === '') {
        // P3-F/G2: deterministic shuffle per cache-window + filter view so every page
        // of an accumulating scroll sees the SAME order (no duplicates, no skips).
        $ds_seed = get_transient('imc_browse_ds_v1_seed');
        if ($ds_seed === false) { $ds_seed = '0'; }
        mt_srand(crc32($ds_seed . '|' . $type . '|' . $search));
        $imc_img    = array_values(array_filter($filtered, fn($c) => !empty($c['has_imc_listing']) && !empty($c['image'])));
        $imc_noimg  = array_values(array_filter($filtered, fn($c) => !empty($c['has_imc_listing']) &&  empty($c['image'])));
        $ext_img    = array_values(array_filter($filtered, fn($c) =>  empty($c['has_imc_listing']) && !empty($c['image'])));
        $ext_noimg  = array_values(array_filter($filtered, fn($c) =>  empty($c['has_imc_listing']) &&  empty($c['image'])));
        shuffle($imc_img);
        shuffle($imc_noimg);
        shuffle($ext_img);
        shuffle($ext_noimg);
        $filtered = array_merge($imc_img, $imc_noimg, $ext_img, $ext_noimg);
    }

    // ── Paginate ───────────────────────────────────────────────────────────────
    $total_pages = max(1, (int)ceil($total / $limit));
    $page        = min($page, $total_pages);
    $offset      = ($page - 1) * $limit;
    $page_slice  = array_slice($filtered, $offset, $limit);

    wp_send_json([
        'success'     => true,
        'collections' => array_values($page_slice),
        'total'       => $total,
        'page'        => $page,
        'total_pages' => $total_pages,
        'limit'       => $limit,
        'dataset_size'=> count($all_collections),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// v328 1E: ACTION: get_collection_cover
// Called by JS carousel onerror to fetch a working first-NFT image for a
// third-party collection whose stored cover URL is dead/missing.
// Returns { success, image } or { success: false }
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'get_collection_cover') {
    $issuer = sanitize_text_field($_GET['issuer'] ?? '');
    $taxon  = intval($_GET['taxon']  ?? 0);
    if (!$issuer) wp_send_json(['success' => false, 'error' => 'Missing issuer']);

    $cache_key = 'imc_col_cover_' . md5($issuer . ':' . $taxon);
    $cached    = get_transient($cache_key);
    if ($cached !== false) {
        wp_send_json($cached);
    }

    // Hit VPS ?action=collection endpoint — returns nfts[] with nftokenID fields
    $vps_url = 'https://metadata.imcollectibles.io/?action=collection'
        . '&issuer=' . urlencode($issuer)
        . '&taxon='  . $taxon
        . '&limit=5'; // try up to 5 to find one with a real image

    $ch = curl_init($vps_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $vps_resp = curl_exec($ch);
    $vps_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = ['success' => false];

    if ($vps_code === 200 && $vps_resp) {
        $vps_data = json_decode($vps_resp, true);
        // Try top-level image first (collection cover)
        $col_img = $vps_data['collection']['image'] ?? $vps_data['image'] ?? '';
        if ($col_img && strpos($col_img, 'ipfs://') === 0) {
            $col_img = 'https://metadata.imcollectibles.io/img.php?url=' . urlencode($col_img);
        }
        if ($col_img && strpos($col_img, 'placehold') === false && strpos($col_img, 'fallback-nft') === false) {
            $result = ['success' => true, 'image' => $col_img];
        } else {
            // Walk nfts[] to find first with a resolvable image
            $nfts = $vps_data['nfts'] ?? $vps_data['results'] ?? [];
            foreach ($nfts as $nft) {
                $nft_id = $nft['nftokenID'] ?? $nft['nft_token_id'] ?? $nft['NFTokenID'] ?? '';
                if (!$nft_id) continue;
                $img = $nft['metadata']['image'] ?? $nft['image_resolved'] ?? $nft['image_url'] ?? $nft['image'] ?? '';
                if ($img && strpos($img, 'ipfs://') === 0) {
                    $img = 'https://metadata.imcollectibles.io/img.php?url=' . urlencode($img);
                } elseif ($nft_id) {
                    // Fall back to img.php?nft=TOKEN_ID — VPS will proxy and cache it
                    $img = 'https://metadata.imcollectibles.io/img.php?nft=' . urlencode($nft_id);
                }
                if ($img) {
                    $result = ['success' => true, 'image' => $img];
                    break;
                }
            }
        }
    }

    // Cache: 1 hour on success, 10 min on failure (avoid hammering VPS for truly imageless collections)
    set_transient($cache_key, $result, $result['success'] ? 3600 : 600);
    wp_send_json($result);
}

// ACTION: Get user's NFTs
if ($action !== 'get_my_nfts') {
    mn_json_error('Invalid action. Use: get_my_nfts, get_metadata, get_vps_collections, get_collection_cover');
}

$account = sanitize_text_field($_GET['account'] ?? '');

if (empty($account) || !preg_match('/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/', $account)) {
    mn_json_error('Invalid account');
}

// v414/P1: LEDGER-FIRST ARCHITECTURE
// The XRPL ledger is the single source of truth for NFT ownership.
// We fetch account_nfts FIRST, then query our DB by nftoken_id (not buyer_account)
// to find which wallet NFTs were minted on our platform — regardless of who
// originally purchased them. This makes the Access tab work for received/transferred NFTs.
$imc_purchases_nft_ids = [];
$imc_purchase_meta     = [];
$imc_p_table           = null;
$imc_l_table           = null;
$imc_p_check = $wpdb->prefix . 'imc_purchases';
if ($wpdb->get_var("SHOW TABLES LIKE '$imc_p_check'") === $imc_p_check) {
    $imc_p_table = $imc_p_check;
    $imc_l_table = $wpdb->prefix . 'imc_listings';

    // v414/P1: Add nftoken_id index if missing — speeds up the IN-clause lookup
    // that matches wallet NFTs against purchase records. Safe additive migration.
    $p_indexes = $wpdb->get_results("SHOW INDEX FROM $imc_p_table WHERE Key_name = 'idx_nftoken'", ARRAY_A);
    if (empty($p_indexes)) {
        $wpdb->query("ALTER TABLE $imc_p_table ADD INDEX idx_nftoken (nftoken_id)");
        error_log('P1: added idx_nftoken index to imc_purchases');
    }
}

// Fetch NFTs from XRPL — THE SOURCE OF TRUTH FOR OWNERSHIP
// v414/P1: 90-second transient cache eliminates redundant RPC calls on rapid
// page refreshes / tab switches. ?force=1 bypasses cache for manual refresh.
$cache_key_xrpl = 'imc_xrpl_nfts_' . substr(md5($account), 0, 16);
$force_refresh   = isset($_GET['force']) && $_GET['force'] === '1';
$cached_nfts     = $force_refresh ? false : get_transient($cache_key_xrpl);
$cache_hit       = false;

if ($cached_nfts !== false && is_array($cached_nfts)) {
    $nfts      = $cached_nfts;
    $cache_hit = true;
} else {
    // v577: FULL OWNERSHIP ENUMERATION — paginate account_nfts via marker until
    // exhausted so wallets holding >400 NFTs are no longer silently truncated.
    // Mirrors the proven in-house indexer loop (account_nfts variant);
    // ledger_index pinned to 'validated' for a consistent snapshot across pages.
    $nfts           = [];
    $xrpl_marker    = null;
    $xrpl_pages     = 0;
    $XRPL_MAX_PAGES = 50; // safety ceiling: 50 x 400 = 20,000 NFTs

    do {
        $params = [
            'account'      => $account,
            'ledger_index' => 'validated',
            'limit'        => 400,
        ];
        if ($xrpl_marker) $params['marker'] = $xrpl_marker;

        $result = mn_xrpl_call('account_nfts', $params);

        if (!$result || isset($result['error'])) {
            // Only hard-fail if the very first page failed; keep partial otherwise.
            if (empty($nfts)) {
                $error_msg = $result['error_message'] ?? $result['error'] ?? 'Failed to fetch NFTs';
                mn_json_error($error_msg);
            }
            break;
        }

        $page_nfts = $result['account_nfts'] ?? [];
        if (!empty($page_nfts)) {
            $nfts = array_merge($nfts, $page_nfts);
        }

        $xrpl_marker = $result['marker'] ?? null;
        $xrpl_pages++;
    } while ($xrpl_marker && $xrpl_pages < $XRPL_MAX_PAGES);

    if ($xrpl_marker && $xrpl_pages >= $XRPL_MAX_PAGES) {
        error_log('my-nfts: account_nfts pagination hit ceiling (' . $XRPL_MAX_PAGES . ' pages, ' . count($nfts) . ' NFTs) for ' . $account);
    }

    set_transient($cache_key_xrpl, $nfts, 90);
}

// v414/P1: LEDGER-FIRST ACCESS LOOKUP
// Now that we know what the wallet ACTUALLY holds (from XRPL), check which of
// those NFTs were minted on our platform. We query by nftoken_id — not by
// buyer_account — so the Access tab works for ALL holders, not just the
// original purchaser. A user who receives an IMC NFT via transfer, trade,
        // or airdrop sees it in their Access tab.
if ($imc_p_table && !empty($nfts)) {
    $wallet_upper_ids = [];
    foreach ($nfts as $wn) {
        $wid = strtoupper($wn['NFTokenID'] ?? '');
        if ($wid) $wallet_upper_ids[] = $wid;
    }

    if (!empty($wallet_upper_ids)) {
        // v578: chunk the ownership IN-clause so an uncapped wallet never builds an
        // oversized prepared statement. Same query, batched in 1,000-ID pages + merged.
        $imc_rows = [];
        foreach (array_chunk($wallet_upper_ids, 1000) as $id_chunk) {
        $ph = implode(',', array_fill(0, count($id_chunk), '%s'));
        $chunk_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT UPPER(p.nftoken_id) AS nid,
                        p.listing_id,
                        p.buyer_account,
                        l.nft_name,
                        l.cover_ipfs   AS listing_cover,
                        l.artist_name,
                        l.artist_account,
                        l.collection_taxon,
                        l.nft_type
                   FROM $imc_p_table p
                   LEFT JOIN $imc_l_table l ON p.listing_id = l.id
                  WHERE UPPER(p.nftoken_id) IN ($ph)
                    AND p.mint_status IN ('minted','claimed')
                    AND p.nftoken_id IS NOT NULL
                    AND p.nftoken_id != ''",
                ...$id_chunk
            ),
            ARRAY_A
        ) ?: [];
        if (!empty($chunk_rows)) {
            $imc_rows = array_merge($imc_rows, $chunk_rows);
        }
        }

        foreach ($imc_rows as $r) {
            if (!empty($r['nid'])) {
                $imc_purchases_nft_ids[$r['nid']] = true;
                $imc_purchase_meta[$r['nid']]     = [
                    'listing_id'       => intval($r['listing_id'] ?? 0),
                    'nft_name'         => $r['nft_name']       ?? '',
                    'cover_ipfs'       => $r['listing_cover']  ?? '',
                    'artist_name'      => (function_exists('imc_resolve_artist_name') ? imc_resolve_artist_name($r['artist_account'] ?? '', $r['artist_name'] ?? '', '') : ($r['artist_name'] ?? '')),
                    'collection_taxon' => intval($r['collection_taxon'] ?? 0),
                    'nft_type'         => $r['nft_type'] ?? '',
                    // v414/P1: true if current wallet is NOT the original buyer
                    'is_received'      => (strtolower($r['buyer_account'] ?? '') !== strtolower($account)),
                ];
            }
        }

        error_log('P1 Access: ' . count($imc_purchases_nft_ids) . ' platform NFTs in wallet for ' . $account);
    }
}

// Initialize grouped arrays
$grouped = [];
$other_grouped = [];
$other_collections_meta = [];

foreach ($IMU_COLLECTIONS as $key => $col) {
    $grouped[$key] = [];
}

// Process each NFT - NO METADATA FETCHING (fast!)
// Metadata will be fetched on-demand by frontend when user clicks collection
foreach ($nfts as $nft) {
    $collection_key = mn_get_collection_key($nft, $IMU_COLLECTIONS);
    
    // Prepare NFT data - just XRPL data, no metadata
    $nft_token_id = $nft['NFTokenID'] ?? '';
    $nft_upper_id = strtoupper($nft_token_id);
    $nft_data = [
        'nftokenID'       => $nft_token_id,
        'issuer'          => $nft['Issuer'] ?? '',
        'taxon'           => intval($nft['NFTokenTaxon'] ?? 0),
        'uri'             => $nft['URI'] ?? '',
        'serial'          => $nft['nft_serial'] ?? null,
        'flags'           => $nft['Flags'] ?? 0,
        'metadata'        => null, // Frontend will fetch this on-demand
        // v414/P1: True if this NFT was minted on our platform (found in imc_purchases
        // for ANY buyer). Works for original purchaser AND subsequent holders.
        'is_imc_purchase' => isset($imc_purchases_nft_ids[$nft_upper_id]),
        // v414/P1: True if this wallet is NOT the original buyer — received via
        // transfer, trade, or airdrop. Frontend shows "Received" badge.
        'is_received_transfer' => ($imc_purchase_meta[$nft_upper_id]['is_received'] ?? false),
        // v305: Listing/collection metadata for the Access tab collection grouping.
        'imc_listing'     => $imc_purchase_meta[$nft_upper_id] ?? null,
    ];
    
    if ($collection_key) {
        // IMU Collection
        $grouped[$collection_key][] = $nft_data;
    } else {
        // Other collection - group by issuer+taxon
        $issuer = $nft_data['issuer'];
        $taxon = $nft_data['taxon'];
        $other_key = 'other_' . substr($issuer, 0, 8) . '_' . $taxon;
        
        if (!isset($other_grouped[$other_key])) {
            $other_grouped[$other_key] = [];
            $other_collections_meta[$other_key] = [
                'issuer' => $issuer,
                'taxon' => $taxon,
                'name' => null,
                'image' => null
            ];
        }
        
        $other_grouped[$other_key][] = $nft_data;
    }
}

// Fetch metadata for "other" collections from WordPress cache or VPS
foreach ($other_collections_meta as $key => &$meta) {
    $col_data = mn_get_other_collection_meta($meta['issuer'], $meta['taxon']);
    if ($col_data['name']) {
        $meta['name'] = $col_data['name'];
    }
    if ($col_data['image']) {
        $meta['image'] = $col_data['image'];
    }
}
unset($meta); // Break reference

// Build flat NFT list
$all_nfts = [];
foreach ($grouped as $nfts_in_col) {
    foreach ($nfts_in_col as $nft) {
        $all_nfts[] = $nft;
    }
}
foreach ($other_grouped as $nfts_in_col) {
    foreach ($nfts_in_col as $nft) {
        $all_nfts[] = $nft;
    }
}

// Calculate counts
$imu_count = 0;
foreach ($grouped as $g) {
    $imu_count += count($g);
}
$other_count = count($all_nfts) - $imu_count;
$total_collections = count(array_filter($grouped, function($g) { return count($g) > 0; })) + count($other_grouped);

// Send response
wp_send_json([
    'success' => true,
    'nfts' => $all_nfts,
    'grouped' => $grouped,
    'other_grouped' => $other_grouped,
    'other_collections' => $other_collections_meta,
    'total_count' => count($all_nfts),
    'imu_count' => $imu_count,
    'other_count' => $other_count,
    'collections_count' => $total_collections,
    'account' => $account,
    // v414/P1: Cache awareness — frontend can show "cached" indicator or auto-force
    'cache_hit' => $cache_hit,
]);