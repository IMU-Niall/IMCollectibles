<?php
/**
 * File: collections-api-handler.php
 * Path: /wp-content/themes/astra/xrpl-nft-marketplace/backend/collections-api-handler.php
 * 
 * CRUD API for wp_imc_collections table.
 * Manages artist collections for mint-on-demand NFTs.
 * 
 * Endpoints:
 *   GET  ?action=get_by_artist&account=rXXX       — List artist's collections
 *   GET  ?action=get&id=123                        — Get single collection
 *   GET  ?action=check_taxon&account=rXXX&taxon=N  — Check taxon availability
 *   POST action=create                             — Create new collection (custom taxon required)
 *   POST action=update                             — Update collection details
 * 
 * XRPL NFTokenTaxon compliance:
 *   - Range: 0x0 to 0xFFFFFFFF (0 to 4,294,967,295)
 *   - Issuer chooses any numeric value
 *   - Groups NFTs into on-chain collections
 *   - Unique per artist (no two collections share a taxon)
 * 
 * @version 2.0.0 — Custom taxon + duplicate prevention
 */

// WordPress bootstrap
$wp_load = dirname(__FILE__, 5) . '/wp-load.php';
if (!file_exists($wp_load)) {
    $wp_load = dirname(__FILE__, 6) . '/wp-load.php';
}
require_once $wp_load;

// CORS headers — restrict to known domains
header('Content-Type: application/json');
$allowedOrigins = [
    'https://imcollectibles.xyz',
    'https://www.imcollectibles.xyz',
    'https://imcollectibles.io',
    'https://www.imcollectibles.io',
    'https://improtectors.com',
    'https://www.improtectors.com'
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($origin, $allowedOrigins) ? $origin : $allowedOrigins[0]));
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

global $wpdb;

$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {
        case 'get_by_artist':
            handle_get_by_artist();
            break;
        case 'get':
            handle_get_single();
            break;
        case 'check_taxon':
            handle_check_taxon();
            break;
        case 'create':
            handle_create();
            break;
        case 'update':
            handle_update();
            break;
        case 'list_all':
            handle_list_all();
            break;
        default:
            wp_send_json(['success' => false, 'error' => 'Invalid action'], 400);
    }
} catch (Exception $e) {
    error_log('IMC Collections API Error: ' . $e->getMessage());
    wp_send_json(['success' => false, 'error' => $e->getMessage()], 500);
}

// =========================================================================
// HELPERS
// =========================================================================

function validate_xrpl_account($account) {
    return !empty($account) && preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account);
}

/**
 * Validate taxon is within XRPL NFTokenTaxon range: 0 to 4,294,967,295
 * Uses string comparison for large numbers (PHP 32-bit safe)
 */
function validate_taxon_range($taxon_str) {
    if (!preg_match('/^\d+$/', $taxon_str)) return false;
    if (function_exists('bccomp')) {
        return bccomp($taxon_str, '4294967295') <= 0;
    }
    if (strlen($taxon_str) < 10) return true;
    if (strlen($taxon_str) > 10) return false;
    return strcmp($taxon_str, '4294967295') <= 0;
}

// =========================================================================
// HANDLERS
// =========================================================================

/**
 * GET: List all collections for an artist
 */
function handle_get_by_artist() {
    global $wpdb;
    
    $account = sanitize_text_field($_GET['account'] ?? '');
    
    if (!validate_xrpl_account($account)) {
        wp_send_json(['success' => false, 'error' => 'Valid XRPL account required'], 400);
        return;
    }
    
    $table = $wpdb->prefix . 'imc_collections';
    
    // v138: Primary query — simple, guaranteed to work
    $collections = $wpdb->get_results($wpdb->prepare(
        "SELECT id, artist_account, collection_name, collection_description, 
                collection_taxon, cover_image_ipfs, nft_count, 
                created_at, updated_at
         FROM {$table}
         WHERE artist_account = %s
         ORDER BY updated_at DESC",
        $account
    ), ARRAY_A);
    
    if ($wpdb->last_error) {
        throw new Exception('Database error: ' . $wpdb->last_error);
    }
    
    // v138: Enrich with listing data (separate query — fails gracefully)
    $listings_table = $wpdb->prefix . 'imc_listings';
    $listings_exist = ($wpdb->get_var("SHOW TABLES LIKE '$listings_table'") === $listings_table);
    
    if ($listings_exist && !empty($collections)) {
        try {
            $listing_stats = $wpdb->get_results($wpdb->prepare(
                "SELECT collection_taxon,
                        SUM(minted_count) AS total_minted,
                        SUM(total_editions) AS total_editions,
                        MAX(cover_ipfs) AS listing_cover,
                        MAX(nft_type) AS nft_type
                 FROM {$listings_table}
                 WHERE artist_account = %s AND is_hidden = 0
                 GROUP BY collection_taxon",
                $account
            ), OBJECT_K);
            
            // Merge listing stats into collections
            foreach ($collections as &$col) {
                $taxon = $col['collection_taxon'];
                if (isset($listing_stats[$taxon])) {
                    $stats = $listing_stats[$taxon];
                    $col['actual_minted'] = intval($stats->total_minted);
                    $col['total_editions'] = intval($stats->total_editions);
                    $col['listing_nft_type'] = $stats->nft_type;
                    // v139: Always include listing_cover for JS fallback
                    $col['listing_cover'] = $stats->listing_cover ?: '';
                    // Use listing cover if collection cover is empty
                    if (empty($col['cover_image_ipfs']) && !empty($stats->listing_cover)) {
                        $col['cover_image_ipfs'] = $stats->listing_cover;
                    }
                    // Use best count
                    $col['nft_count'] = max(intval($col['nft_count']), intval($stats->total_minted));
                }
            }
        } catch (Exception $e) {
            // Enrichment failed — collections still load with base data
            error_log('IMC: Listing enrichment failed: ' . $e->getMessage());
        }
    }
    
    wp_send_json([
        'success' => true,
        'collections' => $collections ?: [],
        'count' => count($collections ?: [])
    ]);
}

/**
 * GET: Get a single collection by ID
 */
function handle_get_single() {
    global $wpdb;
    
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) {
        wp_send_json(['success' => false, 'error' => 'Valid collection ID required'], 400);
        return;
    }
    
    $table = $wpdb->prefix . 'imc_collections';
    $collection = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE id = %d", $id
    ), ARRAY_A);
    
    if (!$collection) {
        wp_send_json(['success' => false, 'error' => 'Collection not found'], 404);
        return;
    }
    
    wp_send_json(['success' => true, 'collection' => $collection]);
}

/**
 * GET: Check if a taxon is available for an artist (real-time validation)
 */
function handle_check_taxon() {
    global $wpdb;
    
    $account = sanitize_text_field($_GET['account'] ?? '');
    $taxon_str = sanitize_text_field($_GET['taxon'] ?? '');
    
    if (!validate_xrpl_account($account)) {
        wp_send_json(['success' => false, 'error' => 'Valid XRPL account required'], 400);
        return;
    }
    
    if ($taxon_str === '' || !validate_taxon_range($taxon_str)) {
        wp_send_json(['success' => false, 'error' => 'Invalid taxon (must be 0-4294967295)'], 400);
        return;
    }
    
    $taxon = intval($taxon_str);
    $table = $wpdb->prefix . 'imc_collections';
    
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT collection_name FROM {$table} WHERE artist_account = %s AND collection_taxon = %d",
        $account, $taxon
    ), ARRAY_A);
    
    wp_send_json([
        'success' => true,
        'available' => !$existing,
        'existing_collection' => $existing ? $existing['collection_name'] : null
    ]);
}

/**
 * POST: Create a new collection
 * REQUIRES client-provided collection_taxon. Rejects duplicate taxons per artist.
 */
function handle_create() {
    global $wpdb;
    
    // Security: verify nonce
    $nonce = sanitize_text_field($_POST['nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        wp_send_json(['success' => false, 'error' => 'Invalid security token'], 403);
        return;
    }
    
    $account     = sanitize_text_field($_POST['artist_account'] ?? '');
    // v473: stripslashes — WordPress wp_magic_quotes() escapes POST values, so quotes/apostrophes
    // in collection_name and description otherwise get stored as \" and \' literally (matches the
    // proven v390 pattern in listings-handler.php:1125-1127).
    $name        = sanitize_text_field(stripslashes($_POST['collection_name'] ?? ''));
    $description = sanitize_textarea_field(stripslashes($_POST['collection_description'] ?? ''));
    $cover_ipfs  = sanitize_text_field($_POST['cover_image_ipfs'] ?? '');
    $taxon_str   = sanitize_text_field($_POST['collection_taxon'] ?? '');
    
    if (!validate_xrpl_account($account)) {
        wp_send_json(['success' => false, 'error' => 'Valid XRPL account required'], 400);
        return;
    }

    // Fix 1b (Aug 2026): token-first authorization. The caller must hold a
    // valid imc_session token whose wallet MATCHES the artist account being
    // modified. Replaces the spoofable xrpl_account cookie match (v506 Phase C).
    $c_auth = function_exists('imc_session_require_wallet')
        ? imc_session_require_wallet($account)
        : array('ok' => false, 'wallet' => '', 'error' => 'auth');
    if (empty($c_auth['ok'])) {
        wp_send_json(['success' => false, 'error' => 'Not authorized for this artist account'], 403);
        return;
    }
    if (empty($name)) {
        wp_send_json(['success' => false, 'error' => 'Collection name is required'], 400);
        return;
    }
    if (strlen($name) > 200) {
        wp_send_json(['success' => false, 'error' => 'Collection name too long (max 200 chars)'], 400);
        return;
    }
    
    // Taxon is REQUIRED — user-provided
    if ($taxon_str === '') {
        wp_send_json(['success' => false, 'error' => 'Collection taxon is required (XRPL NFTokenTaxon)'], 400);
        return;
    }
    if (!validate_taxon_range($taxon_str)) {
        wp_send_json(['success' => false, 'error' => 'Taxon must be 0 to 4,294,967,295 (XRPL uint32 range)'], 400);
        return;
    }
    
    $taxon = intval($taxon_str) & 0xFFFFFFFF;
    $table = $wpdb->prefix . 'imc_collections';
    $now = current_time('mysql');
    
    // REJECT duplicate taxon for this artist
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id, collection_name FROM {$table} WHERE artist_account = %s AND collection_taxon = %d",
        $account, $taxon
    ), ARRAY_A);
    
    if ($existing) {
        wp_send_json([
            'success' => false,
            'error' => "Taxon {$taxon} is already used by your collection \"{$existing['collection_name']}\". Each collection must have a unique taxon.",
            'existing_collection' => $existing['collection_name'],
            'existing_id' => intval($existing['id'])
        ], 409);
        return;
    }
    
    // Also check duplicate name
    $name_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE artist_account = %s AND collection_name = %s",
        $account, $name
    ));
    if ($name_exists) {
        wp_send_json([
            'success' => false,
            'error' => "You already have a collection named \"{$name}\". Please choose a different name."
        ], 409);
        return;
    }
    
    // Insert
    $inserted = $wpdb->insert($table, [
        'artist_account' => $account,
        'collection_name' => $name,
        'collection_description' => $description,
        'collection_taxon' => $taxon,
        'cover_image_ipfs' => $cover_ipfs ?: null,
        'nft_count' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ], ['%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s']);
    
    if ($inserted === false) {
        throw new Exception('Failed to create collection: ' . $wpdb->last_error);
    }
    
    wp_send_json([
        'success' => true,
        'collection' => [
            'id' => $wpdb->insert_id,
            'artist_account' => $account,
            'collection_name' => $name,
            'collection_description' => $description,
            'collection_taxon' => $taxon,
            'cover_image_ipfs' => $cover_ipfs ?: null,
            'nft_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        'existing' => false
    ]);
}

/**
 * POST: Update an existing collection (taxon is immutable)
 */
function handle_update() {
    global $wpdb;
    
    // Security: verify nonce
    $nonce = sanitize_text_field($_POST['nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        wp_send_json(['success' => false, 'error' => 'Invalid security token'], 403);
        return;
    }
    
    $id = intval($_POST['id'] ?? 0);
    $account = sanitize_text_field($_POST['artist_account'] ?? '');
    
    if ($id <= 0) {
        wp_send_json(['success' => false, 'error' => 'Valid collection ID required'], 400);
        return;
    }
    if (!validate_xrpl_account($account)) {
        wp_send_json(['success' => false, 'error' => 'Valid XRPL account required'], 400);
        return;
    }

    // Fix 1b (Aug 2026): token-first authorization. The caller must hold a
    // valid imc_session token whose wallet MATCHES the artist account being
    // modified. Replaces the spoofable xrpl_account cookie match (v506 Phase C).
    $c_auth = function_exists('imc_session_require_wallet')
        ? imc_session_require_wallet($account)
        : array('ok' => false, 'wallet' => '', 'error' => 'auth');
    if (empty($c_auth['ok'])) {
        wp_send_json(['success' => false, 'error' => 'Not authorized for this artist account'], 403);
        return;
    }
    
    $table = $wpdb->prefix . 'imc_collections';
    
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE id = %d AND artist_account = %s",
        $id, $account
    ), ARRAY_A);
    
    if (!$existing) {
        wp_send_json(['success' => false, 'error' => 'Collection not found or access denied'], 404);
        return;
    }
    
    $update = [];
    $formats = [];
    
    if (isset($_POST['collection_name']) && !empty($_POST['collection_name'])) {
        // v473: stripslashes (matches create path + listings-handler.php v390 pattern)
        $update['collection_name'] = sanitize_text_field(stripslashes($_POST['collection_name']));
        $formats[] = '%s';
    }
    if (isset($_POST['collection_description'])) {
        // v473: stripslashes (matches create path + listings-handler.php v390 pattern)
        $update['collection_description'] = sanitize_textarea_field(stripslashes($_POST['collection_description']));
        $formats[] = '%s';
    }
    if (isset($_POST['cover_image_ipfs'])) {
        $update['cover_image_ipfs'] = sanitize_text_field($_POST['cover_image_ipfs']);
        $formats[] = '%s';
    }
    
    if (empty($update)) {
        wp_send_json(['success' => false, 'error' => 'No fields to update'], 400);
        return;
    }
    
    $update['updated_at'] = current_time('mysql');
    $formats[] = '%s';
    
    $updated = $wpdb->update($table, $update,
        ['id' => $id, 'artist_account' => $account],
        $formats, ['%d', '%s']
    );
    
    if ($updated === false) {
        throw new Exception('Failed to update collection: ' . $wpdb->last_error);
    }
    
    // v506 Phase C: propagate edits immediately — clear cached imagery + OG meta.
    // collection_image_ self-refreshes from imc_collections on read, but must be
    // cleared for the cover-removed edge case; imc_og_col_ caches title/description/
    // image for 6h across several key variants (issuer/taxon/slug), so clear the
    // prefix (cheap: 6h cache, rebuilt on demand).
    $c_inv_issuer = $existing['artist_account'];
    $c_inv_taxon  = intval($existing['collection_taxon']);
    delete_transient('collection_image_' . md5($c_inv_issuer . '_' . $c_inv_taxon));

    // v507 Phase C-hotfix: RENAME CASCADE — the dashboard, the v360 listing
    // fallback, and slug derivation all key off imc_listings.collection_name.
    // A rename in imc_collections alone would desync them, so cascade it, and
    // invalidate the 24h slug transient so the new public URL resolves promptly.
    if (isset($update['collection_name']) && $update['collection_name'] !== ($existing['collection_name'] ?? '')) {
        $c_listings_table = $wpdb->prefix . 'imc_listings';
        $wpdb->update(
            $c_listings_table,
            ['collection_name' => $update['collection_name']],
            ['artist_account' => $c_inv_issuer, 'collection_taxon' => $c_inv_taxon],
            ['%s'], ['%s', '%d']
        );
        delete_transient('imc_slug_' . substr(md5($c_inv_issuer . '_' . $c_inv_taxon), 0, 16));
    }
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '\\_transient\\_imc\\_og\\_col\\_%'
            OR option_name LIKE '\\_transient\\_timeout\\_imc\\_og\\_col\\_%'"
    );
    
    $collection = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE id = %d", $id
    ), ARRAY_A);
    
    wp_send_json(['success' => true, 'collection' => $collection]);
}

/**
 * S1c — LIST_ALL: every IMC-minted collection, for the IMUP3 Access lane.
 *
 * PUBLIC READ, by the same reasoning this file already applies to
 * handle_get_by_artist(): reads here are public because the data is public
 * (collection name, taxon, cover, count); WRITES are wallet-matched.
 *
 * Three limits, deliberately:
 *   1. hard cap  — the table is small; capped at 200 it can never become
 *                  a bulk-export surface.
 *   2. column whitelist — never SELECT *, so a future non-public column
 *                  cannot leak by accident.
 *   3. no ordering on anything editorial — plain name order.
 *
 * NOTE: wp_imc_collections has NO is_hidden column (verified: MySQL #1054).
 * That flag lives on listings, not collections.
 */
function handle_list_all() {
    global $wpdb;

    $table  = $wpdb->prefix . 'imc_collections';
    $limit  = min(200, max(1, intval($_REQUEST['limit'] ?? 200)));
    $offset = max(0, intval($_REQUEST['offset'] ?? 0));

    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT artist_account, collection_taxon, collection_name,
                cover_image_ipfs, nft_count
           FROM {$table}
          ORDER BY collection_name ASC
          LIMIT %d OFFSET %d",
        $limit, $offset
    ), ARRAY_A);

    $collections = [];
    foreach ((array) $rows as $r) {
        $collections[] = [
            'issuer'    => $r['artist_account'],
            'taxon'     => (int) $r['collection_taxon'],
            'name'      => $r['collection_name'],
            'cover'     => $r['cover_image_ipfs'],
            'nft_count' => (int) $r['nft_count'],
        ];
    }

    wp_send_json([
        'success'     => true,
        'collections' => $collections,
        'total'       => $total,
        'limit'       => $limit,
        'offset'      => $offset,
    ]);
}