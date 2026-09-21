<?php
/**
 * ============================================================================
 * FILE: artist-profile-handler.php
 * PATH: /wp-content/themes/astra/xrpl-nft-marketplace/backend/
 * ============================================================================
 *
 * IMCollectibles - Artist Profile endpoint (Phase 1 foundation).
 *
 * ACTIONS:
 *   GET  ?action=get_profile&account=rXXX          - public read of a profile
 *   POST action=set_profile (+ nonce, display_name) - upsert the CALLER's own profile
 *
 * AUTH MODEL (mirrors the rest of the marketplace backend exactly):
 *   - WP nonce 'xrpl_marketplace_nonce' on all POST mutations (CSRF guard)
 *   - wallet cookie 'xrpl_account' identifies the caller
 *   - a profile is ALWAYS written for the cookie wallet (self-only). The write
 *     target is NEVER taken from client input, so a caller can only ever edit
 *     their own profile.
 *
 * @version 1.0.0 (Phase 1 foundation)
 */

// WordPress bootstrap
require_once dirname(__DIR__, 5) . '/wp-load.php';
if (!defined('ABSPATH')) { exit; }

// Headers (same CORS allow-list + no-cache policy as the other backend handlers)
header('Content-Type: application/json; charset=utf-8');
$ap_allowed_origins = [
    'https://imcollectibles.xyz',
    'https://www.imcollectibles.xyz',
    'https://imcollectibles.io',
    'https://www.imcollectibles.io',
    'https://improtectors.com',
    'https://www.improtectors.com',
];
$ap_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($ap_origin, $ap_allowed_origins, true) ? $ap_origin : $ap_allowed_origins[0]));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ----------------------------------------------------------------------------
// Local JSON helpers (scoped names to avoid clashing with other handlers)
// ----------------------------------------------------------------------------
function ap_error($msg, $code = 400) {
    http_response_code($code);
    echo wp_json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function ap_success($data) {
    echo wp_json_encode(array_merge(['success' => true], $data));
    exit;
}

// Foundation module must be present (loaded via functions.php). Fail safe, never fatal.
if (!function_exists('imc_artist_profiles_ensure_table') || !function_exists('imc_artist_profiles_table')) {
    ap_error('Artist profile module not available', 500);
}

global $wpdb;
$action  = $_GET['action'] ?? $_POST['action'] ?? '';
$xrpl_re = '/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/';

// ----------------------------------------------------------------------------
// P1 (Aug 2026): shared image-upload validator/storer - used by the pfp AND the
// two banner fields. One file per request by design (php.ini post_max_size=8M).
// Returns the stored URL, or calls ap_error() (which exits) on any failure.
function ap_store_uploaded_image($f, $max_mb, $label) {
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (($f['size'] ?? 0) > $max_mb * 1024 * 1024) {
        ap_error($label . ' too large (max ' . $max_mb . 'MB)', 400);
    }
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info['mime'], $allowed, true)) {
        ap_error('Invalid ' . strtolower($label) . ' format (use JPG, PNG, WEBP or GIF)', 400);
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $up = wp_handle_upload($f, [
        'test_form' => false,
        'mimes' => [
            'jpg|jpeg' => 'image/jpeg',
            'png'      => 'image/png',
            'webp'     => 'image/webp',
            'gif'      => 'image/gif',
        ],
    ]);
    if (isset($up['error'])) {
        ap_error($label . ' upload failed: ' . $up['error'], 400);
    }
    return $up['url'];
}

// ----------------------------------------------------------------------------
// GET: public profile read
// ----------------------------------------------------------------------------
if ($action === 'get_profile') {
    $account = sanitize_text_field($_GET['account'] ?? '');
    if (!preg_match($xrpl_re, $account)) {
        ap_error('Invalid XRPL account');
    }

    imc_artist_profiles_ensure_table();
    $table = imc_artist_profiles_table();

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT artist_account, display_name, slug, profile_image_url, bio, social_links,
                banner_desktop_url, banner_mobile_url, verified_user, is_team,
                verified_x_handle, section_visibility
         FROM {$table} WHERE artist_account = %s LIMIT 1",
        $account
    ), ARRAY_A);

    // P1 (User Profiles, Aug 2026): computed identity extras.
    // is_creator is DERIVED (has listings), mirroring the header v65 gate - never stored.
    $listings_table = $wpdb->prefix . 'imc_listings';
    $is_creator = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$listings_table} WHERE artist_account = %s LIMIT 1", $account
    )) > 0;
    $followers = 0; $following = 0;
    if (function_exists('imc_follows_table')) {
        $ft = imc_follows_table();
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ft)) === $ft) {
            $followers = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$ft} WHERE followed_account = %s", $account));
            $following = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$ft} WHERE follower_account = %s", $account));
        }
    }

    ap_success(['profile' => [
        'artist_account'     => $account,
        'display_name'       => $row['display_name']      ?? '',
        'slug'               => $row['slug']              ?? '',
        'profile_image_url'  => $row['profile_image_url'] ?? '',
        'bio'                => $row['bio']               ?? '',
        'social_links'       => !empty($row['social_links']) ? json_decode($row['social_links'], true) : null,
        'banner_desktop_url' => $row['banner_desktop_url'] ?? '',
        'banner_mobile_url'  => $row['banner_mobile_url']  ?? '',
        'verified_user'      => (int) ($row['verified_user'] ?? 0),
        'is_team'            => (int) ($row['is_team'] ?? 0),
        'verified_x_handle'  => $row['verified_x_handle'] ?? '',
        'section_visibility' => !empty($row['section_visibility']) ? json_decode($row['section_visibility'], true) : null,
        'is_creator'         => $is_creator,
        'followers'          => $followers,
        'following'          => $following,
    ]]);
}

// ----------------------------------------------------------------------------
// POST: set the caller's OWN profile (self-only)
// ----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'set_profile') {
    // CSRF: same nonce as every marketplace mutation.
    $nonce = sanitize_text_field($_POST['nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        ap_error('Invalid security token', 403);
    }

    // Identity (Phase 1, Aug 2026): session-token first via
    // imc_session_require_wallet(); the spoofable xrpl_account cookie is no
    // longer the authority (legacy fallback stays gated behind
    // IMC_SESSION_TOKEN_ONLY, as on every IMU surface). The account is never
    // taken from client POST input -> a caller can only ever edit their own
    // profile.
    $ap_auth = function_exists('imc_session_require_wallet')
        ? imc_session_require_wallet('')
        : array('ok' => false, 'wallet' => '', 'error' => 'auth');
    if (empty($ap_auth['ok']) || !preg_match($xrpl_re, (string) $ap_auth['wallet'])) {
        ap_error('Not authenticated - please sign in again', 403);
    }
    $account = $ap_auth['wallet'];

    // -- Existing row (preserve fields not being changed, e.g. the image) --
    imc_artist_profiles_ensure_table();
    $table = imc_artist_profiles_table();
    $now   = current_time('mysql');
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE artist_account = %s LIMIT 1",
        $account
    ), ARRAY_A);

    // -- Display name: strip tags, collapse whitespace, cap 120 --
    // P1 (Aug 2026): PARTIAL updates - a field absent from the POST keeps its
    // stored value (enables one-file-per-request image saves without wiping
    // text fields). The dashboard posts every field, so its behaviour is unchanged.
    $name_posted = isset($_POST['display_name']);
    $display_name = $name_posted
        ? wp_strip_all_tags(stripslashes((string) $_POST['display_name']))
        : '';
    $display_name = trim(preg_replace('/\s+/', ' ', $display_name));
    $display_name = function_exists('mb_substr')
        ? mb_substr($display_name, 0, 120)
        : substr($display_name, 0, 120);
    $store_name = ($display_name === '') ? null : $display_name;
    if (!$name_posted) { $store_name = $existing['display_name'] ?? null; }

    // -- Bio: strip tags, keep line breaks, cap 1000 --
    $bio = isset($_POST['bio'])
        ? sanitize_textarea_field(stripslashes((string) $_POST['bio']))
        : '';
    $bio = function_exists('mb_substr') ? mb_substr($bio, 0, 1000) : substr($bio, 0, 1000);
    $store_bio = ($bio === '') ? null : $bio;
    if (!isset($_POST['bio'])) { $store_bio = $existing['bio'] ?? null; }

    // -- Social links: fixed key set, each must be a http(s) URL --
    $social_keys = ['imutv', 'imup3', 'website', 'x', 'instagram', 'discord', 'youtube'];
    $socials = [];
    foreach ($social_keys as $sk) {
        $raw = trim((string) ($_POST['social_' . $sk] ?? ''));
        if ($raw === '') { continue; }
        $url = esc_url_raw($raw);
        if ($url !== '' && preg_match('#^https?://#i', $url)) {
            $socials[$sk] = $url;
        }
    }
    // v579: Custom links — free-form [{label,url}], cap 6, label<=40, url must be http(s).
    // Stored inside the same social_links JSON so no schema change is needed.
    $custom_links = [];
    $raw_cl = isset($_POST['custom_links']) ? (string) $_POST['custom_links'] : '';
    if ($raw_cl !== '') {
        $decoded_cl = json_decode(stripslashes($raw_cl), true);
        if (is_array($decoded_cl)) {
            foreach ($decoded_cl as $cl) {
                if (count($custom_links) >= 6) { break; }
                $clabel = isset($cl['label']) ? wp_strip_all_tags((string) $cl['label']) : '';
                $clabel = trim(preg_replace('/\s+/', ' ', $clabel));
                $clabel = function_exists('mb_substr') ? mb_substr($clabel, 0, 40) : substr($clabel, 0, 40);
                $curl   = isset($cl['url']) ? esc_url_raw(trim((string) $cl['url'])) : '';
                if ($clabel !== '' && $curl !== '' && preg_match('#^https?://#i', $curl)) {
                    $custom_links[] = ['label' => $clabel, 'url' => $curl];
                }
            }
        }
    }
    if (!empty($custom_links)) { $socials['custom_links'] = $custom_links; }

    $socials_posted = isset($_POST['custom_links']);
    foreach ($social_keys as $sk) { if (isset($_POST['social_' . $sk])) { $socials_posted = true; break; } }
    $store_socials = $socials_posted
        ? (empty($socials) ? null : wp_json_encode($socials))
        : ($existing['social_links'] ?? null);

    // -- Profile image: keep existing unless removed or a new file is given --
    $store_image = $existing['profile_image_url'] ?? null;
    if (!empty($_POST['remove_image'])) {
        $store_image = null;
    }
    if (!empty($_FILES['profile_image']) && ((($_FILES['profile_image']['error'] ?? UPLOAD_ERR_NO_FILE)) === UPLOAD_ERR_OK)) {
        $f = $_FILES['profile_image'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (($f['size'] ?? 0) > 4 * 1024 * 1024) {
            ap_error('Image too large (max 4MB)', 400);
        }
        $info = @getimagesize($f['tmp_name']);
        if (!$info || !in_array($info['mime'], $allowed, true)) {
            ap_error('Invalid image format (use JPG, PNG, WEBP or GIF)', 400);
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $up = wp_handle_upload($f, [
            'test_form' => false,
            'mimes' => [
                'jpg|jpeg' => 'image/jpeg',
                'png'      => 'image/png',
                'webp'     => 'image/webp',
                'gif'      => 'image/gif',
            ],
        ]);
        if (isset($up['error'])) {
            ap_error('Upload failed: ' . $up['error'], 400);
        }
        $att_id = wp_insert_attachment([
            'guid'           => $up['url'],
            'post_mime_type' => $up['type'],
            'post_title'     => 'Artist PFP ' . imc_wallet_short($account),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ], $up['file']);
        if (!$att_id || is_wp_error($att_id)) {
            ap_error('Could not save image', 500);
        }
        wp_update_attachment_metadata($att_id, wp_generate_attachment_metadata($att_id, $up['file']));
        $store_image = $up['url'];
    }

    // -- P1 banners (Aug 2026): desktop 1500x500 (3:1), mobile 1200x600 (2:1). --
    // Keep existing unless removed or a new file arrives. ONE file per request
    // (client saves pfp / banners as separate submits; post_max_size=8M).
    $store_banner_d = $existing['banner_desktop_url'] ?? null;
    if (!empty($_POST['remove_banner_desktop'])) { $store_banner_d = null; }
    if (!empty($_FILES['banner_desktop']) && ((($_FILES['banner_desktop']['error'] ?? UPLOAD_ERR_NO_FILE)) === UPLOAD_ERR_OK)) {
        $store_banner_d = ap_store_uploaded_image($_FILES['banner_desktop'], 6, 'Desktop banner');
    }
    $store_banner_m = $existing['banner_mobile_url'] ?? null;
    if (!empty($_POST['remove_banner_mobile'])) { $store_banner_m = null; }
    if (!empty($_FILES['banner_mobile']) && ((($_FILES['banner_mobile']['error'] ?? UPLOAD_ERR_NO_FILE)) === UPLOAD_ERR_OK)) {
        $store_banner_m = ap_store_uploaded_image($_FILES['banner_mobile'], 6, 'Mobile banner');
    }

    // -- P1 section visibility: whitelisted keys, booleans; NULL = all public --
    $store_visibility = $existing['section_visibility'] ?? null;
    if (isset($_POST['section_visibility'])) {
        $vis_in = json_decode(stripslashes((string) $_POST['section_visibility']), true);
        $vis_keys = ['created', 'collected', 'history', 'favourites', 'links', 'followers'];
        $vis = [];
        if (is_array($vis_in)) {
            foreach ($vis_keys as $vk) {
                if (array_key_exists($vk, $vis_in) && !$vis_in[$vk]) { $vis[$vk] = false; }
            }
        }
        $store_visibility = empty($vis) ? null : wp_json_encode($vis);
    }

    // -- Slug: from display name, unique, stable unless the name changes --
    $store_slug = $existing['slug'] ?? null;
    if ($store_name !== null) {
        $name_changed = !$existing || ((($existing['display_name'] ?? null)) !== $store_name);
        if (empty($store_slug) || $name_changed) {
            $store_slug = function_exists('imc_artist_unique_slug')
                ? imc_artist_unique_slug($store_name, $account)
                : (function_exists('imc_artist_slugify') ? imc_artist_slugify($store_name) : null);
        }
    }

    // -- Persist (all fields) --
    $data = [
        'display_name'      => $store_name,
        'bio'               => $store_bio,
        'social_links'      => $store_socials,
        'profile_image_url' => $store_image,
        'banner_desktop_url'  => $store_banner_d,
        'banner_mobile_url'   => $store_banner_m,
        'section_visibility'  => $store_visibility,
        'slug'              => $store_slug,
        'updated_at'        => $now,
    ];
    if ($existing) {
        $wpdb->update($table, $data, ['artist_account' => $account]);
    } else {
        $data['artist_account'] = $account;
        $data['created_at']     = $now;
        $wpdb->insert($table, $data);
    }

    // Phase 3: register this artist's collections on the VPS so the live WS + scan-events
    // track them. Discover-once (the VPS skips issuers already registered); fire-and-forget,
    // so it never blocks or affects the profile save. Kill switch: define('IMC_DISCOVER_ON_PROFILE', false).
    if (!defined('IMC_DISCOVER_ON_PROFILE') || IMC_DISCOVER_ON_PROFILE) {
        $disc_key = 'imc_triggered_discover_' . md5($account);
        if (!get_transient($disc_key)) {
            $disc_secret = defined('VPS_SHARED_SECRET') ? VPS_SHARED_SECRET : '';
            $disc_url = (defined('IMC_DISCOVERY_URL') ? IMC_DISCOVERY_URL : '')
                      . '?action=trigger_discover'
                      . '&issuer=' . rawurlencode($account)
                      . '&secret=' . rawurlencode($disc_secret);
            wp_remote_get($disc_url, array('timeout' => 1, 'blocking' => false, 'sslverify' => true));
            set_transient($disc_key, 1, 900);
        }
    }

    ap_success([
        'display_name'      => $store_name ?? '',
        'banner_desktop_url' => $store_banner_d ?? '',
        'banner_mobile_url'  => $store_banner_m ?? '',
        'section_visibility' => $store_visibility ? json_decode($store_visibility, true) : null,
        'bio'               => $store_bio ?? '',
        'social_links'      => $socials,
        'profile_image_url' => $store_image ?? '',
        'slug'              => $store_slug ?? '',
    ]);
}

ap_error('Unknown action');