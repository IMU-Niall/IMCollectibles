<?php
/**
 * Template Name: NFT Single Page
 * Description: Dedicated page for viewing a single NFT with IMUTV branding
 * 
 * FIXES in this version:
 * - Make Offer button now functional (opens offer popup)
 * - Message Owner button now functional (opens chat)
 * - Added JavaScript handlers for both buttons
 */

// Get NFT ID from URL
$nft_id = get_query_var('nft_id', '');

// Sanitize - allow hex characters only
$nft_id = preg_replace('/[^A-Fa-f0-9]/', '', $nft_id);

if (empty($nft_id)) {
    wp_redirect(home_url('/trading-hub/'));
    exit;
}

// ── LOCAL DB CHECK (always, for all platform NFTs) ──────────────────────────
// Run unconditionally so $local_nft is available regardless of VPS result.
// Used for: image/media override, content_type correction, master_access hash.
$local_nft = null;
if (!empty($nft_id)) {
    global $wpdb;
    $imc_p = $wpdb->prefix . 'imc_purchases';
    $imc_l = $wpdb->prefix . 'imc_listings';
    $imc_t = $wpdb->prefix . 'imc_listing_tiers';

    if ($wpdb->get_var("SHOW TABLES LIKE '$imc_p'") === $imc_p) {
        $local_nft = $wpdb->get_row($wpdb->prepare(
            "SELECT p.nftoken_id, p.edition_number, p.buyer_account,
                    p.metadata_ipfs AS purchase_metadata_ipfs, p.tier_id, p.listing_id,
                    l.is_hidden, l.artist_account AS listing_artist,
                    l.nft_name, l.description, l.cover_ipfs AS listing_cover, l.media_ipfs,
                    l.nft_type, l.artist_account, l.artist_name,
                    l.collection_name, l.collection_taxon, l.transfer_fee,
                    l.metadata_json, l.master_content_hash, l.cover_content_hash, l.downloadable,
                    l.album_tracks_json,
                    l.audiobook_chapters_json, l.audiobook_format, l.back_cover_ipfs,
                    l.ebook_pages_json, l.ebook_format,
                    t.cover_ipfs AS tier_cover, t.preview_ipfs AS tier_preview, t.master_content_hash AS tier_master_hash,
                    t.cover_content_hash AS tier_cover_hash
             FROM $imc_p p
             JOIN $imc_l l ON p.listing_id = l.id
             LEFT JOIN $imc_t t ON p.tier_id = t.id
             WHERE p.nftoken_id = %s
               AND p.mint_status IN ('minted', 'claimed')
             LIMIT 1",
            $nft_id
        ), ARRAY_A);
    }
}

// ── v682: MODERATION GATE ───────────────────────────────────────────────────
// A listing hidden by moderation (see is_hidden / hidden_reason) must not stay
// reachable on its direct URL — a saved link or a cached search result would keep
// serving it publicly. Returning a real 404 (rather than redirecting) is also what
// prompts search engines to drop the page.
//
// Owners keep access. They paid for the NFT and it exists on-chain regardless of what
// this site shows; revoking their view would destroy something they legitimately bought
// without reducing the exposure the takedown is addressing. The artist is allowed
// through too, so they can see what was actioned.
//
// NOTE: the wallet here comes from the xrpl_account cookie, which is a display gate and
// NOT authentication. That is sufficient for this purpose — the protected asset (the
// master file) is gated separately by the VPS entitlement API, which verifies ownership
// server-side. Crawlers send no cookie, so they always receive the 404.
if (!empty($local_nft) && !empty($local_nft['is_hidden'])) {
    $imc_viewer = function_exists('imc_session_wallet') ? imc_session_wallet() : '';
    $imc_owner  = $local_nft['buyer_account'] ?? '';
    $imc_artist = $local_nft['listing_artist'] ?? '';
    $imc_may_view = $imc_viewer !== '' && ($imc_viewer === $imc_owner || $imc_viewer === $imc_artist);
    if (!$imc_may_view) {
        global $wp_query;
        if (isset($wp_query)) { $wp_query->set_404(); }
        status_header(404);
        nocache_headers();
        $imc_404 = get_query_template('404');
        if ($imc_404) { include $imc_404; }
        exit;
    }
}

// ── Precompute reliable DB image/media URLs (used for patching below) ───────
$db_img_url   = '';
$db_media_url = '';
$db_content_type = '';
if (!empty($local_nft)) {
    $cover_cid = $local_nft['tier_cover'] ?? $local_nft['listing_cover'] ?? '';
    if ($cover_cid) {
        // v309 FIX: Direct Pinata gateway — we pinned the cover there ourselves.
        // Previously routed through img.php which tried cloudflare-ipfs.com (dead since 2023).
        // Direct gateway = instant load, no proxy hop, matches how the grid now works.
        $clean_cid = preg_replace('#^ipfs://#', '', trim($cover_cid));
        if ($clean_cid) {
            $db_img_url = 'https://<your-pinata-gateway>/ipfs/' . $clean_cid;
        }
    }
    // v601: tier-first preview — use the per-tier clip (matches the NFT's on-chain preview.audio),
    // falling back to the listing-level media_ipfs for non-tiered listings.
    $media_cid = $local_nft['tier_preview'] ?? $local_nft['media_ipfs'] ?? '';
    if ($media_cid) {
        $media_cid    = preg_replace('#^ipfs://#', '', $media_cid);
        // v309 FIX: cloudflare-ipfs.com shut down 2023. Use Pinata directly.
        $db_media_url = 'https://<your-pinata-gateway>/ipfs/' . $media_cid;
    }
    // v256: Correct content_type for all 4 nft_types
    // v24:  album treated as audio (multi-track audio access)
    $db_content_type = match($local_nft['nft_type'] ?? 'music') {
        'music'      => 'audio',
        'musicvideo' => 'video',
        'art'        => 'image',
        'film'       => 'video',
        'album'      => 'audio',
        'audiobook'  => 'audio',   // M1-f2: AudioBook plays in the audio lane
        'ebook'      => 'image',   // M2-c1: an eBook is read - the cover is its display media
        default      => 'audio',
    };
}

// ── Fetch NFT data server-side (avoids CORS issues) ──────────────────────────
$nft_data = null;
$fetch_error = null;

// v423: VPS called for ALL NFTs with reduced 5s timeout (was 15s in v414).
// VPS provides complete metadata including master_access, cover_access, and media_url
// which are critical for the audio player, preview playback, and dual master upgrade.
// DB PATCH section below overrides image/media/content_type with authoritative DB values.
// LOCAL DB BUILD (below) fires as fallback if VPS fails or times out.
// P1d (Aug 2026): fetch=true could grind the VPS worker ~56s inline on a miss
// (IPFS gateway retries). fetch=fast is the bounded replacement: <=1 Clio (4s)
// + <=1 xrpl.to (6s) attempt, write-back either way, IPFS left to the daemon.
$p1d_pending_facts = null;
$api_url = 'https://metadata.imcollectibles.io/?action=get&id=' . urlencode($nft_id) . '&fetch=fast';
$response = wp_remote_get($api_url, [
    'timeout' => 5,  // v423: reduced from 15s — fast enough for indexed NFTs
    'sslverify' => true,
    'headers' => ['Accept' => 'application/json']
]);

if (is_wp_error($response)) {
    $fetch_error = $response->get_error_message();
} else {
    $status = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($status === 200 && !empty($body)) {
        $data = json_decode($body, true);
        if (!empty($data['success']) && !empty($data['nft'])) {
            $nft_data = $data['nft'];

            // P1d GUARD: fetch=fast answers in any-mode - a not-yet-decoded row
            // returns success with placeholder content ('Unnamed NFT', null media).
            // Treat non-success rows as a CONTENT miss so the LOCAL DB BUILD
            // (platform mints) and the degraded path behave EXACTLY as a VPS miss
            // always has - but stash the ledger facts (owner/transferFee) for the
            // owner chain below.
            if (!empty($nft_data['decode_status']) && $nft_data['decode_status'] !== 'success') {
                $p1d_pending_facts = $nft_data;
                $nft_data = null;
                $fetch_error = 'VPS row pending decode (' . $p1d_pending_facts['decode_status'] . ')';
            }

            if (!empty($nft_data)) {
            // v256: PATCH VPS data with authoritative DB values for platform NFTs.
            if (!empty($local_nft)) {
                if ($db_img_url)                        $nft_data['image']        = $db_img_url;
                if ($db_img_url && isset($nft_data['assets']))
                                                        $nft_data['assets']['image'] = $db_img_url;
                if ($db_media_url)                      $nft_data['media_url']    = $db_media_url;
                if ($db_content_type)                   $nft_data['content_type'] = $db_content_type;
                if (!empty($nft_data['metadata'])) {
                    if ($db_img_url)                    $nft_data['metadata']['image'] = $db_img_url;
                }
                if (!empty($local_nft['artist_account'])) {
                    $nft_data['issuer'] = $local_nft['artist_account'];
                }
                if (empty($nft_data['owner']) && !empty($local_nft['buyer_account'])) {
                    $nft_data['owner'] = $local_nft['buyer_account'];
                }
            }
            } // P1d guard wrapper end
        } else {
            $fetch_error = $data['error'] ?? 'NFT not found in database';
        }
    } else {
        $fetch_error = 'API returned status ' . $status;
    }
}

// ── LOCAL DB BUILD: VPS failed but we have a platform NFT in our DB ──────────
if (empty($nft_data) && !empty($local_nft)) {
    // $local_nft was populated unconditionally above; now build full nft_data from it
    $img_url = $db_img_url; // computed from DB cover_ipfs
            
            $ed_num = $local_nft['edition_number'] ? ' #' . $local_nft['edition_number'] : '';
            
            // ═══════════════════════════════════════════════════════════════════
            // PRIMARY: Fetch from purchase's IPFS metadata (canonical source)
            // This is the actual minted NFT's metadata with edition-specific attributes
            // ═══════════════════════════════════════════════════════════════════
            $nft_attrs = [];
            $full_ipfs_meta = [];
            $ipfs_fetch_success = false;
            
            if (!empty($local_nft['purchase_metadata_ipfs'])) {
                $ipfs_cid = preg_replace('#^ipfs://#', '', $local_nft['purchase_metadata_ipfs']);
                
                // v421: IPFS METADATA CACHE — 24hr transient keyed on CID.
                // Before this fix, every platform NFT page load fetched from IPFS
                // gateways (10s timeout each, 3 gateways = 30s worst case).
                // Now: first load fetches and caches, all subsequent loads are instant.
                $ipfs_cache_key = 'ipfs_meta_' . substr(md5($ipfs_cid), 0, 16);
                $cached_ipfs = get_transient($ipfs_cache_key);
                
                if ($cached_ipfs !== false && is_array($cached_ipfs) && empty($cached_ipfs['_failed'])) {
                    // Valid cached IPFS metadata — use immediately
                    $full_ipfs_meta = $cached_ipfs;
                    $ipfs_fetch_success = true;
                    if (!empty($cached_ipfs['attributes']) && is_array($cached_ipfs['attributes'])) {
                        $nft_attrs = $cached_ipfs['attributes'];
                    }
                } else {
                    error_log("NFT Single: Fetching metadata from purchase IPFS: $ipfs_cid");
                    
                    // v421: Gateway order optimised — ipfs.io first (faster, more reliable),
                    // our VPS second (often times out). Timeout reduced 10s→3s.
                    // Duplicate ipfs.io gateway removed.
                    $gateways = [
                        "https://ipfs.io/ipfs/$ipfs_cid",
                        'https://metadata.imcollectibles.io/img.php?url=' . urlencode('ipfs://' . $ipfs_cid),
                        "https://cloudflare-ipfs.com/ipfs/$ipfs_cid"
                    ];
                    
                    foreach ($gateways as $gateway_url) {
                        $ipfs_response = wp_remote_get($gateway_url, [
                            'timeout' => 3,  // v421: was 10s — fail fast, try next gateway
                            'sslverify' => true,
                            'headers' => ['Accept' => 'application/json']
                        ]);
                        
                        if (!is_wp_error($ipfs_response)) {
                            $status_code = wp_remote_retrieve_response_code($ipfs_response);
                            if ($status_code === 200) {
                                $body = wp_remote_retrieve_body($ipfs_response);
                                $ipfs_meta = json_decode($body, true);
                                
                                if (is_array($ipfs_meta)) {
                                    $full_ipfs_meta = $ipfs_meta;
                                    $ipfs_fetch_success = true;
                                    // Cache for 24 hours — IPFS metadata is immutable
                                    set_transient($ipfs_cache_key, $ipfs_meta, 86400);
                                    
                                    if (!empty($ipfs_meta['attributes']) && is_array($ipfs_meta['attributes'])) {
                                        $nft_attrs = $ipfs_meta['attributes'];
                                        error_log("NFT Single: ✓ Found " . count($nft_attrs) . " attributes from IPFS (cached)");
                                    } else {
                                        error_log("NFT Single: IPFS metadata loaded but no attributes array. Keys: " . implode(', ', array_keys($ipfs_meta)));
                                    }
                                    break; // Success, stop trying gateways
                                } else {
                                    error_log("NFT Single: IPFS response not valid JSON from $gateway_url");
                                }
                            } else {
                                error_log("NFT Single: Gateway HTTP $status_code from $gateway_url");
                            }
                        } else {
                            error_log("NFT Single: Gateway error from $gateway_url: " . $ipfs_response->get_error_message());
                        }
                    }
                    
                    // Cache failures for 1hr to avoid re-hitting broken gateways every page load
                    if (!$ipfs_fetch_success) {
                        set_transient($ipfs_cache_key, ['_failed' => true], 3600);
                    }
                }
            } else {
                error_log("NFT Single: purchase_metadata_ipfs is NULL/empty in database");
            }
            
            // FALLBACK: Try listing's metadata_json if IPFS fetch failed completely
            $meta_json = [];
            if (!empty($local_nft['metadata_json'])) {
                // v390: Try direct decode first, then stripslashes fallback for pre-v390 escaped data
                $decoded = json_decode($local_nft['metadata_json'], true)
                        ?: json_decode(stripslashes($local_nft['metadata_json']), true);
                if (is_array($decoded)) {
                    $meta_json = $decoded;
                    
                    // Only use listing attributes if we couldn't get IPFS metadata
                    if (empty($nft_attrs) && !empty($meta_json['attributes']) && is_array($meta_json['attributes'])) {
                        $nft_attrs = $meta_json['attributes'];
                        error_log("NFT Single: Using " . count($nft_attrs) . " attributes from listing metadata_json (IPFS fallback)");
                    }
                }
            }
            
            // Final debug log
            if (empty($nft_attrs)) {
                error_log("NFT Single: ✗ NO ATTRIBUTES FOUND for NFT $nft_id");
                error_log("NFT Single: purchase_metadata_ipfs = " . ($local_nft['purchase_metadata_ipfs'] ?? 'NULL'));
                error_log("NFT Single: IPFS fetch success = " . ($ipfs_fetch_success ? 'YES' : 'NO'));
            }
            
            // Use IPFS metadata values if available (more accurate than listing template)
            $display_name = $full_ipfs_meta['name'] ?? (($local_nft['nft_name'] ?? 'NFT') . $ed_num);
            $display_desc = $full_ipfs_meta['description'] ?? ($local_nft['description'] ?? '');
            $display_image = $full_ipfs_meta['image'] ?? $img_url;
            if (strpos($display_image, 'ipfs://') === 0) {
                $display_image = 'https://metadata.imcollectibles.io/img.php?url=' . urlencode(preg_replace('#^ipfs://#', 'ipfs://', $display_image));
            }
            
            // v61 FIX: Extract media URL from IPFS metadata (audio/animation_url)
            $display_media = '';
            if (!empty($full_ipfs_meta['audio'])) {
                $display_media = $full_ipfs_meta['audio'];
            } elseif (!empty($full_ipfs_meta['animation_url'])) {
                $display_media = $full_ipfs_meta['animation_url'];
            } elseif (!empty($local_nft['media_ipfs'])) {
                $display_media = 'ipfs://' . ltrim($local_nft['media_ipfs'], 'ipfs://');
            }
            // Convert IPFS URI to gateway URL
            if (strpos($display_media, 'ipfs://') === 0) {
                $display_media = 'https://metadata.imcollectibles.io/img.php?url=' . urlencode(preg_replace('#^ipfs://#', 'ipfs://', $display_media));
            }
            // v239: Also fix dead cloudflare-ipfs.com URLs stored in IPFS metadata
            $display_media = str_replace('https://cloudflare-ipfs.com/ipfs/', 'https://ipfs.io/ipfs/', $display_media);
            
            // Build NFT data structure matching VPS format
            $nft_data = [
                'nft_token_id' => $nft_id,
                'issuer' => $local_nft['artist_account'] ?? '',
                'taxon' => intval($local_nft['collection_taxon'] ?? 0),
                'owner' => $local_nft['buyer_account'] ?? '',
                'name' => $display_name,
                'description' => $display_desc,
                'image' => $display_image,
                'media_url' => $display_media, // v61 FIX: Include media URL for player
                'content_type' => $db_content_type ?: 'audio', // v256: art->image, film->video, music->audio
                'metadata' => array_merge([
                    'name' => $display_name,
                    'description' => $display_desc,
                    'image' => $display_image,
                    'attributes' => $nft_attrs
                ], $full_ipfs_meta ?: $meta_json),
                'attributes' => $nft_attrs,  // Also at top level for fallback
                'assets' => [
                    'image' => $display_image
                ],
                // Extra fields for display
                'artist_name' => $local_nft['artist_name'] ?? '',
                'collection_name' => $full_ipfs_meta['collection']['name'] ?? ($local_nft['collection_name'] ?? ''),
                'transfer_fee' => intval($local_nft['transfer_fee'] ?? 5000),
                'is_mod_nft' => true
            ];

            // v422: INJECT master_access + cover_access into metadata from DB hashes.
            // VPS used to provide these. Now that VPS is skipped for platform NFTs,
            // we must ensure these are present for the downstream access checks.
            // DB hashes are authoritative — they're set during listing creation.
            $db_master_hash = $local_nft['tier_master_hash'] ?? $local_nft['master_content_hash'] ?? '';
            $db_cover_hash  = $local_nft['tier_cover_hash'] ?? $local_nft['cover_content_hash'] ?? '';

            if ($db_master_hash && empty($nft_data['metadata']['properties']['master_access']['content_hash'])) {
                $nft_data['metadata']['properties']['master_access'] = [
                    'content_hash'    => $db_master_hash,
                    'entitlement_api' => site_url('/wp-json/imu-master/v1/session-access')
                ];
            }
            if ($db_cover_hash && empty($nft_data['metadata']['cover_access']['content_hash'])) {
                $nft_data['metadata']['cover_access'] = [
                    'content_hash' => $db_cover_hash
                ];
            }

            // v422: Ensure media_url is always set for platform NFTs (preview playback).
            // If IPFS didn't provide audio/animation_url, fall back to DB media_ipfs.
            // v601: tier-first preview fallback (per-tier clip), listing media_ipfs as last resort.
            $imc_fallback_cid = $local_nft['tier_preview'] ?? $local_nft['media_ipfs'] ?? '';
            if (empty($nft_data['media_url']) && !empty($imc_fallback_cid)) {
                $fallback_media = $imc_fallback_cid;
                if (strpos($fallback_media, 'ipfs://') === 0) {
                    $fallback_media = 'https://ipfs.io/ipfs/' . substr($fallback_media, 7);
                }
                $nft_data['media_url'] = $fallback_media;
                error_log("NFT Single: media_url patched from DB media_ipfs");
            }
            
    $fetch_error = null; // Clear any previous error
    error_log("NFT Single: Loaded MOD NFT $nft_id from local DB");
}

// v208: Bithomp API fallback - if VPS and local DB both failed
if (empty($nft_data) && !empty($nft_id)) {
    $bithomp_token = defined('BITHOMP_API_KEY') ? BITHOMP_API_KEY : '';
    if ($bithomp_token) {
        error_log("NFT Single: VPS+local failed, trying Bithomp for $nft_id");
        $bh_url = 'https://bithomp.com/api/v2/nft/' . urlencode($nft_id) . '?metadata=true&uri=true' /* P1-fin: assets=true was free-tier-rejected - this last resort has been DEAD since the plan change (A4 carried the render); dropping the token makes it genuinely work */;
        $bh_response = wp_remote_get($bh_url, [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json', 'x-bithomp-token' => $bithomp_token]
        ]);
        
        if (!is_wp_error($bh_response) && wp_remote_retrieve_response_code($bh_response) === 200) {
            $bh_data = json_decode(wp_remote_retrieve_body($bh_response), true);
            
            if (!empty($bh_data) && !empty($bh_data['nftokenID'])) {
                $bh_meta = $bh_data['metadata'] ?? [];
                $bh_assets = $bh_data['assets'] ?? [];
                
                $bh_img = $bh_assets['image'] ?? $bh_assets['preview'] ?? $bh_meta['image'] ?? '';
                if (strpos($bh_img, 'ipfs://') === 0) {
                    $bh_img = 'https://ipfs.io/ipfs/' . substr($bh_img, 7);
                } elseif (preg_match('/^(Qm|bafy)/', $bh_img)) {
                    $bh_img = 'https://ipfs.io/ipfs/' . $bh_img;
                }

                // v237: Extract audio/video media from Bithomp response.
                // Bithomp returns media in assets.video, assets.audio, metadata.animation_url,
                // and metadata.audio. We detect media type and resolve IPFS URLs so the
                // existing audio/video player HTML renders correctly for unindexed NFTs.
                $bh_media_url   = '';
                $bh_content_type = 'image';

                // Helper: resolve ipfs:// and bare CID to https gateway URL
                $resolve_ipfs = function(string $url): string {
                    if (strpos($url, 'ipfs://') === 0) {
                        return 'https://ipfs.io/ipfs/' . substr($url, 7);
                    }
                    if (preg_match('/^(Qm|bafy)/i', $url)) {
                        return 'https://ipfs.io/ipfs/' . $url;
                    }
                    return $url;
                };

                // Helper: detect content type from URL extension
                $detect_media_type = function(string $url): string {
                    $path = strtolower(parse_url($url, PHP_URL_PATH) ?? $url);
                    if (preg_match('/\.(mp4|webm|mov|m4v|ogv)(\?|$)/', $path)) return 'video';
                    if (preg_match('/\.(mp3|wav|ogg|flac|m4a|aac|opus)(\?|$)/', $path)) return 'audio';
                    return ''; // unknown — let caller decide default
                };

                // Priority 1: Bithomp-processed asset URLs (already resolved, most reliable)
                if (!empty($bh_assets['video'])) {
                    $bh_media_url    = $resolve_ipfs($bh_assets['video']);
                    $bh_content_type = 'video';
                } elseif (!empty($bh_assets['audio'])) {
                    $bh_media_url    = $resolve_ipfs($bh_assets['audio']);
                    $bh_content_type = 'audio';
                }
                // Priority 2: metadata.animation_url (covers both audio and video NFTs)
                elseif (!empty($bh_meta['animation_url'])) {
                    $raw = $bh_meta['animation_url'];
                    $resolved = $resolve_ipfs($raw);
                    $detected = $detect_media_type($resolved ?: $raw);
                    // animation_url defaults to video when extension is ambiguous
                    $bh_content_type = $detected ?: 'video';
                    $bh_media_url    = $resolved;
                }
                // Priority 3: explicit metadata.audio field
                elseif (!empty($bh_meta['audio'])) {
                    $bh_media_url    = $resolve_ipfs($bh_meta['audio']);
                    $bh_content_type = 'audio';
                }
                // Priority 4: metadata.video field (less common but exists on some projects)
                elseif (!empty($bh_meta['video'])) {
                    $bh_media_url    = $resolve_ipfs($bh_meta['video']);
                    $bh_content_type = 'video';
                }

                error_log("NFT Single (Bithomp): content_type=$bh_content_type media_url=" . ($bh_media_url ?: '(none)'));

                $nft_data = [
                    'nftokenID'   => $bh_data['nftokenID'],
                    'issuer'      => $bh_data['issuer'] ?? '',
                    'owner'       => $bh_data['owner'] ?? '',
                    'taxon'       => $bh_data['nftokenTaxon'] ?? 0,
                    'transferFee' => $bh_data['transferFee'] ?? 0,
                    'content_type' => $bh_content_type,
                    'media_url'    => $bh_media_url,
                    'metadata' => [
                        'name'        => $bh_meta['name'] ?? ('NFT #' . substr($bh_data['nftokenID'], -8)),
                        'description' => $bh_meta['description'] ?? '',
                        'image'       => $bh_img,
                        'attributes'  => $bh_meta['attributes'] ?? []
                    ],
                    'assets' => ['image' => $bh_img],
                    'is_bithomp_fallback' => true
                ];
                
                $fetch_error = null;
                error_log("NFT Single: Loaded NFT $nft_id from Bithomp API");
            }
        }
    }
}

// P1d LAST RESORT: every content source missed, but the store's pending row gave
// us authoritative ledger facts. Render a minimal real page (placeholder art,
// correct owner + royalty) instead of the error page - strictly better than a
// dead end, and the daemon/fast write-back means the next visit has content.
if (empty($nft_data) && !empty($p1d_pending_facts)) {
    $nft_data = [
        'nftokenID'   => $p1d_pending_facts['nftokenID'] ?? $nft_id,
        'issuer'      => $p1d_pending_facts['issuer'] ?? '',
        'owner'       => $p1d_pending_facts['owner'] ?? '',
        'taxon'       => $p1d_pending_facts['taxon'] ?? 0,
        'transferFee' => $p1d_pending_facts['transferFee'] ?? 0,
        'content_type' => 'image',
        'media_url'    => '',
        'metadata' => [
            'name'        => 'NFT #' . substr($nft_id, -8),
            'description' => 'This NFT has been discovered on the XRPL and is being indexed. Full details will appear shortly.',
            'image'       => '/wp-content/uploads/fallback-nft.svg',
            'attributes'  => []
        ],
        'assets' => ['image' => '/wp-content/uploads/fallback-nft.svg'],
        'is_pending_fallback' => true
    ];
    $fetch_error = null;
    error_log("NFT Single: P1d minimal render from pending facts for $nft_id");
}

// Parse NFT data
$metadata = $nft_data['metadata'] ?? [];
$assets = $nft_data['assets'] ?? [];

$nft_name = $metadata['name'] ?? $nft_data['name'] ?? 'Unnamed NFT';
/**
 * PROXY ANY IPFS URL THROUGH img.php (Aug 2026).
 *
 * ⚠ WHY: ipfs.io sends Cross-Origin-Resource-Policy: same-origin, so a browser
 *   REFUSES to render its images cross-origin — ERR_BLOCKED_BY_RESPONSE.NotSameOrigin.
 *   The fetch itself is a clean 200 in 0.18s server-side, which is why every curl
 *   test passed while pages showed placeholders. Measured: the large majority of cards
 *   broken on collections.php, and the trading hub's offer cards likewise.
 *
 * ★ MATCH THE PATH, NOT THE HOST. A host list is an enumeration of the gateways
 *   known on the day it was written — collections.php's read `ipfs.com`, a domain
 *   that does not exist. The /ipfs/ path is the invariant, which is the same lesson
 *   IMUP3's isValidStreamUrl learned in F0a.
 *
 * ⚠ IDEMPOTENT: returns an already-proxied URL untouched, so it is safe to apply
 *   to values that may have been proxied earlier in this file (L311, L325 do).
 */
if (!function_exists('imc_proxy_img')) {
    function imc_proxy_img($u, $thumb = false) {
        if (!is_string($u) || $u === '') return $u;
        if (strpos($u, 'img.php') !== false) return $u;           // already proxied
        $q = $thumb ? '&thumb=1' : '';
        if (strpos($u, 'ipfs://') === 0) {
            return 'https://metadata.imcollectibles.io/img.php?url=' . urlencode($u) . $q;
        }
        if (strpos($u, '/ipfs/') !== false) {
            $cid = preg_replace('#^.*/ipfs/#', '', $u);
            return 'https://metadata.imcollectibles.io/img.php?url=' . urlencode('ipfs://' . $cid) . $q;
        }
        return $u;   // a plain https asset (e.g. images.improtectors.com) — leave it
    }
}

/**
 * R8: back_cover_ipfs may be stored WITH the ipfs:// scheme or as a bare CID, and
 * the old code glued it straight onto a gateway path, producing /ipfs/ipfs://Qm…
 * Normalise first, then reuse imc_proxy_img above.
 *
 * Declared at TOP LEVEL beside imc_proxy_img on purpose: R7 declared it inside the
 * audiobook branch, so an eBook page never reached the declaration and fatalled on
 * the call. A helper used by more than one branch must not live inside one of them.
 */
if (!function_exists('imc_back_cover_url')) {
    function imc_back_cover_url($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') { return ''; }
        if (stripos($raw, 'http') !== 0 && stripos($raw, 'ipfs://') !== 0 && strpos($raw, '/ipfs/') === false) {
            $raw = 'ipfs://' . ltrim($raw, '/');   // bare CID
        }
        return imc_proxy_img($raw);
    }
}

// ★ ONE line covers FOUR render sites: the video poster (L2249), the album cover
//   (L2265), the audio cover (L2351) and the art image (L2402). $assets['image'] is
//   already proxied upstream at L311, but the ?? fallbacks are NOT — and this is
//   Pass 8's Q25, recorded as "$nft_image does NOT use image_proxy" and unfixed since.
// ⚠ NO &thumb=1: this is the full-size single view, and it is the one place a
//   large image is correct.
$nft_image = imc_proxy_img($assets['image'] ?? $metadata['image'] ?? $nft_data['image'] ?? '');
$nft_description = $metadata['description'] ?? $nft_data['description'] ?? 'No description available.';
$nft_attributes = $metadata['attributes'] ?? $nft_data['attributes'] ?? [];
$nft_issuer = $nft_data['issuer'] ?? '';
$nft_taxon = $nft_data['taxon'] ?? '-';

/**
 * Phase 4D: scam-issuer set + per-NFT scam flag (own copy; fail-open).
 * Flags an NFT whose ISSUER (creator) is on the VPS scam list -> warn + disable trading.
 * Kill-switch: define('IMC_SCAM_FILTER_DISABLED', true).
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
$nft_is_scam = ($nft_issuer !== '' && in_array(strtolower($nft_issuer), imc_get_scam_issuers(), true));

// OWNER & ROYALTY — XRPL first (authoritative), VPS/Bithomp as fallback
// v414/P1: Flipped priority. XRPL nft_info is always the source of truth for
// current ownership. VPS indexer's owner field can be days/weeks stale.
$nft_owner = 'Unknown';
$nft_transfer_fee = '-';

// METHOD 1: XRPL RPC (authoritative, real-time owner) — ALWAYS TRY FIRST
// v760/P1a: xrplcluster.com rejects nft_info (all Clio methods) — it NEVER worked
// here, so every view silently fell through to VPS/Bithomp. Replaced with an
// ordered Clio failover loop. objectNotFound is a VERDICT (burned/nonexistent),
// not an error — stop the loop on it rather than retrying other hosts.
$xrpl_rpc_endpoints = [
    'https://s2-clio.ripple.com:51234/',
    'https://s1.ripple.com:51234/',
    'https://s2.ripple.com:51234/',
];
$rpc_request = json_encode([
    'method' => 'nft_info',
    'params' => [['nft_id' => $nft_id]]
]);

foreach ($xrpl_rpc_endpoints as $xrpl_rpc_url) {
    $rpc_response = wp_remote_post($xrpl_rpc_url, [
        'timeout' => 5,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => $rpc_request
    ]);
    if (is_wp_error($rpc_response)) {
        continue; // network-level failure — try the next endpoint
    }
    $rpc_body = json_decode(wp_remote_retrieve_body($rpc_response), true);
    if (!is_array($rpc_body) || empty($rpc_body['result'])) {
        continue; // unparseable — try the next endpoint
    }
    if (($rpc_body['result']['error'] ?? '') === 'objectNotFound') {
        break; // definitive ledger answer: NFT has no live object (burned/unknown)
    }
    if (!empty($rpc_body['result']['owner'])) {
        $nft_owner = $rpc_body['result']['owner'];
    }
    if ($nft_transfer_fee === '-' && isset($rpc_body['result']['transfer_fee'])) {
        $fee_basis_points = intval($rpc_body['result']['transfer_fee']);
        $fee_percentage = $fee_basis_points / 1000;
        $nft_transfer_fee = number_format($fee_percentage, 1) . '%';
    }
    if (($rpc_body['result']['status'] ?? '') === 'success') {
        break; // authoritative answer received — no further endpoints needed
    }
}

// METHOD 2: VPS indexer data (already returned from ?action=get above) — fallback only
if ($nft_owner === 'Unknown' && !empty($nft_data['owner'])) {
    $nft_owner = $nft_data['owner'];
}
if ($nft_transfer_fee === '-' && isset($nft_data['transferFee']) && $nft_data['transferFee'] > 0) {
    $fee_percentage = intval($nft_data['transferFee']) / 1000;
    $nft_transfer_fee = number_format($fee_percentage, 1) . '%';
}
// METHOD 2b (P1d): a pending store row still carries authoritative ledger facts
// captured by the stream/fast-fetch - use them before falling to Bithomp.
if ($nft_owner === 'Unknown' && !empty($p1d_pending_facts['owner'])) {
    $nft_owner = $p1d_pending_facts['owner'];
}
if ($nft_transfer_fee === '-' && isset($p1d_pending_facts['transferFee']) && $p1d_pending_facts['transferFee'] > 0) {
    $nft_transfer_fee = number_format(intval($p1d_pending_facts['transferFee']) / 1000, 1) . '%';
}

// METHOD 3: Bithomp API (last resort fallback)
if ($nft_owner === 'Unknown') {
    $bithomp_token = defined('BITHOMP_API_KEY') ? BITHOMP_API_KEY : '';
    if ($bithomp_token) {
        $bithomp_url = 'https://bithomp.com/api/v2/nft/' . urlencode($nft_id) . '?uri=true&metadata=true';
        $bithomp_response = wp_remote_get($bithomp_url, [
            'timeout' => 8,
            'headers' => [
                'Accept' => 'application/json',
                'x-bithomp-token' => $bithomp_token
            ]
        ]);
        
        if (!is_wp_error($bithomp_response)) {
            $bithomp_status = wp_remote_retrieve_response_code($bithomp_response);
            if ($bithomp_status === 200) {
                $bithomp_data = json_decode(wp_remote_retrieve_body($bithomp_response), true);
                if (!empty($bithomp_data['owner'])) {
                    $nft_owner = $bithomp_data['owner'];
                }
                if ($nft_transfer_fee === '-' && isset($bithomp_data['transferFee'])) {
                    $fee_basis_points = intval($bithomp_data['transferFee']);
                    $fee_percentage = $fee_basis_points / 1000;
                    $nft_transfer_fee = number_format($fee_percentage, 1) . '%';
                }
            }
        }
    }
}

// Media type detection (audio, video, or image)
$nft_content_type = $nft_data['content_type'] ?? 'image';
$nft_media_url = $nft_data['media_url'] ?? '';

// v239: Sanitise media_url from any source (VPS cache may contain dead gateway URLs).
// Also convert raw ipfs:// URIs that some VPS responses return without resolving.
if (!empty($nft_media_url)) {
    // ⚠ cloudflare-ipfs.com SHUT DOWN in 2024 — this rewrite is still needed for
    //   stored URLs that predate it.
    $nft_media_url = str_replace('https://cloudflare-ipfs.com/ipfs/', 'https://ipfs.io/ipfs/', $nft_media_url);
    // P-r2: audio/video must NOT route through the image proxy (it strangles
    // non-image mimes -> the dead 0:00 player). The mint box's proven lane is
    // DIRECT from our Pinata gateway, which serves audio/* CORS-permissive
    // (collections.php AP note). Normalize any ipfs gateway URL to ours.
    if (preg_match('#/ipfs/([^\s"\']+)$#', $nft_media_url, $pr2_m)) {
        $nft_media_url = 'https://<your-pinata-gateway>/ipfs/' . $pr2_m[1];
    }
}
// P-r1: FALLBACK for platform music NFTs whose listing rows carry no preview CID
// (older listings) -- the pinned metadata itself carries preview.audio (root key,
// set by the engine). Resolve ipfs:// -> our gateway -> the same CORP-safe proxy.
if (empty($nft_media_url)) {
    $pr1_prev = $nft_data['metadata']['preview']['audio'] ?? '';
    if ($pr1_prev) {
        $pr1_cid = preg_replace('#^ipfs://#', '', trim($pr1_prev));
        if (strpos($pr1_cid, 'http') !== 0) {
            $pr1_cid = 'https://<your-pinata-gateway>/ipfs/' . $pr1_cid;
        }
        $nft_media_url = $pr1_cid; // P-r2: direct gateway, never the image proxy
    }
}

// User account for checking if owner
$user_account = function_exists('imc_session_wallet') ? imc_session_wallet() : '';
$is_owner = ($user_account && $user_account === $nft_owner);

// v414/P1: DB-level buyer fallback — ONLY activates when XRPL owner resolution
// failed entirely ($nft_owner is still 'Unknown'). If XRPL or Bithomp confirmed
// a different owner, the ledger takes precedence — the DB must NOT override it.
// This prevents the original buyer seeing owner UI after transferring/selling.
// The legitimate use case (XRPL timeout during mid-delivery) is preserved.
$is_platform_buyer = false;
if (!$is_owner && $nft_owner === 'Unknown' && $user_account && !empty($nft_id)) {
    global $wpdb;
    $imc_p_check = $wpdb->prefix . 'imc_purchases';
    if ($wpdb->get_var("SHOW TABLES LIKE '$imc_p_check'") === $imc_p_check) {
        $buyer_check = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $imc_p_check
             WHERE nftoken_id = %s AND buyer_account = %s AND delivered = 1
             LIMIT 1",
            $nft_id, $user_account
        ));
        if ($buyer_check) {
            $is_platform_buyer = true;
            $is_owner = true; // grant full owner UX
        }
    }
}
$nonce = wp_create_nonce('xrpl_marketplace_nonce');

// ── IMU Royalty Tracking: Resolve IMC wallet → IMUTV user_id ──
// Cross-database lookup: queries IMUTV DB (credentials in wp-config.php).
// If the user has an IMU account with the same wallet connected,
// they're eligible for streaming royalties on master file plays.
$imu_royalty_user_id = 0;
if ( $user_account && defined( 'IMUTV_DB_NAME' ) ) {
    $imutv_conn = function_exists( 'imc_get_imutv_db' ) ? imc_get_imutv_db() : null;
    if ( $imutv_conn ) {
        $imutv_prefix = defined( 'IMUTV_DB_PREFIX' ) ? IMUTV_DB_PREFIX : 'wp_';
        $um_table = $imutv_prefix . 'usermeta';
        $lu_stmt = $imutv_conn->prepare(
            "SELECT user_id FROM `$um_table`
             WHERE meta_key = 'xrpl_address' AND meta_value = ? LIMIT 1"
        );
        $lu_stmt->bind_param( 's', $user_account );
        $lu_stmt->execute();
        $lu_result = $lu_stmt->get_result();
        $lu_row = $lu_result ? $lu_result->fetch_assoc() : null;
        $imu_royalty_user_id = $lu_row ? (int) $lu_row['user_id'] : 0;
        $lu_stmt->close();
    }
}

// v198: Check if this NFT has an active sell listing by the owner
$active_listing = null;
if ($is_owner && !empty($nft_id)) {
    global $wpdb;
    $offers_table = $wpdb->prefix . 'xumm_offers';
    if ($wpdb->get_var("SHOW TABLES LIKE '$offers_table'") === $offers_table) {
        $active_listing = $wpdb->get_row($wpdb->prepare(
            "SELECT offer_id, amount, currency, created_at FROM $offers_table 
             WHERE target_nft_id = %s AND offerer_account = %s AND offer_type = 'sell' AND status = 'active'
             ORDER BY created_at DESC LIMIT 1",
            $nft_id, $user_account
        ), ARRAY_A);
    }
}

// ── Master File Access (v97: tokengated master quality) ──
$has_master_access = false;
$master_content_hash = '';
$master_api_url = '';

// Check metadata properties for master_access bridge
$nft_metadata = $nft_data['metadata'] ?? [];
if (!empty($nft_metadata['properties']['master_access']['content_hash'])) {
    $has_master_access = true;
    $master_content_hash = $nft_metadata['properties']['master_access']['content_hash'];
    $master_api_url = $nft_metadata['properties']['master_access']['entitlement_api'] ?? '';
}

// Also check listing/tier-level master (for NFTs minted before master_access was in metadata)
if (!$has_master_access && !empty($local_nft)) {
    $listing_hash = $local_nft['tier_master_hash'] ?? $local_nft['master_content_hash'] ?? '';
    if ($listing_hash) {
        $has_master_access = true;
        $master_content_hash = $listing_hash;
    }
}

// v379: Check for protected cover art (Music/MV/Film flows with watermarked covers)
$has_cover_access = false;
$cover_content_hash = '';
if (!empty($nft_metadata['cover_access']['content_hash'])) {
    $has_cover_access = true;
    $cover_content_hash = $nft_metadata['cover_access']['content_hash'];
}

// v395: Also check listing/tier-level cover hash (for NFTs where VPS didn't return cover_access)
// Mirrors the master_access fallback above — tier hash takes priority over listing hash.
if (!$has_cover_access && !empty($local_nft)) {
    $listing_cover_hash = $local_nft['tier_cover_hash'] ?? $local_nft['cover_content_hash'] ?? '';
    if ($listing_cover_hash) {
        $has_cover_access = true;
        $cover_content_hash = $listing_cover_hash;
    }
}

// v387: Check if artist enabled master file downloads for this listing
$is_downloadable = !empty($local_nft['downloadable']);

// v24: Album Access NFT detection
// $is_album    — true only for album nft_type with valid track data
// $album_tracks — decoded track array from album_tracks_json
// $has_album_access — true when owner has album access (tracks available via API)
$is_album        = false;
$album_tracks    = [];
$has_album_access = false;
// M1-f2: AudioBook detection. $is_audiobook_chaptered gates the chapter player and
// takes the same exclusions as $is_album (single-file books keep the normal master path).
$is_audiobook            = false;
// M2-c1: eBook detection. Pages are holder-only; non-holders get covers only.
$is_ebook                = false;
$ebook_page_count        = 0;
$ebook_format            = '';
$has_ebook_access        = false;
$ebook_has_pdf           = false;   // V2-O1
$is_audiobook_chaptered  = false;
$audiobook_chapters      = [];
$has_audiobook_access    = false;
$back_cover_url          = '';
// This NFT's vault entitlements, for display only. The download button
// re-verifies ownership live, server-side.
$nft_unlockables = [];
if (!empty($local_nft['listing_id'])) {
    $imc_ul_t = $wpdb->prefix . 'imc_listing_unlockables';
    $nft_unlockables = $wpdb->get_results($wpdb->prepare(
        "SELECT content_hash, label, file_size, mime_type FROM $imc_ul_t
          WHERE listing_id = %d AND status = 'active' AND fee_paid = 1
            AND (tier_id = 0 OR tier_id = %d)
          ORDER BY tier_id, sort_order, id",
        intval($local_nft['listing_id']), intval($local_nft['tier_id'] ?? 0)
    ), ARRAY_A) ?: [];
    // Cross-scope dedup (listing-wide wins) -- mirrors U4's metadata rule.
    $imc_ul_seen = [];
    $nft_unlockables = array_values(array_filter($nft_unlockables, function($r) use (&$imc_ul_seen) {
        if (isset($imc_ul_seen[$r['content_hash']])) return false;
        return $imc_ul_seen[$r['content_hash']] = true;
    }));
}

if (!empty($local_nft['nft_type']) && $local_nft['nft_type'] === 'album') {
    $is_album = true;
    // Decode track data — used to render the tracklist in the page
    if (!empty($local_nft['album_tracks_json'])) {
        $decoded = json_decode($local_nft['album_tracks_json'], true);
        if (is_array($decoded) && count($decoded) > 0) {
            $album_tracks = $decoded;
        }
    }
    // Also check on-chain metadata album block as fallback
    if (empty($album_tracks) && !empty($nft_metadata['album']['tracks'])) {
        $album_tracks = $nft_metadata['album']['tracks'];
    }
    // Album access available when owner has master_access (Track 1) — the API
    // handles all tracks. Mirrors the has_master_access gate used by other types.
    $has_album_access = ($has_master_access && !empty($album_tracks));
}
// AudioBook chapters come from the stored chapter manifest, with the on-chain
// audiobook.chapters block as a fallback (titles + lengths only).
if (!empty($local_nft['nft_type']) && $local_nft['nft_type'] === 'audiobook') {
    $is_audiobook = true;
    if (!empty($local_nft['audiobook_chapters_json'])) {
        $ab_manifest = json_decode($local_nft['audiobook_chapters_json'], true);
        if (is_array($ab_manifest) && !empty($ab_manifest['versions'])) {
            $ab_version = $ab_manifest['versions']['1'] ?? reset($ab_manifest['versions']);
            if (!empty($ab_version['chapters']) && is_array($ab_version['chapters'])) {
                $audiobook_chapters = $ab_version['chapters'];
            }
        }
    }
    if (empty($audiobook_chapters) && !empty($nft_metadata['audiobook']['chapters'])) {
        $audiobook_chapters = $nft_metadata['audiobook']['chapters'];
    }
    $is_audiobook_chaptered = !empty($audiobook_chapters);
    // Same gate as album.
    $has_audiobook_access = ($has_master_access && $is_audiobook_chaptered);
    if (!empty($local_nft['back_cover_ipfs'])) {
        $back_cover_url = imc_back_cover_url($local_nft['back_cover_ipfs']);
    }
}
// M2-c1: eBook. Pages come from the M2 manifest; page 1's source is the listing
// master hash (M2-b), so $has_master_access is the same gate every type uses.
if (!empty($local_nft['nft_type']) && $local_nft['nft_type'] === 'ebook') {
    $is_ebook = true;
    if (!empty($local_nft['ebook_pages_json'])) {
        $eb_manifest = json_decode($local_nft['ebook_pages_json'], true);
        if (is_array($eb_manifest) && !empty($eb_manifest['versions'])) {
            $eb_version = $eb_manifest['versions']['1'] ?? reset($eb_manifest['versions']);
            if (!empty($eb_version['pages']) && is_array($eb_version['pages'])) {
                $ebook_page_count = count($eb_version['pages']);
                // V2-O1: does this book contain PDF pages? The template already walks the
                // manifest here, so this costs nothing extra -- no second query, no second
                // json_decode. It decides whether the reader preloads PDF.js (1.5 MB of
                // library + worker) at boot instead of on the first turn to a page.
                foreach ($eb_version['pages'] as $_eb_pg) {
                    if (is_array($_eb_pg) && ($_eb_pg['src'] ?? '') === 'pdf') { $ebook_has_pdf = true; break; }
                }
            }
        }
    }
    if (!$ebook_page_count && !empty($nft_metadata['ebook']['page_count'])) {
        $ebook_page_count = (int) $nft_metadata['ebook']['page_count'];
    }
    $ebook_format         = $local_nft['ebook_format'] ?? '';
    $has_ebook_access     = ($has_master_access && $ebook_page_count > 0);
    if (!empty($local_nft['back_cover_ipfs']) && empty($back_cover_url)) {
        $back_cover_url = imc_back_cover_url($local_nft['back_cover_ipfs']);
    }
    // R4: the back cover is also on-chain (M2-e puts it in the metadata as back_cover),
    // so fall back to it when the listing row has no column value - e.g. an NFT whose
    // listing cannot be resolved, or one held after a secondary sale.
    if (empty($back_cover_url) && !empty($nft_metadata['back_cover'])) {
        $back_cover_url = imc_back_cover_url($nft_metadata['back_cover']);
    }
}

// Collection info lookup
function get_collection_info($issuer, $taxon) {
    $collections = [
        'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR_0' => ['name' => 'Guardians of the Frequencies', 'slug' => 'guardians'],
        'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga_717825' => ['name' => 'Protectors of the Frequencies', 'slug' => 'frequencies'],
        'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt_1056369418' => ['name' => 'Protectors of the Ledger', 'slug' => 'ledger'],
        'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR_777' => ['name' => 'Protectors of Las Vegas', 'slug' => 'lasvegas'],
        'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR_666' => ['name' => 'Firepit Protectors', 'slug' => 'firepit'],
        'rHsrif6nHTkmyh38W7JmYjairPWhq5P3AH_0' => ['name' => 'Special Edition Protectors', 'slug' => 'special-edition']
    ];
    $key = $issuer . '_' . $taxon;
    return $collections[$key] ?? ['name' => 'IMU Collection', 'slug' => 'collections'];
}

$collection_info = get_collection_info($nft_issuer, $nft_taxon);

// ── v325: Build "Back to Collection" button URL and label ──────────────────────
// Three-tier priority so every NFT type resolves correctly:
//   (1) Hardcoded IMU collections  → clean slug URL  /collections/guardians/ etc.
//   (2) Platform-minted NFTs       → DB integers from local_nft (artist_account / collection_taxon)
//   (3) XRPL-wide third-party NFTs → XRPL issuer + taxon from nft_data
$_col_url  = '';
$_col_name = '';

$_known_slug = $collection_info['slug'] ?? '';

if ( !empty($_known_slug) && $_known_slug !== 'collections' ) {
    // Tier 1: known IMU collection with a registered pretty slug
    $_col_url  = home_url( '/collections/' . $_known_slug . '/' );
    $_col_name = $collection_info['name'];

} elseif ( !empty($local_nft['artist_account']) && isset($local_nft['collection_taxon']) ) {
    // Tier 2: platform-minted NFT — use DB values (always integers, never '-')
    $_col_url  = home_url( '/collections/?issuer=' . urlencode($local_nft['artist_account'])
                           . '&taxon=' . intval($local_nft['collection_taxon']) );
    // Use collection_name from DB record if available, else leave blank (page resolves it)
    $_col_name = !empty($local_nft['collection_name']) ? $local_nft['collection_name'] : '';

} elseif ( !empty($nft_issuer) && $nft_taxon !== '-' && $nft_taxon !== '' ) {
    // Tier 3: XRPL-wide third-party NFT
    $_col_url  = home_url( '/collections/?issuer=' . urlencode($nft_issuer)
                           . '&taxon=' . urlencode($nft_taxon) );
    $_col_name = '';
}
// ──────────────────────────────────────────────────────────────────────────────

// Set global OG data for wp_head hook (must be before get_header)
global $imc_og_data;
// Build robust OG description — fallback if NFT has no description
$_og_nft_desc = wp_trim_words(strip_tags($nft_description), 25, '...');
if (empty(trim(strip_tags($_og_nft_desc)))) {
    $_og_nft_desc = $collection_info['name'] . ' NFT on the XRP Ledger. Collect, trade, and access exclusive media on IMCollectibles.';
}
$_og_nft_img = !empty($nft_image) ? $nft_image : (defined('IMC_OG_DEFAULT_IMAGE') ? IMC_OG_DEFAULT_IMAGE : '');

$imc_og_data = [
    'title'       => esc_attr($nft_name) . ' | ' . esc_attr($collection_info['name']) . ' | IMCollectibles',
    'description' => esc_attr($_og_nft_desc),
    'image'       => esc_url($_og_nft_img),
    'url'         => esc_url(home_url('/nft/' . $nft_id . '/')),
    'type'        => 'article',
    'section'     => esc_attr($collection_info['name']),
];

get_header();
?>

<style>
:root {
    --gold: #c9a832;
    --gold-dark: #b8962e;
    --gold-glow: rgba(201, 168, 50, 0.20);
    --bg-dark: #0a0a0f;
    --bg-card: #12121a;
    --text-primary: #ffffff;
    --text-secondary: #888;
    --border-subtle: rgba(212, 175, 55, 0.15);
}

.nft-single-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 2rem;
    min-height: 80vh;
}

/* v337: .nft-single-breadcrumb removed */

/* v325: Back to Collection — matches .btn-secondary style/hover exactly */
.back-to-collection-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.2rem;
    margin-top: 0.55rem;
    margin-bottom: 0.15rem;
    padding: 0.3rem 0.7rem;
    font-family: inherit;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    border-radius: 50px;
    border: 2px solid #c9a832;
    background: rgba(8, 8, 12, 0.75);
    color: #c9a832;
    white-space: nowrap;
    text-decoration: none;
    cursor: pointer;
    box-shadow:
        0 0 0 1px rgba(201, 168, 50, 0.28),
        0 0 10px rgba(201, 168, 50, 0.14);
    transition:
        background   0.2s ease,
        border-color 0.2s ease,
        box-shadow   0.22s ease,
        transform    0.15s ease,
        color        0.2s ease;
    -webkit-font-smoothing: antialiased;
}
.back-to-collection-btn:hover,
.back-to-collection-btn:focus-visible {
    background: rgba(0, 212, 255, 0.06);
    border-color: #00d4ff;
    color: #00d4ff;
    box-shadow:
        0 0 0 2px rgba(0, 212, 255, 0.55),
        0 0 18px rgba(0, 212, 255, 0.35),
        0 0 36px rgba(0, 212, 255, 0.12);
    transform: translateY(-1px);
    text-decoration: none;
    outline: none;
}

/* Marketplace Navigation Header - Hidden on single NFT page */
/* v337: marketplace-header-nav removed — back-to-collection btn replaces it */

/* Grid Layout - Image top-left, Description below image, Details right */
.nft-single-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    grid-template-rows: auto auto;
    gap: 2rem 3rem;
    align-items: start;
}

/* Image section - top left, constrained to viewport */
.nft-single-image-section {
    grid-column: 1;
    grid-row: 1;
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    overflow: visible; /* v325: never clip master-status-bar */
}

/* Description section - below image (left column, row 2) */
.nft-single-description-section {
    grid-column: 1;
    grid-row: 2;
}

/* Details section spans full right column */
.nft-desc-clamp { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
.nft-desc-clamp.open { display: block; -webkit-line-clamp: unset; overflow: visible; }
.nft-desc-toggle { background: none; border: none; color: var(--gold, #d4af37); cursor: pointer; padding: 4px 0 0; font-size: 0.82rem; font-weight: 600; }
.nft-vault-card { border: 1px solid rgba(212,175,55,0.25); border-radius: 14px; padding: 18px 20px; background: linear-gradient(180deg, rgba(212,175,55,0.05), rgba(255,255,255,0.02)); }
.nft-vault-card h3 { margin-top: 0; }
.nft-vault-section .nft-vault-count { font-size: 0.8rem; opacity: 0.6; font-weight: 400; margin-left: 10px; }
.nft-vault-locked-box { text-align: center; padding: 22px 16px; border: 1px dashed rgba(255,255,255,0.15); border-radius: 12px; background: rgba(0,0,0,0.2); }
.nft-vault-locked-icon { font-size: 1.6rem; margin-bottom: 6px; opacity: 0.85; }
.nft-vault-locked-text { font-size: 0.92rem; color: #cbd5e1; }
.nft-vault-locked-cta { margin-top: 8px; font-size: 0.8rem; color: var(--gold, #d4af37); font-weight: 600; }
.nft-vault-actions { display: flex; gap: 8px; align-items: center; }
.nft-vault-btn { padding: 6px 12px; font-size: 0.78rem; line-height: 1.2; border-radius: 8px; border: 1px solid rgba(255,255,255,0.18); background: rgba(255,255,255,0.05); color: inherit; cursor: pointer; white-space: nowrap; }
.nft-vault-btn:hover { border-color: var(--gold, #d4af37); color: var(--gold, #d4af37); }
#imc-vault-viewer { display: none; position: fixed; inset: 0; z-index: 100000; background: rgba(0,0,0,0.82); align-items: center; justify-content: center; padding: 20px; }
#imc-vault-viewer.open { display: flex; }
#imc-vault-viewer .ivv-box { width: min(920px, 96vw); max-height: 92vh; background: #101322; border: 1px solid rgba(212,175,55,0.3); border-radius: 14px; padding: 14px 16px; overflow: auto; }
#imc-vault-viewer .ivv-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
#imc-vault-viewer .ivv-title { font-size: 0.95rem; font-weight: 600; }
#imc-vault-viewer .ivv-close { background: none; border: none; color: #e2e8f0; font-size: 1.1rem; cursor: pointer; padding: 4px 8px; }
.nft-vault-list { display: flex; flex-direction: column; gap: 10px; margin-top: 12px; }
.nft-vault-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 10px 14px; border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; background: rgba(255,255,255,0.03); }
.nft-vault-info { display: flex; flex-direction: column; min-width: 0; }
.nft-vault-label { font-size: 0.92rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.nft-vault-meta { font-size: 0.75rem; opacity: 0.6; }
.nft-vault-locked { font-size: 0.8rem; opacity: 0.55; white-space: nowrap; }
.nft-vault-dl-btn { white-space: nowrap; }
.nft-single-details-section {
    grid-column: 2;
    grid-row: 1 / 3;
}

.nft-single-image {
    width: 100%;
    max-height: 70vh;
    object-fit: contain;
    border-radius: 16px;
    border: 1px solid var(--border-subtle);
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.4);
}

/* Constrain media containers to viewport */
.nft-media-container {
    max-height: 70vh;
}

.nft-video-container video {
    max-height: 70vh;
    width: 100%;
    object-fit: contain;
}

.nft-audio-container .audio-cover-wrapper img {
    max-height: 60vh;
    width: 100%;
    object-fit: contain;
}

/* ============================================================
   MEDIA PLAYER STYLES (Audio/Video NFTs)
   ============================================================ */

.nft-media-container {
    position: relative;
    width: 100%;
    border-radius: 16px;
    overflow: hidden;
    border: 1px solid var(--border-subtle);
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.4);
    background: var(--bg-card);
}

/* Video Player */
.nft-video-container {
    background: #000;
}

.nft-single-video {
    width: 100%;
    display: block;
    border-radius: 16px;
    background: #000;
    max-height: 70vh;
}

/* Audio Player */
.nft-audio-container {
    display: flex;
    flex-direction: column;
}

.audio-cover-wrapper {
    position: relative;
    width: 100%;
    /* Square aspect ratio so cover art always looks intentional */
    aspect-ratio: 1 / 1;
    overflow: hidden;
    border-radius: 16px 16px 0 0;
    background: #0a0a0f;
}

.audio-cover-wrapper .nft-single-image.audio-cover {
    width: 100%;
    height: 100%;
    display: block;
    border-radius: 0;
    border: none;
    box-shadow: none;
    object-fit: cover;
}

/* Bottom gradient fades cover into player */
.audio-cover-wrapper::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 40%;
    background: linear-gradient(to top, rgba(18,18,26,1) 0%, rgba(18,18,26,0.4) 60%, transparent 100%);
    pointer-events: none;
}

.audio-play-indicator {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 72px;
    height: 72px;
    background: rgba(0, 0, 0, 0.65);
    border: 2px solid rgba(var(--imu-gold-rgb), 0.6);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.25s ease;
    pointer-events: none;
    z-index: 5;
}

.audio-cover-wrapper:hover .audio-play-indicator {
    opacity: 1;
}

.play-icon-large {
    font-size: 2.2rem;
    color: var(--gold);
}

.audio-player-wrapper {
    padding: 0.85rem 1.25rem 1.25rem;
    background: var(--bg-card);
    border-top: 1px solid rgba(var(--imu-gold-rgb), 0.1);
}

/* Track info shown inside the player wrapper */
.audio-track-info {
    display: flex;
    flex-direction: column;
    margin-bottom: 0.65rem;
}
.audio-track-title {
    font-weight: 600;
    font-size: 0.95rem;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.audio-track-artist {
    font-size: 0.8rem;
    color: var(--text-muted);
    margin-top: 0.1rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.nft-single-audio {
    width: 100%;
    height: 45px;
    border-radius: 8px;
    outline: none;
}

/* Custom audio player styling for webkit browsers */
.nft-single-audio::-webkit-media-controls-panel {
    background: linear-gradient(135deg, #1a1a2e, #0f0f18);
}

.nft-single-audio::-webkit-media-controls-play-button,
.nft-single-audio::-webkit-media-controls-mute-button {
    filter: invert(0.9) sepia(1) saturate(5) hue-rotate(10deg);
}

.nft-single-audio::-webkit-media-controls-current-time-display,
.nft-single-audio::-webkit-media-controls-time-remaining-display {
    color: var(--gold);
}

/* Media Type Badges */
.media-type-badge {
    position: absolute;
    top: 1rem;
    right: 1rem;
    padding: 0.4rem 0.9rem;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
    z-index: 10;
    pointer-events: none;
}

.audio-badge {
    background: linear-gradient(135deg, var(--gold), var(--gold-dark));
    color: #000;
}

.video-badge {
    background: rgba(0, 0, 0, 0.8);
    color: #fff;
    border: 1px solid rgba(255, 255, 255, 0.3);
}

/* Master Quality Badge — positioned inside audio-cover-wrapper or video container */
/* Master status bar: sits below player inside image column, never overlays media */
.master-status-bar {
    margin-top: 0.5rem;
}

.master-badge {
    background: linear-gradient(135deg, #9b59b6, #8e44ad);
    color: #fff;
    display: none; /* shown by JS when master loads */
    padding: 0.4rem 0.9rem;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
    pointer-events: none;
    box-shadow: 0 2px 8px rgba(155, 89, 182, 0.4);
    width: fit-content;
    margin: 0.4rem auto 0;
}

.master-badge.active {
    display: block;
    animation: masterPulse 2s ease-in-out;
}

@keyframes masterPulse {
    0% { transform: scale(0.8); opacity: 0; }
    50% { transform: scale(1.05); }
    100% { transform: scale(1); opacity: 1; }
}

/* Master file loading banner — sits below the NFT image/player, always in normal flow */
.master-loading-banner {
    background: linear-gradient(135deg, rgba(155, 89, 182, 0.15), rgba(142, 68, 173, 0.1));
    border: 1px solid rgba(155, 89, 182, 0.4);
    border-radius: 8px;
    padding: 0.75rem 1.2rem;
    text-align: center;
    transition: opacity 0.4s ease;
    /* v325 fix: always in normal flow — never absolute. This guarantees visibility
       below the image at every viewport size on both mobile and desktop. */
}

.master-loading-banner.master-ready {
    background: linear-gradient(135deg, rgba(155, 89, 182, 0.25), rgba(142, 68, 173, 0.18));
    border-color: rgba(155, 89, 182, 0.7);
}

.master-loading-banner.master-hidden {
    display: none;
}

.master-loading-inner {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.6rem;
    font-size: 0.9rem;
    font-weight: 600;
    color: #c39bd3;
}

.master-loading-spinner {
    display: inline-block;
    width: 14px;
    height: 14px;
    border: 2px solid rgba(155, 89, 182, 0.3);
    border-top-color: #9b59b6;
    border-radius: 50%;
    animation: masterSpin 0.8s linear infinite;
    flex-shrink: 0;
}

.master-loading-banner.master-ready .master-loading-spinner {
    display: none;
}

@keyframes masterSpin {
    to { transform: rotate(360deg); }
}

/* v387: Master file download button — only shown when artist enables downloads */
.master-download-btn {
    display: block;
    margin: 0.5rem auto 0;
    padding: 0.5rem 1.2rem;
    background: linear-gradient(135deg, #2ecc71, #27ae60);
    color: #fff;
    border: none;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(46, 204, 113, 0.3);
    transition: all 0.2s ease;
    width: fit-content;
}
.master-download-btn:hover {
    background: linear-gradient(135deg, #27ae60, #219a52);
    box-shadow: 0 4px 12px rgba(46, 204, 113, 0.5);
    transform: translateY(-1px);
}
.master-download-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

/* v447: Cover art download button — same shape as master, distinct colour */
.cover-download-btn {
    background: linear-gradient(135deg, #3498db, #2980b9) !important;
    box-shadow: 0 2px 8px rgba(52, 152, 219, 0.3) !important;
}
.cover-download-btn:hover {
    background: linear-gradient(135deg, #2980b9, #2471a3) !important;
    box-shadow: 0 4px 12px rgba(52, 152, 219, 0.5) !important;
}

/* Mobile adjustments for media players */
@media (max-width: 768px) {
    .audio-play-indicator {
        width: 60px;
        height: 60px;
    }
    
    .play-icon-large {
        font-size: 1.8rem;
    }
    
    .media-type-badge {
        font-size: 0.75rem;
        padding: 0.3rem 0.7rem;
    }
}

.nft-single-header h1 {
    font-size: 2rem;
    margin: 0 0 0.5rem;
    /* v337: inline override — cascade from trading-hub.css was suppressed by this block */
    font-family: 'Cinzel Decorative', 'Cinzel', 'Times New Roman', serif;
    font-weight: 700;
    letter-spacing: 0.07em;
    background: linear-gradient(180deg, #ffe066 0%, var(--imu-gold, #d6ba66) 40%, #996515 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    filter: drop-shadow(0 0 18px rgba(212, 175, 55, 0.4));
}

/* v532: Artist name under the title */
.nft-single-header .nft-single-artist,
.nft-single-header .nft-single-artist-link {
    display: inline-block;
    margin: 0 0 0.5rem;
    font-size: 0.95rem;
    font-weight: 600;
    letter-spacing: 0.02em;
    color: var(--text-secondary, #b9b9c3) !important;
}
.nft-single-header .nft-single-artist-link {
    color: #c9a832 !important;
    text-decoration: none;
    transition: color 0.2s ease;
}
.nft-single-header .nft-single-artist-link:hover,
.nft-single-header .nft-single-artist-link:focus-visible {
    color: #00d4ff !important;
    text-decoration: underline;
}

.nft-single-collection a {
    color: var(--text-secondary);
    text-decoration: none;
    font-size: 0.95rem;
}

.nft-single-collection a:hover {
    color: var(--gold);
}

.nft-single-owner-box {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: 12px;
    padding: 1rem 1.25rem;
    margin: 1.5rem 0;
}

.owner-label {
    font-size: 0.8rem;
    color: var(--text-secondary);
    margin-bottom: 0.25rem;
}

.owner-address {
    font-family: monospace;
    font-size: 0.9rem;
    color: var(--text-primary);
    word-break: break-all;
}

/* ═══════════════════════════════════════════════════════════════════════
   NFT SINGLE PAGE — ACTION BUTTONS  v262
   Design system: permanently lit neon amber pill
   • Resting  = dark amber fill (#c9a832) + ambient outer glow ring — always visible
   • Hover    = same amber + intensified glow + lift
   • Secondary = dark fill + amber neon border + ambient glow — always visible
   ═══════════════════════════════════════════════════════════════════════ */

/* ── Container ───────────────────────────────────────────────────────── */
.nft-single-actions {
    display: grid !important;
    grid-template-columns: 1fr 1fr;
    gap: 0.35rem;
    margin-bottom: 1.2rem;
    line-height: 1.2;
}

/* When Buy NFT hidden: Make Offer spans both columns (full width) */
.nft-single-actions:not(.has-buy-btn) #nft-make-offer-btn {
    grid-column: 1 / -1;
}

/* v533: Buy NFT hides via class — inline display:none was overridden by .btn{display:inline-flex!important} */
.nft-single-actions .btn.nft-buy-hidden {
    display: none !important;
}

/* Mobile: single column */
@media (max-width: 600px) {
    .nft-single-actions {
        grid-template-columns: 1fr;
    }
    .nft-single-actions:not(.has-buy-btn) #nft-make-offer-btn {
        grid-column: 1;
    }
}

/* v532: Back to Collection sits in the action grid (bottom-left), fills its cell, keeps its black-pill look */
.nft-single-actions .back-to-collection-btn {
    width: 100%;
    margin: 0;
    box-sizing: border-box;
}

/* ── Base ────────────────────────────────────────────────────────────── */
.nft-single-actions .btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 0.2rem !important;
    width: 100% !important;
    padding: 0.3rem 0.7rem !important;
    font-family: inherit !important;
    font-size: 0.7rem !important;
    font-weight: 700 !important;
    letter-spacing: 0.05em !important;
    text-transform: uppercase !important;
    border-radius: 50px !important;
    cursor: pointer !important;
    border: 2px solid transparent !important;
    background: transparent !important;
    white-space: nowrap !important;
    text-align: center !important;
    transition:
        background   0.2s ease,
        border-color 0.2s ease,
        box-shadow   0.22s ease,
        transform    0.15s ease,
        opacity      0.15s ease;
    -webkit-font-smoothing: antialiased;
}

/* ── PRIMARY — amber base, electric blue on hover (IMUTV style) ─────── */
/* Rest:  solid amber fill + soft ambient amber ring                      */
/* Hover: amber surface flips to IMUTV neon blue + cyan glow ring        */
.nft-single-actions .btn-primary {
    background: #c9a832;
    border-color: #c9a832;
    color: #080808;
    box-shadow:
        0 0 0 1px rgba(201, 168, 50, 0.45),
        0 0 14px rgba(201, 168, 50, 0.28);
}

.nft-single-actions .btn-primary:hover:not(:disabled),
.nft-single-actions .btn-primary:focus-visible:not(:disabled) {
    background: #00d4ff;            /* IMUTV electric blue */
    border-color: #00d4ff;
    color: #040a0e;
    box-shadow:
        0 0 0 2px rgba(0, 212, 255, 0.75),
        0 0 22px rgba(0, 212, 255, 0.55),
        0 0 50px rgba(0, 212, 255, 0.22);
    transform: translateY(-1px);
    outline: none;
}

.nft-single-actions .btn-primary:active:not(:disabled) {
    transform: translateY(0);
    background: #00bce6;
    box-shadow:
        0 0 0 1px rgba(0, 212, 255, 0.55),
        0 0 10px rgba(0, 212, 255, 0.35);
}

/* ── SECONDARY — amber border base, blue border on hover ─────────────── */
.nft-single-actions .btn-secondary {
    background: rgba(8, 8, 12, 0.75);
    border-color: #c9a832;
    color: #c9a832;
    box-shadow:
        0 0 0 1px rgba(201, 168, 50, 0.28),
        0 0 10px rgba(201, 168, 50, 0.14);
}

.nft-single-actions .btn-secondary:hover:not(:disabled),
.nft-single-actions .btn-secondary:focus-visible:not(:disabled) {
    background: rgba(0, 212, 255, 0.06);
    border-color: #00d4ff;
    color: #00d4ff;
    box-shadow:
        0 0 0 2px rgba(0, 212, 255, 0.55),
        0 0 18px rgba(0, 212, 255, 0.35),
        0 0 36px rgba(0, 212, 255, 0.12);
    transform: translateY(-1px);
    outline: none;
}

/* ── GHOST / low-priority ────────────────────────────────────────────── */
.nft-single-actions .btn-ghost-action {
    background: rgba(10, 10, 15, 0.5);
    border-color: rgba(255, 255, 255, 0.15);
    color: rgba(255, 255, 255, 0.55);
    font-weight: 600;
    letter-spacing: 0.04em;
    box-shadow: none;
}

.nft-single-actions .btn-ghost-action:hover:not(:disabled) {
    background: rgba(255, 255, 255, 0.04);
    border-color: rgba(255, 255, 255, 0.3);
    color: rgba(255, 255, 255, 0.85);
    box-shadow: 0 0 10px rgba(255, 255, 255, 0.06);
    transform: translateY(-1px);
}

/* ── DANGER — Cancel listing ─────────────────────────────────────────── */
.nft-single-actions .btn-danger {
    background: rgba(10, 10, 15, 0.6);
    border-color: rgba(220, 60, 75, 0.5);
    color: #e06070;
    font-size: 0.8rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    padding: 0.55rem 1.4rem;
    width: auto;
    align-self: flex-start;
    box-shadow:
        0 0 0 1px rgba(220, 60, 75, 0.18),
        0 0 10px rgba(220, 60, 75, 0.12);
}

.nft-single-actions .btn-danger:hover:not(:disabled) {
    background: rgba(220, 60, 75, 0.09);
    border-color: #e06070;
    box-shadow:
        0 0 0 2px rgba(220, 60, 75, 0.45),
        0 0 16px rgba(220, 60, 75, 0.30);
    transform: translateY(-1px);
}

/* ── WATCHLIST — red neon pill ───────────────────────────────────────── */
.nft-single-actions .btn-watchlist {
    background: rgba(10, 10, 15, 0.6);
    border-color: rgba(255, 90, 90, 0.5);
    color: #ff8080;
    font-weight: 700;
    letter-spacing: 0.05em;
    box-shadow:
        0 0 0 1px rgba(255, 90, 90, 0.18),
        0 0 10px rgba(255, 90, 90, 0.12);
}

.nft-single-actions .btn-watchlist:hover:not(:disabled),
.nft-single-actions .btn-watchlist:focus-visible:not(:disabled) {
    background: rgba(255, 90, 90, 0.07);
    border-color: #ff8080;
    box-shadow:
        0 0 0 2px rgba(255, 90, 90, 0.55),
        0 0 18px rgba(255, 90, 90, 0.35),
        0 0 36px rgba(255, 90, 90, 0.12);
    transform: translateY(-1px);
    outline: none;
}

.nft-single-actions .btn-watchlist.in-watchlist {
    background: rgba(255, 90, 90, 0.1);
    border-color: rgba(255, 90, 90, 0.7);
    color: #ff8080;
    box-shadow:
        0 0 0 1px rgba(255, 90, 90, 0.30),
        0 0 12px rgba(255, 90, 90, 0.22);
}

.nft-single-actions .btn-watchlist .heart-icon {
    font-size: 1rem;
    transition: transform 0.2s ease;
}

.nft-single-actions .btn-watchlist:hover .heart-icon {
    transform: scale(1.3);
}

/* ── Disabled ────────────────────────────────────────────────────────── */
.nft-single-actions .btn:disabled {
    opacity: 0.3;
    cursor: not-allowed;
    transform: none !important;
    box-shadow: none !important;
}

/* ── Listing status display ──────────────────────────────────────────── */
.nft-listing-status {
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
}

.listing-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    background: rgba(201, 168, 50, 0.07);
    border: 1px solid rgba(201, 168, 50, 0.38);
    box-shadow:
        0 0 0 1px rgba(201, 168, 50, 0.12),
        0 0 12px rgba(201, 168, 50, 0.10);
    color: #c9a832;
    padding: 0.45rem 1rem;
    border-radius: 50px;
    font-size: 0.84rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

/* v198: Force Astra header behind modals */
body.imc-modal-open .site-header,
body.imc-modal-open #masthead,
body.imc-modal-open .ast-primary-header,
body.imc-modal-open .ast-above-header-wrap,
body.imc-modal-open .ast-header-stacked,
body.imc-modal-open .ast-mobile-header-wrap,
body.imc-modal-open header.entry-header {
    z-index: 1 !important;
    position: relative !important;
}




.nft-single-section {
    margin-bottom: 1.5rem;
}

.nft-single-section h3 {
    /* v337: inline override for Description / Attributes / Details headings */
    font-family: 'Cinzel Decorative', 'Cinzel', 'Times New Roman', serif;
    font-weight: 700;
    letter-spacing: 0.07em;
    background: linear-gradient(180deg, #ffe066 0%, var(--imu-gold, #d6ba66) 40%, #996515 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    filter: drop-shadow(0 0 14px rgba(212, 175, 55, 0.35));
    font-size: 1rem;
    margin-bottom: 0.75rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--border-subtle);
}

.nft-single-section p {
    color: var(--text-secondary);
    line-height: 1.6;
}

.nft-attributes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 0.75rem;
}

.nft-attribute {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: 8px;
    padding: 0.75rem;
    text-align: center;
}

.nft-attribute-label {
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.09em;
    margin-bottom: 0.3rem;
    font-weight: 600;
    color: var(--text-secondary, rgba(255,255,255,0.55));
}

.nft-attribute-value {
    font-size: 0.9rem;
    font-weight: 700;
    /* v337: Cinzel gold gradient for attribute values */
    font-family: 'Cinzel', 'Times New Roman', serif;
    letter-spacing: 0.04em;
    background: linear-gradient(180deg, #ffe066 0%, var(--imu-gold, #d6ba66) 40%, #996515 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    filter: drop-shadow(0 0 8px rgba(212, 175, 55, 0.2));
}

.nft-details-list {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: 10px;
    overflow: hidden;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--border-subtle);
}

.detail-row:last-child {
    border-bottom: none;
}

.detail-label {
    color: var(--text-secondary);
    font-size: 0.9rem;
}

.detail-value {
    color: var(--text-primary);
    font-family: monospace;
    font-size: 0.85rem;
}

.detail-value.owner-value {
    color: var(--gold);
    font-weight: 500;
    text-decoration: none;
    transition: opacity 0.2s;
}

a.detail-value.owner-value:hover {
    opacity: 0.8;
    text-decoration: underline;
}

.detail-value.royalty-value {
    color: #4ade80;
    font-weight: 600;
}

.nft-single-explorer {
    margin-top: 1.5rem;
}

.btn-ghost {
    display: inline-block;
    padding: 0.75rem 1.5rem;
    background: transparent;
    border: 1px solid var(--text-secondary);
    color: var(--text-secondary);
    border-radius: 8px;
    text-decoration: none;
    transition: all 0.2s;
}

.btn-ghost:hover {
    border-color: var(--gold);
    color: var(--gold);
}

.nft-single-error {
    text-align: center;
    padding: 4rem 2rem;
}

.nft-single-error h2 {
    color: #f44;
    margin-bottom: 1rem;
}

.nft-single-error p {
    color: var(--text-secondary);
    margin-bottom: 1.5rem;
}

/* Offer Modal */
.offer-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.8);
    z-index: 999998;
    align-items: center;
    justify-content: center;
    overflow-y: auto;
}

.offer-modal.active {
    display: flex;
}

.offer-modal-content {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: 16px;
    padding: 2rem;
    max-width: 400px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    margin: auto;
}

.offer-modal h3 {
    /* colour/font: .imu-heading-gold via trading-hub.css */
    margin-bottom: 1.5rem;
}

.offer-modal-close {
    float: right;
    background: none;
    border: none;
    color: var(--text-secondary);
    font-size: 1.5rem;
    cursor: pointer;
}

.offer-form-group {
    margin-bottom: 1.25rem;
}

.offer-form-group label {
    display: block;
    color: var(--text-secondary);
    font-size: 0.85rem;
    margin-bottom: 0.5rem;
}

.offer-form-group input,
.offer-form-group select {
    width: 100%;
    padding: 0.75rem;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid var(--border-subtle);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 1rem;
}

/* v86: Fix dropdown option styling */
.offer-form-group select option {
    background: #1a1a2e;
    color: #fff;
    padding: 0.5rem;
}

.offer-form-group select option:hover,
.offer-form-group select option:checked {
    background: #2a2a4e;
}

.offer-form-group input:focus,
.offer-form-group select:focus {
    outline: none;
    border-color: var(--gold);
}

.offer-submit-btn {
    width: 100%;
    padding: 0.875rem;
    background: linear-gradient(135deg, var(--gold), var(--gold-dark));
    color: #000;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    font-size: 1rem;
}

.offer-submit-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

@media (max-width: 900px) {
    /* v337: marketplace-header-nav mobile CSS removed */
    
    .nft-single-grid {
        grid-template-columns: 1fr;
        grid-template-rows: auto;
        gap: 1.5rem;
    }
    
    .nft-single-image-section,
    .nft-single-description-section,
    .nft-single-details-section {
        grid-column: 1;
        grid-row: auto;
    }
    
    .nft-single-image-section {
        overflow: visible;
    }
    
    .nft-single-image {
        max-height: 55vh;
    }
    
    .nft-media-container {
        max-height: 55vh;
    }
    
    .nft-audio-container .audio-cover-wrapper img {
        max-height: 45vh;
    }
    
    .nft-single-container {
        padding: 1rem;
    }
}

/* Listing Modal */
.listing-modal {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.8);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 999999;
    padding: 1rem;
    overflow-y: auto;
}

.listing-modal.active {
    display: flex;
}

.listing-modal-content {
    background: var(--bg-card, #12121a);
    border: 1px solid var(--gold, #d4af37);
    border-radius: 16px;
    padding: 2rem;
    max-width: 450px;
    width: 100%;
    position: relative;
    z-index: 1000000;
    max-height: 90vh;
    overflow-y: auto;
    margin: auto;
}

.listing-modal-close {
    position: absolute;
    top: 1rem;
    right: 1rem;
    background: none;
    border: none;
    color: var(--text-secondary, #888);
    font-size: 1.5rem;
    cursor: pointer;
    line-height: 1;
    padding: 0.25rem;
}

.listing-modal-close:hover {
    color: var(--gold, #d4af37);
}

.listing-modal h3 {
    /* colour/font: .imu-heading-gold via trading-hub.css */
    font-size: 1.5rem;
    margin: 0 0 1rem;
    text-align: center;
}

.listing-form-group {
    margin-bottom: 1.25rem;
}

.listing-form-group label {
    display: block;
    color: var(--text-secondary, #888);
    font-size: 0.85rem;
    margin-bottom: 0.5rem;
}

.listing-form-group input,
.listing-form-group select {
    width: 100%;
    padding: 0.75rem 1rem;
    background: rgba(10, 11, 14, 0.8);
    border: 1px solid rgba(212, 175, 55, 0.15);
    border-radius: 8px;
    color: var(--text-primary, #fff);
    font-size: 1rem;
}

.listing-form-group input:focus,
.listing-form-group select:focus {
    outline: none;
    border-color: var(--gold, #d4af37);
}

.listing-fee-breakdown {
    background: rgba(212, 175, 55, 0.05);
    border: 1px solid rgba(212, 175, 55, 0.15);
    border-radius: 8px;
    padding: 1rem;
    margin: 1.5rem 0;
}

.listing-fee-row {
    display: flex;
    justify-content: space-between;
    padding: 0.35rem 0;
    font-size: 0.9rem;
    color: var(--text-secondary, #888);
}

.listing-fee-row.total {
    border-top: 1px solid rgba(212, 175, 55, 0.18);
    margin-top: 0.5rem;
    padding-top: 0.75rem;
    font-weight: 600;
    color: var(--gold, #d4af37);
}

.listing-submit-btn {
    width: 100%;
    padding: 1rem;
    background: linear-gradient(135deg, #d4af37, #b8962e);
    color: #000;
    border: none;
    border-radius: 10px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.listing-submit-btn:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(212, 175, 55, 0.25);
}

.listing-submit-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Offer Fee Breakdown Styles */
.offer-fee-breakdown {
    background: rgba(212, 175, 55, 0.05);
    border: 1px solid rgba(212, 175, 55, 0.15);
    border-radius: 8px;
    padding: 1rem;
    margin: 1.5rem 0;
}

.offer-fee-row {
    display: flex;
    justify-content: space-between;
    padding: 0.35rem 0;
    font-size: 0.9rem;
    color: var(--text-secondary, #888);
}

.offer-fee-row.info {
    color: var(--text-muted, #666);
    font-size: 0.85rem;
}

.offer-fee-row.total {
    border-top: 1px solid rgba(212, 175, 55, 0.18);
    margin-top: 0.5rem;
    padding-top: 0.75rem;
    font-weight: 600;
    color: var(--gold, #d4af37);
}

/* ── v24: ALBUM ACCESS NFT — tracklist player ──────────────────────────── */
/* =========================================================================
   M2-c2: eBook reader. Inline on the NFT page; the fullscreen button promotes it.
   Additive only - no existing rule is edited.
   ========================================================================= */
/* R1: eBook stage - one surface for covers AND pages. */
/* R4: .nft-media-container caps every media block at 70vh. The stage is surface +
   control bar, so at that cap the bar was pushed past the edge and clipped. The
   stage sizes itself instead, and the page media is capped to leave room for the bar. */
.eb-media { position: relative; max-height: none; }
.eb-media .eb-stage { max-height: 76vh; }
.eb-stage { position: relative; background: #0e0e14; border: 1px solid rgba(201,168,50,0.22);
    border-radius: 12px; overflow: hidden; user-select: none;
    display: flex; flex-direction: column; }   /* bar is a flex child: never clipped */
/* R6: the second min-height:0 (added for flex shrink) overrode the 340px, so the
   surface collapsed to nothing the moment it held an image that had not decoded
   yet - the stage 'minimised' and then jumped back. One min-height only. */
.eb-surface { display: flex; gap: 10px; align-items: center; justify-content: center;
    padding: 14px; background: #07070b;
    flex: 1 1 auto; min-height: 340px; overflow: hidden; }
/* width/height auto matter: a PDF canvas carries intrinsic width/height attributes, so
   without them max-width scaled the width while the height stayed full size - the page
   overflowed and pushed the controls out of the (overflow:hidden) stage. */
.eb-surface img, .eb-surface canvas { max-width: 100%; max-height: calc(76vh - 64px); width: auto; height: auto;
    border-radius: 5px; display: block; object-fit: contain; box-shadow: 0 6px 22px rgba(0,0,0,0.5);
}
.eb-surface.two img, .eb-surface.two canvas { max-width: calc(50% - 5px); }
.eb-badge { position: absolute; left: 10px; top: 10px; z-index: 4; background: rgba(8,8,12,0.75);
    border: 1px solid rgba(201,168,50,0.4); color: var(--gold, #c9a832); border-radius: 6px;
    padding: 3px 9px; font-size: 11px; letter-spacing: 0.6px; }
.eb-arrow { position: absolute; top: 50%; transform: translateY(-50%); z-index: 4;
    width: 42px; height: 42px; border-radius: 50%; cursor: pointer; display: flex;
    align-items: center; justify-content: center; background: rgba(8,8,12,0.78); color: #ece7d8;
    border: 1px solid rgba(201,168,50,0.45); font-size: 21px; line-height: 1; }
.eb-arrow:hover:not(:disabled) { background: rgba(8,8,12,0.96); border-color: var(--gold, #c9a832); }
.eb-arrow:disabled { opacity: 0.25; cursor: default; }
.eb-arrow.prev { left: 10px; } .eb-arrow.next { right: 10px; }
/* V1-d: in-stage loading overlay. .eb-stage is position:relative and already hosts
   z-index-4 absolutes (.eb-badge, .eb-arrow), so this layers on without touching the
   flex layout. Gold, to match the reader -- the purple master banner BELOW the stage
   keeps the platform-wide "upgrading to master quality" language every type uses. */
.eb-loading { position: absolute; inset: 0; z-index: 6; display: flex;
    align-items: center; justify-content: center; background: rgba(7,7,11,0.82); }
.eb-loading[hidden] { display: none; }
.eb-loading-inner { display: flex; align-items: center; gap: 10px; font-size: 13px;
    font-weight: 600; color: var(--gold, #c9a832); letter-spacing: 0.3px; text-align: center;
    background: rgba(8,8,12,0.9); border: 1px solid rgba(201,168,50,0.4);
    border-radius: 10px; padding: 12px 18px; max-width: 82%; }
.eb-loading-spin { width: 14px; height: 14px; flex-shrink: 0; border-radius: 50%;
    border: 2px solid rgba(201,168,50,0.28); border-top-color: var(--gold, #c9a832);
    animation: masterSpin 0.8s linear infinite; }
/* V1-c: transient note. NEVER replaces the page - the reader keeps whatever it was
   showing, which is the entire point: setStatus() used to wipe the surface, so a
   background prefetch failure erased the spread the holder was reading. */
.eb-note { position: absolute; left: 50%; bottom: 58px; transform: translateX(-50%);
    z-index: 7; max-width: 82%; background: rgba(28,12,12,0.95);
    border: 1px solid rgba(239,68,68,0.5); color: #fca5a5; border-radius: 8px;
    padding: 8px 14px; font-size: 12px; text-align: center; }
.eb-note[hidden] { display: none; }
/* V1-e: a page the grant could not resolve. An unresolvable page is skipped rather
   than failing the whole batch, which used to leave a blank surface with a
   correct-looking page number and no explanation. */
.eb-miss { display: flex; align-items: center; justify-content: center;
    width: 190px; height: 265px; border: 1px dashed rgba(201,168,50,0.35);
    border-radius: 5px; color: rgba(236,231,216,0.5); font-size: 12px;
    text-align: center; padding: 12px; }
.eb-bar { display: flex; align-items: center; gap: 9px; padding: 9px 12px; background: #0e0e14;
    border-top: 1px solid rgba(255,255,255,0.07); font-size: 13px;
    flex: 0 0 auto; position: relative; z-index: 5; }
/* R5: the page turn is driven inline from JS (see _leave/_enter) so the incoming
   node's start state is guaranteed to be computed before it animates. Reduced
   motion is honoured in JS, so no class-based rules are needed here. */
.eb-pos { color: var(--gold, #c9a832); font-weight: 600; }
.eb-spacer { flex: 1; }
.eb-locked { opacity: 0.5; font-size: 12px; }
.eb-menu-wrap { position: relative; }
.eb-icon { width: 34px; height: 32px; display: flex; align-items: center; justify-content: center;
    background: rgba(255,255,255,0.05); color: #ece7d8; border: 1px solid rgba(255,255,255,0.14);
    border-radius: 7px; cursor: pointer; font-size: 15px; }
.eb-icon:hover, .eb-icon.on { border-color: var(--gold, #c9a832); }
.eb-icon.on { background: var(--gold, #c9a832); color: #10100c; }
.eb-menu { position: absolute; right: 0; bottom: 40px; background: #14141c;
    border: 1px solid rgba(201,168,50,0.35); border-radius: 9px; padding: 6px; min-width: 186px;
    display: none; z-index: 6; box-shadow: 0 10px 26px rgba(0,0,0,0.6); }
.eb-menu.open { display: block; }
.eb-menu h5 { margin: 4px 8px 6px; font-size: 10.5px; letter-spacing: 1.1px; text-transform: uppercase;
    opacity: 0.5; font-weight: 600; }
.eb-opt { display: block; width: 100%; text-align: left; background: none; border: none;
    color: #ece7d8; padding: 7px 9px; border-radius: 6px; cursor: pointer; font-size: 13px; }
.eb-opt:hover { background: rgba(255,255,255,0.07); }
.eb-opt-sel { background: rgba(201,168,50,0.16); color: var(--gold, #c9a832); font-weight: 600; }
.eb-status { font-size: 13px; opacity: 0.75; padding: 24px; text-align: center; }
.eb-stage:fullscreen { background: #0b0b0f; display: flex; flex-direction: column; }
.eb-stage:fullscreen .eb-surface { flex: 1; min-height: 0; }
.eb-stage:fullscreen .eb-surface img, .eb-stage:fullscreen .eb-surface canvas { max-height: calc(100vh - 62px); }
@media (max-width: 640px) {
    .eb-surface { min-height: 240px; padding: 10px; }
    .eb-arrow { width: 34px; height: 34px; font-size: 17px; }
}

/* M1-f2: AudioBook player. Mirrors the album player; no album rule is edited. */
.audiobook-player-container { position: relative; }
.ab-cover-area { position: relative; }
.ab-cover-flip { position: absolute; bottom: 10px; right: 10px; background: rgba(0,0,0,0.65);
    color: #ece7d8; border: 1px solid rgba(201,168,50,0.45); border-radius: 6px;
    padding: 6px 12px; font-size: 12px; cursor: pointer; z-index: 3; }
.ab-cover-flip:hover { border-color: var(--gold, #c9a832); }
.ab-now-playing-bar { margin-top: 10px; font-size: 13px; opacity: 0.85; }
.ab-now-playing-title { color: var(--gold, #c9a832); }
.ab-native-player { width: 100%; margin-top: 10px; }
.ab-chapterlist { margin-top: 12px; max-height: 260px; overflow-y: auto; }
.ab-chapterlist::-webkit-scrollbar { width: 4px; }
.ab-chapterlist::-webkit-scrollbar-thumb { background: rgba(201,168,50,0.3); border-radius: 2px; }
.ab-chapter-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px;
    border-radius: 6px; cursor: pointer; }
.ab-chapter-row:hover { background: rgba(201,168,50,0.06); }
.ab-chapter-row.active { background: rgba(201,168,50,0.12); }
.ab-chapter-row.active .ab-chapter-num,
.ab-chapter-row.active .ab-chapter-name { color: var(--gold, #c9a832); font-weight: 600; }
.ab-chapter-row.is-locked { cursor: default; opacity: 0.55; }
.ab-chapter-num { min-width: 22px; text-align: right; opacity: 0.7; font-size: 12px; }
.ab-chapter-name { flex: 1; font-size: 13px; }
.ab-chapter-dur, .ab-chapter-lock { font-size: 11px; opacity: 0.6; }
.ab-chapter-loading { font-size: 13px; opacity: 0.7; padding: 8px 10px; }

.album-player-container {
    background: var(--bg-card, #12121a);
    border: 1px solid var(--border-subtle, rgba(212,175,55,0.15));
    border-radius: 12px;
    overflow: hidden;
}
/* v465 BUGFIX A: The album container also carries .nft-media-container which
   applies max-height:70vh — that cap is correct for single video/audio NFTs
   (where the <video>/<audio> element IS the media) but wrong for albums
   where the cover alone fills the 70vh cap and the tracklist + now-playing
   bar + audio element below are clipped by the container's overflow:hidden.
   Compound selector below has higher specificity than .nft-media-container
   and removes the cap only for albums. overflow:hidden is retained so the
   cover's corners still clip to the container's border-radius. */
.nft-media-container.album-player-container {
    max-height: none;
}
.album-cover-area {
    position: relative;
    aspect-ratio: 1 / 1;
    overflow: hidden;
    background: #0a0a0f;
}
.album-cover-area img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.album-now-playing-bar {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.85rem 1rem;
    background: rgba(0,0,0,0.4);
    border-bottom: 1px solid var(--border-subtle, rgba(212,175,55,0.12));
    min-height: 52px;
}
.album-now-playing-label {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--gold, #c9a832);
    white-space: nowrap;
    flex-shrink: 0;
}
.album-now-playing-title {
    font-size: 0.9rem;
    font-weight: 600;
    color: #fff;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex: 1;
}
.album-native-player {
    width: 100%;
    display: block;
    background: #0d0d14;
    height: 38px;
    outline: none;
}
.album-native-player::-webkit-media-controls-panel { background: #0d0d14; }
.album-tracklist {
    list-style: none;
    margin: 0;
    padding: 0;
    max-height: 320px;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: rgba(201,168,50,0.3) transparent;
}
.album-tracklist::-webkit-scrollbar { width: 4px; }
.album-tracklist::-webkit-scrollbar-track { background: transparent; }
.album-tracklist::-webkit-scrollbar-thumb { background: rgba(201,168,50,0.3); border-radius: 2px; }
.album-track-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.7rem 1rem;
    cursor: pointer;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    transition: background 0.15s ease;
}
.album-track-row:hover { background: rgba(201,168,50,0.06); }
.album-track-row.active {
    background: rgba(201,168,50,0.12);
    border-left: 3px solid var(--gold, #c9a832);
    padding-left: calc(1rem - 3px);
}
.album-track-num {
    font-size: 0.78rem;
    color: var(--text-secondary, #888);
    width: 1.4rem;
    text-align: center;
    flex-shrink: 0;
}
.album-track-row.active .album-track-num { color: var(--gold, #c9a832); }
.album-track-name {
    flex: 1;
    font-size: 0.92rem;
    color: #e0e0e0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.album-track-row.active .album-track-name { color: #fff; font-weight: 600; }
.album-track-play-icon {
    font-size: 0.75rem;
    color: var(--gold, #c9a832);
    opacity: 0;
    transition: opacity 0.15s ease;
    flex-shrink: 0;
}
.album-track-row.active .album-track-play-icon,
.album-track-row:hover .album-track-play-icon { opacity: 1; }
.album-locked-notice {
    text-align: center;
    padding: 1.5rem 1rem;
    color: var(--text-secondary, #888);
    font-size: 0.88rem;
    background: rgba(0,0,0,0.2);
}
.album-locked-notice span { display: block; font-size: 1.5rem; margin-bottom: 0.5rem; }
/* Badge override for album */
.master-badge.album-badge::before { content: '💿 '; }
/* v458: Preview play icon always visible on tracks with preview URLs */
.album-track-row[data-preview-url] .album-track-play-icon { opacity: 0.5; }
.album-track-row[data-preview-url]:hover .album-track-play-icon { opacity: 1; }
.album-track-row[data-preview-url].preview-playing .album-track-play-icon { opacity: 1; color: #10b981; }
.album-track-row[data-preview-url].preview-playing .album-track-num { color: #10b981; }
.album-track-row[data-preview-url].preview-playing .album-track-name { color: #fff; font-weight: 600; }
.album-now-playing-preview-tag {
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    background: rgba(16,185,129,0.15);
    color: #10b981;
    padding: 2px 6px;
    border-radius: 3px;
    flex-shrink: 0;
}
@media (max-width: 768px) {
    .album-tracklist { max-height: 220px; }
    .album-now-playing-bar { padding: 0.65rem 0.75rem; }
}
/* ── MOBILE OVERFLOW — long NFT names + vault rows ─────────────────── */
/* Both elements ALREADY truncate (nowrap + ellipsis). The bug is not a
   missing wrap rule: `white-space: nowrap` sets an element's MIN-CONTENT
   width, and `overflow: hidden` clips the paint without lowering that
   floor. Below 900px the layout is a single grid track with
   `min-width: auto`, so a long title/label floors the whole column and
   every section under it renders wider than the screen — unreachable,
   because base.css/custom.css set `html,body{overflow-x:hidden}`.
   `overflow-wrap: anywhere` is deliberate: it is the only wrap value that
   also LOWERS min-content. `break-word` wraps but keeps the floor.
   The 2-line clamp bounds height growth — .nft-media-container carries
   `max-height: 55vh` + `overflow: hidden` in the 900px block above. */
@media (max-width: 900px) {
    .audio-track-title,
    .audio-track-artist {
        white-space: normal;
        overflow-wrap: anywhere;
    }
    .audio-track-title {
        display: -webkit-box;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
    }

    /* Vault: label above, buttons on their own full-width row.
       align-items MUST be reset — the base row sets `center`, which in a
       column flex would centre the label instead of left-aligning it. */
    .nft-vault-row {
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
    }
    .nft-vault-label {
        white-space: normal;
        overflow-wrap: anywhere;
    }
    .nft-vault-actions { width: 100%; }
    .nft-vault-actions .nft-vault-btn { flex: 1 1 0; }
}
/* ── end album CSS ──────────────────────────────────────────────────────── */
</style>

<div class="nft-single-container" role="main" aria-label="NFT Details">
    <!-- v337: marketplace-header-nav removed -->

    <!-- v337: breadcrumb removed — back-to-collection btn is sufficient -->
<?php if ($fetch_error): ?>
    <div class="nft-single-error">
        <h2>⚠️ Unable to Load NFT</h2>
        <p><?php echo esc_html($fetch_error); ?></p>
        <a href="<?php echo esc_url(home_url('/trading-hub/')); ?>" class="btn-ghost">← Back to Trading Hub</a>
    </div>
<?php else: ?>
    <div class="nft-single-grid">
        <div class="nft-single-image-section" role="region" aria-label="NFT media">
            <?php if ($nft_content_type === 'video' && !empty($nft_media_url)): ?>
                <!-- VIDEO NFT -->
                <div class="nft-media-container nft-video-container" oncontextmenu="return false;">
                    <video 
                        class="nft-single-video" 
                        id="nft-video-player"
                        controls 
                        playsinline
                        controlsList="nodownload nofullscreen"
                        disablePictureInPicture
                        oncontextmenu="return false;"
                        poster="<?php echo esc_url($nft_image); ?>"
                        preload="metadata"
                    >
                        <source src="<?php echo esc_url($nft_media_url); ?>" type="video/mp4">
                        <source src="<?php echo esc_url($nft_media_url); ?>" type="video/webm">
                        Your browser does not support video playback.
                    </video>
                    <div class="media-type-badge video-badge">▶ Video NFT</div>
                </div>
            <?php elseif ($is_ebook): ?>
                <!-- R1: eBOOK - ONE stage. Non-holders get the covers; holders get the whole
                     sequence (front cover, page spreads, back cover) in the SAME surface. -->
                <div class="nft-media-container eb-media" oncontextmenu="return false;">
                    <div class="eb-stage" id="eb-stage" data-front="<?php echo esc_attr($nft_image); ?>" data-back="<?php echo esc_attr($back_cover_url); ?>" data-pages="<?php echo (int) $ebook_page_count; ?>">
                        <span class="eb-badge">eBook</span>
                        <!-- V1-d/V1-c: loading overlay + transient note. Both start hidden and
                             are driven by the reader (holders) only; the non-holder cover binder
                             never touches them. -->
                        <div class="eb-loading" id="eb-loading" hidden>
                            <div class="eb-loading-inner">
                                <span class="eb-loading-spin"></span>
                                <span id="eb-loading-text">Preparing your book&hellip;</span>
                            </div>
                        </div>
                        <div class="eb-note" id="eb-note" hidden></div>
                        <!-- V1-b: both arrows start DISABLED. They used to be live from the moment
                             the HTML parsed - before any JS ran and before a single page was
                             fetched - so the first click landed mid-load and jammed the reader.
                             Both binders (holder reader / non-holder covers) enable them. -->
                        <button type="button" class="eb-arrow prev" id="eb-prev" aria-label="Previous" disabled>&#8249;</button>
                        <div class="eb-surface" id="eb-surface">
                            <img class="eb-face" id="eb-cover-front" src="<?php echo esc_url($nft_image); ?>"
                                 alt="<?php echo esc_attr($nft_name); ?>">
                        </div>
                        <button type="button" class="eb-arrow next" id="eb-next" aria-label="Next" disabled>&#8250;</button>
                        <div class="eb-bar">
                            <span class="eb-pos" id="eb-pos">Front cover</span>
                            <span class="eb-spacer"></span>
                <?php if ($is_owner && $has_ebook_access): ?>
                            <div class="eb-menu-wrap">
                                <button type="button" class="eb-icon" id="eb-gear" aria-label="View settings">&#9881;</button>
                                <div class="eb-menu" id="eb-menu">
                                    <h5>Page view</h5>
                                    <button type="button" class="eb-opt" data-view="1">Single page</button>
                                    <button type="button" class="eb-opt eb-opt-sel" data-view="2">Two pages</button>
                                </div>
                            </div>
                            <button type="button" class="eb-icon" id="eb-full" aria-label="Fullscreen">&#9974;</button>
                <?php else: ?>
                            <span class="eb-locked"><?php echo (int) $ebook_page_count; ?> pages &middot; holders only</span>
                <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php elseif ($is_audiobook): ?>
                <!-- M1-f2: AUDIOBOOK - covers (flip) + audio. Chaptered books add a chapter list. -->
                <div class="nft-media-container audiobook-player-container" oncontextmenu="return false;">
                    <div class="ab-cover-area">
                        <img class="nft-single-image audio-cover ab-cover-face" id="ab-cover-front"
                             src="<?php echo esc_url($nft_image); ?>"
                             alt="<?php echo esc_attr($nft_name); ?>" loading="lazy">
                        <?php if (!empty($back_cover_url)): ?>
                        <img class="nft-single-image audio-cover ab-cover-face ab-cover-back" id="ab-cover-back"
                             src="<?php echo esc_url($back_cover_url); ?>"
                             alt="<?php echo esc_attr($nft_name); ?> back cover" loading="lazy" style="display:none;">
                        <button type="button" class="ab-cover-flip" id="ab-cover-flip" aria-label="Flip cover">Back cover</button>
                        <?php endif; ?>
                    </div>
                    <div class="ab-now-playing-bar" id="ab-now-playing-bar">
                        <span class="ab-now-playing-title" id="ab-now-playing-title"><?php echo esc_html($nft_name); ?></span>
                    </div>
                    <?php if ($is_owner && $has_audiobook_access): ?>
                    <audio id="ab-chapter-audio" class="ab-native-player" controls controlsList="nodownload"
                           oncontextmenu="return false;" preload="none"></audio>
                    <div class="ab-chapterlist" id="ab-chapterlist">
                        <div class="ab-chapter-loading" id="ab-chapter-loading">Loading chapters...</div>
                    </div>
                    <?php endif; ?>
                    <?php if (!$is_owner || !$has_audiobook_access): ?>
                    <audio id="ab-preview-audio" class="ab-native-player" controls controlsList="nodownload"
                           oncontextmenu="return false;" preload="none"></audio>
                    <?php if ($is_audiobook_chaptered): ?>
                    <div class="ab-chapterlist ab-chapterlist-locked">
                        <?php foreach (array_slice($audiobook_chapters, 0, 50) as $ab_i => $ab_ch): ?>
                        <div class="ab-chapter-row is-locked">
                            <span class="ab-chapter-num"><?php echo (int) ($ab_ch["n"] ?? ($ab_i + 1)); ?></span>
                            <span class="ab-chapter-name"><?php echo esc_html($ab_ch["title"] ?? ""); ?></span>
                            <span class="ab-chapter-lock">locked</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                    <div class="media-type-badge audio-badge">AudioBook</div>
                </div>
            <?php elseif ($is_album): ?>
                <!-- ── v24: ALBUM ACCESS NFT — cover + tracklist ────────────── -->
                <div class="nft-media-container album-player-container" oncontextmenu="return false;">
                    <!-- Album cover art -->
                    <div class="album-cover-area">
                        <img class="nft-single-image audio-cover"
                             id="album-cover-img"
                             src="<?php echo esc_url($nft_image); ?>"
                             alt="<?php echo esc_attr($nft_name); ?>"
                             loading="lazy">
                    </div>
                    <!-- Now-playing bar (populated by JS when owner plays a track) -->
                    <div class="album-now-playing-bar" id="album-now-playing-bar">
                        <span class="album-now-playing-label">💿</span>
                        <span class="album-now-playing-title" id="album-now-playing-title">
                            <?php echo esc_html($nft_name); ?>
                        </span>
                    </div>
                    <!-- Hidden native audio element — JS drives it -->
                    <?php if ($is_owner && $has_album_access): ?>
                    <audio id="album-track-audio"
                           class="album-native-player"
                           controls
                           controlsList="nodownload"
                           oncontextmenu="return false;"
                           preload="none"></audio>
                    <?php endif; ?>

                    <!-- v458: Preview audio element for non-owners — plays IPFS preview clips -->
                    <?php if (!$is_owner || !$has_album_access): ?>
                    <audio id="album-preview-audio"
                           class="album-native-player"
                           controls
                           controlsList="nodownload"
                           oncontextmenu="return false;"
                           preload="none"></audio>
                    <?php endif; ?>
                    <!-- Tracklist -->
                    <ul class="album-tracklist" id="album-tracklist" role="list" aria-label="Album tracks">
                        <?php
                        if (!empty($album_tracks)):
                            foreach ($album_tracks as $idx => $track):
                                $track_num   = (int)($track['track_number'] ?? ($idx + 1));
                                $track_title = esc_html($track['title'] ?? ('Track ' . $track_num));
                                // v458: Extract preview CID from DB format or on-chain format
                                $preview_cid = '';
                                if (!empty($track['preview_ipfs'])) {
                                    $preview_cid = preg_replace('#^ipfs://#', '', $track['preview_ipfs']);
                                } elseif (!empty($track['preview']['audio'])) {
                                    $preview_cid = preg_replace('#^ipfs://#', '', $track['preview']['audio']);
                                }
                                $preview_url = $preview_cid ? 'https://<your-pinata-gateway>/ipfs/' . $preview_cid : ''; // AP: our Pinata gateway
                        ?>
                        <li class="album-track-row<?php echo ($idx === 0) ? ' active' : ''; ?>"
                            data-track-index="<?php echo $idx; ?>"
                            data-track-num="<?php echo $track_num; ?>"
                            data-track-title="<?php echo $track_title; ?>"
                            <?php if ($preview_url): ?>data-preview-url="<?php echo esc_url($preview_url); ?>"<?php endif; ?>
                            role="listitem"
                            <?php if ($is_owner && $has_album_access): ?>
                            tabindex="0"
                            aria-label="Play track <?php echo $track_num; ?>: <?php echo $track_title; ?>"
                            <?php elseif ($preview_url): ?>
                            tabindex="0"
                            aria-label="Preview track <?php echo $track_num; ?>: <?php echo $track_title; ?>"
                            <?php endif; ?>>
                            <span class="album-track-num"><?php echo $track_num; ?></span>
                            <span class="album-track-name"><?php echo $track_title; ?></span>
                            <span class="album-track-play-icon" aria-hidden="true">▶</span>
                        </li>
                        <?php
                            endforeach;
                        else:
                        ?>
                        <li class="album-locked-notice">
                            <span>💿</span>
                            Track listing not available
                        </li>
                        <?php endif; ?>
                        <?php if (!$is_owner || !$has_album_access): ?>
                        <li class="album-locked-notice">
                            <span>🔒</span>
                            Own this NFT to unlock full-quality tracks — tap any track to hear a preview
                        </li>
                        <?php endif; ?>
                    </ul>
                    <div class="media-type-badge audio-badge">💿 Album Access NFT</div>
                </div>
            <?php elseif ($nft_content_type === 'audio' && !empty($nft_media_url)): ?>
                <!-- AUDIO NFT -->
                <div class="nft-media-container nft-audio-container" oncontextmenu="return false;">
                    <!-- Cover Art — square, full-bleed, fades into player -->
                    <div class="audio-cover-wrapper">
                        <img class="nft-single-image audio-cover"
                             src="<?php echo esc_url($nft_image); ?>"
                             alt="<?php echo esc_attr($nft_name); ?>">
                        <div class="audio-play-indicator">
                            <span class="play-icon-large">♪</span>
                        </div>
                    </div>
                    <!-- Player area with track info -->
                    <div class="audio-player-wrapper">
                        <!-- Track identity so it's clear what's playing -->
                        <div class="audio-track-info">
                            <span class="audio-track-title"><?php echo esc_html($nft_name); ?></span>
                            <?php
                            // v493 Phase 3: resolver precedence (dashboard -> listing -> meta -> wallet).
                            $display_artist = function_exists('imc_resolve_artist_name')
                                ? imc_resolve_artist_name(
                                    $local_nft['artist_account'] ?? '',
                                    $local_nft['artist_name'] ?? '',
                                    ($nft_metadata['properties']['artist'] ?? ($nft_metadata['artist'] ?? ''))
                                  )
                                : '';
                            if ($display_artist === '') {
                                if (!empty($local_nft['artist_name'])) {
                                    $display_artist = $local_nft['artist_name'];
                                } elseif (!empty($nft_metadata['properties']['artist'])) {
                                    $display_artist = $nft_metadata['properties']['artist'];
                                } elseif (!empty($nft_metadata['artist'])) {
                                    $display_artist = $nft_metadata['artist'];
                                }
                            }
                            if ($display_artist): ?>
                            <span class="audio-track-artist"><?php echo esc_html($display_artist); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($nft_media_url)): ?>
                        <audio
                            id="nft-audio-player"
                            class="nft-single-audio"
                            controls
                            controlsList="nodownload"
                            oncontextmenu="return false;"
                            preload="metadata"
                        >
                            <source src="<?php echo esc_url($nft_media_url); ?>" type="audio/mpeg">
                            <source src="<?php echo esc_url($nft_media_url); ?>" type="audio/wav">
                            <source src="<?php echo esc_url($nft_media_url); ?>" type="audio/ogg">
                            Your browser does not support audio playback.
                        </audio>
                        <?php else: ?>
                        <div style="padding:10px 0;font-size:0.85rem;color:#94a3b8;">Preview unavailable — own this NFT for full playback.</div>
                        <?php endif; ?>
                    </div>
                    <div class="media-type-badge audio-badge">♪ Audio NFT</div>
                </div>
            <?php else: ?>
                <!-- IMAGE NFT (default) -->
                <img class="nft-single-image" src="<?php echo esc_url($nft_image); ?>" alt="<?php echo esc_attr($nft_name); ?>" loading="lazy">
            <?php endif; ?>

            <?php /* Phase D - on-demand on-chain metadata refresh (external/XRPL-wide NFTs only; platform NFTs untouched) */ ?>
            <?php if (empty($local_nft) && !empty($nft_id)): ?>
            <div class="imc-refresh-wrap">
                <button type="button" id="imc-refresh-nft-btn" class="imc-refresh-btn" data-nft="<?php echo esc_attr($nft_id); ?>">
                    <span class="imc-refresh-ico">&#8635;</span> Refresh metadata
                </button>
                <span id="imc-refresh-msg" class="imc-refresh-msg" aria-live="polite"></span>
            </div>
            <style>
                .imc-refresh-wrap{margin-top:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
                .imc-refresh-btn{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.18);color:#cfd3dc;font-size:13px;font-weight:500;padding:7px 13px;border-radius:8px;cursor:pointer;transition:background .15s,color .15s,opacity .15s}
                .imc-refresh-btn:hover:not(:disabled){background:rgba(255,255,255,.12);color:#fff}
                .imc-refresh-btn:disabled{opacity:.55;cursor:default}
                .imc-refresh-ico{display:inline-block;font-size:15px;line-height:1}
                .imc-refresh-ico.spin{animation:imc-refresh-spin .8s linear infinite}
                .imc-refresh-msg{font-size:12.5px;color:#8b93a3}
                .imc-refresh-msg.ok{color:#5fd38d}
                .imc-refresh-msg.err{color:#e0746f}
                @keyframes imc-refresh-spin{to{transform:rotate(360deg)}}
            </style>
            <script>
            (function(){
                var btn = document.getElementById('imc-refresh-nft-btn');
                if(!btn) return;
                var msg = document.getElementById('imc-refresh-msg');
                var ico = btn.querySelector('.imc-refresh-ico');
                var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                var nonce = '<?php echo esc_js($nonce); ?>';
                function setMsg(t,cls){ if(msg){ msg.className='imc-refresh-msg'+(cls?(' '+cls):''); msg.textContent=t; } }
                btn.addEventListener('click', function(){
                    var nftId = btn.getAttribute('data-nft') || '';
                    if(!nftId) return;
                    btn.disabled = true;
                    if(ico) ico.classList.add('spin');
                    setMsg('Refreshing on-chain metadata...','');
                    var fd = new FormData();
                    fd.append('action','imc_refresh_nft');
                    fd.append('nonce',nonce);
                    fd.append('nft_id',nftId);
                    fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
                        .then(function(r){ return r.json(); })
                        .then(function(j){
                            if(j && j.success){
                                var d = j.data || {};
                                var img = document.querySelector('.nft-single-image');
                                var src = d.image_url || d.image_proxy || '';
                                if(img && src){
                                    var sep = src.indexOf('?')>-1 ? '&' : '?';
                                    img.src = src + sep + 'nocache=1&v=' + Date.now();
                                }
                                setMsg('\u2713 Updated to the latest on-chain data.','ok');
                                setTimeout(function(){ location.reload(); }, 900);
                            } else {
                                setMsg((j && j.data && j.data.message) ? j.data.message : 'Refresh failed. Try again shortly.','err');
                            }
                        })
                        .catch(function(){ setMsg('Network error. Please try again.','err'); })
                        .finally(function(){
                            btn.disabled = false;
                            if(ico) ico.classList.remove('spin');
                        });
                });
            })();
            </script>
            <?php endif; ?>

            <?php if ($is_owner && $has_master_access): ?>
            <!-- Master quality badge + loading banner — below the player, inside the image column -->
            <div class="master-status-bar">
                <?php
                /* R1-B: the upgrade ROUTINE is excluded for eBook and chaptered AudioBook
                   (see initMasterAccess), and only that routine ever hides this banner - the
                   album player hides its own. So for those two types the spinner rendered and
                   then spun forever. They deliver their content per page / per chapter through
                   the grant instead, so there is no single master file to upgrade to. */
                /* V1-d (Sep 2026) REPLACES R1-B. R1-B suppressed this banner for eBooks because
                   initMasterAccess -- the only routine that ever hid it -- is excluded for them,
                   so it would have spun forever. The reader now drives it itself (see
                   _bannerText/_bannerReady/_bannerHide in initEbookReader), using the SAME
                   helper shape and the SAME .master-ready/.master-hidden classes as
                   initMasterAccess and initAlbumPlayer. Chaptered AudioBooks still have no
                   driver, so they stay suppressed. */
                $imc_show_master_banner = !$is_audiobook_chaptered;
                ?>
                <?php if ($imc_show_master_banner): ?>
                <div id="master-loading-banner" class="master-loading-banner">
                    <div class="master-loading-inner">
                        <span class="master-loading-spinner"></span>
                        <span id="master-loading-text"><?php echo $is_album ? '💿 Loading album tracks…' : '⬆️ Upgrading to master quality…'; ?></span>
                    </div>
                </div>
                <?php endif; ?>
                <div class="master-badge<?php echo $is_album ? ' album-badge' : ''; ?>" id="master-badge"><?php echo $is_album ? '💿 Album Access' : '🎵 Master Quality'; ?></div>
                <?php if ($is_downloadable && !$is_album && !$is_audiobook_chaptered): ?>
                <button id="master-download-btn" class="master-download-btn" onclick="window.imcDownloadMaster()" title="Download the original master quality file">
                    ⬇️ Download Master File
                </button>
                <?php if ($has_cover_access): ?>
                <button id="cover-download-btn" class="master-download-btn cover-download-btn" onclick="window.imcDownloadCover()" title="Download the original cover artwork">
                    🎨 Download Cover Art
                </button>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- U9-r2: Attributes moved here (left column, below media) --
             description now lives in the right column under the action buttons. -->
        <?php if (!empty($nft_attributes) && is_array($nft_attributes)): ?>
        <div class="nft-single-description-section" role="region" aria-label="NFT attributes">
            <div class="nft-single-section">
                <h3>Attributes</h3>
                <div class="nft-attributes-grid">
                    <?php foreach ($nft_attributes as $attr): ?>
                    <div class="nft-attribute">
                        <div class="nft-attribute-label"><?php echo esc_html($attr['trait_type'] ?? 'Trait'); ?></div>
                        <div class="nft-attribute-value"><?php echo esc_html($attr['value'] ?? '-'); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="nft-single-details-section">
            <div class="nft-single-header">
                <h1><?php echo esc_html($nft_name); ?></h1>
                <?php
                // v532: Artist name under the title — links to creator profile when one exists
                $_title_artist_acct = !empty($local_nft['artist_account']) ? $local_nft['artist_account'] : $nft_issuer;
                $_title_artist = function_exists('imc_resolve_artist_name')
                    ? imc_resolve_artist_name(
                        $local_nft['artist_account'] ?? '',
                        $local_nft['artist_name'] ?? '',
                        ($nft_metadata['properties']['artist'] ?? ($nft_metadata['artist'] ?? ''))
                      ) : '';
                $_title_artist_slug = (function_exists('imc_artist_profile_get') && $_title_artist_acct !== '')
                    ? (imc_artist_profile_get($_title_artist_acct)['slug'] ?? '') : '';
                if ($_title_artist !== ''):
                    if ($_title_artist_slug !== ''): ?>
                        <a href="<?php echo esc_url(home_url('/artists/' . $_title_artist_slug . '/')); ?>" class="nft-single-artist-link">by <?php echo esc_html($_title_artist); ?></a>
                    <?php else: ?>
                        <span class="nft-single-artist">by <?php echo esc_html($_title_artist); ?></span>
                    <?php endif;
                endif; ?>
            </div>

            <?php if ($nft_is_scam): ?>
            <div class="nft-scam-warning" role="alert" style="margin:0 0 16px;padding:14px 16px;border-radius:10px;background:rgba(220,40,55,0.12);border:1px solid rgba(220,40,55,0.5);color:#ff6b78;font-weight:600;line-height:1.45;">
                &#9888;&#65039; High-risk issuer &mdash; this NFT&rsquo;s creator address has been flagged as a scam by XRPL.to. Trading has been disabled here for your protection.
            </div>
            <?php endif; ?>

            <div class="nft-single-actions">
                <?php if ($is_owner): ?>
                    <!-- OWNER: Primary = List/Status, Secondary = Transfer -->
                    <?php if ($active_listing): ?>
                        <div id="nft-listing-status" class="nft-listing-status"
                             data-offer-id="<?php echo esc_attr($active_listing['offer_id']); ?>"
                             data-pending-verify="1">
                            <span class="listing-badge" id="listing-badge-text">
                                🏷️ Listed for <?php echo esc_html(number_format((float)$active_listing['amount'], 6)); ?> <?php echo esc_html(function_exists('imc_offer_currency_display') ? imc_offer_currency_display($active_listing['currency']) : $active_listing['currency']); ?>
                                <span id="listing-verify-dot" title="Verifying on-chain…" style="display:inline-block;width:7px;height:7px;border-radius:50%;background:rgba(212,175,55,0.5);margin-left:5px;vertical-align:middle;"></span>
                            </span>
                            <button id="nft-cancel-listing-btn" class="btn btn-danger"
                                    data-offer-id="<?php echo esc_attr($active_listing['offer_id']); ?>">
                                Cancel Listing
                            </button>
                        </div>
                    <?php else: ?>
                        <button id="nft-list-btn" class="btn btn-primary">
                            🏷️ List for Sale
                        </button>
                    <?php endif; ?>
                    <button id="nft-transfer-btn" class="btn btn-secondary"
                            data-nft-id="<?php echo esc_attr($nft_id); ?>"
                            data-nft-name="<?php echo esc_attr($nft_name); ?>">
                        🔁 Transfer NFT
                    </button>
                    <button id="nft-watchlist-btn" class="btn btn-watchlist"
                            data-nft-id="<?php echo esc_attr($nft_id); ?>"
                            data-nft-name="<?php echo esc_attr($nft_name); ?>">
                        <span class="heart-icon">♡</span>
                        <span class="watchlist-text">Favourite</span>
                    </button>
                <?php else: ?>
                    <!-- VISITOR: Make Offer = primary full-width, then row of secondary actions -->
                    <!-- Buy NFT button — hidden until JS confirms an active sell listing -->
                    <button id="nft-buy-nft-btn" class="btn btn-primary nft-buy-hidden"
                            data-nft-id="<?php echo esc_attr($nft_id); ?>">
                        🛒 Buy NFT
                    </button>
                    <button id="nft-make-offer-btn" class="btn btn-primary"
                            data-nft-id="<?php echo esc_attr($nft_id); ?>"
                            data-owner="<?php echo esc_attr($nft_owner); ?>"
                            data-name="<?php echo esc_attr($nft_name); ?>">
                        💬 Make Offer
                    </button>
                    <button id="nft-watchlist-btn" class="btn btn-watchlist"
                            data-nft-id="<?php echo esc_attr($nft_id); ?>"
                            data-nft-name="<?php echo esc_attr($nft_name); ?>">
                        <span class="heart-icon">♡</span>
                        <span class="watchlist-text">Favourite</span>
                    </button>
                    <button id="nft-message-owner-btn" class="btn btn-secondary"
                            data-owner="<?php echo esc_attr($nft_owner); ?>"
                            data-nft-id="<?php echo esc_attr($nft_id); ?>">
                        ✉️ Message Owner
                    </button>
                <?php endif; ?>
                <!-- v532: Back to Collection — bottom-left of the action grid, paired with View on TV -->
                <?php if (!empty($_col_url)): ?>
                <a href="<?php echo esc_url($_col_url); ?>" class="back-to-collection-btn">
                    ← Back to Collection
                </a>
                <?php endif; ?>
                <!-- v409: View on TV tutorial link — visible to all users -->
                <a href="<?php echo esc_url(home_url('/view-on-tv/')); ?>" class="btn btn-secondary nft-tv-btn" title="Learn how to view your NFTs on TV">
                    📺 View on TV
                </a>
            </div>

            <!-- U9-r2: Description (all NFT types) directly under the action
                 buttons -- clamped to 3 lines with Read more. Attributes moved to
                 the left column below the media. -->
            <?php if (!empty($nft_description)): ?>
            <div class="nft-single-section">
                <h3>Description</h3>
                <p id="nft-desc-text" class="nft-desc-clamp"><?php echo esc_html($nft_description); ?></p>
                <button id="nft-desc-toggle" class="nft-desc-toggle" style="display:none;" onclick="var t=document.getElementById('nft-desc-text');var open=t.classList.toggle('open');this.textContent=open?'Read less':'Read more';">Read more</button>
                <script>(function(){var t=document.getElementById('nft-desc-text'),b=document.getElementById('nft-desc-toggle');if(t&&b&&t.scrollHeight>t.clientHeight+2)b.style.display='';})();</script>
            </div>
            <?php endif; ?>

            <?php if (!empty($nft_unlockables)): ?>
            <!-- U6/U9 (Unlockables Master): THE VAULT -- boxed card. Non-owners get a
                 locked teaser (COUNT ONLY -- no labels/sizes reach the HTML); owners
                 get rows with View (previewable types) + Download. -->
            <div class="nft-single-details-section nft-vault-section nft-vault-card">
                <h3>🔐 The Vault
                    <span class="nft-vault-count"><?php echo count($nft_unlockables); ?> item<?php echo count($nft_unlockables) === 1 ? '' : 's'; ?></span></h3>
                <?php if (!$is_owner): ?>
                <div class="nft-vault-locked-box">
                    <div class="nft-vault-locked-icon">🔒</div>
                    <div class="nft-vault-locked-text">The Vault contains <strong><?php echo count($nft_unlockables); ?> item<?php echo count($nft_unlockables) === 1 ? '' : 's'; ?></strong> exclusively available for collectors.</div>
                    <div class="nft-vault-locked-cta">Own this NFT to unlock</div>
                </div>
                <?php else: ?>
                <div class="nft-vault-list">
                    <?php foreach ($nft_unlockables as $imc_ul):
                        $imc_ul_mime = strtolower((string)($imc_ul['mime_type'] ?? ''));
                        $imc_ul_viewable = (bool) preg_match('#^(image/|audio/|video/|application/pdf|text/plain)#', $imc_ul_mime);
                    ?>
                    <div class="nft-vault-row">
                        <div class="nft-vault-info">
                            <span class="nft-vault-label"><?php echo esc_html($imc_ul['label'] ?: 'Unlockable file'); ?></span>
                            <span class="nft-vault-meta"><?php echo esc_html(strtoupper(pathinfo('x.' . (explode('/', $imc_ul_mime ?: 'file/bin')[1] ?? 'bin'), PATHINFO_EXTENSION))); ?> · <?php echo esc_html(size_format(intval($imc_ul['file_size']), 1)); ?></span>
                        </div>
                        <div class="nft-vault-actions">
                            <?php if ($imc_ul_viewable): ?>
                            <button class="nft-vault-btn nft-vault-view-btn"
                                    onclick="imcViewUnlockable('<?php echo esc_js($imc_ul['content_hash']); ?>', '<?php echo esc_js($imc_ul_mime); ?>', '<?php echo esc_js($imc_ul['label'] ?: 'Unlockable'); ?>', this)">
                                👁️ View
                            </button>
                            <?php endif; ?>
                            <button class="nft-vault-btn nft-vault-dl-btn"
                                    onclick="imcDownloadUnlockable('<?php echo esc_js($imc_ul['content_hash']); ?>', '<?php echo esc_js(sanitize_file_name($imc_ul['label'] ?: 'unlockable')); ?>', this)">
                                ⬇️ Download
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- v466 PHASE1: For album NFTs only, description renders here —
                 between Attributes and Details. The tracklist is already the
                 dominant element in the left column below the cover, so the
                 description moves to the right column for a cleaner layout.
                 For non-album NFTs this block is skipped and the description
                 stays in col-1-row-2 (see conditional block above). -->

            <?php if (!empty($nft_unlockables) && $is_owner): ?>
            <script>
            /* U9-r1: these fns previously lived inside the COVER-download script
               region gated by ($is_downloadable && !$is_album && $has_cover_access),
               so owner pages without cover access threw ReferenceError. u9r2: the first
               relocation anchored on 'nft-vault-actions' -- whose FIRST occurrence
               is in the CSS block -- and landed inside the album-only markup.
               Now seated before the always-rendered Details section. */
    // In-page preview for vault files. The signed URL never enters the DOM.
    window.imcViewUnlockable = async function(contentHash, mime, label, btn) {
        if (!btn) return;
        const originalText = btn.innerHTML;
        btn.disabled = true; btn.textContent = '\u23f3';
        try {
            const response = await fetch('/wp-json/imu-master/v1/session-access', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>' },
                body: JSON.stringify({ nftoken_id: '<?php echo esc_js($nft_id); ?>', asset_type: 'unlockable', content_hash: contentHash })
            });
            const data = await response.json();
            if (window.__imcHandleReauth(response)) return;
            if (!data.granted || !data.url) throw new Error(data.error || 'Access denied');
            const fileRes = await fetch(data.url, { credentials: 'omit' });
            if (!fileRes.ok) throw new Error('Load failed: ' + fileRes.status);
            const blob = await fileRes.blob();
            const blobUrl = URL.createObjectURL(blob);
            let inner = '';
            if (mime.indexOf('image/') === 0)      inner = '<img src="' + blobUrl + '" style="max-width:100%;max-height:76vh;border-radius:10px;display:block;margin:0 auto;">';
            else if (mime.indexOf('audio/') === 0) inner = '<audio controls autoplay controlsList="nodownload" style="width:100%;" src="' + blobUrl + '"></audio>';
            else if (mime.indexOf('video/') === 0) inner = '<video controls autoplay controlsList="nodownload" style="max-width:100%;max-height:74vh;display:block;margin:0 auto;border-radius:10px;" src="' + blobUrl + '"></video>';
            else if (mime === 'application/pdf')   inner = '<iframe src="' + blobUrl + '" style="width:100%;height:76vh;border:0;border-radius:10px;background:#fff;"></iframe>';
            else if (mime === 'text/plain') { const txt = await blob.text(); inner = '<pre style="max-height:70vh;overflow:auto;white-space:pre-wrap;font-size:0.85rem;">' + txt.replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</pre>'; }
            else throw new Error('No preview for this file type');
            let ov = document.getElementById('imc-vault-viewer');
            if (!ov) {
                ov = document.createElement('div');
                ov.id = 'imc-vault-viewer';
                ov.innerHTML = '<div class="ivv-box"><div class="ivv-head"><span class="ivv-title"></span><button class="ivv-close" aria-label="Close">\u2715</button></div><div class="ivv-body"></div></div>';
                document.body.appendChild(ov);
                ov.addEventListener('click', (e) => { if (e.target === ov || e.target.classList.contains('ivv-close')) window.imcCloseVaultViewer(); });
            }
            ov.querySelector('.ivv-title').textContent = label || 'Vault file';
            ov.querySelector('.ivv-body').innerHTML = inner;
            ov.dataset.blobUrl = blobUrl;
            ov.classList.add('open');
            if (typeof data.downloads_remaining === 'number' && data.downloads_remaining <= 3) {
                if (typeof imcToast === 'function') imcToast(data.downloads_remaining + ' downloads left this month');
            }
        } catch (err) {
            btn.textContent = '\u274c';
            setTimeout(() => { btn.innerHTML = originalText; }, 2200);
        } finally {
            setTimeout(() => { btn.disabled = false; if (btn.textContent === '\u23f3') btn.innerHTML = originalText; }, 600);
        }
    };
    window.imcCloseVaultViewer = function() {
        const ov = document.getElementById('imc-vault-viewer');
        if (!ov) return;
        ov.classList.remove('open');
        const b = ov.querySelector('.ivv-body'); if (b) b.innerHTML = '';
        if (ov.dataset.blobUrl) { URL.revokeObjectURL(ov.dataset.blobUrl); delete ov.dataset.blobUrl; }
    };

    // Per-file vault download. The signed URL never enters the DOM.
    window.imcDownloadUnlockable = async function(contentHash, safeName, btn) {
        if (!btn) return;
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.textContent = '\u23f3 Preparing\u2026';
        try {
            const response = await fetch('/wp-json/imu-master/v1/session-access', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                },
                body: JSON.stringify({
                    nftoken_id: '<?php echo esc_js($nft_id); ?>',
                    asset_type: 'unlockable',
                    content_hash: contentHash
                })
            });
            const data = await response.json();
            if (window.__imcHandleReauth(response)) return; // 2b: token-only 401 -> re-auth
            if (!data.granted || !data.url) {
                throw new Error(data.error || 'Access denied');
            }
            btn.textContent = '\u2b07\ufe0f Downloading\u2026';
            const fileRes = await fetch(data.url, { credentials: 'omit' });
            if (!fileRes.ok) throw new Error('Download failed: ' + fileRes.status);
            const blob = await fileRes.blob();
            const blobUrl = URL.createObjectURL(blob);
            const ext = (data.mime_type || 'application/octet-stream').split('/').pop() || 'bin';
            const a = document.createElement('a');
            a.href = blobUrl;
            a.download = (safeName || 'unlockable') + '.' + ext;
            a.style.display = 'none';
            document.body.appendChild(a);
            a.click();
            setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(blobUrl); }, 1500);
            btn.textContent = '\u2705 Downloaded';
            if (typeof data.downloads_remaining === 'number' && data.downloads_remaining <= 3) {
                if (typeof imcToast === 'function') imcToast(data.downloads_remaining + ' downloads left this month');
            }
            setTimeout(() => { btn.innerHTML = originalText; btn.disabled = false; }, 2500);
        } catch (err) {
            btn.textContent = '\u274c ' + (err.message || 'Failed');
            setTimeout(() => { btn.innerHTML = originalText; btn.disabled = false; }, 3000);
        }
    };

            </script>
            <?php endif; ?>
            <div class="nft-single-section">
                <h3>Details</h3>
                <div class="nft-details-list">
                    <div class="detail-row">
                        <span class="detail-label">NFT ID</span>
                        <span class="detail-value"><?php echo esc_html(substr($nft_id, 0, 16) . '...'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Issuer</span>
                        <span class="detail-value" title="<?php echo esc_attr($nft_issuer); ?>"><?php echo esc_html($nft_issuer ? substr($nft_issuer, 0, 12) . '...' : '-'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Current Owner</span>
                        <?php if ($nft_owner !== 'Unknown'): ?>
                        <a href="https://bithomp.com/explorer/<?php echo esc_attr($nft_owner); ?>" target="_blank" class="detail-value owner-value" title="<?php echo esc_attr($nft_owner); ?>"><?php echo esc_html(substr($nft_owner, 0, 12) . '...'); ?> ↗</a>
                        <?php else: ?>
                        <span class="detail-value">Unknown</span>
                        <?php endif; ?>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Taxon</span>
                        <span class="detail-value"><?php echo esc_html($nft_taxon); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Creator Royalty</span>
                        <span class="detail-value royalty-value"><?php echo esc_html($nft_transfer_fee); ?></span>
                    </div>
                </div>
            </div>

            <div class="nft-single-explorer">
                <a href="https://bithomp.com/nft/<?php echo esc_attr($nft_id); ?>" target="_blank" rel="noopener" class="btn-ghost">
                    View on Bithomp ↗
                </a>
            </div>

            <!-- ── v258: Live Marketplace Offers Panel ────────────────────────── -->
            <div id="nft-live-offers-panel" style="margin-top:1.5rem;">

                <!-- Sell Offers (listings) — for non-owners or when owner listed externally -->
                <div id="nft-sell-listings-panel" style="display:none;">
                    <div style="border-top:1px solid rgba(var(--imu-gold-rgb), 0.15);padding-top:1rem;margin-bottom:0.5rem;">
                        <h4 style="margin:0 0 0.75rem;font-size:0.9rem;color:var(--gold);font-weight:600;">
                            🏷️ Active Sell Listings <span id="sell-listings-count" style="background:rgba(var(--imu-gold-rgb), 0.15);border-radius:10px;padding:1px 8px;font-size:0.78rem;margin-left:6px;">0</span>
                            <span id="sell-listings-source" style="font-size:0.7rem;color:var(--text-secondary);font-weight:400;margin-left:8px;">Live from XRPL</span>
                        </h4>
                        <div id="sell-listings-list"></div>
                    </div>
                </div>

                <!-- Buy Offers — visible to the NFT owner -->
                <div id="nft-buy-offers-panel" style="display:none;">
                    <div style="border-top:1px solid rgba(var(--imu-gold-rgb), 0.15);padding-top:1rem;">
                        <h4 style="margin:0 0 0.75rem;font-size:0.9rem;color:var(--gold);font-weight:600;">
                            💰 Active Buy Offers <span id="buy-offers-count" style="background:rgba(var(--imu-gold-rgb), 0.15);border-radius:10px;padding:1px 8px;font-size:0.78rem;margin-left:6px;">0</span>
                            <span id="buy-offers-source" style="font-size:0.7rem;color:var(--text-secondary);font-weight:400;margin-left:8px;">Live from XRPL</span>
                        </h4>
                        <div id="buy-offers-list"></div>
                    </div>
                </div>

            </div>
            <!-- ── end live offers panel ──────────────────────────────────────── -->

        </div>
    </div>
<?php endif; ?>

</div>

<!-- Offer Modal -->
<div id="offer-modal" class="offer-modal" role="dialog" aria-label="Make an offer" aria-modal="true">
    <div class="offer-modal-content">
        <button class="offer-modal-close" onclick="closeOfferModal()" aria-label="Close offer dialog">×</button>
        <h3>Make an Offer</h3>
        <div id="offer-nft-preview" style="margin-bottom: 1rem; color: var(--text-secondary); font-size: 0.9rem; text-align: center;"></div>
        <form id="offer-form" aria-label="NFT offer form">
            <div class="offer-form-group">
                <label for="offer-amount">Your Offer Amount</label>
                <input type="number" id="offer-amount" step="0.000001" min="0.000001" placeholder="0.00" required aria-label="Offer amount">
            </div>
            <div class="offer-form-group">
                <label for="offer-currency">Currency</label>
                <select id="offer-currency" aria-label="Payment currency">
                    <option value="XRP">💧 XRP</option>
                    <!-- Tokens loaded dynamically from Token Manager -->
                </select>
                <div id="offer-trustline-warning" style="display: none; margin-top: 0.5rem; padding: 0.5rem; background: rgba(255,193,7,0.1); border: 1px solid rgba(255,193,7,0.3); border-radius: 6px; font-size: 0.85rem; color: #ffc107;">
                    ⚠️ <span id="trustline-warning-text">You need a trustline for this token</span>
                    <a href="#" id="set-trustline-link" target="_blank" style="display: block; margin-top: 0.5rem; color: var(--gold);">Set Trustline →</a>
                </div>
            </div>
            
<!-- Fee Breakdown for Offers -->
            <div class="offer-fee-breakdown" id="offer-fee-breakdown" style="display: none;">
                <div class="offer-fee-row">
                    <span>Offer Amount</span>
                    <span id="offer-amount-display">-</span>
                </div>
                <div class="offer-fee-row info">
                    <span>Platform Fee (1.5%)</span>
                    <span id="offer-platform-fee">-</span>
                </div>
                <div class="offer-fee-row info">
                    <span>Creator Royalty (<span id="offer-royalty-percent">0</span>%)</span>
                    <span id="offer-royalty-fee">-</span>
                </div>
                <div class="offer-fee-row total">
                    <span>Seller Receives</span>
                    <span id="offer-net-amount">-</span>
                </div>
            </div>
            
            <button type="submit" class="offer-submit-btn" id="offer-submit-btn">
                Submit Offer
            </button>
        </form>
    </div>
</div>

<!-- Listing Modal (for owners) - v94: GTC Only -->
<div id="listing-modal" class="listing-modal">
    <div class="listing-modal-content">
        <button class="listing-modal-close" onclick="closeListingModal()">×</button>
        <h3>List for Sale</h3>
        <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1.5rem; text-align: center;">
            <?php echo esc_html($nft_name); ?>
        </p>
        <form id="listing-form">
            <div class="listing-form-group">
                <label>Sale Price</label>
                <input type="number" id="listing-amount" step="0.000001" min="0.000001" placeholder="0.00" required>
            </div>
            <div class="listing-form-group">
                <label>Currency</label>
                <select id="listing-currency">
                    <option value="XRP" data-issuer="">💧 XRP</option>
                    <!-- v197: Tokens loaded dynamically from Token Manager + user trustlines -->
                </select>
            </div>
            <div id="listing-trustline-warning" style="display: none; margin-top: 0.5rem; padding: 0.5rem; background: rgba(255,193,7,0.1); border: 1px solid rgba(255,193,7,0.3); border-radius: 6px; font-size: 0.85rem; color: #ffc107;">
                ⚠️ <span id="listing-trustline-text">You need a trustline for this token to receive payment</span>
                <a href="#" id="listing-trustline-link" target="_blank" style="display: block; margin-top: 0.5rem; color: var(--gold);">Set Trustline →</a>
            </div>
            
            <div class="listing-fee-breakdown" id="listing-fee-breakdown" style="display: none;">
                <div class="listing-fee-row">
                    <span>Sale Price</span>
                    <span id="listing-price-display">-</span>
                </div>
                <div class="listing-fee-row">
                    <span>Platform Fee (1.5%)</span>
                    <span id="listing-platform-fee">-</span>
                </div>
                <div class="listing-fee-row">
                    <span>Creator Royalty (<?php echo esc_html($nft_transfer_fee); ?>)</span>
                    <span id="listing-royalty-fee">-</span>
                </div>
                <div class="listing-fee-row total">
                    <span>You'll Receive</span>
                    <span id="listing-net-amount">-</span>
                </div>
            </div>
            
            <button type="submit" class="listing-submit-btn" id="listing-submit-btn">
                Create Listing
            </button>
            <p style="color: var(--text-secondary); font-size: 0.8rem; margin-top: 12px; text-align: center;">
                Listing stays active until sold or cancelled
            </p>
        </form>
    </div>
</div>

<!-- Transfer NFT Modal (v250) -->
<div id="transfer-modal" class="listing-modal">
    <div class="listing-modal-content">
        <button class="listing-modal-close" onclick="closeTransferModal()">×</button>
        <h3>🔁 Transfer NFT</h3>
        <p style="color:var(--text-secondary);font-size:0.9rem;margin-bottom:1.5rem;text-align:center;">
            <?php echo esc_html($nft_name); ?>
        </p>
        <div style="background:rgba(255,215,0,0.06);border:1px solid rgba(255,215,0,0.2);border-radius:10px;padding:1rem;margin-bottom:1.5rem;font-size:0.88rem;color:var(--text-secondary);line-height:1.6;">
            ⚠️ <strong style="color:var(--gold);">This sends a transfer offer to the recipient.</strong><br>
            They will need to accept it in their wallet. The NFT stays in your wallet until accepted. This transfer is <strong>free</strong> — no payment required.
        </div>
        <form id="transfer-form">
            <div class="listing-form-group">
                <label for="transfer-destination">Recipient XRPL Address</label>
                <input type="text" id="transfer-destination" placeholder="rRecipientXRPLAddress..." autocomplete="off" spellcheck="false">
                <div id="transfer-addr-error" style="display:none;margin-top:0.4rem;font-size:0.82rem;color:#f87171;"></div>
            </div>
            <button type="submit" class="listing-submit-btn" id="transfer-submit-btn">
                Send Transfer Offer
            </button>
        </form>
    </div>
</div>

<!-- QR Code / Signing Modal -->
<div id="qr-modal" class="qr-modal">
    <div class="qr-modal-content">
        <button class="qr-modal-close" id="qr-close-btn" style="display: none;">×</button>
        <h3 id="qr-modal-title">Sign with Xaman</h3>
        <p id="qr-modal-subtitle" style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1.5rem; text-align: center;">
            Scan the QR code or tap the button below to sign
        </p>
        
        <div class="qr-code-container">
            <img id="qr-code-image" src="" alt="QR Code" />
        </div>
        
        <div id="qr-signing-status" class="qr-signing-status">
            Waiting for signature...
        </div>
        
        <a id="qr-deeplink-btn" href="#" target="_blank" class="qr-deeplink-btn">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                <polyline points="15 3 21 3 21 9"></polyline>
                <line x1="10" y1="14" x2="21" y2="3"></line>
            </svg>
            Open in Xaman App
        </a>
        
        <button id="qr-retry-btn" class="qr-retry-btn" style="display: none;">
            Try Again
        </button>
    </div>
</div>

<style>
/* QR Modal Styles */
.qr-modal {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.9);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 1000001;
    padding: 1rem;
}

.qr-modal.active {
    display: flex;
}

.qr-modal-content {
    background: var(--bg-card, #12121a);
    border: 1px solid var(--gold, #d4af37);
    border-radius: 16px;
    padding: 2rem;
    max-width: 400px;
    width: 100%;
    text-align: center;
    position: relative;
    max-height: 90vh;
    overflow-y: auto;
    margin: auto;
}

.qr-modal-close {
    position: absolute;
    top: 1rem;
    right: 1rem;
    background: none;
    border: none;
    color: var(--text-secondary, #888);
    font-size: 1.5rem;
    cursor: pointer;
    line-height: 1;
    padding: 0.25rem;
}

.qr-modal-close:hover {
    color: var(--gold, #d4af37);
}

.qr-modal h3 {
    /* colour/font: .imu-heading-gold via trading-hub.css */
    font-size: 1.5rem;
    margin: 0 0 0.5rem;
}

.qr-code-container {
    background: white;
    border-radius: 12px;
    padding: 1rem;
    display: inline-block;
    margin: 1rem 0;
}

.qr-code-container img {
    width: 250px;
    height: 250px;
    display: block;
}

.qr-signing-status {
    color: var(--text-secondary, #888);
    font-size: 0.9rem;
    margin: 1rem 0;
    min-height: 1.5rem;
}

.qr-signing-status.success {
    color: #4CAF50;
}

.qr-signing-status.error {
    color: #f44336;
}

.qr-deeplink-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 1rem 2rem;
    background: linear-gradient(135deg, #d4af37, #b8962e);
    color: #000;
    text-decoration: none;
    border-radius: 10px;
    font-size: 1rem;
    font-weight: 600;
    transition: all 0.2s;
    margin-top: 0.5rem;
}

.qr-deeplink-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(212, 175, 55, 0.25);
}

.qr-retry-btn {
    display: block;
    width: 100%;
    padding: 0.75rem;
    margin-top: 1rem;
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: var(--text-primary, #fff);
    border-radius: 8px;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.2s;
}

.qr-retry-btn:hover {
    background: rgba(255, 255, 255, 0.2);
}

/* Success animation */
@keyframes successPulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.05); }
    100% { transform: scale(1); }
}

.qr-modal-content.success {
    animation: successPulse 0.5s ease;
    border-color: #4CAF50;
}

/* v258: spinner keyframe for live offer loading states */
@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>

<script>
(function() {
    // ── Session-auth 2b: token-only re-auth handler ──────────────────────
    // Once session-access is token-only, a user whose master request is rejected
    // with 401 is holding a stale/spoofed soft cookie with no proven token. Detect
    // that, clear the stale soft cookie client-side, tell them plainly, and send them
    // to the real /login/ page -- which already runs the full working Xaman + Joey
    // sign-in flow (QR, deeplink, poll, proof). We deliberately do NOT try to kick off
    // a payload from here: the Xaman flow renders a QR and polls in place, so a direct
    // navigation would break it; /login/ does it correctly. Returns true if it handled
    // a re-auth (caller should stop), false otherwise (caller proceeds as before).
    // While 2b is dark (flag false) session-access never returns this 401, so this
    // never fires -- behaviour is identical to today.
    window.__imcHandleReauth = window.__imcHandleReauth || function(response) {
        try {
            if (!response || response.status !== 401) return false;
        } catch (e) { return false; }
        // Clear the stale soft cookie on every scope it may live on.
        try {
            var expo = 'Thu, 01 Jan 1970 00:00:00 GMT';
            document.cookie = 'xrpl_account=; expires=' + expo + '; path=/;';
            document.cookie = 'xrpl_account=; expires=' + expo + '; path=/; domain=.imcollectibles.io;';
            document.cookie = 'xrpl_account=; expires=' + expo + '; path=/; domain=imcollectibles.io;';
        } catch (e) {}
        try { alert('Please sign in again to continue.'); } catch (e) {}
        try { window.location.href = 'https://imcollectibles.io/login/'; }
        catch (e) { window.location.href = '/login/'; }
        return true;
    };
    // ─────────────────────────────────────────────────────────────────────

    const userAccount = '<?php echo esc_js($user_account); ?>';
    const nonce = '<?php echo esc_js($nonce); ?>';
    const nftId  = '<?php echo esc_js($nft_id); ?>';
    const isOwner = <?php echo $is_owner ? 'true' : 'false'; ?>;
    // Phase 4D: NFT-issuer scam flag -> gate all trading UI on this page
    window.IMC_NFT_SCAM = <?php echo $nft_is_scam ? 'true' : 'false'; ?>;
    if (window.IMC_NFT_SCAM) {
        var _moBtn = document.getElementById('nft-make-offer-btn');
        if (_moBtn) { _moBtn.disabled = true; _moBtn.title = 'Trading disabled — flagged issuer'; _moBtn.style.cssText += ';opacity:0.5;cursor:not-allowed;'; }
    }
    const offerHandler = '<?php echo esc_js(get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/offer-handler.php'); ?>';
    // v658: broker/fee wallet — brokered offers are Destination-locked to this.
    window.IMC_BROKER_WALLET = 'riMCgymFVzdqQoTR82m5oUJE697bDHrJm';

    // ═══════════════════════════════════════════════════════════════════
    // v258: XRPL Offer Sync — staleness check + cross-marketplace display
    //
    // Runs once on every NFT page load:
    //  1. Calls sync_nft_offers → XRPL nft_sell_offers + nft_buy_offers
    //  2. DB rows no longer on-chain are marked 'cancelled' server-side
    //  3. Live sell offers rendered for ALL visitors (incl. external marketplace)
    //  4. Live buy offers rendered for the NFT owner
    //  5. Owner's IMC-listed badge verified (green/red dot)
    //  6. Owner notified if they have an external listing not in our DB
    // ═══════════════════════════════════════════════════════════════════

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function fmtAmt(o) {
        if (!o || o.amount == null) return '—';
        const n = parseFloat(o.amount);
        return (Number.isInteger(n) ? n.toString() : n.toFixed(n < 1 ? 6 : 2)) + ' ' + (o.currency_display || o.currency || 'XRP');
    }

    function addrShort(addr) {
        if (!addr || addr.length < 12) return addr || '—';
        return addr.slice(0,8) + '…' + addr.slice(-4);
    }

    // ── Main entry point ─────────────────────────────────────────────────
    async function syncNftOffers() {
        if (!nftId) return;
        try {
            const url = offerHandler
                + '?action=sync_nft_offers'
                + '&nft_id='  + encodeURIComponent(nftId)
                + '&nonce='   + encodeURIComponent(nonce);
            const res  = await fetch(url);
            if (!res.ok) { handleSyncFailure(); return; }
            const data = await res.json();
            if (!data.success) { handleSyncFailure(); return; }

            reconcileOwnerListingBadge(data.sell_offers_live || []);
            renderSellListingsPanel(data.sell_offers_live || []);
            renderBuyOffersPanel(data.buy_offers_live || []);
            updateMakeOfferButton(data.buy_offers_live || []);
            updateBuyNftButton(data.sell_offers_live || []);
        } catch (e) {
            console.warn('[IMC] offer sync failed:', e);
            handleSyncFailure();
        }
    }

    function handleSyncFailure() {
        const dot = document.getElementById('listing-verify-dot');
        if (dot) { dot.style.background = '#9ca3af'; dot.title = 'On-chain verification unavailable'; }
    }

    // ── 1. Owner: reconcile IMC-listed badge with live ledger ─────────────
    function reconcileOwnerListingBadge(liveSellOffers) {
        const statusEl = document.getElementById('nft-listing-status');
        const listBtn  = document.getElementById('nft-list-btn');
        const dot      = document.getElementById('listing-verify-dot');

        if (!statusEl) {
            // No IMC listing in DB — but owner may have listed elsewhere
            if (isOwner) {
                const myExternal = liveSellOffers.filter(o => !o.in_our_db);
                if (myExternal.length > 0) {
                    // Show a warning banner so owner knows they have an active external listing
                    const existingWarn = document.getElementById('imc-external-listing-warn');
                    if (!existingWarn) {
                        const warn = document.createElement('div');
                        warn.id = 'imc-external-listing-warn';
                        warn.style.cssText = 'display:flex;align-items:center;gap:10px;padding:0.6rem 0.9rem;'
                            + 'background:rgba(212,175,55,0.08);border:1px solid rgba(212,175,55,0.25);'
                            + 'border-radius:8px;font-size:0.84rem;color:#d4af37;margin-top:0.75rem;';
                        warn.innerHTML = '⚠️ You have <strong style="margin:0 3px;">' + myExternal.length
                            + '</strong> active sell listing' + (myExternal.length > 1 ? 's' : '')
                            + ' on external marketplaces.';
                        // Insert after the list/transfer buttons row
                        if (listBtn) {
                            listBtn.insertAdjacentElement('afterend', warn);
                        } else {
                            const transferBtn = document.getElementById('nft-transfer-btn');
                            if (transferBtn) transferBtn.insertAdjacentElement('afterend', warn);
                        }
                    }
                }
            }
            return;
        }

        // IMC listing exists — check if it's still on-chain
        const dbOfferId  = statusEl.dataset.offerId || '';
        const liveIds    = liveSellOffers.map(o => o.offer_id);
        const isStale    = dbOfferId && !liveIds.includes(dbOfferId);

        if (isStale) {
            statusEl.style.display = 'none';
            if (listBtn) listBtn.style.display = '';
            if (dot) { dot.style.background = '#f87171'; dot.title = 'Listing cancelled externally — refreshed'; }
            console.log('[IMC] Stale DB listing for offer_id:', dbOfferId);
        } else {
            if (dot) { dot.style.background = '#4ade80'; dot.title = 'Verified on XRPL ledger ✓'; }
        }
    }

    // ── 2. Sell listings panel (all visitors) ────────────────────────────
    // Shows all active sell offers for this NFT from the live XRPL ledger,
    // including listings created on OnXRP, XRPLMeta, and other marketplaces.
    // Non-owners see a "Buy Now" button that routes through accept_sell.
    // Owners see their own listing(s) with a "Cancel" button.
    function renderSellListingsPanel(sellOffers) {
        const panel    = document.getElementById('nft-sell-listings-panel');
        const listEl   = document.getElementById('sell-listings-list');
        const countEl  = document.getElementById('sell-listings-count');
        if (!panel || !listEl) return;

        // Only show public listings (no destination, or destination = this user)
        const visible = sellOffers.filter(o =>
            !o.destination || o.destination === userAccount || o.destination === window.IMC_BROKER_WALLET
        );

        if (visible.length === 0) { panel.style.display = 'none'; return; }

        if (countEl) countEl.textContent = visible.length;

        listEl.innerHTML = visible.map(o => {
            const amt      = fmtAmt(o.brokered ? { amount: (o.sticker || o.amount), currency: o.currency, currency_display: o.currency_display } : o);
            const isMyList = isOwner && o.seller === userAccount;
            const srcBadge = o.in_our_db
                ? '<span style="font-size:0.65rem;color:#4ade80;background:rgba(74,222,128,0.12);border:1px solid rgba(74,222,128,0.25);border-radius:4px;padding:1px 6px;font-weight:600;letter-spacing:0.03em;">IMC</span>'
                : '<span style="font-size:0.65rem;color:#d4af37;background:rgba(212,175,55,0.1);border:1px solid rgba(212,175,55,0.25);border-radius:4px;padding:1px 6px;font-weight:600;letter-spacing:0.03em;">External</span>';

            let actionBtn = '';
            if (isMyList) {
                // Owner sees Cancel button for their own listing
                actionBtn = '<button class="imc-offer-action-btn imc-cancel-ext-btn" '
                    + 'data-offer-id="' + escHtml(o.offer_id) + '" '
                    + 'style="font-size:0.72rem;padding:5px 14px;background:rgba(10,10,15,0.6);'
                    + 'border:2px solid rgba(220,60,75,0.5);color:#e06070;border-radius:50px;cursor:pointer;'
                    + 'font-weight:800;letter-spacing:0.06em;text-transform:uppercase;'
                    + 'box-shadow:0 0 0 1px rgba(220,60,75,0.18),0 0 10px rgba(220,60,75,0.12);'
                    + 'transition:box-shadow 0.18s ease,background 0.18s ease;"'
                    + 'onmouseover="this.style.boxShadow=\'0 0 0 2px rgba(220,60,75,0.55),0 0 16px rgba(220,60,75,0.38)\'"'
                    + 'onmouseout="this.style.boxShadow=\'0 0 0 1px rgba(220,60,75,0.18),0 0 10px rgba(220,60,75,0.12)\'">'
                    + 'Cancel</button>';
            } else if (!isOwner && userAccount) {
                // Non-owner, logged in — show Buy Now (amber base, blue on hover)
                actionBtn = '<button class="imc-offer-action-btn imc-buy-now-btn" '
                    + 'data-offer-id="' + escHtml(o.offer_id) + '" '
                    + 'data-amount="'   + escHtml(String(o.amount)) + '" '
                    + 'data-currency="' + escHtml(o.currency || 'XRP') + '" '
                    + 'data-currency-display="' + escHtml(o.currency_display || o.currency || 'XRP') + '" '
                    + 'data-issuer="' + escHtml(o.issuer || '') + '" '
                    + 'data-brokered="' + (o.brokered ? '1' : '0') + '" '
                    + 'data-sticker="'  + escHtml(String(o.sticker || o.amount)) + '" '
                    + 'style="font-size:0.72rem;padding:5px 14px;background:#c9a832;'
                    + 'border:2px solid #c9a832;color:#040a0e;border-radius:50px;cursor:pointer;'
                    + 'font-weight:800;letter-spacing:0.06em;text-transform:uppercase;'
                    + 'box-shadow:0 0 0 1px rgba(201,168,50,0.45),0 0 10px rgba(201,168,50,0.25);'
                    + 'transition:all 0.18s ease;"'
                    + 'onmouseover="this.style.background=\'#00d4ff\';this.style.borderColor=\'#00d4ff\';this.style.color=\'#040a0e\';this.style.boxShadow=\'0 0 0 2px rgba(0,212,255,0.7),0 0 22px rgba(0,212,255,0.45)\'"'
                    + 'onmouseout="this.style.background=\'#c9a832\';this.style.borderColor=\'#c9a832\';this.style.color=\'#040a0e\';this.style.boxShadow=\'0 0 0 1px rgba(201,168,50,0.45),0 0 10px rgba(201,168,50,0.25)\'">'
                    + 'Buy Now</button>';
            } else if (!isOwner && !userAccount) {
                // Not logged in
                actionBtn = '<span style="font-size:0.75rem;color:var(--text-secondary);font-style:italic;">Login to buy</span>';
            }

            return '<div style="display:flex;align-items:center;justify-content:space-between;'
                + 'gap:0.5rem;padding:0.6rem 0;border-bottom:1px solid rgba(212,175,55,0.07);">'
                + '<div style="display:flex;align-items:center;gap:6px;min-width:0;">'
                + srcBadge
                + '<span style="font-size:0.82rem;color:var(--text-secondary);white-space:nowrap;">'
                + escHtml(addrShort(o.seller)) + '</span>'
                + '</div>'
                + '<div style="display:flex;align-items:center;gap:10px;min-width:0;flex-wrap:wrap;justify-content:flex-end;">'
                + '<span style="font-size:0.9rem;color:var(--gold);font-weight:700;min-width:0;overflow-wrap:anywhere;">' + escHtml(amt) + '</span>'
                + actionBtn
                + '</div>'
                + '</div>';
        }).join('');

        panel.style.display = '';

        // Wire up Buy Now buttons
        listEl.querySelectorAll('.imc-buy-now-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                if (this.dataset.brokered === '1') { handleBrokeredBuy(this.dataset.sticker, this.dataset.currencyDisplay || this.dataset.currency, this.dataset.issuer); }
                else { handleBuyExternalListing(this.dataset.offerId, this.dataset.amount, this.dataset.currency); }
            });
        });
        // Phase 4D: disable Buy Now on a scam-issued NFT
        if (window.IMC_NFT_SCAM) { listEl.querySelectorAll('.imc-buy-now-btn').forEach(b => { b.disabled = true; b.textContent = 'Trading disabled'; b.style.cssText += ';opacity:0.5;cursor:not-allowed;'; }); }

        // Wire up Cancel buttons (for owner's external listings)
        listEl.querySelectorAll('.imc-cancel-ext-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                handleCancelExternalListing(this.dataset.offerId);
            });
        });
    }

    // ── 3. Buy offers panel (NFT owner only) ─────────────────────────────
    function renderBuyOffersPanel(buyOffers) {
        const panel   = document.getElementById('nft-buy-offers-panel');
        const listEl  = document.getElementById('buy-offers-list');
        const countEl = document.getElementById('buy-offers-count');
        if (!panel || !listEl) return;

        // Show to ALL visitors — buyers can see what others are offering;
        // owner sees Accept buttons, others see the offers as context.
        const relevant = buyOffers.filter(o =>
            !o.destination || o.destination === userAccount || o.destination === window.IMC_BROKER_WALLET
        );

        if (relevant.length === 0) { panel.style.display = 'none'; return; }
        if (countEl) countEl.textContent = relevant.length;

        listEl.innerHTML = relevant.map(o => {
            const amt    = fmtAmt(o);
            const buyer  = addrShort(o.buyer);
            const srcBadge = o.in_our_db
                ? '<span style="font-size:0.65rem;color:#4ade80;background:rgba(74,222,128,0.12);border:1px solid rgba(74,222,128,0.25);border-radius:4px;padding:1px 6px;font-weight:600;letter-spacing:0.03em;">IMC</span>'
                : '<span style="font-size:0.65rem;color:#d4af37;background:rgba(212,175,55,0.1);border:1px solid rgba(212,175,55,0.25);border-radius:4px;padding:1px 6px;font-weight:600;letter-spacing:0.03em;">External</span>';
            const acceptBtn = isOwner
                ? ('<button class="imc-offer-action-btn imc-accept-buy-btn" '
                + 'data-offer-id="' + escHtml(o.offer_id) + '" '
                + 'data-brokered="' + ((o.destination === window.IMC_BROKER_WALLET) ? '1' : '0') + '" '
                + 'style="font-size:0.72rem;padding:5px 14px;background:rgba(74,222,128,0.1);'
                + 'border:2px solid rgba(74,222,128,0.5);color:#4ade80;border-radius:50px;cursor:pointer;'
                + 'font-weight:800;letter-spacing:0.06em;text-transform:uppercase;'
                + 'box-shadow:0 0 0 1px rgba(74,222,128,0.18),0 0 10px rgba(74,222,128,0.14);'
                + 'transition:box-shadow 0.18s ease,background 0.18s ease;"'
                + 'onmouseover="this.style.boxShadow=\'0 0 0 2px rgba(74,222,128,0.55),0 0 16px rgba(74,222,128,0.40)\';this.style.background=\'rgba(74,222,128,0.18)\'"'
                + 'onmouseout="this.style.boxShadow=\'0 0 0 1px rgba(74,222,128,0.18),0 0 10px rgba(74,222,128,0.14)\';this.style.background=\'rgba(74,222,128,0.1)\'">'
                + 'Accept</button>')
                : '';

            return '<div style="display:flex;align-items:center;justify-content:space-between;'
                + 'gap:0.5rem;padding:0.6rem 0;border-bottom:1px solid rgba(212,175,55,0.07);">'
                + '<div style="display:flex;align-items:center;gap:6px;">'
                + srcBadge
                + '<span style="font-size:0.82rem;color:var(--text-secondary);">' + escHtml(buyer) + '</span>'
                + '</div>'
                + '<div style="display:flex;align-items:center;gap:10px;min-width:0;flex-wrap:wrap;justify-content:flex-end;">'
                + '<span style="font-size:0.9rem;color:var(--gold);font-weight:700;min-width:0;overflow-wrap:anywhere;">' + escHtml(amt) + '</span>'
                + acceptBtn
                + '</div>'
                + '</div>';
        }).join('');

        panel.style.display = '';

        listEl.querySelectorAll('.imc-accept-buy-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                handleAcceptBuyOffer(this.dataset.offerId, this.dataset.brokered === '1');
            });
        });
        // Phase 4D: disable Accept on a scam-issued NFT
        if (window.IMC_NFT_SCAM) { listEl.querySelectorAll('.imc-accept-buy-btn').forEach(b => { b.disabled = true; b.textContent = 'Trading disabled'; b.style.cssText += ';opacity:0.5;cursor:not-allowed;'; }); }
    }

    // ── Buy NFT button: show/hide based on live sell listings ─────────────
    // Shown to non-owners when at least one public sell listing exists.
    // Clicking triggers purchase of the cheapest public listing.
    function updateBuyNftButton(sellOffers) {
        const btn = document.getElementById('nft-buy-nft-btn');
        if (!btn || isOwner) return; // Owners see List/Cancel, not Buy
        if (window.IMC_NFT_SCAM) { btn.classList.add('nft-buy-hidden'); btn.closest('.nft-single-actions')?.classList.remove('has-buy-btn'); return; }
        // v658: brokered listings (destination = broker wallet) are now buyable too. For a
        // brokered offer the buyer pays the STICKER (not the on-ledger net) and settlement
        // routes through the broker. Direct offers keep their existing behaviour.
        const publicOffers = sellOffers.filter(o => !o.destination || o.destination === userAccount || o.destination === window.IMC_BROKER_WALLET);
        if (publicOffers.length === 0) { btn.classList.add('nft-buy-hidden'); btn.closest('.nft-single-actions')?.classList.remove('has-buy-btn'); return; }
        const priceOf = o => o.brokered ? Number(o.sticker || o.amount || 0) : Number(o.amount || 0);
        publicOffers.sort((a, b) => priceOf(a) - priceOf(b));
        const cheapest = publicOffers[0];
        const dispOffer = cheapest.brokered ? { amount: (cheapest.sticker || cheapest.amount), currency: cheapest.currency, currency_display: cheapest.currency_display } : cheapest;
        const amtDisplay = fmtAmt(dispOffer);
        btn.textContent = '🛒 Buy NFT — ' + amtDisplay;
        btn.classList.remove('nft-buy-hidden');
        btn.closest('.nft-single-actions')?.classList.add('has-buy-btn');
        btn.onclick = function() {
            if (cheapest.brokered) { handleBrokeredBuy((cheapest.sticker || cheapest.amount), cheapest.currency_display || cheapest.currency || 'XRP', cheapest.issuer || ''); }
            else { handleBuyExternalListing(cheapest.offer_id, cheapest.amount, cheapest.currency || 'XRP'); }
        };
    }

    // v560: When this visitor already has an active BUY offer on the NFT, flip the
    // 'Make Offer' button to 'Cancel Offer'. Reuses the proven dual-path cancel
    // (handleCancelExternalListing -> Joey or Xaman). The click handler branches on
    // dataset.cancelOfferId so we only toggle text + dataset here (no double-binding).
    function updateMakeOfferButton(buyOffers) {
        const btn = document.getElementById('nft-make-offer-btn');
        if (!btn || isOwner) return;
        const mine = (buyOffers || []).find(o => o.buyer && userAccount && o.buyer === userAccount);
        if (mine && mine.offer_id) {
            btn.textContent = '✖ Cancel Offer';
            btn.dataset.cancelOfferId = mine.offer_id;
        } else {
            btn.textContent = '💬 Make Offer';
            delete btn.dataset.cancelOfferId;
        }
    }

    // ── 4. Buy an external (or IMC) sell listing ─────────────────────────
    // Routes through accept_sell which creates an NFTokenAcceptOffer XUMM payload.
    // Works for ANY valid XRPL offer_id — whether created on IMC, OnXRP, or XRPLMeta.
    //
    // IMPORTANT: accept_sell does NOT insert the payload UUID into xumm_offers DB
    // (only buy_listing does). So the default pollSigningStatus (DB-based) will
    // never find the row and the UI would hang on "Waiting for signature..." forever.
    // Instead we kill the default poll and switch to poll_xumm_payload (XUMM-direct),
    // exactly like the cancel flow does.
    // v658 (Phase 6, step 3b): one-click brokered "Buy Now". Creates a broker-locked buy
    // offer at the STICKER (via create_offer, which Phase 4 destination-locks to riMCgym),
    // then, once it confirms on-ledger, asks the server to broker the sale (broker_settle).
    // No custody: the ledger splits atomically. Mirrors the QR+poll pattern used elsewhere.
    async function handleBrokeredBuy(sticker, currency, issuer) {
        if (!userAccount) { showToast('Connect your Xaman wallet to buy.', 'error'); return; }
        if (window.IMC_NFT_SCAM) { showToast('Trading disabled for this NFT.', 'error'); return; }
        try {
            const fd = new FormData();
            fd.append('action', 'create_offer');
            fd.append('target_nft_id', nftId);
            fd.append('account', userAccount);
            fd.append('amount', sticker);
            fd.append('currency', currency || 'XRP');
            // v706: create_offer resolves the issuer by TICKER; a ledger hex code matches
            // nothing, so a token-priced brokered buy 400'd with 'Missing issuer'. The
            // issuer now travels with the offer row from sync_nft_offers.
            if (issuer) fd.append('issuer', issuer);
            fd.append('nonce', nonce);
            const res = await fetch(offerHandler, { method: 'POST', body: fd });
            const data = await res.json();
            // v720 (G3B): route a trustline rejection before falling back to the toast.
            if (imcHandleTrustlineFlag(data, document.getElementById('offer-currency'), 'buyer_trustline_required')) { return; }
            if (!data || !data.success || !data.qr) { showToast((data && data.error) || 'Failed to start purchase', 'error'); return; }
            showQrModal(data.qr, data.deeplink, data.payload_uuid, 'Buy NFT');
            pollingActive = false;

            let attempts = 0;
            const poll = setInterval(async () => {
                attempts++;
                if (attempts > 72) {
                    clearInterval(poll);
                    qrSigningStatus.textContent = '\u2717 Timed out. Please try again.';
                    qrSigningStatus.className = 'qr-signing-status error';
                    qrCloseBtn.style.display = 'block';
                    return;
                }
                try {
                    const pd = await (await fetch(offerHandler + '?action=poll_xumm_payload&uuid=' + encodeURIComponent(data.payload_uuid) + '&type=nft_offer_create&nonce=' + encodeURIComponent(nonce))).json();
                    if (pd.status === 'confirmed') {
                        clearInterval(poll);
                        qrSigningStatus.textContent = '\u2713 Offer placed \u2014 completing your purchase\u2026';
                        qrSigningStatus.className = 'qr-signing-status';
                        await brokeredBuySettle();
                    } else if (pd.status === 'rejected' || pd.status === 'declined') {
                        clearInterval(poll);
                        qrSigningStatus.textContent = '\u2717 Declined';
                        qrSigningStatus.className = 'qr-signing-status error';
                        qrCloseBtn.style.display = 'block';
                    }
                } catch (e) { /* transient poll error; keep trying */ }
            }, 5000);
        } catch (e) {
            showToast('Purchase failed: ' + ((e && e.message) || 'unknown'), 'error');
        }
    }

    // After the buy offer confirms, resolve its on-ledger index (webhook fills it), then
    // call broker_settle. Polls a few times to let the offer index populate.
    async function brokeredBuySettle() {
        for (let i = 0; i < 10; i++) {
            try {
                const sd = await (await fetch(offerHandler + '?action=sync_nft_offers&nft_id=' + encodeURIComponent(nftId) + '&account=' + encodeURIComponent(userAccount) + '&nonce=' + encodeURIComponent(nonce))).json();
                const mine = (sd.buy_offers_live || []).find(o => o.buyer === userAccount && o.destination === window.IMC_BROKER_WALLET && o.offer_id);
                if (mine) {
                    const fd = new FormData();
                    fd.append('action', 'broker_settle');
                    fd.append('buy_offer_id', mine.offer_id);
                    fd.append('account', userAccount);
                    fd.append('nonce', nonce);
                    const rd = await (await fetch(offerHandler, { method: 'POST', body: fd })).json();
                    if (rd && rd.success) {
                        qrSigningStatus.textContent = '\u2713 Purchased! The NFT is yours.';
                        qrSigningStatus.className = 'qr-signing-status success';
                        qrModalContent.classList.add('success');
                        qrCloseBtn.style.display = 'block';
                        showToast('Purchase complete!', 'success');
                        setTimeout(() => { closeQrModal(); window.location.reload(); }, 2200);
                    } else {
                        qrSigningStatus.textContent = '\u2717 ' + ((rd && rd.error) || 'Settlement failed');
                        qrSigningStatus.className = 'qr-signing-status error';
                        qrCloseBtn.style.display = 'block';
                    }
                    return;
                }
            } catch (e) { /* keep polling */ }
            await new Promise(r => setTimeout(r, 2000));
        }
        qrSigningStatus.textContent = '\u23f3 Offer placed. Refresh shortly to complete, or the seller can finalize it.';
        qrSigningStatus.className = 'qr-signing-status';
        qrCloseBtn.style.display = 'block';
    }

    async function handleBuyExternalListing(offerId, amount, currency) {
        if (!offerId || !userAccount) {
            showToast('Please connect your Xaman wallet to buy.', 'error'); return;
        }
        try {
            // --- Joey branch (v551, additive): sign accept_sell locally, no QR modal. 
            // Server is Joey-wired (joey_verify_accept_offer). 
            if (window.__joeySession && window.__joeySession.live && window.imuWallet) {
                if (userAccount !== window.__joeySession.account) {
                    throw new Error('Connected wallet does not match your account. Please reconnect.');
                }
                const jurl = offerHandler + '?action=accept_sell&offer_id=' + encodeURIComponent(offerId) + '&account=' + encodeURIComponent(userAccount) + '&nonce=' + encodeURIComponent(nonce) + '&skip_verify=1&wallet=joey';
                const jres = await fetch(jurl);
                const jd = await jres.json();
                if (!jd.success || !jd.txjson) throw new Error(jd.error || 'Failed to prepare transaction');
                showToast('Check your wallet to sign...', 'success');
                let jsigned;
                try { jsigned = await (window.imuJoeySign||window.imuWallet.sign)(jd.txjson); }
                catch (e) { const em=(e&&e.message)||''; throw new Error(/timed out|timeout/i.test(em)?'Signing timed out. Please try again.':/cancel/i.test(em)?'Signing cancelled.':'Signing was rejected.'); }
                const jtx = jsigned && (jsigned.hash || jsigned.tx_hash);
                if (!jtx) throw new Error('No transaction hash returned from your wallet.');
                const jvfd = new FormData();
                jvfd.append('action', 'joey_verify_accept_offer');
                jvfd.append('tx_hash', jtx);
                jvfd.append('nft_id', jd.nft_id || '');
                jvfd.append('nonce', nonce);
                const jvr = await fetch(offerHandler, { method: 'POST', body: jvfd });
                const jvd = await jvr.json();
                if (!jvd.success) throw new Error(jvd.error || 'Verification failed');
                showToast('NFT purchased successfully!', 'success');
                setTimeout(function() { window.location.reload(); }, 2500);
                return;
            }

            const url = offerHandler
                + '?action=accept_sell'
                + '&offer_id=' + encodeURIComponent(offerId)
                + '&account='  + encodeURIComponent(userAccount)
                + '&nonce='    + encodeURIComponent(nonce);
            const res  = await fetch(url);
            const data = await res.json();
            if (!data.success) {
                showToast(data.error || 'Failed to prepare purchase', 'error'); return;
            }
            // v684: this site starts its OWN inline poller after showQrModal, so the guard inside
            // showQrModal is not sufficient -- bounce here, before either runs.
            if (data.wallet === 'joey' && data.txjson) {
                showToast('Wallet still connecting — please try again in a moment.', 'error'); return;
            }
            const label = 'Buy · ' + parseFloat(amount).toFixed(2) + ' ' + (currency || 'XRP');
            showQrModal(data.qr_code || '', data.deeplink || '', data.payload_uuid, label);

            // Kill the DB-based poll that showQrModal started — it won't find this UUID
            pollingActive = false;

            // Start XUMM-direct poll (same approach as cancelListingBtn)
            let buyAttempts = 0;
            const buyPoll = setInterval(async () => {
                buyAttempts++;
                if (buyAttempts > 72) {
                    clearInterval(buyPoll);
                    qrSigningStatus.textContent = '✗ Timed out. Please try again.';
                    qrSigningStatus.className = 'qr-signing-status error';
                    qrCloseBtn.style.display = 'block';
                    return;
                }
                try {
                    const pollUrl = offerHandler
                        + '?action=poll_xumm_payload'
                        + '&uuid='  + encodeURIComponent(data.payload_uuid)
                        + '&type=nft_offer_accept'
                        + '&nonce=' + encodeURIComponent(nonce);
                    const pollRes  = await fetch(pollUrl);
                    const pollData = await pollRes.json();

                    if (pollData.status === 'confirmed') {
                        clearInterval(buyPoll);
                        qrSigningStatus.textContent = '✓ Purchase complete!';
                        qrSigningStatus.className = 'qr-signing-status success';
                        qrModalContent.classList.add('success');
                        qrCloseBtn.style.display = 'block';
                        showToast('NFT purchased successfully!', 'success');
                        setTimeout(() => { closeQrModal(); window.location.reload(); }, 2000);
                    } else if (pollData.status === 'rejected' || pollData.status === 'declined') {
                        clearInterval(buyPoll);
                        qrSigningStatus.textContent = '✗ Declined';
                        qrSigningStatus.className = 'qr-signing-status error';
                        qrCloseBtn.style.display = 'block';
                    } else if (pollData.status === 'expired') {
                        clearInterval(buyPoll);
                        qrSigningStatus.textContent = '✗ Expired. Please try again.';
                        qrSigningStatus.className = 'qr-signing-status error';
                        qrCloseBtn.style.display = 'block';
                    } else if (pollData.status === 'failed') {
                        clearInterval(buyPoll);
                        qrSigningStatus.textContent = '✗ Transaction failed on-chain';
                        qrSigningStatus.className = 'qr-signing-status error';
                        qrCloseBtn.style.display = 'block';
                    } else if (pollData.status === 'signed_pending') {
                        qrSigningStatus.textContent = 'Confirming on blockchain…';
                    }
                } catch (e) { /* keep polling */ }
            }, 2500);
        } catch (e) {
            showToast('Purchase error: ' + e.message, 'error');
        }
    }

    // ── 5. Accept a buy offer (owner sells to a bidder) ───────────────────
    // Same polling fix applies: accept_buy doesn't write a UUID to the DB
    // for offers that originated outside IMC. Use poll_xumm_payload directly.
    // v658: complete a brokered sale — ask the server (riMCgym) to broker the matched
    // sell+buy pair via broker_settle. No custody: the ledger splits atomically. Synchronous.
    async function handleBrokeredSettle(buyOfferId) {
        if (!buyOfferId || !userAccount) { showToast('Please connect your wallet.', 'error'); return; }
        try {
            showToast('Completing sale\u2026', 'success');
            const fd = new FormData();
            fd.append('action', 'broker_settle');
            fd.append('buy_offer_id', buyOfferId);
            fd.append('account', userAccount);
            fd.append('nonce', nonce);
            const res = await fetch(offerHandler, { method: 'POST', body: fd });
            const data = await res.json();
            if (data && data.success) {
                showToast('Sale complete! NFT transferred.', 'success');
                setTimeout(function() { window.location.reload(); }, 1800);
            } else {
                showToast((data && data.error) || 'Settlement failed.', 'error');
            }
        } catch (e) {
            showToast('Settlement error: ' + ((e && e.message) || 'unknown'), 'error');
        }
    }

    async function handleAcceptBuyOffer(offerId, brokered) {
        if (!offerId || !userAccount) {
            showToast('Please connect your Xaman wallet.', 'error'); return;
        }
        // v658: a brokered bid is Destination-locked to the fee wallet and cannot be
        // direct-accepted. Route to the server-side broker settlement instead.
        if (brokered) { return handleBrokeredSettle(offerId); }
        try {
            // --- Joey branch (v551, additive): sign accept_buy locally, no QR modal. 
            // Server is Joey-wired (joey_verify_accept_offer). 
            if (window.__joeySession && window.__joeySession.live && window.imuWallet) {
                if (userAccount !== window.__joeySession.account) {
                    throw new Error('Connected wallet does not match your account. Please reconnect.');
                }
                const jurl = offerHandler + '?action=accept_buy&offer_id=' + encodeURIComponent(offerId) + '&account=' + encodeURIComponent(userAccount) + '&nonce=' + encodeURIComponent(nonce) + '&skip_verify=1&wallet=joey';
                const jres = await fetch(jurl);
                const jd = await jres.json();
                if (!jd.success || !jd.txjson) throw new Error(jd.error || 'Failed to prepare transaction');
                showToast('Check your wallet to sign...', 'success');
                let jsigned;
                try { jsigned = await (window.imuJoeySign||window.imuWallet.sign)(jd.txjson); }
                catch (e) { const em=(e&&e.message)||''; throw new Error(/timed out|timeout/i.test(em)?'Signing timed out. Please try again.':/cancel/i.test(em)?'Signing cancelled.':'Signing was rejected.'); }
                const jtx = jsigned && (jsigned.hash || jsigned.tx_hash);
                if (!jtx) throw new Error('No transaction hash returned from your wallet.');
                const jvfd = new FormData();
                jvfd.append('action', 'joey_verify_accept_offer');
                jvfd.append('tx_hash', jtx);
                jvfd.append('nft_id', jd.nft_id || '');
                jvfd.append('nonce', nonce);
                const jvr = await fetch(offerHandler, { method: 'POST', body: jvfd });
                const jvd = await jvr.json();
                if (!jvd.success) throw new Error(jvd.error || 'Verification failed');
                showToast('Offer accepted! NFT sold.', 'success');
                setTimeout(function() { window.location.reload(); }, 1500);
                return;
            }

            const url = offerHandler
                + '?action=accept_buy'
                + '&offer_id=' + encodeURIComponent(offerId)
                + '&account='  + encodeURIComponent(userAccount)
                + '&nonce='    + encodeURIComponent(nonce);
            const res  = await fetch(url);
            const data = await res.json();
            if (!data.success) {
                showToast(data.error || 'Failed to prepare accept', 'error'); return;
            }
            // v684: inline poller below -- bounce before it starts. See accept_sell above.
            if (data.wallet === 'joey' && data.txjson) {
                showToast('Wallet still connecting — please try again in a moment.', 'error'); return;
            }
            showQrModal(data.qr_code || '', data.deeplink || '', data.payload_uuid, 'Accept Buy Offer');

            // Kill DB-based poll — accept_buy UUID may not be in xumm_offers
            pollingActive = false;

            let acceptAttempts = 0;
            const acceptPoll = setInterval(async () => {
                acceptAttempts++;
                if (acceptAttempts > 72) {
                    clearInterval(acceptPoll);
                    qrSigningStatus.textContent = '✗ Timed out. Please try again.';
                    qrSigningStatus.className = 'qr-signing-status error';
                    qrCloseBtn.style.display = 'block';
                    return;
                }
                try {
                    const pollUrl = offerHandler
                        + '?action=poll_xumm_payload'
                        + '&uuid='  + encodeURIComponent(data.payload_uuid)
                        + '&type=nft_offer_accept'
                        + '&nonce=' + encodeURIComponent(nonce);
                    const pollRes  = await fetch(pollUrl);
                    const pollData = await pollRes.json();

                    if (pollData.status === 'confirmed') {
                        clearInterval(acceptPoll);
                        qrSigningStatus.textContent = '✓ Offer accepted! NFT sold.';
                        qrSigningStatus.className = 'qr-signing-status success';
                        qrModalContent.classList.add('success');
                        qrCloseBtn.style.display = 'block';
                        showToast('Buy offer accepted!', 'success');
                        setTimeout(() => { closeQrModal(); window.location.reload(); }, 2000);
                    } else if (pollData.status === 'rejected' || pollData.status === 'declined') {
                        clearInterval(acceptPoll);
                        qrSigningStatus.textContent = '✗ Declined';
                        qrSigningStatus.className = 'qr-signing-status error';
                        qrCloseBtn.style.display = 'block';
                    } else if (pollData.status === 'expired') {
                        clearInterval(acceptPoll);
                        qrSigningStatus.textContent = '✗ Expired. Please try again.';
                        qrSigningStatus.className = 'qr-signing-status error';
                        qrCloseBtn.style.display = 'block';
                    } else if (pollData.status === 'failed') {
                        clearInterval(acceptPoll);
                        qrSigningStatus.textContent = '✗ Transaction failed on-chain';
                        qrSigningStatus.className = 'qr-signing-status error';
                        qrCloseBtn.style.display = 'block';
                    } else if (pollData.status === 'signed_pending') {
                        qrSigningStatus.textContent = 'Confirming on blockchain…';
                    }
                } catch (e) { /* keep polling */ }
            }, 2500);
        } catch (e) {
            showToast('Error: ' + e.message, 'error');
        }
    }

    // ── 6. Cancel an external sell listing (owner) ────────────────────────
    // --- Joey (WalletConnect) cancel helper -- v546, additive ---------------
    // Shared by both cancel surfaces on this page. Returns true if it handled the
    // cancel via a live Joey session (caller then returns); false means no Joey
    // session, so the caller continues to its unchanged Xaman path.
    async function imcJoeyCancelListing(offerId) {
        if (!(window.__joeySession && window.__joeySession.live && window.imuWallet)) return false;
        if (!offerId || !userAccount) { showToast('Missing offer ID or account.', 'error'); return true; }
        if (userAccount !== window.__joeySession.account) {
            showToast('Connected wallet does not match your account. Please reconnect.', 'error');
            return true;
        }
        try {
            const body = new URLSearchParams({ action: 'cancel_offer', account: userAccount, offer_id: offerId, nonce: nonce, wallet: 'joey' });
            const res = await fetch('/wp-content/themes/astra/xrpl-nft-marketplace/backend/offer-handler.php', {
                method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body
            });
            const data = await res.json();
            if (!data || !data.success || !data.txjson) {
                showToast((data && data.error) || 'Failed to prepare cancel', 'error');
                return true;
            }
            showToast('Check your wallet to sign the cancel...', 'info');
            let signed;
            try {
                signed = await (window.imuJoeySign||window.imuWallet.sign)(data.txjson);
            } catch (e) {
                showToast('Signing was cancelled or failed.', 'error');
                return true;
            }
            const txHash = signed && (signed.hash || signed.tx_hash);
            if (!txHash) {
                showToast('No transaction hash returned from your wallet.', 'error');
                return true;
            }
            const vfd = new FormData();
            vfd.append('action', 'joey_verify_cancel_offer');
            vfd.append('tx_hash', txHash);
            vfd.append('nonce', nonce);
            const vres = await fetch('/wp-content/themes/astra/xrpl-nft-marketplace/backend/offer-handler.php', { method: 'POST', body: vfd });
            const vdata = await vres.json();
            if (vdata && vdata.success) {
                showToast('Listing cancelled.', 'success');
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                showToast((vdata && vdata.error) || 'Cancel verification failed', 'error');
            }
        } catch (e) {
            showToast('Error: ' + e.message, 'error');
        }
        return true;
    }

    async function handleCancelExternalListing(offerId) {
        if (!offerId || !userAccount) return;
        if (await imcJoeyCancelListing(offerId)) return;
        try {
            const body = new URLSearchParams({
                action: 'cancel_offer', account: userAccount,
                offer_id: offerId, nonce: nonce
            });
            const res  = await fetch(offerHandler, { method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
            const data = await res.json();
            if (!data.success) {
                showToast(data.error || 'Failed to cancel', 'error'); return;
            }
            showQrModal(data.qr || '', data.deeplink || '', data.payload_uuid, 'Cancel Listing');
        } catch (e) {
            showToast('Error: ' + e.message, 'error');
        }
    }

    // Kick off on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', syncNftOffers);
    } else {
        syncNftOffers();
    }
    
    // Toast notification
    function showToast(message, type = 'info') {
        const existing = document.querySelector('.single-nft-toast');
        if (existing) existing.remove();
        
        const toast = document.createElement('div');
        toast.className = 'single-nft-toast';
        toast.style.cssText = `
            position: fixed; bottom: 20px; right: 20px; z-index: 10001;
            padding: 1rem 1.5rem; border-radius: 8px;
            background: ${type === 'success' ? '#4CAF50' : type === 'error' ? '#f44336' : '#333'};
            color: white; font-weight: 500; box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        `;
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 4000);
    }
    
    // =========================================================================
    // MESSAGE OWNER BUTTON
    // =========================================================================
    const messageBtn = document.getElementById('nft-message-owner-btn');
    if (messageBtn) {
        messageBtn.addEventListener('click', async function() {
            const owner = this.dataset.owner;
            const nftId = this.dataset.nftId;
            
            if (!userAccount) {
                showToast('Please log in with Xaman to message the owner.', 'error');
                return;
            }
            
            if (userAccount === owner) {
                showToast('You cannot message yourself!', 'error');
                return;
            }
            
            // Try to use the chat system
            if (typeof window.startChat === 'function') {
                try {
                    this.disabled = true;
                    this.textContent = 'Opening chat...';
                    await window.startChat(owner, nftId);
                    showToast('Chat opened with owner!', 'success');
                } catch (err) {
                    console.error('Chat error:', err);
                    showToast('Failed to open chat: ' + err.message, 'error');
                } finally {
                    this.disabled = false;
                    this.textContent = 'Message Owner';
                }
            } else {
                // Fallback: redirect to trading hub with chat param
                showToast('Opening chat...', 'info');
                window.location.href = `/trading-hub/?chat=${owner}&nft=${nftId}`;
            }
        });
    }
    
    // =========================================================================
    // WATCHLIST BUTTON
    // =========================================================================
    const watchlistBtn = document.getElementById('nft-watchlist-btn');
    const watchlistHandlerUrl = '<?php echo get_stylesheet_directory_uri(); ?>/xrpl-nft-marketplace/backend/watchlist-handler.php';
    const taskHandlerUrl = '<?php echo get_stylesheet_directory_uri(); ?>/xrpl-nft-marketplace/backend/task-handler.php';
    
    // Check if NFT is already in watchlist on page load
    async function checkWatchlistStatus() {
        if (!userAccount || !watchlistBtn) return;
        
        try {
            const response = await fetch(`${watchlistHandlerUrl}?action=get&account=${userAccount}&nonce=${nonce}`);
            const data = await response.json();
            
            if (data.success && data.data?.watchlist) {
                const nftId = watchlistBtn.dataset.nftId;
                const isInWatchlist = data.data.watchlist.some(item => item.nft_id === nftId);
                
                if (isInWatchlist) {
                    watchlistBtn.classList.add('in-watchlist');
                    watchlistBtn.querySelector('.heart-icon').textContent = '♥';
                    watchlistBtn.querySelector('.watchlist-text').textContent = 'In Favourites';
                }
            }
        } catch (err) {
            console.error('Failed to check watchlist status:', err);
        }
    }
    
    // Initialize watchlist status check
    if (userAccount) {
        checkWatchlistStatus();
    }
    
    if (watchlistBtn) {
        watchlistBtn.addEventListener('click', async function() {
            if (!userAccount) {
                showToast('Please log in with Xaman to use favourites.', 'error');
                return;
            }
            
            const nftId = this.dataset.nftId;
            const nftName = this.dataset.nftName;
            const isInWatchlist = this.classList.contains('in-watchlist');
            const action = isInWatchlist ? 'remove' : 'add';
            
            this.disabled = true;
            const originalText = this.querySelector('.watchlist-text').textContent;
            this.querySelector('.watchlist-text').textContent = isInWatchlist ? 'Removing...' : 'Adding...';
            
            try {
                const formData = new FormData();
                formData.append('action', action);
                formData.append('account', userAccount);
                formData.append('nft_id', nftId);
                formData.append('nonce', nonce);
                
                const response = await fetch(watchlistHandlerUrl, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    if (action === 'add') {
                        this.classList.add('in-watchlist');
                        this.querySelector('.heart-icon').textContent = '♥';
                        this.querySelector('.watchlist-text').textContent = 'In Favourites';
                        showToast(`Added "${nftName}" to favourites!`, 'success');
                        
                        // Trigger task completion for "Add NFT to Watchlist"
                        try {
                            const taskForm = new FormData();
                            taskForm.append('action', 'claim_task');
                            taskForm.append('account', userAccount);
                            taskForm.append('task_id', 'daily_watchlist');
                            taskForm.append('nonce', nonce);
                            
                            fetch(taskHandlerUrl, {
                                method: 'POST',
                                body: taskForm
                            }).then(r => r.json()).then(taskData => {
                                if (taskData.success && taskData.data?.points_earned) {
                                    showToast(`🎉 Task completed! +${taskData.data.points_earned} XFT`, 'success');
                                }
                            }).catch(() => {}); // Silent fail for task
                        } catch (e) {}
                        
                    } else {
                        this.classList.remove('in-watchlist');
                        this.querySelector('.heart-icon').textContent = '♡';
                        this.querySelector('.watchlist-text').textContent = 'Add to Favourites';
                        showToast(`Removed "${nftName}" from favourites`, 'info');
                    }
                } else {
                    throw new Error(data.data?.error || 'Failed to update watchlist');
                }
            } catch (err) {
                console.error('Watchlist error:', err);
                showToast(err.message || 'Failed to update favourites', 'error');
                this.querySelector('.watchlist-text').textContent = originalText;
            } finally {
                this.disabled = false;
            }
        });
    }
    
    // =========================================================================
    // MAKE OFFER BUTTON
    // =========================================================================
    const offerBtn = document.getElementById('nft-make-offer-btn');
    const offerModal = document.getElementById('offer-modal');
    const offerForm = document.getElementById('offer-form');
    const offerAmountInput = document.getElementById('offer-amount');
    // ── v720 (G3B): turn a server trustline rejection into something actionable ──────
    //
    // Every one of these rejections is CORRECT and fails closed — offer-handler will not
    // let an offer exist that cannot settle. The problem was purely that they arrived as a
    // bare toast with no route forward, so a buyer was told "you lack an XMEME trustline"
    // and left to work out what that means on their own.
    //
    // `selfFlag` is which flag represents the CURRENT USER at the calling site. That is not
    // cosmetic: `seller_trustline_required` is the user when they are listing for sale, but
    // a THIRD PARTY when they are making a buy offer on someone else's NFT. Routing on the
    // flag name alone would offer a Set Trustline button for a wallet they do not control.
    //
    // Returns TRUE when it has handled the rejection (caller should return), FALSE to fall
    // through to the existing toast — so any response without a trustline flag behaves
    // exactly as it does today.
    function imcHandleTrustlineFlag(resp, selectEl, selfFlag) {
        if (!resp || resp.success) return false;
        const cur = resp.currency || '';
        if (!cur) return false;

        const isSelf   = !!(selfFlag && resp[selfFlag]);
        const isOther  = !!(resp.issuer_trustline_required
                         || resp.seller_trustline_required
                         || resp.buyer_trustline_required
                         || resp.trustline_required);
        if (!isSelf && !isOther) return false;

        // THEIR OWN trustline — fixable in place. The popover gives Set Trustline (Joey
        // signs in-wallet, Xaman opens xrpl.services) plus Buy {TICKER}.
        if (isSelf && window.imcTrustline && window.imcTrustline.openTokenActions) {
            window.imcTrustline.openTokenActions(cur, resp.issuer || resp.nft_issuer || '', { account: userAccount });
            return true;
        }

        // SOMEONE ELSE'S trustline — the NFT issuer's royalty wallet, or the current owner.
        // The user cannot fix either, so per product decision we remove the option rather
        // than offer a button that would mislead. Neutral wording, no Set Trustline.
        if (!isSelf) {
            if (selectEl && selectEl.options) {
                for (let i = selectEl.options.length - 1; i >= 0; i--) {
                    if (selectEl.options[i].value === cur) { selectEl.remove(i); }
                }
                if (selectEl.options.length) {
                    selectEl.selectedIndex = 0;
                    selectEl.dispatchEvent(new Event('change'));
                }
            }
            showToast(cur + ' is not available for this NFT', 'warning');
            return true;
        }
        return false;
    }

    const offerCurrencySelect = document.getElementById('offer-currency');
    const offerTrustlineWarning = document.getElementById('offer-trustline-warning');
    const setTrustlineLink = document.getElementById('set-trustline-link');
    
    // v86: Track trustline status
    let currentTrustlineOk = true;
    let tokensLoaded = false;
    
    // Get royalty info from PHP
    const nftRoyaltyPercent = <?php echo floatval(str_replace('%', '', $nft_transfer_fee)); ?>;
    const platformFeePercent = 1.5;
    
    // v86: Load tokens from Token Manager
    async function loadOfferTokens() {
        if (tokensLoaded) return;
        try {
            const tokens = window.imcTrustline ? await window.imcTrustline.getTokens('offers') : [];
            if (tokens.length > 0) {
                // Keep XRP as first option
                offerCurrencySelect.innerHTML = '<option value="XRP" data-issuer="">💧 XRP</option>';
                tokens.filter(t => t.ticker !== 'XRP').forEach(t => {
                    const option = document.createElement('option');
                    option.value = t.ticker;
                    option.dataset.issuer = t.issuer || '';
                    option.dataset.trustlineUrl = t.trustline_url || '';
                    option.textContent = `${t.icon || '🪙'} ${t.ticker}`;
                    offerCurrencySelect.appendChild(option);
                });
            }
            tokensLoaded = true;
        } catch (err) {
            console.error('Failed to load tokens:', err);
        }
    }
    
    // v86: Check trustline when currency changes
    async function checkOfferTrustline() {
        const currency = offerCurrencySelect?.value || 'XRP';
        const submitBtn = document.getElementById('offer-submit-btn');
        
        if (currency === 'XRP') {
            currentTrustlineOk = true;
            offerTrustlineWarning.style.display = 'none';
            submitBtn.disabled = false;
            return;
        }
        
        if (!window.imcTrustline || !userAccount) {
            currentTrustlineOk = true; // Assume ok if can't check
            return;
        }
        
        const selectedOption = offerCurrencySelect.selectedOptions[0];
        const trustlineUrl = selectedOption?.dataset.trustlineUrl || '';
        
        // v711 (G1): pass the issuer. Before Step D this check never ran (imcTrustline was
        // not enqueued here), so the missing 3rd argument was harmless. It runs now. Without
        // an explicit issuer the server falls back to the token registries, and a WALLET-
        // SOURCED custom token has no registry entry -> resolution fails CLOSED -> the user is
        // told they lack a trustline for a token they demonstrably hold, and submit stays
        // disabled. dataset.issuer is populated at load (see the option build above).
        const result = await window.imcTrustline.checkTrustline(userAccount, currency, selectedOption?.dataset.issuer || '');
        currentTrustlineOk = result.has_trustline;
        
        if (!result.has_trustline) {
            document.getElementById('trustline-warning-text').textContent = 
                `You need a trustline for ${currency} to make offers with this token.`;
            // v716 (G2B): open the shared popover rather than a bare deeplink. Always shown
            // now -- the old code hid this link whenever no URL resolved, which left the
            // warning with nothing to act on. The popover works from the issuer alone.
            setTrustlineLink.href = '#';
            setTrustlineLink.style.display = 'block';
            setTrustlineLink.textContent = `Set ${currency} Trustline \u2192`;
            setTrustlineLink.onclick = function (ev) {
                ev.preventDefault();
                if (window.imcTrustline && window.imcTrustline.openTokenActions) {
                    window.imcTrustline.openTokenActions(currency, selectedOption?.dataset.issuer || '', { account: userAccount });
                } else if (trustlineUrl || result.trustline_url) {
                    window.open(trustlineUrl || result.trustline_url, '_blank');   // graceful fallback
                }
            };
            offerTrustlineWarning.style.display = 'block';
            submitBtn.disabled = true;
        } else {
            offerTrustlineWarning.style.display = 'none';
            submitBtn.disabled = false;
        }
    }
    
    // Update offer fee breakdown when amount changes
    function updateOfferFeeBreakdown() {
        const amount = parseFloat(offerAmountInput?.value) || 0;
        const currency = offerCurrencySelect?.value || 'XRP';
        const feeBreakdown = document.getElementById('offer-fee-breakdown');
        
        // Update royalty percent display
        const royaltyPercentEl = document.getElementById('offer-royalty-percent');
        if (royaltyPercentEl) royaltyPercentEl.textContent = nftRoyaltyPercent.toFixed(1);
        
        if (amount > 0) {
            const platformFee = amount * (platformFeePercent / 100);
            const royaltyFee = amount * (nftRoyaltyPercent / 100);
            const netAmount = amount - platformFee - royaltyFee;
            
            document.getElementById('offer-amount-display').textContent = `${amount.toFixed(6)} ${currency}`;
            document.getElementById('offer-platform-fee').textContent = `${platformFee.toFixed(6)} ${currency}`;
            document.getElementById('offer-royalty-fee').textContent = `${royaltyFee.toFixed(6)} ${currency}`;
            document.getElementById('offer-net-amount').textContent = `${netAmount.toFixed(6)} ${currency}`;
            feeBreakdown.style.display = 'block';
        } else {
            feeBreakdown.style.display = 'none';
        }
    }
    
    offerAmountInput?.addEventListener('input', updateOfferFeeBreakdown);
    offerCurrencySelect?.addEventListener('change', () => {
        updateOfferFeeBreakdown();
        checkOfferTrustline(); // v86: Check trustline on currency change
    });
    
    if (offerBtn && !offerBtn.disabled) {
        offerBtn.addEventListener('click', async function() {
            if (!userAccount) {
                showToast('Please log in with Xaman to make an offer.', 'error');
                return;
            }
            // v560: in 'Cancel Offer' mode (visitor already has an active buy offer),
            // route to the existing cancel flow instead of opening the make-offer modal.
            if (this.dataset.cancelOfferId) {
                await handleCancelExternalListing(this.dataset.cancelOfferId);
                return;
            }
            
            const nftName = this.dataset.name || 'this NFT';
            document.getElementById('offer-nft-preview').textContent = `Offering on: ${nftName}`;
            
            // Reset form and fee breakdown
            offerAmountInput.value = '';
            document.getElementById('offer-fee-breakdown').style.display = 'none';
            offerTrustlineWarning.style.display = 'none';
            
            // v86: Load tokens and reset to XRP
            await loadOfferTokens();
            offerCurrencySelect.value = 'XRP';
            currentTrustlineOk = true;
            
            offerModal.classList.add('active');
            document.body.classList.add('imc-modal-open'); // v198
        });
    }
    
    window.closeOfferModal = function() {
        offerModal.classList.remove('active');
        document.body.classList.remove('imc-modal-open'); // v198
    };
    
    // Close modal on backdrop click
    offerModal?.addEventListener('click', function(e) {
        if (e.target === offerModal) closeOfferModal();
    });
    
    // =========================================================================
    // QR MODAL HANDLING
    // =========================================================================
    const qrModal = document.getElementById('qr-modal');
    const qrCodeImage = document.getElementById('qr-code-image');
    const qrDeeplinkBtn = document.getElementById('qr-deeplink-btn');
    const qrSigningStatus = document.getElementById('qr-signing-status');
    const qrCloseBtn = document.getElementById('qr-close-btn');
    const qrRetryBtn = document.getElementById('qr-retry-btn');
    const qrModalContent = qrModal?.querySelector('.qr-modal-content');
    const qrModalTitle = document.getElementById('qr-modal-title');
    
    let currentPayloadUuid = null;
    let pollingActive = false;

    // -- v588 mobile UX (additive): instant signature re-check when returning from Xaman --
    // Offer flow only. poll_xumm_payload derives the type from the payload's own custom_meta,
    // so a uuid-only re-poll returns the correct status AND performs the same server-side DB
    // update as the active poll. Read-only: it never signs, it only reflects confirmed state.
    const imcIsMobileUA = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent || '');
    let imcQrReturnBusy = false;
    document.addEventListener('visibilitychange', function() {
        if (document.hidden || imcQrReturnBusy) return;
        if (!qrModal || !qrModal.classList.contains('active') || !currentPayloadUuid) return;
        imcQrReturnBusy = true;
        fetch(offerHandler + '?action=poll_xumm_payload&uuid=' + encodeURIComponent(currentPayloadUuid) + '&nonce=' + encodeURIComponent(nonce))
            .then(function(r){ return r.json(); })
            .then(function(d){
                imcQrReturnBusy = false;
                if (d && d.status === 'confirmed') {
                    if (qrSigningStatus) { qrSigningStatus.textContent = '✓ Signed - updating...'; qrSigningStatus.className = 'qr-signing-status success'; }
                    setTimeout(function(){ window.location.reload(); }, 800);
                }
            })
            .catch(function(){ imcQrReturnBusy = false; });
    });
    
    function showQrModal(qrUrl, deeplink, payloadUuid, title = 'Sign with Xaman') {
        if (!qrModal) return;

        // v684: single choke point for every Xaman signing path on this page. Since v683 the
        // SERVER decides the wallet from the xrpl_wallet_type cookie, so a user holding a joey
        // cookie whose WalletConnect session isn't live takes an Xaman path but receives a Joey
        // txjson -- no qr, no payload_uuid. Without this, the modal opens and polls an undefined
        // uuid forever. Every genuine Xaman caller passes a real payload_uuid, so this can only
        // fire when the modal would already be non-functional.
        if (!payloadUuid) {
            if (typeof showToast === 'function') {
                showToast('Wallet still connecting — please try again in a moment.', 'error');
            }
            return;
        }
        
        currentPayloadUuid = payloadUuid;
        
        // v197: Proper QR handling — never show NFT placeholder in QR container
        const qrContainer = document.querySelector('.qr-code-container');
        const existingFallback = qrContainer?.querySelector('.qr-fallback-msg');
        if (existingFallback) existingFallback.remove();
        
        if (qrUrl) {
            qrCodeImage.style.display = '';
            qrCodeImage.onerror = function() {
                // QR image failed to load — show text fallback, not NFT image
                this.style.display = 'none';
                if (qrContainer) {
                    const msg = document.createElement('div');
                    msg.className = 'qr-fallback-msg';
                    msg.style.cssText = 'padding: 2rem 1rem; text-align: center;';
                    msg.innerHTML = '<p style="color: #999; font-size: 0.9rem; margin: 0;">QR code unavailable<br><span style="font-size: 0.8rem;">Use the button below to sign in Xaman</span></p>';
                    qrContainer.appendChild(msg);
                }
            };
            qrCodeImage.src = qrUrl;
        } else {
            // No QR URL provided at all
            qrCodeImage.style.display = 'none';
            if (qrContainer) {
                const msg = document.createElement('div');
                msg.className = 'qr-fallback-msg';
                msg.style.cssText = 'padding: 2rem 1rem; text-align: center;';
                msg.innerHTML = '<p style="color: #999; font-size: 0.9rem; margin: 0;">QR code unavailable<br><span style="font-size: 0.8rem;">Use the button below to sign in Xaman</span></p>';
                qrContainer.appendChild(msg);
            }
        }
        
        qrDeeplinkBtn.href = deeplink || '#';
        if (imcIsMobileUA) qrDeeplinkBtn.setAttribute('target', '_self'); // v588 mobile: open Xaman in the same tab -- no orphan tab
        qrModalTitle.textContent = title;
        qrSigningStatus.textContent = 'Waiting for signature...';
        qrSigningStatus.className = 'qr-signing-status';
        qrCloseBtn.style.display = 'none';
        qrRetryBtn.style.display = 'none';
        qrModalContent.classList.remove('success');
        document.body.classList.add('imc-modal-open'); // v198
        qrModal.classList.add('active');
        
        // Start polling
        pollSigningStatus(payloadUuid);
    }
    
    function closeQrModal() {
        if (!qrModal) return;
        pollingActive = false;
        qrModal.classList.remove('active');
        document.body.classList.remove('imc-modal-open'); // v198
    }
    
    qrCloseBtn?.addEventListener('click', closeQrModal);
    qrModal?.addEventListener('click', function(e) {
        if (e.target === qrModal && qrCloseBtn.style.display !== 'none') {
            closeQrModal();
        }
    });
    
    // Polling configuration
    const POLL_INTERVAL = 2500; // 2.5 seconds
    const MAX_POLL_ATTEMPTS = 72; // 3 minutes total
    
    async function pollSigningStatus(payloadUuid) {
        if (!payloadUuid) return;
        
        pollingActive = true;
        let attempts = 0;
        
        while (pollingActive && attempts < MAX_POLL_ATTEMPTS) {
            attempts++;
            
            try {
                // First check our database for status
                const statusUrl = `/wp-content/themes/astra/xrpl-nft-marketplace/backend/offer-handler.php?action=get_offer_status&uuid=${encodeURIComponent(payloadUuid)}&account=${encodeURIComponent(userAccount)}&nonce=${encodeURIComponent(nonce)}`;
                
                const res = await fetch(statusUrl);
                const data = await res.json();
                
                console.log('Poll status:', data);
                
                // Check if status is active (signed and CONFIRMED on-chain)
                if (data.success && data.status === 'active') {
                    pollingActive = false;
                    qrSigningStatus.textContent = '✓ Offer created successfully!';
                    qrSigningStatus.className = 'qr-signing-status success';
                    qrModalContent.classList.add('success');
                    qrCloseBtn.style.display = 'block';
                    
                    showToast('Offer created successfully!', 'success');
                    
                    setTimeout(() => {
                        closeQrModal();
                        closeOfferModal();
                        // v198: Reload page if this was a sell listing to show listed state
                        if (window._imcListingPending) {
                            window._imcListingPending = false;
                            window.location.reload();
                        }
                    }, 2000);
                    
                    return true;
                } else if (data.success && data.status === 'signed_pending') {
                    // Transaction signed but still being confirmed on-chain
                    qrSigningStatus.textContent = 'Confirming on blockchain...';
                    // Don't reset signedConfirmations - keep polling
                } else if (data.success && data.status === 'failed') {
                    // Transaction failed on-chain
                    pollingActive = false;
                    const errorMsg = data.error || 'Transaction failed on-chain';
                    qrSigningStatus.textContent = '✗ ' + errorMsg;
                    qrSigningStatus.className = 'qr-signing-status error';
                    qrCloseBtn.style.display = 'block';
                    qrRetryBtn.style.display = 'block';
                    
                    showToast(errorMsg, 'error');
                    return false;
                } else if (data.success && data.status === 'accepted') {
                    pollingActive = false;
                    qrSigningStatus.textContent = '✓ Offer accepted!';
                    qrSigningStatus.className = 'qr-signing-status success';
                    qrModalContent.classList.add('success');
                    qrCloseBtn.style.display = 'block';
                    
                    showToast('Transaction complete!', 'success');
                    setTimeout(() => {
                        closeQrModal();
                        window.location.reload();
                    }, 2000);
                    
                    return true;
                } else if (data.success && data.status === 'rejected') {
                    pollingActive = false;
                    qrSigningStatus.textContent = '✗ Transaction was declined';
                    qrSigningStatus.className = 'qr-signing-status error';
                    qrCloseBtn.style.display = 'block';
                    qrRetryBtn.style.display = 'block';
                    
                    showToast('Transaction was declined', 'error');
                    return false;
                } else if (data.success && data.status === 'expired') {
                    pollingActive = false;
                    qrSigningStatus.textContent = '✗ Transaction expired';
                    qrSigningStatus.className = 'qr-signing-status error';
                    qrCloseBtn.style.display = 'block';
                    qrRetryBtn.style.display = 'block';
                    
                    showToast('Transaction expired. Please try again.', 'error');
                    return false;
                } else {
                    // Still pending - keep polling
                    qrSigningStatus.textContent = 'Waiting for signature...';
                }
                
            } catch (err) {
                console.error('Polling error:', err);
            }
            
            await new Promise(resolve => setTimeout(resolve, POLL_INTERVAL));
        }
        
        // Timeout
        if (pollingActive) {
            pollingActive = false;
            qrSigningStatus.textContent = 'Signing timed out. Please try again.';
            qrSigningStatus.className = 'qr-signing-status error';
            qrCloseBtn.style.display = 'block';
            qrRetryBtn.style.display = 'block';
            
            showToast('Signing timed out', 'error');
        }
        
        return false;
    }
    
    // =========================================================================
    // OFFER SUBMISSION - Now uses QR Modal
    // =========================================================================
    offerForm?.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const amount = parseFloat(document.getElementById('offer-amount').value);
        const currency = document.getElementById('offer-currency').value;
        const nftId = offerBtn.dataset.nftId;
        const owner = offerBtn.dataset.owner;
        
        if (!amount || amount <= 0) {
            showToast('Please enter a valid amount.', 'error');
            return;
        }
        
        // v86: Check trustline before submission
        if (!currentTrustlineOk) {
            showToast(`You need a trustline for ${currency} to make this offer.`, 'error');
            return;
        }
        
        const submitBtn = document.getElementById('offer-submit-btn');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Creating offer...';
        
        try {
            // v86: Get issuer from selected option
            const selectedOption = offerCurrencySelect.selectedOptions[0];
            const issuer = selectedOption?.dataset.issuer || '';
            
            const formData = new FormData();
            formData.append('action', 'create_offer');
            formData.append('target_nft_id', nftId);
            formData.append('account', userAccount);
            formData.append('amount', amount);
            formData.append('currency', currency);
            if (issuer) formData.append('issuer', issuer);
            formData.append('nonce', nonce);
            
            // --- Joey (WalletConnect) make-offer branch -- v556, additive --------------
            // Mirrors the working listing branch: with a live Joey session, sign locally;
            // otherwise fall straight through to the unchanged Xaman path below. The
            // outer finally{} re-enables the Submit button on every return.
            if (window.__joeySession && window.__joeySession.live && window.imuWallet) {
                if (userAccount !== window.__joeySession.account) {
                    showToast('Connected wallet does not match your account. Please reconnect.', 'error');
                    return;
                }
                formData.append('wallet', 'joey');
                const jres = await fetch('/wp-content/themes/astra/xrpl-nft-marketplace/backend/offer-handler.php', { method: 'POST', body: formData });
                const jdata = await jres.json();
                if (!jdata || !jdata.success || !jdata.txjson) {
                    // v720 (G3B): the buyer's OWN trustline is fixable here; the issuer's
                    // royalty wallet and the current owner's are not.
                    if (imcHandleTrustlineFlag(jdata, document.getElementById('offer-currency'), 'buyer_trustline_required')) { return; }
                    showToast((jdata && jdata.error) || 'Failed to prepare offer', 'error');
                    return;
                }
                closeOfferModal();
                let signed;
                try {
                    signed = await (window.imuJoeySign||window.imuWallet.sign)(jdata.txjson);
                } catch (e) {
                    const em = (e && e.message) || '';
                    showToast(/timed out|timeout/i.test(em) ? 'Signing timed out. Please try again.' : /cancel/i.test(em) ? 'Offer cancelled.' : 'Offer rejected.', 'error');
                    return;
                }
                const txHash = signed && (signed.hash || signed.tx_hash);
                if (!txHash) {
                    showToast('No transaction hash returned from your wallet.', 'error');
                    return;
                }
                let vdata;
                try {
                    const vfd = new FormData();
                    vfd.append('action', 'joey_verify_create_buy_offer');
                    vfd.append('tx_hash', txHash);
                    vfd.append('nonce', nonce);
                    const vres = await fetch('/wp-content/themes/astra/xrpl-nft-marketplace/backend/offer-handler.php', { method: 'POST', body: vfd });
                    vdata = await vres.json();
                } catch (e) {
                    showToast('Offer signed, but confirmation failed. It may still appear shortly.', 'warning');
                    return;
                }
                if (vdata && vdata.success) {
                    showToast('Offer submitted!', 'success');
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    showToast((vdata && vdata.error) || 'Offer verification failed', 'error');
                }
                return;
            }

            const res = await fetch('/wp-content/themes/astra/xrpl-nft-marketplace/backend/offer-handler.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await res.json();
            console.log('Offer response:', data);
            
            if (data.success && data.qr && data.deeplink) {
                // Show QR Modal instead of opening new tab
                closeOfferModal();
                showQrModal(data.qr, data.deeplink, data.payload_uuid, 'Sign Offer');
            } else {
                showToast(data.error || 'Failed to create offer', 'error');
            }
        } catch (err) {
            console.error('Offer error:', err);
            showToast('Failed to create offer: ' + err.message, 'error');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Submit Offer';
        }
    });
    
    // Retry button handler
    qrRetryBtn?.addEventListener('click', function() {
        closeQrModal();
        offerModal?.classList.add('active');
    });
    
    // =========================================================================
    // LIST FOR SALE BUTTON (for owners)
    // =========================================================================
    const listBtn = document.getElementById('nft-list-btn');
    const listingModal = document.getElementById('listing-modal');
    const listingForm = document.getElementById('listing-form');
    const listingAmount = document.getElementById('listing-amount');
    const listingCurrency = document.getElementById('listing-currency');
    const nftIdForListing = '<?php echo esc_js($nft_id); ?>';
    
    // v197: Dynamic token loading for sell listings (mirrors offer modal pattern)
    let listingTokensLoaded = false;
    let listingTrustlineOk = true;
    const listingTrustlineWarning = document.getElementById('listing-trustline-warning');
    const listingTrustlineLink = document.getElementById('listing-trustline-link');
    
    async function loadListingTokens() {
        if (listingTokensLoaded) return;
        try {
            // Step 1: Get platform-registered tokens from Token Manager
            const registeredTokens = window.imcTrustline ? await window.imcTrustline.getTokens('offers') : [];
            const tokenMap = new Map();
            
            // Add XRP first (always available)
            tokenMap.set('XRP', { ticker: 'XRP', issuer: '', icon: '💧', name: 'XRP', source: 'platform' });
            
            // Add registered tokens
            registeredTokens.filter(t => t.ticker !== 'XRP').forEach(t => {
                tokenMap.set(t.ticker + '_' + (t.issuer || ''), {
                    ticker: t.ticker, issuer: t.issuer || '', icon: t.icon || '🪙',
                    name: t.name || t.ticker, trustline_url: t.trustline_url || '', source: 'platform'
                });
            });
            
            // Step 2: Get user's actual trustlines (shows tokens they hold that aren't in Token Manager)
            if (userAccount) {
                try {
                    const resp = await fetch(`/wp-content/themes/astra/xrpl-nft-marketplace/backend/currency-handler.php?action=get_account_currencies&account=${encodeURIComponent(userAccount)}`);
                    const data = await resp.json();
                    if (data.success && Array.isArray(data.data)) {
                        data.data.filter(c => c.currency !== 'XRP' && !c.is_native && c.issuer).forEach(c => {
                            const displayName = c.display_name || c.currency;
                            const key = displayName + '_' + c.issuer;
                            if (!tokenMap.has(key)) {
                                tokenMap.set(key, {
                                    ticker: displayName, issuer: c.issuer,
                                    icon: c.icon || '🔗', name: c.name || displayName,
                                    source: 'wallet', balance: c.balance
                                });
                            }
                        });
                    }
                } catch (err) {
                    console.warn('Could not fetch user currencies:', err);
                }
            }
            
            // Step 3: Build dropdown
            listingCurrency.innerHTML = '<option value="XRP" data-issuer="">💧 XRP</option>';
            for (const [key, t] of tokenMap) {
                if (t.ticker === 'XRP') continue;
                const option = document.createElement('option');
                option.value = t.ticker;
                option.dataset.issuer = t.issuer || '';
                option.dataset.trustlineUrl = t.trustline_url || '';
                option.dataset.source = t.source || 'platform';
                const label = t.source === 'wallet' ? `${t.icon} ${t.ticker} (wallet)` : `${t.icon} ${t.ticker}`;
                option.textContent = label;
                listingCurrency.appendChild(option);
            }
            
            listingTokensLoaded = true;
        } catch (err) {
            console.error('Failed to load listing tokens:', err);
        }
    }
    
    // v197: Trustline check for sell listings (seller needs trustline to RECEIVE payment)
    async function checkListingTrustline() {
        const currency = listingCurrency?.value || 'XRP';
        const submitBtn = document.getElementById('listing-submit-btn');
        
        if (currency === 'XRP') {
            listingTrustlineOk = true;
            if (listingTrustlineWarning) listingTrustlineWarning.style.display = 'none';
            if (submitBtn) submitBtn.disabled = false;
            return;
        }
        
        if (!window.imcTrustline || !userAccount) {
            listingTrustlineOk = true;
            return;
        }
        
        const selectedOption = listingCurrency.selectedOptions[0];
        const trustlineUrl = selectedOption?.dataset.trustlineUrl || '';
        
        // v711 (G1): pass the issuer -- see the note on the offer check above. This path is
        // the more exposed one: the listing dropdown deliberately includes wallet-held custom
        // tokens (get_account_currencies, balance > 0), which are exactly the tokens with no
        // registry entry to fall back on.
        const result = await window.imcTrustline.checkTrustline(userAccount, currency, selectedOption?.dataset.issuer || '');
        listingTrustlineOk = result.has_trustline;
        
        if (!result.has_trustline) {
            if (document.getElementById('listing-trustline-text')) {
                document.getElementById('listing-trustline-text').textContent =
                    `You need a ${currency} trustline to receive payment in this token.`;
            }
            if (listingTrustlineLink) {
                // v716 (G2B): same treatment as the offer warning above -- popover instead of
                // a raw deeplink, and never hidden, so the seller always has a way to act.
                listingTrustlineLink.href = '#';
                listingTrustlineLink.style.display = 'block';
                listingTrustlineLink.textContent = `Set ${currency} Trustline \u2192`;
                listingTrustlineLink.onclick = function (ev) {
                    ev.preventDefault();
                    if (window.imcTrustline && window.imcTrustline.openTokenActions) {
                        window.imcTrustline.openTokenActions(currency, selectedOption?.dataset.issuer || '', { account: userAccount });
                    } else if (trustlineUrl || result.trustline_url) {
                        window.open(trustlineUrl || result.trustline_url, '_blank');
                    }
                };
            }
            if (listingTrustlineWarning) listingTrustlineWarning.style.display = 'block';
            if (submitBtn) submitBtn.disabled = true;
        } else {
            if (listingTrustlineWarning) listingTrustlineWarning.style.display = 'none';
            if (submitBtn) submitBtn.disabled = false;
        }
    }
    // Note: nftRoyaltyPercent and platformFeePercent already declared above
    
    window.closeListingModal = function() {
        listingModal?.classList.remove('active');
        document.body.classList.remove('imc-modal-open'); // v198
    };
    
    // Close listing modal on backdrop click
    listingModal?.addEventListener('click', function(e) {
        if (e.target === listingModal) closeListingModal();
    });
    
    // Update fee breakdown when amount changes
    function updateFeeBreakdown() {
        const amount = parseFloat(listingAmount?.value) || 0;
        const currency = listingCurrency?.value || 'XRP';
        const feeBreakdown = document.getElementById('listing-fee-breakdown');
        
        if (amount > 0) {
            const platformFee = amount * (platformFeePercent / 100);
            const royaltyFee = amount * (nftRoyaltyPercent / 100);
            const netAmount = amount - platformFee - royaltyFee;
            
            document.getElementById('listing-price-display').textContent = `${amount.toFixed(6)} ${currency}`;
            document.getElementById('listing-platform-fee').textContent = `-${platformFee.toFixed(6)} ${currency}`;
            document.getElementById('listing-royalty-fee').textContent = `-${royaltyFee.toFixed(6)} ${currency}`;
            document.getElementById('listing-net-amount').textContent = `${netAmount.toFixed(6)} ${currency}`;
            feeBreakdown.style.display = 'block';
        } else {
            feeBreakdown.style.display = 'none';
        }
    }
    
    listingAmount?.addEventListener('input', updateFeeBreakdown);
    listingCurrency?.addEventListener('change', () => {
        updateFeeBreakdown();
        checkListingTrustline(); // v197: Check trustline on currency change
    });
    
    if (listBtn) {
        listBtn.addEventListener('click', async function() {
            if (!userAccount) {
                showToast('Please log in with Xaman first.', 'error');
                return;
            }
            await loadListingTokens(); // v197: Load tokens dynamically
            if (listingTrustlineWarning) listingTrustlineWarning.style.display = 'none';
            listingTrustlineOk = true;
            document.body.classList.add('imc-modal-open'); // v198
            listingModal?.classList.add('active');
        });
    }
    
    // v94: Simplified GTC-only listing submission
    listingForm?.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const amount = parseFloat(listingAmount.value);
        const currency = listingCurrency.value;
        
        if (!amount || amount <= 0) {
            showToast('Please enter a valid amount.', 'error');
            return;
        }
        
        const submitBtn = document.getElementById('listing-submit-btn');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Creating listing...';
        
        try {
            // v197: Check trustline before submission
            if (!listingTrustlineOk) {
                showToast(`You need a trustline for ${currency} to receive payment.`, 'error');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Create Listing';
                return;
            }
            
            // v197: Get issuer from selected option
            const selectedOption = listingCurrency.selectedOptions[0];
            const issuer = selectedOption?.dataset.issuer || '';
            
            const formData = new FormData();
            formData.append('action', 'create_sell_offer');
            formData.append('nft_id', nftIdForListing);
            formData.append('account', userAccount);
            formData.append('amount', amount);
            formData.append('currency', currency);
            if (issuer) formData.append('issuer', issuer); // v197: Send issuer
            formData.append('nonce', nonce);
            
            // --- Joey (WalletConnect) listing branch -- v544, additive ---------
            // Only when a live Joey session exists do we sign locally; otherwise we
            // fall straight through to the unchanged Xaman path below. offer-handler
            // applies the SAME server-side validation in both cases.
            if (window.__joeySession && window.__joeySession.live && window.imuWallet) {
                // Account-binding: never sign with a wallet that isn't this account.
                if (userAccount !== window.__joeySession.account) {
                    showToast('Connected wallet does not match your account. Please reconnect.', 'error');
                    return;
                }
                formData.append('wallet', 'joey');
                let jdata;
                try {
                    const jres = await fetch('/wp-content/themes/astra/xrpl-nft-marketplace/backend/offer-handler.php', { method: 'POST', body: formData });
                    jdata = await jres.json();
                } catch (e) {
                    showToast('Could not prepare listing. Please try again.', 'error');
                    return;
                }
                if (!jdata || !jdata.success || !jdata.txjson) {
                    // v720 (G3B): here the SELLER is the user, so seller_trustline_required
                    // is the actionable one -- the opposite of the buy-offer sites above.
                    if (imcHandleTrustlineFlag(jdata, document.getElementById('listing-currency'), 'seller_trustline_required')) { return; }
                    showToast((jdata && jdata.error) || 'Failed to prepare listing', 'error');
                    return;
                }
                closeListingModal();
                submitBtn.textContent = 'Check your wallet...';
                let signed;
                try {
                    signed = await (window.imuJoeySign||window.imuWallet.sign)(jdata.txjson);
                } catch (e) {
                    showToast('Signing was cancelled or failed.', 'error');
                    return;
                }
                const txHash = signed && (signed.hash || signed.tx_hash);
                if (!txHash) {
                    showToast('No transaction hash returned from your wallet.', 'error');
                    return;
                }
                let vdata;
                try {
                    const vfd = new FormData();
                    vfd.append('action', 'joey_verify_create_offer');
                    vfd.append('tx_hash', txHash);
                    vfd.append('nonce', nonce);
                    const vres = await fetch('/wp-content/themes/astra/xrpl-nft-marketplace/backend/offer-handler.php', { method: 'POST', body: vfd });
                    vdata = await vres.json();
                } catch (e) {
                    showToast('Listing signed, but confirmation failed. It may still appear shortly.', 'warning');
                    return;
                }
                if (vdata && vdata.success) {
                    showToast('Listing is now live!', 'success');
                    window._imcListingPending = true;
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    showToast((vdata && vdata.error) || 'Listing verification failed', 'error');
                }
                return;
            }

            const res = await fetch('/wp-content/themes/astra/xrpl-nft-marketplace/backend/offer-handler.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await res.json();
            console.log('Listing response:', data);
            
            if (data.success && data.qr && data.deeplink) {
                closeListingModal();
                window._imcListingPending = true; // v198: track for reload
                showQrModal(data.qr, data.deeplink, data.payload_uuid, 'Sign Listing');
            } else {
                showToast(data.error || 'Failed to create listing', 'error');
            }
        } catch (err) {
            console.error('Listing error:', err);
            showToast('Failed to create listing: ' + err.message, 'error');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Create Listing';
        }
    });

    // ========================================================================
    // v198: CANCEL LISTING from NFT page
    // ========================================================================
    const cancelListingBtn = document.getElementById('nft-cancel-listing-btn');
    if (cancelListingBtn) {
        cancelListingBtn.addEventListener('click', async function() {
            const offerId = this.dataset.offerId;
            if (!offerId || !userAccount) {
                showToast('Missing offer ID or account.', 'error');
                return;
            }
            
            if (await imcJoeyCancelListing(offerId)) return;
            this.disabled = true;
            this.textContent = 'Cancelling...';
            
            try {
                const body = new URLSearchParams({
                    action: 'cancel_offer',
                    account: userAccount,
                    offer_id: offerId,
                    nonce: nonce
                });
                
                const res = await fetch('/wp-content/themes/astra/xrpl-nft-marketplace/backend/offer-handler.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body
                });
                
                const data = await res.json();
                
                if (data.success && data.payload_uuid) {
                    // Show QR modal for cancel signing (uses existing showQrModal)
                    showQrModal(data.qr || '', data.deeplink || '', data.payload_uuid, 'Cancel Listing');
                    
                    // Override the default DB-based poll with direct XUMM poll
                    // (cancel payloads aren't stored in offers table, so get_offer_status won't find them)
                    pollingActive = false; // Stop the default poll started by showQrModal
                    
                    let cancelAttempts = 0;
                    const cancelPoll = setInterval(async () => {
                        cancelAttempts++;
                        if (cancelAttempts > 72) {
                            clearInterval(cancelPoll);
                            qrSigningStatus.textContent = '✗ Timed out';
                            qrSigningStatus.className = 'qr-signing-status error';
                            qrCloseBtn.style.display = 'block';
                            return;
                        }
                        try {
                            const pollUrl = `/wp-content/themes/astra/xrpl-nft-marketplace/backend/offer-handler.php?action=poll_xumm_payload&uuid=${encodeURIComponent(data.payload_uuid)}&type=nft_offer_cancel&nonce=${encodeURIComponent(nonce)}`;
                            const pollRes = await fetch(pollUrl);
                            const pollData = await pollRes.json();
                            
                            if (pollData.status === 'confirmed') {
                                clearInterval(cancelPoll);
                                qrSigningStatus.textContent = '✓ Listing cancelled!';
                                qrSigningStatus.className = 'qr-signing-status success';
                                qrModalContent.classList.add('success');
                                showToast('Listing cancelled successfully!', 'success');
                                setTimeout(() => window.location.reload(), 2000);
                            } else if (pollData.status === 'rejected' || pollData.status === 'declined') {
                                clearInterval(cancelPoll);
                                qrSigningStatus.textContent = '✗ Cancelled by user';
                                qrSigningStatus.className = 'qr-signing-status error';
                                qrCloseBtn.style.display = 'block';
                                cancelListingBtn.disabled = false;
                                cancelListingBtn.textContent = 'Cancel Listing';
                            } else if (pollData.status === 'expired') {
                                clearInterval(cancelPoll);
                                qrSigningStatus.textContent = '✗ Expired';
                                qrSigningStatus.className = 'qr-signing-status error';
                                qrCloseBtn.style.display = 'block';
                                cancelListingBtn.disabled = false;
                                cancelListingBtn.textContent = 'Cancel Listing';
                            } else if (pollData.status === 'signed_pending') {
                                qrSigningStatus.textContent = 'Confirming on blockchain...';
                            }
                        } catch (e) { /* keep polling */ }
                    }, 2500);
                } else {
                    showToast(data.error || 'Failed to cancel listing.', 'error');
                    this.disabled = false;
                    this.textContent = 'Cancel Listing';
                }
            } catch (err) {
                console.error('Cancel listing error:', err);
                showToast('Failed to cancel: ' + err.message, 'error');
                this.disabled = false;
                this.textContent = 'Cancel Listing';
            }
        });
    }

    // ========================================================================
    // v250: TRANSFER NFT BUTTON
    // ========================================================================
    const transferBtn  = document.getElementById('nft-transfer-btn');
    const transferModal = document.getElementById('transfer-modal');
    const transferForm  = document.getElementById('transfer-form');
    const transferDest  = document.getElementById('transfer-destination');
    const transferErr   = document.getElementById('transfer-addr-error');

    window.closeTransferModal = function() {
        transferModal?.classList.remove('active');
        document.body.classList.remove('imc-modal-open');
    };

    transferModal?.addEventListener('click', function(e) {
        if (e.target === transferModal) closeTransferModal();
    });

    if (transferBtn) {
        transferBtn.addEventListener('click', function() {
            if (!userAccount) {
                showToast('Please log in with Xaman first.', 'error');
                return;
            }
            // Reset form
            if (transferDest)  transferDest.value = '';
            if (transferErr)   { transferErr.textContent = ''; transferErr.style.display = 'none'; }
            const submitBtn = document.getElementById('transfer-submit-btn');
            if (submitBtn)     { submitBtn.disabled = false; submitBtn.textContent = 'Send Transfer Offer'; }

            document.body.classList.add('imc-modal-open');
            transferModal.classList.add('active');
        });
    }

    transferForm?.addEventListener('submit', async function(e) {
        e.preventDefault();

        const destination = (transferDest?.value || '').trim();
        const nftId       = transferBtn?.dataset.nftId || '';
        const submitBtn   = document.getElementById('transfer-submit-btn');

        // Client-side address validation (basic XRPL format check)
        if (!/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/.test(destination)) {
            transferErr.textContent = 'Invalid XRPL address format. Must start with r and be 26–35 characters.';
            transferErr.style.display = 'block';
            return;
        }
        if (destination === userAccount) {
            transferErr.textContent = 'You cannot transfer an NFT to yourself.';
            transferErr.style.display = 'block';
            return;
        }
        transferErr.style.display = 'none';

        submitBtn.disabled = true;
        submitBtn.textContent = 'Creating transfer…';

        try {
            // --- Joey (WalletConnect) transfer branch -- v561, additive. Same server
            // validation + 0-value sell-to-destination txjson; sign locally, then verify
            // on-chain. Returns before the Xaman QR path; finally{} resets the button. -------
            if (window.__joeySession && window.__joeySession.live && window.imuWallet) {
                if (userAccount !== window.__joeySession.account) {
                    transferErr.textContent = 'Connected wallet does not match your account. Please reconnect.';
                    transferErr.style.display = 'block';
                    return;
                }
                const jfd = new FormData();
                jfd.append('action', 'create_transfer_offer');
                jfd.append('nft_id', nftId);
                jfd.append('account', userAccount);
                jfd.append('destination', destination);
                jfd.append('nonce', nonce);
                jfd.append('wallet', 'joey');
                const jres = await fetch('/wp-content/themes/astra/xrpl-nft-marketplace/backend/offer-handler.php', { method: 'POST', body: jfd });
                const jd = await jres.json();
                if (!jd.success || !jd.txjson) { showToast(jd.error || 'Failed to prepare transfer', 'error'); return; }
                closeTransferModal();
                showToast('Check your wallet to sign the transfer...', 'info');
                let jsigned;
                try { jsigned = await (window.imuJoeySign||window.imuWallet.sign)(jd.txjson); }
                catch (e) {
                    const em = (e && e.message) || '';
                    showToast(/timed out|timeout/i.test(em) ? 'Transfer timed out. Please try again.' : /cancel/i.test(em) ? 'Transfer cancelled.' : 'Transfer rejected.', 'error');
                    return;
                }
                const jtx = jsigned && (jsigned.hash || jsigned.tx_hash);
                if (!jtx) { showToast('No transaction hash returned from your wallet.', 'error'); return; }
                const jvfd = new FormData();
                jvfd.append('action', 'joey_verify_create_transfer_offer');
                jvfd.append('tx_hash', jtx);
                jvfd.append('nonce', nonce);
                const jvr = await fetch('/wp-content/themes/astra/xrpl-nft-marketplace/backend/offer-handler.php', { method: 'POST', body: jvfd });
                const jvd = await jvr.json();
                if (jvd && jvd.success) {
                    showToast('Transfer offer sent! The recipient can now accept it.', 'success');
                } else {
                    showToast((jvd && jvd.error) || 'Transfer verification failed', 'error');
                }
                return;
            }

            const formData = new FormData();
            formData.append('action',      'create_transfer_offer');
            formData.append('nft_id',      nftId);
            formData.append('account',     userAccount);
            formData.append('destination', destination);
            formData.append('nonce',       nonce);

            const res  = await fetch('/wp-content/themes/astra/xrpl-nft-marketplace/backend/offer-handler.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (data.success && data.payload_uuid) {
                closeTransferModal();

                // Use poll_xumm_payload (same as cancel listing — no DB row needed)
                showQrModal(data.qr || '', data.deeplink || '', data.payload_uuid, 'Sign Transfer');

                // Override the default DB-based poll started by showQrModal
                pollingActive = false;

                let transferAttempts = 0;
                const transferPoll = setInterval(async () => {
                    transferAttempts++;
                    if (transferAttempts > 72) {
                        clearInterval(transferPoll);
                        qrSigningStatus.textContent = '✗ Timed out';
                        qrSigningStatus.className = 'qr-signing-status error';
                        qrCloseBtn.style.display = 'block';
                        return;
                    }
                    try {
                        const shortDest = destination.slice(0, 8) + '…' + destination.slice(-4);
                        const pollUrl = `/wp-content/themes/astra/xrpl-nft-marketplace/backend/offer-handler.php`
                            + `?action=poll_xumm_payload&uuid=${encodeURIComponent(data.payload_uuid)}`
                            + `&type=nft_transfer_offer&nonce=${encodeURIComponent(nonce)}`;
                        const pollRes  = await fetch(pollUrl);
                        const pollData = await pollRes.json();

                        if (pollData.status === 'confirmed') {
                            clearInterval(transferPoll);
                            qrSigningStatus.textContent = `✓ Transfer offer sent to ${shortDest}!`;
                            qrSigningStatus.className = 'qr-signing-status success';
                            qrModalContent.classList.add('success');
                            qrCloseBtn.style.display = 'block';
                            showToast(`Transfer offer sent! The recipient can now accept it.`, 'success');
                            // Don't auto-reload — NFT is still owned until recipient accepts
                        } else if (pollData.status === 'rejected' || pollData.status === 'declined') {
                            clearInterval(transferPoll);
                            qrSigningStatus.textContent = '✗ Transfer declined';
                            qrSigningStatus.className = 'qr-signing-status error';
                            qrCloseBtn.style.display = 'block';
                        } else if (pollData.status === 'expired') {
                            clearInterval(transferPoll);
                            qrSigningStatus.textContent = '✗ Request expired';
                            qrSigningStatus.className = 'qr-signing-status error';
                            qrCloseBtn.style.display = 'block';
                        } else if (pollData.status === 'signed_pending') {
                            qrSigningStatus.textContent = 'Confirming on blockchain…';
                        }
                    } catch (pollErr) { /* keep polling */ }
                }, 2500);
            } else {
                showToast(data.error || 'Failed to create transfer offer', 'error');
            }
        } catch (err) {
            console.error('Transfer error:', err);
            showToast('Failed to create transfer: ' + err.message, 'error');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Send Transfer Offer';
        }
    });

    // ========================================================================
    // MASTER FILE ACCESS (v97: tokengated owner playback)
    // v465 BUGFIX B: !$is_album guard added. For albums, initAlbumPlayer()
    // further below is the album-aware handler (calls session-access with
    // asset_type='album', gets all track URLs, wires the tracklist, manages
    // the loading banner + master badge). Without this guard, initMasterAccess
    // runs in parallel, finds no #nft-audio-player / #nft-video-player
    // (albums use #album-track-audio), falls into the art-image upgrade path,
    // fetches Track 1's master as a blob and assigns that audio blob URL to
    // every .nft-single-image on the page — clobbering the album cover.
    // Non-album types (music/music-video/art/film) are unaffected.
    // ========================================================================
    <?php if ($is_owner && $has_master_access && !$is_album && !$is_audiobook_chaptered && !$is_ebook): ?>
    (function initMasterAccess() {
        const nftId    = '<?php echo esc_js($nft_id); ?>';
        const hasMaster = true;
        const badge      = document.getElementById('master-badge');
        const banner     = document.getElementById('master-loading-banner');
        const bannerText = document.getElementById('master-loading-text');

        if (!hasMaster || !userAccount) return;

        // Find the active player (audio or video) OR image element (art NFTs)
        const audioPlayer = document.getElementById('nft-audio-player');
        const videoPlayer = document.getElementById('nft-video-player');
        const player = audioPlayer || videoPlayer;
        const artImage = !player ? document.querySelector('.nft-single-image') : null;

        // v386: Art NFTs have no audio/video player — they display as <img>.
        // If neither a player nor an image exists, nothing to upgrade.
        if (!player && !artImage) return;

        // Banner is already visible in the DOM (PHP rendered it — no JS show needed)
        function setBannerText(msg) {
            if (bannerText) bannerText.textContent = msg;
        }
        function setBannerReady(msg) {
            setBannerText(msg);
            if (banner) banner.classList.add('master-ready');
            // Auto-hide after 4 s once master is confirmed playing
            setTimeout(function() { if (banner) banner.classList.add('master-hidden'); }, 4000);
        }
        function hideBanner() {
            if (banner) banner.classList.add('master-hidden');
        }

        async function loadMasterFile() {
            try {
                setBannerText('⬆️ Upgrading to master quality…');
                const response = await fetch('/wp-json/imu-master/v1/session-access', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                    },
                    body: JSON.stringify({ nftoken_id: nftId })
                });

                const data = await response.json();

                if (window.__imcHandleReauth(response)) return; // 2b: token-only 401 -> re-auth

                if (data.granted && data.url) {

                    // ── v386: ART IMAGE UPGRADE PATH ─────────────────────────────
                    // Art NFTs have no audio/video player. The master file IS the
                    // full-resolution unwatermarked image. Fetch it as a blob so the
                    // signed VPS URL never appears in the DOM or right-click menu,
                    // then replace the <img> src with the blob URL.
                    // ─────────────────────────────────────────────────────────────
                    if (artImage && !player) {
                        try {
                            setBannerText('🖼️ Loading master quality image…');
                            const imgRes = await fetch(data.url, { credentials: 'omit' });
                            if (!imgRes.ok) throw new Error('Fetch failed: ' + imgRes.status);

                            const contentLength = imgRes.headers.get('Content-Length');
                            const totalBytes = contentLength ? parseInt(contentLength, 10) : 0;

                            let blobUrl = null;
                            if (totalBytes > 0 && imgRes.body) {
                                const reader = imgRes.body.getReader();
                                const chunks = [];
                                let received = 0;
                                while (true) {
                                    const { done, value } = await reader.read();
                                    if (done) break;
                                    chunks.push(value);
                                    received += value.length;
                                    const pct = Math.round((received / totalBytes) * 100);
                                    setBannerText('🖼️ Loading master quality… ' + pct + '%');
                                }
                                const merged = new Uint8Array(received);
                                let offset = 0;
                                for (const chunk of chunks) { merged.set(chunk, offset); offset += chunk.length; }
                                blobUrl = URL.createObjectURL(new Blob([merged], { type: data.mime_type || 'image/jpeg' }));
                            } else {
                                setBannerText('🖼️ Loading master quality…');
                                const blob = await imgRes.blob();
                                blobUrl = URL.createObjectURL(blob);
                            }

                            // Replace watermarked preview with master quality blob
                            artImage.src = blobUrl;

                            // Also upgrade any other instances of the same cover (e.g. OG image, lightbox)
                            document.querySelectorAll('.nft-single-image').forEach(img => {
                                if (img !== artImage) img.src = blobUrl;
                            });

                            // Disable right-click on the upgraded image
                            artImage.addEventListener('contextmenu', function(e) { e.preventDefault(); });

                        } catch (fetchErr) {
                            console.warn('[Master API] Art image blob fetch failed:', fetchErr.message);
                            hideBanner();
                            return; // Keep watermarked preview visible
                        }

                        if (badge) badge.classList.add('active');
                        setBannerReady('✅ Master quality loaded');
                        console.log('[Master API] Art master image loaded via blob proxy:', data.mime_type);

                        // Schedule refresh before expiry
                        const expiresAt = new Date(data.expires_at).getTime();
                        const refreshIn = expiresAt - Date.now() - (5 * 60 * 1000);
                        if (refreshIn > 0) {
                            setTimeout(() => loadMasterFile(), refreshIn);
                        }
                        return; // Done — skip audio/video paths below
                    }

                    // ── AUDIO / VIDEO UPGRADE PATH (unchanged) ───────────────────
                    // Store current playback position
                    const wasPlaying = !player.paused;
                    const currentTime = player.currentTime;

                    // Bug fix v302: use player element type as primary signal.
                    // mime_type from DB may be null/wrong (especially backfilled entries).
                    // An <audio> element IS always audio; <video> IS always video.
                    const isAudio = player.tagName.toLowerCase() === 'audio' ||
                                    (data.mime_type || '').startsWith('audio/');

                    if (isAudio) {
                    // ── v300: BLOB URL PROXY (audio only) ────────────────────────────
                    // Audio master files (MP3/FLAC/WAV) are fully buffered into a Blob
                    // before playback. The player src becomes blob:// — the signed VPS
                    // URL never appears in the DOM, browser history, or right-click menu.
                    // Video uses a different path below (streaming, no full buffer needed).
                    // ─────────────────────────────────────────────────────────────────
                    // banner already visible

                    // Show download progress if Content-Length is available
                    let blobUrl = null;
                    try {
                        const streamRes = await fetch(data.url, { credentials: 'omit' });
                        if (!streamRes.ok) throw new Error('Fetch failed: ' + streamRes.status);

                        const contentLength = streamRes.headers.get('Content-Length');
                        const totalBytes = contentLength ? parseInt(contentLength, 10) : 0;

                        if (totalBytes > 0 && streamRes.body) {
                            // Stream with progress indicator
                            const reader = streamRes.body.getReader();
                            const chunks = [];
                            let received = 0;

                            while (true) {
                                const { done, value } = await reader.read();
                                if (done) break;
                                chunks.push(value);
                                received += value.length;
                                const pct = Math.round((received / totalBytes) * 100);
                                setBannerText('🎵 Loading master quality… ' + pct + '%');
                            }

                            const merged = new Uint8Array(received);
                            let offset = 0;
                            for (const chunk of chunks) { merged.set(chunk, offset); offset += chunk.length; }
                            blobUrl = URL.createObjectURL(new Blob([merged], { type: data.mime_type || 'audio/mpeg' }));
                        } else {
                            // No Content-Length — just show spinner
                            setBannerText('🎵 Loading master quality…');
                            const blob = await streamRes.blob();
                            blobUrl = URL.createObjectURL(blob);
                        }
                    } catch (fetchErr) {
                        console.warn('[Master API] Audio blob fetch failed:', fetchErr.message);
                        hideBanner();
                        // Do not fall back to exposing the URL — keep preview playing
                        return;
                    }

                    // Revoke previous blob to free memory
                    if (player._imuBlobUrl) URL.revokeObjectURL(player._imuBlobUrl);
                    player._imuBlobUrl = blobUrl;

                    player.src = blobUrl;
                    player.load();

                    } else {
                    // ── VIDEO: signed URL, src attribute cleared from DOM immediately ─
                    // Blob-downloading a 200 MB–5 GB video before playback is impractical.
                    // Instead: assign via JS property (starts the stream), then immediately
                    // removeAttribute('src') so the URL is not readable from the DOM tree,
                    // right-click → Save, or inspect element. The HTMLMediaElement retains
                    // its internal resource reference even after the attribute is removed.
                    // controlsList="nodownload" + disablePictureInPicture + oncontextmenu
                    // block the remaining casual download vectors.
                    // ─────────────────────────────────────────────────────────────────
                    player.src = data.url;
                    player.load(); // start streaming first
                    // Scrub URL from DOM after browser has already begun the network request
                    setTimeout(function () { player.removeAttribute('src'); }, 250);
                    } // end isAudio / video split

                    // Restore position if was playing
                    player.addEventListener('loadedmetadata', function onLoaded() {
                        if (currentTime > 0) player.currentTime = currentTime;
                        if (wasPlaying) player.play().catch(() => {});
                        player.removeEventListener('loadedmetadata', onLoaded);
                    });

                    // Show master badge
                    if (badge) badge.classList.add('active');
                    setBannerReady('✅ Master quality loaded');

                    console.log('[Master API] Master file loaded via blob proxy:', data.mime_type);

                    // IMU Royalty: Trigger play tracking now that master is confirmed
                    if (window._imcStartRoyaltyTracker) window._imcStartRoyaltyTracker();

                    // Schedule refresh before expiry (5 min before signed URL expires,
                    // fetch a new blob — the old blob stays valid until revoked above)
                    const expiresAt = new Date(data.expires_at).getTime();
                    const refreshIn = expiresAt - Date.now() - (5 * 60 * 1000);
                    if (refreshIn > 0) {
                        setTimeout(() => loadMasterFile(), refreshIn);
                    }
                } else {
                    console.warn('[Master API] Access denied:', data.error);
                    hideBanner();
                    // Keep playing preview — no disruption
                }
            } catch (err) {
                console.error('[Master API] Failed to load master file:', err);
                hideBanner();
                // Graceful fallback — preview continues playing
            }
        }

        // Load master file on page load
        loadMasterFile();
    })();
    <?php endif; ?>

    <?php
    // ── IMU Royalty Play Tracker ──
    // Only rendered if: owner + master access + has matching IMUTV account
    // Activated by loadMasterFile() calling window._imcStartRoyaltyTracker()
    if ( $is_owner && $has_master_access && $imu_royalty_user_id > 0 ):
        $royalty_nonce    = wp_create_nonce( 'imc_royalty_nonce' );
        $ajax_url         = admin_url( 'admin-ajax.php' );
        $tracker_nft_id   = esc_js( $nft_id );
        $tracker_title    = esc_js( $nft_data['name'] ?? $nft_data['title'] ?? 'Untitled' );
        $tracker_category = in_array( $db_content_type ?? '', [ 'film', 'musicvideo' ] ) ? 'video' : 'music';
    ?>
    // IMU Streaming Royalties — IMC Play Tracker
    // Tracks master file plays for the IMU User Rewards + Artist Royalties Programme.
    // ONLY activates after loadMasterFile() confirms master access granted.
    (function(){
        var started = false;
        var playId  = 0;
        var seconds = 0;
        var heartbeatTimer = null;
        var durationTimer  = null;

        var config = {
            ajaxUrl:  '<?php echo esc_url( $ajax_url ); ?>',
            nonce:    '<?php echo $royalty_nonce; ?>',
            nftId:    '<?php echo $tracker_nft_id; ?>',
            title:    '<?php echo $tracker_title; ?>',
            category: '<?php echo $tracker_category; ?>'
        };

        function sendPlay(action, extra) {
            var data = new FormData();
            data.append('action', 'imc_royalty_play');
            data.append('_nonce', config.nonce);
            data.append('play_action', action);
            if (extra) {
                for (var k in extra) data.append(k, extra[k]);
            }
            return fetch(config.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .catch(function() { return null; });
        }

        function startTracking() {
            if (started) return;
            started = true;
            seconds = 0;

            sendPlay('start', {
                nft_id: config.nftId,
                title: config.title,
                category: config.category
            }).then(function(res) {
                if (res && res.success && res.data && res.data.play_id) {
                    playId = res.data.play_id;
                    console.log('[IMU Royalty] Play tracked — ID:', playId);

                    // Count seconds while playing
                    durationTimer = setInterval(function() { seconds++; }, 1000);

                    // Heartbeat every 30 seconds
                    heartbeatTimer = setInterval(function() {
                        if (playId && seconds > 0) {
                            sendPlay('heartbeat', { play_id: playId, duration: seconds });
                        }
                    }, 30000);
                }
            });
        }

        function stopTracking() {
            if (!playId) return;
            clearInterval(heartbeatTimer);
            clearInterval(durationTimer);
            sendPlay('end', { play_id: playId, duration: seconds });
            console.log('[IMU Royalty] Play ended — duration:', seconds + 's');
            playId = 0;
            started = false;
            seconds = 0;
        }

        // Expose the trigger for loadMasterFile() to call
        window._imcStartRoyaltyTracker = function() {
            var player = document.getElementById('nft-audio-player') || document.getElementById('nft-video-player');
            if (!player) return;

            // Start tracking when media plays
            player.addEventListener('play', startTracking);

            // Pause tracking (send end, can restart)
            player.addEventListener('pause', function() {
                if (playId) stopTracking();
            });

            // Media ended
            player.addEventListener('ended', function() {
                if (playId) stopTracking();
            });

            // Page unload — send final heartbeat
            window.addEventListener('beforeunload', function() {
                if (playId && seconds > 0) {
                    var data = new FormData();
                    data.append('action', 'imc_royalty_play');
                    data.append('_nonce', config.nonce);
                    data.append('play_action', 'end');
                    data.append('play_id', playId);
                    data.append('duration', seconds);
                    navigator.sendBeacon(config.ajaxUrl, data);
                }
            });

            // If already playing (autoplay scenario), start immediately
            if (!player.paused) startTracking();

            console.log('[IMU Royalty] Tracker ready — listening for play events');
        };
    })();
    <?php endif; ?>

    <?php if ($is_owner && $has_master_access && $is_downloadable): ?>
    // v387: Master file download handler — only rendered when artist enables downloads.
    // Uses the same session-access API and blob proxy as the master quality upgrade.
    // The signed VPS URL never appears in the DOM — downloaded via blob.
    window.imcDownloadMaster = async function() {
        const btn = document.getElementById('master-download-btn');
        if (!btn) return;
        
        const originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = '⏳ Preparing download…';

        try {
            const response = await fetch('/wp-json/imu-master/v1/session-access', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                },
                body: JSON.stringify({ nftoken_id: '<?php echo esc_js($nft_id); ?>' })
            });

            const data = await response.json();

            if (window.__imcHandleReauth(response)) return; // 2b: token-only 401 -> re-auth

            if (!data.granted || !data.url) {
                throw new Error(data.error || 'Access denied');
            }

            // Fetch as blob — signed URL never enters the DOM
            btn.textContent = '⬇️ Downloading…';
            const fileRes = await fetch(data.url, { credentials: 'omit' });
            if (!fileRes.ok) throw new Error('Download failed: ' + fileRes.status);

            const blob = await fileRes.blob();
            const blobUrl = URL.createObjectURL(blob);

            // Determine filename from NFT name + mime extension
            const ext = (data.mime_type || '').split('/').pop() || 'bin';
            const safeName = '<?php echo esc_js(sanitize_file_name($nft_name ?: "NFT")); ?>';
            const filename = safeName + '.' + ext;

            // Programmatic download via temporary <a> element
            const a = document.createElement('a');
            a.href = blobUrl;
            a.download = filename;
            a.style.display = 'none';
            document.body.appendChild(a);
            a.click();

            // Cleanup
            setTimeout(() => {
                URL.revokeObjectURL(blobUrl);
                a.remove();
            }, 1000);

            btn.textContent = '✅ Downloaded!';
            setTimeout(() => { btn.textContent = originalText; btn.disabled = false; }, 3000);

        } catch (err) {
            console.error('[Download] Master download failed:', err);
            btn.textContent = '❌ Failed — try again';
            setTimeout(() => { btn.textContent = originalText; btn.disabled = false; }, 3000);
        }
    };
    <?php endif; ?>

    <?php if ($is_downloadable && !$is_album && !$is_audiobook_chaptered && !$is_ebook && $has_cover_access): ?>
    // v447: Cover art download handler — downloads the original (non-watermarked) cover artwork.
    // Uses the same session-access API as the cover upgrade, with asset_type='cover'.
    // The signed VPS URL never appears in the DOM — downloaded via blob.
    window.imcDownloadCover = async function() {
        const btn = document.getElementById('cover-download-btn');
        if (!btn) return;
        
        const originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = '⏳ Preparing download…';

        try {
            const response = await fetch('/wp-json/imu-master/v1/session-access', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                },
                body: JSON.stringify({ nftoken_id: '<?php echo esc_js($nft_id); ?>', asset_type: 'cover' })
            });

            const data = await response.json();

            if (window.__imcHandleReauth(response)) return; // 2b: token-only 401 -> re-auth

            if (!data.granted || !data.url) {
                throw new Error(data.error || 'Cover art access denied');
            }

            // Fetch as blob — signed URL never enters the DOM
            btn.textContent = '⬇️ Downloading…';
            const fileRes = await fetch(data.url, { credentials: 'omit' });
            if (!fileRes.ok) throw new Error('Download failed: ' + fileRes.status);

            const blob = await fileRes.blob();
            const blobUrl = URL.createObjectURL(blob);

            // Determine filename — append "-cover" to distinguish from master
            const ext = (data.mime_type || 'image/jpeg').split('/').pop() || 'jpg';
            const safeName = '<?php echo esc_js(sanitize_file_name($nft_name ?: "NFT")); ?>';
            const filename = safeName + '-cover.' + ext;

            // Programmatic download via temporary <a> element
            const a = document.createElement('a');
            a.href = blobUrl;
            a.download = filename;
            a.style.display = 'none';
            document.body.appendChild(a);
            a.click();

            // Cleanup
            setTimeout(() => {
                URL.revokeObjectURL(blobUrl);
                a.remove();
            }, 1000);

            btn.textContent = '✅ Downloaded!';
            setTimeout(() => { btn.textContent = originalText; btn.disabled = false; }, 3000);

        } catch (err) {
            console.error('[Download] Cover art download failed:', err);
            btn.textContent = '❌ Failed — try again';
            setTimeout(() => { btn.textContent = originalText; btn.disabled = false; }, 3000);
        }
    };
    <?php endif; ?>

    <?php if ($is_owner && $has_cover_access): ?>
    // v379: Cover Art Upgrade — replace watermarked cover with original for holders
    // Completely independent of master media streaming. A failure here cannot
    // affect audio/video playback — they run in separate IIFE scopes.
    (function initCoverAccess() {
        const nftId = '<?php echo esc_js($nft_id); ?>';
        if (!nftId || !userAccount) return;

        async function loadOriginalCover() {
            try {
                const response = await fetch('/wp-json/imu-master/v1/session-access', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                    },
                    body: JSON.stringify({ nftoken_id: nftId, asset_type: 'cover' })
                });

                const data = await response.json();

                if (window.__imcHandleReauth(response)) return; // 2b: token-only 401 -> re-auth

                if (data.granted && data.url) {
                    // Upgrade all cover image elements to the original (non-watermarked) version
                    // Audio NFTs: .audio-cover (cover art above player)
                    // Video NFTs: poster attribute on video element
                    // Image NFTs: .nft-single-image (main display image)
                    // R3: the eBook stage cover is .eb-face, not .nft-single-image, so it matched none
                    // of the selectors below and stayed watermarked for holders. FRONT ONLY: the grant
                    // has no back-cover asset_type (it resolves cover_content_hash), so the back cover
                    // cannot be upgraded without a server change - see the deploy note.
                    const ebStage   = document.getElementById('eb-stage');
                    const bookFront = document.getElementById('eb-cover-front');
                    const audioCover = document.querySelector('.nft-single-image.audio-cover');
                    const videoPoster = document.getElementById('nft-video-player');
                    const imageCover = document.querySelector('.nft-single-image:not(.audio-cover)');

                    if (ebStage) { ebStage.dataset.front = data.url; }   // reader repaints from this
                    if (bookFront) {
                        bookFront.src = data.url;
                        console.log('[Cover] eBook front cover upgraded to original');
                    }
                    if (audioCover) {
                        audioCover.src = data.url;
                        console.log('[Cover] Audio cover upgraded to original');
                    }
                    if (videoPoster) {
                        videoPoster.poster = data.url;
                        console.log('[Cover] Video poster upgraded to original');
                    }
                    if (imageCover && !audioCover) {
                        // Only upgrade standalone image if no audio cover (avoid double upgrade)
                        imageCover.src = data.url;
                        console.log('[Cover] Image cover upgraded to original');
                    }
                }
            } catch (err) {
                // Non-fatal — watermarked preview remains visible
                console.warn('[Cover] Cover upgrade failed (non-fatal):', err.message);
            }
        }

        loadOriginalCover();
    })();
    <?php endif; ?>

    <?php if ($is_owner && $has_album_access): ?>
    // ── v24: ALBUM PLAYER — multi-track sequential playback ──────────────────
    // Completely isolated IIFE. A failure here cannot affect any other IIFE
    // (master access, cover upgrade, download protection, royalty tracker).
    // Sends asset_type='album' to session-access → returns tracks[] with signed URLs.
    // Signed URLs expire; refresh scheduled automatically before expiry.
    // ─────────────────────────────────────────────────────────────────────────
    (function initAlbumPlayer() {
        'use strict';

        const nftId       = '<?php echo esc_js($nft_id); ?>';
        const wpNonce     = '<?php echo wp_create_nonce('wp_rest'); ?>';
        const sessionUrl  = '/wp-json/imu-master/v1/session-access';

        const audioEl     = document.getElementById('album-track-audio');
        const tracklist   = document.getElementById('album-tracklist');
        const nowPlaying  = document.getElementById('album-now-playing-title');
        const banner      = document.getElementById('master-loading-banner');
        const bannerText  = document.getElementById('master-loading-text');
        const badge       = document.getElementById('master-badge');

        if (!audioEl || !tracklist) return; // safety — missing DOM element

        // Track data store: index → { title, stream_url, mime_type }
        let _tracks    = [];
        let _current   = 0;
        let _loaded    = false;
        let _refreshTimer = null;

        function setBannerText(msg) {
            if (bannerText) bannerText.textContent = msg;
        }
        function setBannerReady(msg) {
            setBannerText(msg);
            if (banner) banner.classList.add('master-ready');
            setTimeout(function () { if (banner) banner.classList.add('master-hidden'); }, 4000);
        }
        function hideBanner() {
            if (banner) banner.classList.add('master-hidden');
        }

        // Highlight the active row in the tracklist
        function setActiveRow(idx) {
            tracklist.querySelectorAll('.album-track-row').forEach(function (row, i) {
                row.classList.toggle('active', i === idx);
            });
        }

        // Load a track by index (uses already-fetched signed URLs)
        function playTrack(idx) {
            if (!_loaded || idx < 0 || idx >= _tracks.length) return;
            const track = _tracks[idx];
            _current = idx;

            audioEl.src = track.stream_url;
            audioEl.load();
            audioEl.play().catch(function () {
                // Autoplay blocked — user can press the native play button
            });

            setActiveRow(idx);
            if (nowPlaying) nowPlaying.textContent = track.title;
            setBannerText('▶ ' + track.title);
        }

        // Auto-advance to next track when one ends
        audioEl.addEventListener('ended', function () {
            if (_current + 1 < _tracks.length) {
                playTrack(_current + 1);
            } else {
                // Playlist finished — reset to track 1 position without auto-play
                _current = 0;
                setActiveRow(0);
                if (nowPlaying && _tracks.length > 0) {
                    nowPlaying.textContent = _tracks[0].title;
                }
                hideBanner();
            }
        });

        // Row click / keyboard activate
        tracklist.addEventListener('click', function (e) {
            const row = e.target.closest('.album-track-row');
            if (!row) return;
            const idx = parseInt(row.dataset.trackIndex, 10);
            if (!isNaN(idx)) playTrack(idx);
        });
        tracklist.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                const row = e.target.closest('.album-track-row');
                if (!row) return;
                e.preventDefault();
                const idx = parseInt(row.dataset.trackIndex, 10);
                if (!isNaN(idx)) playTrack(idx);
            }
        });

        // Fetch all track signed URLs from session-access
        async function loadAlbumTracks() {
            try {
                setBannerText('💿 Loading album tracks…');

                const response = await fetch(sessionUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': wpNonce
                    },
                    body: JSON.stringify({ nftoken_id: nftId, asset_type: 'album' })
                });

                const data = await response.json();

                if (!data.granted || !Array.isArray(data.tracks) || data.tracks.length === 0) {
                    console.warn('[Album] Access not granted or no tracks:', data.error);
                    hideBanner();
                    return;
                }

                // Store signed track data
                _tracks = data.tracks.map(function (t) {
                    return {
                        track_number: t.track_number,
                        title:        t.title        || ('Track ' + t.track_number),
                        stream_url:   t.stream_url,
                        mime_type:    t.mime_type    || 'audio/mpeg'
                    };
                });
                _loaded = true;

                // Show master badge
                if (badge) badge.classList.add('active');
                setBannerReady('💿 Album ready');

                // Start track 1 silently (player is ready; user clicks a row to play)
                setActiveRow(0);
                if (nowPlaying && _tracks.length > 0) {
                    nowPlaying.textContent = _tracks[0].title;
                }

                // Schedule refresh 5 min before signed URL expiry
                if (data.expires_at) {
                    const expiresMs = new Date(data.expires_at).getTime();
                    const refreshIn = expiresMs - Date.now() - (5 * 60 * 1000);
                    if (refreshIn > 0) {
                        if (_refreshTimer) clearTimeout(_refreshTimer);
                        _refreshTimer = setTimeout(function () { loadAlbumTracks(); }, refreshIn);
                    }
                }

                // IMU Royalty Tracker hook (same trigger as master access)
                if (window._imcStartRoyaltyTracker) window._imcStartRoyaltyTracker();

            } catch (err) {
                console.error('[Album] Failed to load tracks:', err);
                hideBanner();
                // Preview cover + tracklist remain visible — non-fatal
            }
        }

        loadAlbumTracks();
    })();
    <?php endif; // $is_owner && $has_album_access ?>

<?php /* R1-E: eBook no longer uses the ab-cover-* ids - its covers are steps in
   the reader sequence - so this flip binding is AudioBook only now. */ ?>
<?php if ($is_audiobook): ?>
/* M1-f2 / M2-c1: AudioBook player, and the shared cover flip.
   The flip is wired for BOTH book types (covers are watermarked in public);
   an eBook has no audio elements, so this returns right after wiring it.
   Chapters are fetched with asset_type='audiobook' - one grant returns them all,
   and ownership is enforced server-side, so a non-owner simply gets nothing. */
(function initAudiobookPlayer() {
    'use strict';
    // Cover flip - front / back
    var flipBtn = document.getElementById('ab-cover-flip');
    var frontImg = document.getElementById('ab-cover-front');
    var backImg  = document.getElementById('ab-cover-back');
    if (flipBtn && frontImg && backImg) {
        var showingBack = false;
        flipBtn.addEventListener('click', function () {
            showingBack = !showingBack;
            frontImg.style.display = showingBack ? 'none' : '';
            backImg.style.display  = showingBack ? '' : 'none';
            flipBtn.textContent    = showingBack ? 'Front cover' : 'Back cover';
        });
    }

    var audioEl = document.getElementById('ab-chapter-audio');
    var listEl  = document.getElementById('ab-chapterlist');
    if (!audioEl || !listEl) return;   // not an owner, or no chapters

    var nftId      = '<?php echo esc_js($nft_id); ?>';
    var wpNonce    = '<?php echo wp_create_nonce('wp_rest'); ?>';
    var sessionUrl = '/wp-json/imu-master/v1/session-access';
    var nowTitle   = document.getElementById('ab-now-playing-title');
    var loadingEl  = document.getElementById('ab-chapter-loading');
    var _chapters = [];
    var _current  = -1;
    var _expires  = 0;

    var listingsUrl = '<?php echo esc_js(get_stylesheet_directory_uri() . "/xrpl-nft-marketplace/backend/listings-handler.php"); ?>';
    var mktNonce    = '<?php echo wp_create_nonce('xrpl_marketplace_nonce'); ?>';
    var _saveTimer  = null;
    var _resumeTo   = null;

    // M1-f3a: progress is a convenience, never load-bearing. Every failure is
    // swallowed so a logged-out or offline holder still gets full playback.
    function saveProgress() {
    if (_current < 0) return;
    var fd = new FormData();
    fd.append('action', 'save_reading_progress');
    fd.append('nonce', mktNonce);
    fd.append('nftoken_id', nftId);
    fd.append('position_json', JSON.stringify({
    chapter: _current,
    ms: Math.max(0, Math.floor(audioEl.currentTime * 1000))
    }));
    fetch(listingsUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
    .catch(function () { /* progress is best-effort */ });
    }

    function queueSave() {
    if (_saveTimer) clearTimeout(_saveTimer);
    _saveTimer = setTimeout(saveProgress, 12000);
    }

    async function loadProgress() {
    try {
    var fd = new FormData();
    fd.append('action', 'get_reading_progress');
    fd.append('nonce', mktNonce);
    fd.append('nftoken_id', nftId);
    var r = await fetch(listingsUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
    var d = await r.json();
    var pos = d && d.data && d.data.position;
    if (pos && typeof pos.chapter === 'number') _resumeTo = pos;
    } catch (e) { /* no saved position is not an error */ }
    }

    function fmtDur(ms) {
        if (!ms) return '';
        var s = Math.round(ms / 1000);
        var m = Math.floor(s / 60);
        var r = s % 60;
        return m + ':' + (r < 10 ? '0' : '') + r;
    }

    function render() {
        listEl.innerHTML = '';
        _chapters.forEach(function (ch, i) {
            var row = document.createElement('div');
            row.className = 'ab-chapter-row' + (i === _current ? ' active' : '');
            var num = document.createElement('span');
            num.className = 'ab-chapter-num';
            num.textContent = ch.chapter_number || (i + 1);
            var name = document.createElement('span');
            name.className = 'ab-chapter-name';
            name.textContent = ch.title || ('Chapter ' + (i + 1));
            var dur = document.createElement('span');
            dur.className = 'ab-chapter-dur';
            dur.textContent = fmtDur(ch.duration_ms);
            row.appendChild(num); row.appendChild(name); row.appendChild(dur);
            row.addEventListener('click', function () { play(i); });
            listEl.appendChild(row);
        });
    }

    function play(i) {
        var ch = _chapters[i];
        if (!ch || !ch.stream_url) return;
        _current = i;
        audioEl.src = ch.stream_url;
        audioEl.play().catch(function () { /* user gesture may be required */ });
        if (nowTitle) nowTitle.textContent = ch.title || ('Chapter ' + (i + 1));
        render();
        saveProgress();   // chapter change is worth recording immediately
    }

    audioEl.addEventListener('timeupdate', queueSave);
    audioEl.addEventListener('pause', saveProgress);
    window.addEventListener('beforeunload', saveProgress);
    audioEl.addEventListener('ended', function () {
        if (_current + 1 < _chapters.length) play(_current + 1);
    });

    async function loadChapters() {
        try {
            var res = await fetch(sessionUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpNonce },
                body: JSON.stringify({ nftoken_id: nftId, asset_type: 'audiobook' })
            });
            var data = await res.json();
            if (!data.granted || !Array.isArray(data.chapters) || !data.chapters.length) {
                if (loadingEl) loadingEl.textContent = data.error || 'Chapters unavailable.';
                return;
            }
            _chapters = data.chapters.map(function (c) {
                return {
                    chapter_number: c.chapter_number,
                    title:          c.title || ('Chapter ' + c.chapter_number),
                    stream_url:     c.stream_url,
                    duration_ms:    c.duration_ms || null
                };
            });
            _expires = data.expires_at ? Date.parse(data.expires_at) : 0;
            if (loadingEl) loadingEl.remove();
            render();
            scheduleRefresh();
            // M1-f3a: restore the saved position silently - never auto-play.
            if (_resumeTo && _chapters[_resumeTo.chapter]) {
                _current = _resumeTo.chapter;
                audioEl.src = _chapters[_current].stream_url;
                var _seek = Math.max(0, (_resumeTo.ms || 0) / 1000);
                audioEl.addEventListener('loadedmetadata', function onMeta() {
                    audioEl.removeEventListener('loadedmetadata', onMeta);
                    try { audioEl.currentTime = _seek; } catch (e) {}
                });
                if (nowTitle) nowTitle.textContent = _chapters[_current].title;
                _resumeTo = null;
                render();
            }
        } catch (e) {
            console.error('[AudioBook] chapter load failed', e);
            if (loadingEl) loadingEl.textContent = 'Could not load chapters.';
        }
    }

    // Signed URLs expire; refresh a few minutes early so playback never breaks.
    function scheduleRefresh() {
        if (!_expires) return;
        var lead = 5 * 60 * 1000;
        var wait = _expires - Date.now() - lead;
        if (wait < 30000) wait = 30000;
        setTimeout(function () {
            var wasAt = audioEl.currentTime;
            var wasIdx = _current;
            var wasPlaying = !audioEl.paused;
            loadChapters().then(function () {
                if (wasIdx >= 0 && _chapters[wasIdx]) {
                    audioEl.src = _chapters[wasIdx].stream_url;
                    audioEl.currentTime = wasAt;
                    if (wasPlaying) audioEl.play().catch(function () {});
                }
            });
        }, wait);
    }

    loadProgress().then(loadChapters);
})();
<?php endif; ?>
<?php if ($is_ebook && (!$is_owner || !$has_ebook_access)): ?>
/* R1: non-holder eBook - the reader never initialises for them, so the stage arrows
   would be inert. This binds the covers only: front <-> back, nothing else. No page
   data exists in this branch. */
(function initEbookCovers() {
    'use strict';
    var stage = document.getElementById('eb-stage');
    var surf  = document.getElementById('eb-surface');
    var prev  = document.getElementById('eb-prev');
    var next  = document.getElementById('eb-next');
    var pos   = document.getElementById('eb-pos');
    if (!stage || !surf) return;
    var front = (document.getElementById('eb-cover-front') || {}).src || '';
    var back  = stage.dataset.back || '';
    var onBack = false;
    function paint() {
        surf.innerHTML = '';
        var img = document.createElement('img');
        img.className = 'eb-face';
        img.src = onBack ? back : front;
        img.alt = onBack ? 'Back cover' : 'Front cover';
        surf.appendChild(img);
        if (pos)  pos.textContent = onBack ? 'Back cover' : 'Front cover';
        if (prev) prev.disabled = !onBack;
        if (next) next.disabled = onBack || !back;
    }
    if (prev) prev.addEventListener('click', function () { onBack = false; paint(); });
    if (next) next.addEventListener('click', function () { if (back) { onBack = true; paint(); } });
    paint();
})();
<?php endif; ?>

<?php if ($is_ebook && $is_owner && $has_ebook_access): ?>
/* M2-c2: eBook reader.
   Delivery is the M2-c1 grant (asset_type='ebook'): a PDF book is ONE signed URL that
   PDF.js range-requests inside; an image book is one URL per page, fetched in batches
   around the current page - never the whole book, because the grant is rate limited.
   This block sits INSIDE the page's existing script element: no nested script tags. */
(function initEbookReader() {
    'use strict';
    var viewport = document.getElementById('eb-surface');   // R1: the ONE stage surface
    if (!viewport) return;

    var nftId       = '<?php echo esc_js($nft_id); ?>';
    var wpNonce     = '<?php echo wp_create_nonce('wp_rest'); ?>';
    var mktNonce    = '<?php echo wp_create_nonce('xrpl_marketplace_nonce'); ?>';
    var sessionUrl  = '/wp-json/imu-master/v1/session-access';
    var listingsUrl = '<?php echo esc_js(get_stylesheet_directory_uri() . "/xrpl-nft-marketplace/backend/listings-handler.php"); ?>';
    // PDF.js is exposed through MINT_CONFIG on /mint/ only, so the reader emits its own URLs.
    var pdfJsUrl     = '<?php echo esc_js(get_stylesheet_directory_uri() . "/xrpl-nft-marketplace/frontend/pdf.min.js"); ?>';
    var pdfWorkerUrl = '<?php echo esc_js(get_stylesheet_directory_uri() . "/xrpl-nft-marketplace/frontend/pdf.worker.min.js"); ?>';

    var TOTAL      = <?php echo (int) $ebook_page_count; ?>;
    var BATCH      = 200;          // page batch size
    var pages      = {};           // n -> { src, url, page, mime }
    var fetched    = {};           // offset -> true
    var pdfDocs    = {};           // url -> PDFDocumentProxy
    var current    = 1;
    var zoom       = 0;            // 0 = fit width; otherwise a scale step
    var spread     = window.innerWidth >= 1024;   // two-page on wide screens, single on narrow
    var expiresAt  = 0;
    var saveTimer  = null;
    var rendering  = false;
    var _ready     = false;   // V1-b: pages are actually available (NOT just "a paint happened")
    var _noteTimer = null;

    /* ------------------------------------------------------------------
       V1-c: setStatus() is GONE. It did viewport.innerHTML = '' , so any
       failure - including one from the BACKGROUND prefetch - erased the
       spread the holder was reading. Errors are now a transient note laid
       OVER the surface, and the page underneath is never touched.
       ------------------------------------------------------------------ */
    var _noteEl    = document.getElementById('eb-note');
    var _loadEl    = document.getElementById('eb-loading');
    var _loadTxt   = document.getElementById('eb-loading-text');

    function _note(msg) {
        if (!_noteEl) { try { console.warn('[eBook]', msg); } catch (e) {} return; }
        _noteEl.textContent = msg;
        _noteEl.hidden = false;
        if (_noteTimer) clearTimeout(_noteTimer);
        _noteTimer = setTimeout(function () { if (_noteEl) _noteEl.hidden = true; }, 6000);
    }
    function _overlay(msg) {
        if (_ovTimer) { clearTimeout(_ovTimer); _ovTimer = null; }
        if (_loadTxt) _loadTxt.textContent = msg;
        if (_loadEl)  _loadEl.hidden = false;
    }
    /* V3 (Sep 2026): a turn that completes quickly should show NOTHING. The overlay
       firing on every uncached spread made fluid reading feel like a series of loads.
       Arm it on a timer instead and cancel on completion: slow turns still explain
       themselves, fast ones stay silent. If it is already up (the boot open owns it)
       just retext, so we never flash it off and straight back on. */
    var _ovTimer = null;
    function _overlayLater(msg, ms) {
        if (_loadEl && !_loadEl.hidden) { _overlay(msg); return; }
        if (_ovTimer) clearTimeout(_ovTimer);
        _ovTimer = setTimeout(function () { _ovTimer = null; _overlay(msg); },
                              (ms === undefined || ms === null) ? 3000 : ms);
    }
    // While the boot document open is in flight it OWNS the overlay: a cover paint
    // (which calls this) must not disarm or hide it underneath the open.
    var _bootOpen = false;
    function _overlayHide() {
        if (_bootOpen) return;
        if (_ovTimer) { clearTimeout(_ovTimer); _ovTimer = null; }
        if (_loadEl) _loadEl.hidden = true;
    }
    function _fmtBytes(b) {
        if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
        if (b >= 1024)    return Math.round(b / 1024) + ' KB';
        return b + ' B';
    }

    /* V1-d: the purple master banner, driven by the reader itself. Same helper shape
       and the same .master-ready / .master-hidden classes as initMasterAccess and
       initAlbumPlayer, so an eBook reports its upgrade exactly like every other type.
       R1-B suppressed this banner precisely because nothing drove it; now something does. */
    var _banner    = document.getElementById('master-loading-banner');
    var _bannerTxt = document.getElementById('master-loading-text');
    var _badge     = document.getElementById('master-badge');
    function _bannerText(msg) { if (_bannerTxt) _bannerTxt.textContent = msg; }
    function _bannerReady(msg) {
        _bannerText(msg);
        if (_badge)  _badge.classList.add('active');
        if (_banner) _banner.classList.add('master-ready');
        setTimeout(function () { if (_banner) _banner.classList.add('master-hidden'); }, 4000);
    }
    function _bannerHide() { if (_banner) _banner.classList.add('master-hidden'); }
    var _bannerDone = false;
    function _bannerSettle(ok) {
        if (_bannerDone) return;
        _bannerDone = true;
        if (ok) { _bannerReady('\u2705 Master quality loaded'); } else { _bannerHide(); }
    }

    var _pdfPromise = null;
    function loadPdfJs() {
        if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
        if (_pdfPromise) return _pdfPromise;
        _pdfPromise = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = pdfJsUrl;
            s.async = true;
            s.onload = function () {
                if (window.pdfjsLib) {
                    window.pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorkerUrl;
                    resolve(window.pdfjsLib);
                } else { reject(new Error('PDF.js missing after load')); }
            };
            s.onerror = function () { _pdfPromise = null; reject(new Error('PDF.js failed to load')); };
            document.head.appendChild(s);
        });
        return _pdfPromise;
    }

    async function fetchBatch(offset) {
        if (fetched[offset]) return true;
        try {
            var res = await fetch(sessionUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpNonce },
                body: JSON.stringify({ nftoken_id: nftId, asset_type: 'ebook', offset: offset, limit: BATCH })
            });
            var data = await res.json();
            if (!data.granted || !Array.isArray(data.pages)) {
                _note(data.error || 'Could not open this book.');
                return false;
            }
            data.pages.forEach(function (p) { pages[p.n] = p; });
            fetched[offset] = true;
            _ready = true;            // V1-b: arrows may now be trusted
            _syncArrows();
            expiresAt = data.expires_at ? Date.parse(data.expires_at) : 0;
            return true;
        } catch (e) {
            console.error('[eBook] page fetch failed', e);
            _note('Could not load pages. Please refresh.');
            return false;
        }
    }

    function batchFor(n) { return Math.floor((n - 1) / BATCH) * BATCH; }

    async function ensurePage(n) {
        if (n < 1 || n > TOTAL) return false;
        if (pages[n]) return true;
        return await fetchBatch(batchFor(n));
    }

    /* ==================================================================
       V2: ADAPTIVE PDF TRANSPORT.
       A PDF book is ONE signed URL (the grant dedupes by content hash), so how we
       open it decides the entire first-paint cost of a large book. getDocument({url})
       relies on the stream server honouring byte ranges; when it does not, PDF.js
       silently downloads the WHOLE file and shows nothing at all while it does.
       Rather than assume either way, probe once and branch. The result is logged so
       the answer is visible in the console without anyone running curl.
       ================================================================== */
    var _pdfMode = null;    // 'range' | 'stream'

    /* Drain a Response with a progress readout. Used when the server did NOT honour
       our range request - in which case the whole file is already on its way down
       this very response, so we consume THIS one rather than asking again. The old
       code probed with one request and then fetched with a second: on a no-range
       server that was two full transfers of the book, the first of which was never
       even read. */
    async function _consume(res) {
        var len = parseInt(res.headers.get('Content-Length') || '0', 10);
        if (!res.body || !res.body.getReader) { return await res.arrayBuffer(); }
        var reader = res.body.getReader(), chunks = [], got = 0;
        while (true) {
            var r = await reader.read();
            if (r.done) break;
            chunks.push(r.value); got += r.value.length;
            // Content-Length can be absent (chunked transfer), which used to leave the
            // holder watching a spinner with no sense of progress at all.
            _overlay(len ? ('Loading book\u2026 ' + Math.round((got / len) * 100) + '%')
                         : ('Loading book\u2026 ' + _fmtBytes(got)));
        }
        var out = new Uint8Array(got), off = 0;
        for (var i = 0; i < chunks.length; i++) { out.set(chunks[i], off); off += chunks[i].length; }
        return out.buffer;
    }
    async function _fetchWithProgress(url) {
        var res = await fetch(url, { credentials: 'omit' });
        if (!res.ok) throw new Error('Book fetch failed: ' + res.status);
        return await _consume(res);
    }

    /* The media URL carries an opaque per-grant query string. Keying the doc
       cache on the FULL url meant every refresh missed, which would have defeated
       the whole point of keeping stream-mode docs across maybeRefresh, because
       they would have been re-downloaded anyway. Key on the stable portion only. */
    function _docKey(url) {
        var m = /[?&]hash=([^&]+)/.exec(url || '');
        return m ? m[1] : (url || '');
    }

    /* ------------------------------------------------------------------
       V3: open the document ONCE, over a single request, with flags that suit a book.

       The media service answers byte ranges natively. The
       previous flags fought that: disableStream:true forbade streaming entirely,
       disableAutoFetch:true forbade any read-ahead, and 256 KB chunks made every
       one of the resulting round trips small. Opening a non-linearized 180-page
       book then cost hundreds of sequential requests before the first pixel, and
       each new spread started the same grind.

       A book is read cover to cover, so read-ahead is exactly what we want: the
       first page arrives from ranges, and PDF.js streams the remainder in the
       background while the holder reads. _openInFlight dedupes concurrent opens
       (the boot warm-up and a fast first turn can both ask at once).
       ------------------------------------------------------------------ */
    var _openInFlight = {};
    async function _openPdf(url) {
        var key = _docKey(url);
        if (pdfDocs[key]) return pdfDocs[key];
        if (_openInFlight[key]) return _openInFlight[key];

        _openInFlight[key] = (async function () {
            try {
                var lib = await loadPdfJs();
                var res = null;
                try {
                    res = await fetch(url, { headers: { 'Range': 'bytes=0-1' }, credentials: 'omit' });
                } catch (e) { res = null; }   // CORS/network: fall through to a plain fetch

                if (res && res.status === 206) {
                    _pdfMode = 'range';
                    // Two bytes were enough to learn what we needed; release the rest.
                    try { if (res.body && res.body.cancel) res.body.cancel(); } catch (e) {}
                    pdfDocs[key] = await lib.getDocument({
                        url: url,
                        disableAutoFetch: false,   // read ahead: it is a book
                        disableStream: false,      // let nginx stream the remainder
                        rangeChunkSize: 1048576    // 1 MB, not 256 KB - a quarter of the round trips
                    }).promise;
                } else if (res && res.ok) {
                    // Range ignored: this response IS the whole file. Consume it here
                    // rather than issuing a second identical request.
                    _pdfMode = 'stream';
                    pdfDocs[key] = await lib.getDocument({ data: await _consume(res) }).promise;
                } else {
                    _pdfMode = 'stream';
                    pdfDocs[key] = await lib.getDocument({ data: await _fetchWithProgress(url) }).promise;
                }
                try { console.log('[eBook] pdf transport:', _pdfMode); } catch (e) {}
                return pdfDocs[key];
            } finally {
                delete _openInFlight[key];   // a failure must leave the next attempt free to retry
            }
        })();
        return _openInFlight[key];
    }

    // First PDF page URL in the grant, or '' for an all-image book. A PDF book dedupes
    // to one signed URL server-side, so the first hit is the whole document.
    function _pdfUrlOf() {
        for (var n = 1; n <= TOTAL; n++) {
            var p = pages[n];
            if (p && p.src === 'pdf' && p.url) return p.url;
        }
        return '';
    }

    async function renderPdfPage(p, targetWidth) {
        var doc  = await _openPdf(p.url);
        var page = await doc.getPage(p.page || 1);
        var base = page.getViewport({ scale: 1 });
        var scale = zoom > 0 ? zoom : Math.max(0.2, targetWidth / base.width);
        var vp = page.getViewport({ scale: scale });
        var canvas = document.createElement('canvas');
        canvas.className = 'eb-page';
        canvas.width  = Math.floor(vp.width);
        canvas.height = Math.floor(vp.height);
        await page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
        return canvas;
    }

    // R6: resolve only once the bitmap is decoded. Returning the <img> immediately
    // meant it was inserted at zero height and grew when it loaded - which is what
    // made the reader collapse and snap back on every turn.
    async function renderImagePage(p, targetWidth) {
        var img = document.createElement('img');
        img.className = 'eb-page';
        img.alt = 'Page ' + p.n;
        if (zoom > 0) { img.style.width = Math.floor(targetWidth * zoom) + 'px'; }
        img.src = p.url;
        try {
            if (img.decode) { await img.decode(); }
            else { await new Promise(function (res) { img.onload = res; img.onerror = res; }); }
        } catch (e) { /* a broken page still renders its alt text */ }
        return img;
    }

    // Page 1 and the final page always sit alone, so spreads pair as a printed book does.
    function spreadFor(n) {
        if (!spread) return [n];
        if (n === 1) return [1];
        if (n >= TOTAL) return [TOTAL];
        var left = (n % 2 === 0) ? n : n - 1;
        var right = left + 1;
        return right <= TOTAL ? [left, right] : [left];
    }

    /* V1-e: a page the grant could not resolve. An unresolvable page is skipped
       rather than failing the whole batch, and fetchBatch still marks the batch
       fetched - so ensurePage() returned TRUE while pages[n] stayed
       undefined. The old loop did `if (!p) continue;`, which produced an EMPTY node
       list, a wiped surface and a correct-looking page number: a blank page with no
       explanation. Show the gap instead of pretending it isn't there. */
    function _withTimeout(promise, ms, label) {
        var t;
        return Promise.race([
            promise,
            new Promise(function (_, rej) {
                t = setTimeout(function () { rej(new Error('Timed out loading ' + label)); }, ms);
            })
        ]).then(function (v) { clearTimeout(t); return v; },
                function (e) { clearTimeout(t); throw e; });
    }

    /* ------------------------------------------------------------------
       ONE source of truth for which pages a step shows.

       _sequence() and spreadFor() disagreed on EVERY step. _sequence() pairs
       (1,2) (3,4) (5,6) - the model the reader's navigation, labels and design
       were all specified against. spreadFor() pairs 1 alone, then (2,3) (4,5),
       the printed-book convention from the earlier M2-c viewer. render() called
       spreadFor(current), so the sequence said [3,4] while the surface drew [2,3]
       - live since R1, and invisible only because the old _setPos() labelled from
       whatever had just been drawn rather than from the step.

       Deriving from _sequence() makes step, content and label agree, and restores
       the specified pagination: cover -> 1+2 -> 3+4 -> ... -> back cover.
       spreadFor() is kept as a fallback for the direct render() calls
       (fullscreenchange) that do not come through _paint().
       ------------------------------------------------------------------ */
    function _currentPages() {
        var S = _sequence(), st = S[_idx];
        if (st && st.t === 'pages' && st.p && st.p.length) return st.p;
        return spreadFor(current);
    }

    function _missNode(pageNo) {
        var d = document.createElement('div');
        d.className = 'eb-miss';
        d.textContent = 'Page ' + pageNo + ' is unavailable';
        return d;
    }

    async function render() {
        if (rendering) {
            // This exit used to leak the turn lock: another render already owns the
            // surface, so THIS turn can never complete and _turnDir stayed set forever,
            // which made _step() ignore every later click. Release it here.
            _finishTurn(false);
            return;
        }
        rendering = true;
        // V3: the busy state has to be PUBLISHED, not just recorded. _syncArrows() was
        // called from only three places - after _ready flipped, inside _endTurn and
        // inside _step - so a render kicked off by boot, resume, fullscreen or resize
        // left the arrows live for its whole duration. That is how a press landed
        // mid-load, faded the surface to nothing and looked like a broken reader.
        _syncArrows();
        var _ok = false;
        try {
            var wanted = _currentPages();
            var avail = viewport.clientWidth || 700;
            var per = Math.floor((avail - (wanted.length > 1 ? 34 : 24)) / wanted.length);
            // Only show the loader when there is real work to do - a cached spread
            // repaints instantly and a flash of overlay would be worse than none.
            var cached = true;
            for (var c = 0; c < wanted.length; c++) {
                if (!_nodeCache[wanted[c] + '@' + per]) { cached = false; break; }
            }
            // V3: armed, not shown. The document is opened at boot now, so a turn that
            // has to fetch is usually quick - and the message no longer races the grant
            // for its wording.
            if (!cached) { _overlayLater('Loading page\u2026', 3000); }

            for (var i = 0; i < wanted.length; i++) {
                if (!(await ensurePage(wanted[i]))) { return; }   // _ok stays false -> finally rolls back
            }
            // R4: reuse an already-built node for the same page at the same width.
            // Re-rasterising a PDF page (or re-decoding an image) on every turn is what
            // made going back and forth feel like a reload.
            var nodes = [], missing = false;
            for (var j = 0; j < wanted.length; j++) {
                var pg = pages[wanted[j]];
                if (!pg) { nodes.push(_missNode(wanted[j])); missing = true; continue; }
                var ck = wanted[j] + '@' + per;
                if (_nodeCache[ck]) { nodes.push(_nodeCache[ck]); continue; }
                // V2: a page render is bounded. A stalled socket inside PDF.js has no
                // timeout of its own, and an unbounded await here would hold `rendering`
                // true forever - arrows greyed, overlay spinning, no way out. Throwing
                // instead routes through the normal catch/finally, so the lock is
                // released and the turn rolls back exactly like any other failure.
                var node = await _withTimeout(
                    (pg.src === 'pdf') ? renderPdfPage(pg, per) : renderImagePage(pg, per),
                    90000, 'page ' + wanted[j]);
                _nodeCache[ck] = node;
                _cacheOrder.push(ck);
                // keep the cache small: a big PDF would otherwise hold every rasterised page
                while (_cacheOrder.length > 12) { delete _nodeCache[_cacheOrder.shift()]; }
                nodes.push(node);
            }
            viewport.innerHTML = '';
            nodes.forEach(function (x) { viewport.appendChild(x); });
            viewport.classList.toggle('two', wanted.length > 1);
            _relabel();
            _warmNeighbours();
            queueSave();
            maybeRefresh();
            _ok = true;
            if (missing) { _note('Some pages of this book could not be loaded.'); }
        } catch (e) {
            console.error('[eBook] render failed', e);
            _note('This page could not be displayed.');
        } finally {
            // V1-a: the ONE place the lock is released. Previously _endTurn() sat on the
            // success path only, so an ensurePage failure or any thrown error left
            // _turnDir set and the reader dead for the rest of the page's life.
            rendering = false;
            _overlayHide();
            _bannerSettle(_ok);
            _finishTurn(_ok);
        }
    }

    // Progress is best-effort: a failure never interrupts reading.
    function saveProgress() {
        var fd = new FormData();
        fd.append('action', 'save_reading_progress');
        fd.append('nonce', mktNonce);
        fd.append('nftoken_id', nftId);
        fd.append('position_json', JSON.stringify({ page: current }));
        fetch(listingsUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function () {});
    }
    function queueSave() {
        if (saveTimer) clearTimeout(saveTimer);
        saveTimer = setTimeout(saveProgress, 4000);
    }
    async function loadProgress() {
        try {
            var fd = new FormData();
            fd.append('action', 'get_reading_progress');
            fd.append('nonce', mktNonce);
            fd.append('nftoken_id', nftId);
            var r = await fetch(listingsUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
            var d = await r.json();
            var pos = d && d.data && d.data.position;
            if (pos && pos.page > 0 && pos.page <= TOTAL) current = pos.page;
        } catch (e) { /* no saved position is not an error */ }
    }

    // Signed URLs expire; drop the cached batches so the next turn re-fetches them.
    function maybeRefresh() {
        if (!expiresAt) return;
        if (Date.now() > expiresAt - 5 * 60 * 1000) {
            fetched = {}; pages = {}; expiresAt = 0;
            // V2-O5: a doc opened in 'stream' mode lives in memory and never needs the
            // URL again, so dumping it forced a pointless re-download of the whole book
            // ~3h55m into a session. Only a range-mode doc, which reads from the signed
            // URL on every page, has to go when that URL expires.
            if (_pdfMode === 'range') { pdfDocs = {}; }
            _warmed = {};
        }
    }

    /* ------------------------------------------------------------------
       R1 sequence model. The book is one linear run:
          [front cover] [1,2] [3,4] ... [back cover]
       (single-page view splits the middle into one page per step).
       Covers always display alone, as they do in a physical book.
       ------------------------------------------------------------------ */
    var _stage    = document.getElementById('eb-stage');
    var _backCover = (_stage && _stage.dataset.back) ? _stage.dataset.back : '';
    var _frontCover = (function () {
        var img = document.getElementById('eb-cover-front');
        return img ? img.getAttribute('src') : '';
    })();
    var _idx = 0;              // index into the sequence
    // Mobile opens single-page (two-up is unreadable on a phone); desktop opens two-up.
    var _view = (window.matchMedia && window.matchMedia('(max-width: 768px)').matches) ? 1 : 2;

    function _sequence() {
        var s = [{ t: 'cover' }];
        if (_view === 2) {
            for (var n = 1; n <= TOTAL; n += 2) {
                var p = [n];
                if (n + 1 <= TOTAL) p.push(n + 1);
                s.push({ t: 'pages', p: p });
            }
        } else {
            for (var m = 1; m <= TOTAL; m++) s.push({ t: 'pages', p: [m] });
        }
        if (_backCover) s.push({ t: 'back' });
        return s;
    }
    function _setPos(txt) {
        var el = document.getElementById('eb-pos');
        if (el) el.textContent = txt;
    }
    /* V1-b: an arrow is live only when the reader can actually honour a press.
       It used to derive purely from _idx vs _sequence().length - and _sequence() is
       built from the PHP-injected TOTAL, so all 180 steps existed before a single
       byte had been fetched. The markup now also ships both arrows disabled, so
       there is no live-but-useless window before this first runs. */
    function _syncArrows() {
        var last = _sequence().length - 1;
        var p = document.getElementById('eb-prev');
        var n = document.getElementById('eb-next');
        // V3: _bootOpen counts as busy. Until the document is open a press can only
        // fade the cover away and sit on an overlay - the dedupe in _openPdf makes it
        // SAFE, but there is nothing to show yet, so do not offer the turn.
        var busy = rendering || (_turnDir !== 0) || !_ready || _bootOpen;
        if (p) p.disabled = busy || (_idx <= 0);
        if (n) n.disabled = busy || (_idx >= last);
    }

    // Label whatever _idx currently points at. One source of truth, so a rollback
    // cannot leave the position text disagreeing with what is on screen.
    function _relabel() {
        var S = _sequence(), st = S[_idx];
        if (!st) return;
        if (st.t === 'cover') { _setPos('Front cover'); return; }
        if (st.t === 'back')  { _setPos('Back cover');  return; }
        _setPos(st.p.length > 1 ? ('Page ' + st.p[0] + '\u2013' + st.p[st.p.length - 1])
                                : ('Page ' + st.p[0]));
    }

    /* V1-a: close out a turn, successfully or not.
       _step() advances _idx BEFORE the paint is known to succeed, so a failed paint
       used to leave the index pointing at a spread that was never drawn. _leave()
       only FADES the outgoing nodes - it never removes them - so on failure the
       previous spread is still sitting in the DOM: rolling _idx back and re-labelling
       restores exactly what the holder is looking at, and _enter() fades it in again. */
    var _pendingFrom = -1;
    function _finishTurn(ok) {
        if (!ok && _pendingFrom >= 0) { _idx = _pendingFrom; _relabel(); }
        _pendingFrom = -1;
        _endTurn();
        _syncArrows();
    }
    // R4: clear the turn state once the new content is actually on screen.
    var _nodeCache  = {};   // R4: page@width -> rendered node
    var _cacheOrder = [];

    /* ------------------------------------------------------------------
       R5: animate the NODES directly, not a class on the parent.
       The class approach could not work reliably: the incoming page is a
       brand-new element, so removing the parent class on the next frame
       was batched into the same style recalculation as the insertion and
       the browser jumped straight to the end state - no transition at all.
       Setting the start state inline, forcing a reflow, then clearing it
       guarantees the transition runs.
       ------------------------------------------------------------------ */
    var TURN_MS = 190;
    function _prefersReducedMotion() {
        return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }
    // Slide/fade the CURRENT page out, then run done().
    function _leave(dir, done) {
        // R6: pin the height for the duration of the turn so nothing can resize the
        // stage mid-swap, whatever the next page turns out to measure.
        var h = viewport.offsetHeight;
        if (h > 0) { viewport.style.minHeight = h + 'px'; }
        if (_prefersReducedMotion()) { done(); return; }
        var kids = viewport.children;
        if (!kids.length) { done(); return; }
        for (var i = 0; i < kids.length; i++) {
            kids[i].style.transition = 'opacity ' + TURN_MS + 'ms ease, transform ' + TURN_MS + 'ms ease';
            kids[i].style.opacity = '0';
            kids[i].style.transform = 'translateX(' + (dir > 0 ? -18 : 18) + 'px)';
        }
        setTimeout(done, TURN_MS);
    }
    // Reveal freshly inserted nodes with a real transition.
    function _enter(dir) {
        var kids = viewport.children;
        if (!kids.length) return;
        if (_prefersReducedMotion()) {
            for (var r = 0; r < kids.length; r++) {
                kids[r].style.opacity = ''; kids[r].style.transform = ''; kids[r].style.transition = '';
            }
            return;
        }
        for (var i = 0; i < kids.length; i++) {
            kids[i].style.transition = 'none';
            kids[i].style.opacity = '0';
            kids[i].style.transform = 'translateX(' + (dir > 0 ? 18 : -18) + 'px)';
        }
        void viewport.offsetWidth;   // force the start state to be computed
        for (var j = 0; j < kids.length; j++) {
            kids[j].style.transition = 'opacity ' + TURN_MS + 'ms ease, transform ' + TURN_MS + 'ms ease';
            kids[j].style.opacity = '1';
            kids[j].style.transform = 'translateX(0)';
        }
    }
    var _turnDir = 0;   // direction of the turn currently in flight
    function _endTurn() {
        _enter(_turnDir);
        _turnDir = 0;
        // release the pin on the next frame, once the new page has laid out
        requestAnimationFrame(function () { viewport.style.minHeight = ''; });
    }

    function _showCover(which) {
        viewport.classList.remove('two');
        var img = document.createElement('img');
        img.className = 'eb-face';
        // Read from the stage each paint: initCoverAccess swaps data-front to the
        // master URL once the grant lands, and a flip back should show it.
        var liveFront = (_stage && _stage.dataset.front) ? _stage.dataset.front : _frontCover;
        var liveBack  = (_stage && _stage.dataset.back)  ? _stage.dataset.back  : _backCover;
        img.src = (which === 'back') ? liveBack : liveFront;
        img.alt = (which === 'back') ? 'Back cover' : 'Front cover';
        viewport.innerHTML = '';
        viewport.appendChild(img);
        _setPos(which === 'back' ? 'Back cover' : 'Front cover');
        _overlayHide();
        _finishTurn(true);
    }
    // paint whatever the current sequence index points at
    function _paint() {
        var S = _sequence();
        if (_idx < 0) _idx = 0;
        if (_idx > S.length - 1) _idx = S.length - 1;
        // V3: label where we are GOING, before the work starts. The bar used to keep
        // the previous step's text for the whole render - so a book resuming at page
        // 39, or a first turn off the cover, sat there reading "Front cover" while it
        // loaded something else entirely. _finishTurn() rolls this back if the paint fails.
        _relabel();
        var step = S[_idx];
        if (step.t === 'cover') { _showCover('front'); return; }
        if (step.t === 'back')  { _showCover('back');  return; }
        spread  = (step.p.length > 1);
        current = step.p[0];
        render();
    }
    // Turn: fade/slide the current page out, swap, let the new one settle in.
    // R7: warm the neighbouring spreads so a turn swaps in an image the browser has
    // already fetched and decoded.
    var _warmed = {};
    function _warmNeighbours() {
        var S = _sequence();
        [_idx - 1, _idx + 1].forEach(function (k) {
            if (k < 0 || k > S.length - 1) return;
            var step = S[k];
            if (!step || step.t !== 'pages') return;
            step.p.forEach(function (n) {
                if (_warmed[n]) return;
                _warmed[n] = true;
                ensurePage(n).then(function (ok) {
                    var p = ok && pages[n];
                    if (!p || p.src === 'pdf' || !p.url) return;
                    var pre = new Image();
                    pre.decoding = 'async';
                    pre.src = p.url;
                }).catch(function () { _warmed[n] = false; });
            });
        });
    }

    function _step(delta) {
        var S = _sequence();
        if ((delta < 0 && _idx <= 0) || (delta > 0 && _idx >= S.length - 1)) return;
        if (_turnDir !== 0) return;          // ignore input mid-turn
        _turnDir = delta;
        _pendingFrom = _idx;                 // V1-a: where to fall back to if the paint fails
        _syncArrows();                       // V1-b: no second press while this one is in flight
        _leave(delta, function () {
            _idx += delta;
            _paint();                        // _finishTurn() reveals the new page, or rolls back
        });
    }

    (function syncViewMenu() {
        var menu = document.getElementById('eb-menu');
        if (!menu) return;
        menu.querySelectorAll('.eb-opt').forEach(function (b) {
            b.classList.toggle('eb-opt-sel', parseInt(b.dataset.view, 10) === _view);
        });
    })();

    document.getElementById('eb-prev').addEventListener('click', function () { _step(-1); });
    document.getElementById('eb-next').addEventListener('click', function () { _step(1); });

    // gear: single vs two pages. The current page is kept across the switch.
    var _gear = document.getElementById('eb-gear');
    var _menu = document.getElementById('eb-menu');
    if (_gear && _menu) {
        _gear.addEventListener('click', function (e) {
            e.stopPropagation();
            _menu.classList.toggle('open');
            _gear.classList.toggle('on');
        });
        document.addEventListener('click', function () {
            _menu.classList.remove('open');
            _gear.classList.remove('on');
        });
        _menu.querySelectorAll('.eb-opt').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var keep = (_sequence()[_idx] || {}).p;
                _view = parseInt(btn.dataset.view, 10) === 1 ? 1 : 2;
                _menu.querySelectorAll('.eb-opt').forEach(function (b) { b.classList.remove('eb-opt-sel'); });
                btn.classList.add('eb-opt-sel');
                if (keep && keep.length) {
                    var S = _sequence(), want = keep[0], hit = 0;
                    for (var k = 0; k < S.length; k++) {
                        if (S[k].t === 'pages' && S[k].p.indexOf(want) !== -1) { hit = k; break; }
                    }
                    _idx = hit;
                }
                _paint();
            });
        });
    }

    var _fullBtn = document.getElementById('eb-full');
    if (_fullBtn) {
        if (!(_stage && _stage.requestFullscreen)) {
            _fullBtn.style.display = 'none';   // unsupported (some iOS) - hide rather than fail
        } else {
            _fullBtn.addEventListener('click', function () {
                if (!document.fullscreenElement) {
                    _stage.requestFullscreen().catch(function () {});
                    _fullBtn.classList.add('on');
                } else if (document.exitFullscreen) {
                    document.exitFullscreen();
                    _fullBtn.classList.remove('on');
                }
            });
            document.addEventListener('fullscreenchange', function () {
                if (!document.fullscreenElement) _fullBtn.classList.remove('on');
                render();
            });
        }
    }

    document.addEventListener('keydown', function (e) {
        if (!document.getElementById('eb-stage')) return;
        if (e.target && /^(INPUT|TEXTAREA)$/.test(e.target.tagName)) return;
        if (e.key === 'ArrowRight') _step(1);
        if (e.key === 'ArrowLeft')  _step(-1);
    });
    var resizeTimer = null;
    window.addEventListener('resize', function () {
        if (resizeTimer) clearTimeout(resizeTimer);
        resizeTimer = setTimeout(_paint, 250);
    });
    window.addEventListener('beforeunload', saveProgress);

    /* ==================================================================
       V2-O1 / V2-O2: warm the expensive things IN PARALLEL with the cover paint.
       The chain used to be strictly serial and only started when the holder first
       turned to a page: grant -> 1.5 MB of PDF.js (377 KB lib + 1.13 MB worker)
       -> the whole PDF -> rasterise. Nothing overlapped, so a 180-page book sat
       on the cover doing nothing until the first click, then did everything at once.
       ================================================================== */
    _bannerText('\u2b06\ufe0f Upgrading to master quality\u2026');
<?php if ($ebook_has_pdf): ?>
    loadPdfJs().catch(function () { /* the render path reports this properly */ });
<?php endif; ?>
    /* V3: the banner settles on the DOCUMENT, not on the grant.

       The grant lands in milliseconds and says nothing about whether the book can
       actually be read, so "Master quality loaded" was appearing while the PDF had not
       been touched - and the holder then waited again, for just as long, on their first
       turn. Opening the document HERE, while the cover is on screen, turns two waits
       into one. (A cover paint never calls render(), which is why the ready state
       cannot hang off render() - that was R1-B's spinner-forever trap.)

       An all-image book has no document to open, so for those the grant genuinely is
       the whole story and it settles immediately. */
    fetchBatch(0).then(function (ok) {
        if (!ok) { _bannerSettle(false); return null; }
        var u = _pdfUrlOf();
        if (!u) { _bannerSettle(true); return null; }      // image book: nothing to open
        _bannerText('\u2b06\ufe0f Preparing your book\u2026');
        _bootOpen = true;
        _overlayLater('Preparing your book\u2026', 500);    // 500ms: a fast open never flashes
        return _withTimeout(_openPdf(u), 180000, 'this book').then(function () {
            _bootOpen = false; _overlayHide(); _syncArrows(); _bannerSettle(true);
        }, function (e) {
            try { console.error('[eBook] boot open failed', e); } catch (x) {}
            _bootOpen = false; _overlayHide(); _syncArrows(); _bannerSettle(false);
            _note('This book could not be opened. Please refresh.');
        });
    }).catch(function () {
        _bootOpen = false; _overlayHide(); _syncArrows(); _bannerSettle(false);
    });

    // Belt and braces: the banner must never spin forever (R1-B). If the open is still
    // genuinely running we say so rather than hiding the overlay out from under it.
    setTimeout(function () {
        _bannerSettle(false);
        if (_bootOpen) {
            // Still going: say so rather than hiding the overlay out from under it, but
            // release the arrow gate so the reader is never permanently un-turnable.
            _overlay('Still preparing this book\u2026 large books can take a while.');
            _bootOpen = false;
            _syncArrows();
        }
    }, 120000);

    // Resume: map the stored page onto the sequence, else open on the cover.
    loadProgress().then(function () {
        if (current && current > 1) {
            var S = _sequence();
            for (var k = 0; k < S.length; k++) {
                if (S[k].t === 'pages' && S[k].p.indexOf(current) !== -1) { _idx = k; break; }
            }
        }
        _paint();
    });

})();
<?php endif; ?>
    <?php if ($is_album && (!$is_owner || !$has_album_access)): ?>
    // ── v458: ALBUM PREVIEW PLAYER — plays IPFS preview clips for non-owners ──
    // Completely isolated IIFE. Cannot affect the owner master player above
    // (which is gated by is_owner && has_album_access — the inverse condition).
    // Reads data-preview-url from each track row, plays via native <audio>.
    // ──────────────────────────────────────────────────────────────────────────
    (function initAlbumPreviewPlayer() {
        'use strict';

        var audioEl    = document.getElementById('album-preview-audio');
        var tracklist  = document.getElementById('album-tracklist');
        var nowPlaying = document.getElementById('album-now-playing-title');
        var nowBar     = document.getElementById('album-now-playing-bar');

        if (!audioEl || !tracklist) return;

        var _currentIdx = -1;

        // Add preview tag to now-playing bar (once)
        var previewTag = document.createElement('span');
        previewTag.className = 'album-now-playing-preview-tag';
        previewTag.textContent = 'Preview';
        previewTag.style.display = 'none';
        if (nowBar) nowBar.appendChild(previewTag);

        // P-r1: seed track 1 on load so first-visit visitors get a live player
        // (previously the element sat empty at 0:00 until a row was clicked).
        (function seedFirstTrack() {
            var first = tracklist.querySelector('.album-track-row[data-preview-url]');
            if (!first || audioEl.src) return;
            audioEl.src = first.dataset.previewUrl;
            audioEl.preload = 'metadata';
            if (nowPlaying) nowPlaying.textContent = first.dataset.trackTitle || 'Track 1';
            previewTag.style.display = '';
        })();

        function clearActive() {
            tracklist.querySelectorAll('.album-track-row').forEach(function (row) {
                row.classList.remove('active', 'preview-playing');
            });
        }

        function playPreview(row) {
            var url   = row.dataset.previewUrl;
            var title = row.dataset.trackTitle || 'Track';
            var idx   = parseInt(row.dataset.trackIndex, 10);

            if (!url) return;

            // If clicking the same track that's playing, toggle pause/play
            if (idx === _currentIdx && !audioEl.paused) {
                audioEl.pause();
                row.classList.remove('preview-playing');
                previewTag.style.display = 'none';
                return;
            }

            clearActive();
            row.classList.add('active', 'preview-playing');
            _currentIdx = idx;

            audioEl.src = url;
            audioEl.load();
            audioEl.play().catch(function () { /* autoplay blocked — user can tap native play */ });

            if (nowPlaying) nowPlaying.textContent = title;
            previewTag.style.display = '';
        }

        // Auto-advance to next track preview when one ends
        audioEl.addEventListener('ended', function () {
            var rows = tracklist.querySelectorAll('.album-track-row[data-preview-url]');
            var nextFound = false;
            for (var i = 0; i < rows.length; i++) {
                if (parseInt(rows[i].dataset.trackIndex, 10) === _currentIdx && i + 1 < rows.length) {
                    playPreview(rows[i + 1]);
                    nextFound = true;
                    break;
                }
            }
            if (!nextFound) {
                // Playlist finished — reset
                clearActive();
                _currentIdx = -1;
                previewTag.style.display = 'none';
                var firstRow = tracklist.querySelector('.album-track-row');
                if (firstRow) firstRow.classList.add('active');
            }
        });

        // Click handler
        tracklist.addEventListener('click', function (e) {
            var row = e.target.closest('.album-track-row[data-preview-url]');
            if (row) playPreview(row);
        });

        // Keyboard handler
        tracklist.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                var row = e.target.closest('.album-track-row[data-preview-url]');
                if (row) { e.preventDefault(); playPreview(row); }
            }
        });
    })();
    <?php endif; // $is_album && non-owner preview player ?>

    // ── v300: DOWNLOAD PROTECTION — keyboard shortcuts + JS hardening ────────
    // Blocks Ctrl+S (save page), Ctrl+U (view source), and disables native
    // download attributes on media elements injected after DOM ready.
    // Works in combination with: controlsList="nodownload", oncontextmenu="return false",
    // disablePictureInPicture, and the Blob URL proxy above.
    // ──────────────────────────────────────────────────────────────────────────
    (function initMediaProtection() {
        // Block save/source shortcuts on the media section
        document.addEventListener('keydown', function(e) {
            const isMac = navigator.platform.toUpperCase().includes('MAC');
            const ctrl  = isMac ? e.metaKey : e.ctrlKey;
            if (!ctrl) return;
            // Ctrl/Cmd+S (save), Ctrl/Cmd+U (source), Ctrl/Cmd+Shift+S (save as)
            if (e.key === 's' || e.key === 'S' || e.key === 'u' || e.key === 'U') {
                const activeEl = document.activeElement;
                const mediaEl  = document.getElementById('nft-video-player') || document.getElementById('nft-audio-player');
                // Only block if focus is within the media section (don't break site-wide Ctrl+S on forms)
                if (mediaEl && (mediaEl.contains(activeEl) || activeEl === mediaEl || activeEl === document.body)) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            }
        }, true); // capture phase — fires before media element's own handlers

        // Ensure controlsList is set even if browser stripped it during parse
        function hardenMediaEl(el) {
            if (!el) return;
            if (el.tagName === 'VIDEO') {
                el.setAttribute('controlsList', 'nodownload nofullscreen');
                el.setAttribute('disablePictureInPicture', '');
            } else {
                el.setAttribute('controlsList', 'nodownload');
            }
            el.setAttribute('oncontextmenu', 'return false;');
        }

        // Run immediately and after master blob swap
        ['nft-video-player', 'nft-audio-player', 'album-track-audio'].forEach(id => {
            hardenMediaEl(document.getElementById(id));
        });

        // MutationObserver: re-harden if player element is replaced by any other script
        const mediaSection = document.querySelector('.nft-media-container');
        if (mediaSection && window.MutationObserver) {
            new MutationObserver(function(mutations) {
                mutations.forEach(function(m) {
                    m.addedNodes.forEach(function(node) {
                        if (node.nodeType === 1) {
                            if (node.tagName === 'VIDEO' || node.tagName === 'AUDIO') hardenMediaEl(node);
                            node.querySelectorAll && node.querySelectorAll('video, audio').forEach(hardenMediaEl);
                        }
                    });
                });
            }).observe(mediaSection, { childList: true, subtree: true });
        }
    })();

})();
</script>

<?php if (function_exists('imc_xrplto_attribution')) imc_xrplto_attribution(); ?>
<?php get_footer(); ?>