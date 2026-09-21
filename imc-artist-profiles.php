<?php
/**
 * ============================================================================
 * FILE: imc-artist-profiles.php
 * PATH: /wp-content/themes/astra/inc/
 * ============================================================================
 *
 * IMCollectibles - Artist Profiles (shared foundation)
 *
 * PHASE 1 (foundation, INERT): table definition + display-name resolution
 * helpers. Defined here and loaded via functions.php, so these helpers are
 * callable from every page template AND every standalone backend handler
 * (which bootstrap wp-load.php, which loads the active theme's functions.php).
 *
 * IMPORTANT: nothing in the existing render/mint/buy path calls these helpers
 * yet. This file adds capability only and changes ZERO existing behaviour.
 * The resolution sweep that wires these into the producers is a later phase.
 *
 * @version 1.0.0 (Phase 1 foundation)
 */

if (!defined('ABSPATH')) { exit; }

/**
 * Fully-qualified artist profiles table name.
 */
if (!function_exists('imc_artist_profiles_table')) {
    function imc_artist_profiles_table() {
        global $wpdb;
        return $wpdb->prefix . 'imc_artist_profiles';
    }
}

/**
 * Idempotently ensure the profiles table exists.
 * Safe to call repeatedly: CREATE TABLE IF NOT EXISTS + a per-request guard so
 * at most one DDL round-trip happens per request, and never on a read path
 * unless explicitly invoked.
 *
 * Forward-ready schema: display_name is used now; slug / profile_image_url /
 * bio / social_links are nullable columns reserved for the /artists phase, so
 * that phase needs no second migration.
 */
if (!function_exists('imc_artist_profiles_ensure_table')) {
    function imc_artist_profiles_ensure_table() {
        static $ensured = false;
        if ($ensured) { return; }
        global $wpdb;
        $table   = imc_artist_profiles_table();
        $charset = $wpdb->get_charset_collate();
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$table} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                artist_account VARCHAR(35) NOT NULL,
                display_name VARCHAR(120) DEFAULT NULL,
                slug VARCHAR(160) DEFAULT NULL,
                profile_image_url VARCHAR(512) DEFAULT NULL,
                bio TEXT DEFAULT NULL,
                social_links LONGTEXT DEFAULT NULL,
                is_verified TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY idx_artist (artist_account)
            ) {$charset}"
        );
        $ensured = true;
    }
}

/**
 * Short wallet form used as the ULTIMATE display fallback.
 * Unifies the previously-divergent truncations site-wide (6 + ... + 4).
 */
if (!function_exists('imc_wallet_short')) {
    function imc_wallet_short($account) {
        $account = (string) $account;
        if (strlen($account) <= 12) { return $account; }
        return substr($account, 0, 6) . '...' . substr($account, -4);
    }
}

/**
 * Batched artist_account => display_name map for a set of accounts.
 *
 * - Only returns entries that have a non-empty display_name.
 * - Validates each account against the XRPL classic-address format.
 * - Request-level static cache so repeated resolves within one request (e.g.
 *   a grid of cards) hit memory, not the DB. Warm the cache once per request by
 *   calling this with ALL the accounts on the page before per-row resolution.
 * - DEFENSIVE: if the table does not exist yet (pre-migration), returns an
 *   empty map so callers fall through to legacy behaviour with no error.
 *
 * @param string[] $accounts
 * @return array<string,string>  account => display_name (only those that have one)
 */
if (!function_exists('imc_artist_display_map')) {
    function imc_artist_display_map(array $accounts) {
        static $cache = []; // account => string|null (null = looked up, none set)
        global $wpdb;

        // Normalise + validate + dedupe.
        $want = [];
        foreach ($accounts as $a) {
            $a = trim((string) $a);
            if ($a !== '' && preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $a)) {
                $want[$a] = true;
            }
        }
        if (empty($want)) { return []; }

        // Serve from cache; collect misses.
        $result = [];
        $misses = [];
        foreach (array_keys($want) as $a) {
            if (array_key_exists($a, $cache)) {
                if ($cache[$a] !== null) { $result[$a] = $cache[$a]; }
            } else {
                $misses[] = $a;
            }
        }
        if (empty($misses)) { return $result; }

        $table = imc_artist_profiles_table();

        // Pre-migration / missing table => behave as "no names set".
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            foreach ($misses as $a) { $cache[$a] = null; }
            return $result;
        }

        $ph   = implode(',', array_fill(0, count($misses), '%s'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT artist_account, display_name
                 FROM {$table}
                 WHERE artist_account IN ($ph)
                   AND display_name IS NOT NULL
                   AND display_name != ''",
                ...$misses
            ),
            ARRAY_A
        );

        $found = [];
        if ($rows) {
            foreach ($rows as $r) {
                $found[$r['artist_account']] = $r['display_name'];
            }
        }
        foreach ($misses as $a) {
            $cache[$a] = $found[$a] ?? null;
            if ($cache[$a] !== null) { $result[$a] = $cache[$a]; }
        }
        return $result;
    }
}

/**
 * Resolve the display artist name for a single account.
 *
 * Precedence (per confirmed spec):
 *   1. Dashboard display_name (the ultimate source)
 *   2. Existing artist name  - per-listing artist_name, then deeper metadata
 *   3. Ultimate fallback     - short wallet form
 *
 * Kill-switch: define('IMC_ARTIST_NAME_DISABLED', true) in wp-config.php
 * instantly reverts to TRUE legacy behaviour (returns the caller's existing
 * value untouched, so each surface applies its own pre-existing fallback) with
 * no redeploy.
 *
 * @param string $account        XRPL artist account (issuer wallet)
 * @param string $fallback_name  Existing per-listing artist_name (legacy source)
 * @param string $meta_artist    Optional deeper metadata artist value
 * @return string
 */
if (!function_exists('imc_resolve_artist_name')) {
    function imc_resolve_artist_name($account, $fallback_name = '', $meta_artist = '') {
        $account       = trim((string) $account);
        $fallback_name = trim((string) $fallback_name);
        $meta_artist   = trim((string) $meta_artist);

        // Emergency off-ramp: hand back the exact legacy value, unchanged.
        // The calling surface keeps applying its own existing fallback logic.
        if (defined('IMC_ARTIST_NAME_DISABLED') && IMC_ARTIST_NAME_DISABLED) {
            return $fallback_name;
        }

        // 1) Dashboard display name (ultimate source).
        if ($account !== '') {
            $map = imc_artist_display_map([$account]);
            if (!empty($map[$account])) {
                return $map[$account];
            }
        }
        // 2) Existing artist name (per-listing, then deeper metadata).
        if ($fallback_name !== '') { return $fallback_name; }
        if ($meta_artist !== '')   { return $meta_artist; }

        // 3) Ultimate fallback: short wallet.
        return $account !== '' ? imc_wallet_short($account) : '';
    }
}

/**
 * Slugify an artist display name (mirrors imu_collection_slugify).
 */
if (!function_exists('imc_artist_slugify')) {
    function imc_artist_slugify($name) {
        $s = strtolower(trim((string) $name));
        $s = preg_replace('/[^a-z0-9\s\-]/u', '', $s);
        $s = preg_replace('/[\s\-]+/', '-', $s);
        $s = trim($s, '-');
        return $s !== '' ? $s : 'artist';
    }
}

/**
 * Unique slug for an artist, not colliding with any OTHER account's slug.
 * Pre-migration safe (returns the base slug if the table is absent).
 */
if (!function_exists('imc_artist_unique_slug')) {
    function imc_artist_unique_slug($name, $account) {
        global $wpdb;
        $base  = imc_artist_slugify($name);
        $table = imc_artist_profiles_table();
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return $base;
        }
        $slug = $base;
        $i = 1;
        while (true) {
            $clash = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE slug = %s AND artist_account != %s",
                $slug, $account
            ));
            if ($clash === 0) { return $slug; }
            $i++;
            $slug = $base . '-' . $i;
            if ($i > 50) { return $base . '-' . substr(md5($account), 0, 6); }
        }
    }
}

/**
 * Full profile row for an account (for server-side prefill / future /artists page).
 * Returns an array with empty defaults if no row / table missing.
 */
if (!function_exists('imc_artist_profile_get')) {
    function imc_artist_profile_get($account) {
        global $wpdb;
        $empty = ['display_name' => '', 'bio' => '', 'slug' => '', 'profile_image_url' => '', 'social_links' => []];
        $account = trim((string) $account);
        if ($account === '') { return $empty; }
        $table = imc_artist_profiles_table();
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return $empty;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT display_name, bio, slug, profile_image_url, social_links
             FROM {$table} WHERE artist_account = %s LIMIT 1",
            $account
        ), ARRAY_A);
        if (!$row) { return $empty; }
        return [
            'display_name'      => $row['display_name'] ?? '',
            'bio'               => $row['bio'] ?? '',
            'slug'              => $row['slug'] ?? '',
            'profile_image_url' => $row['profile_image_url'] ?? '',
            'social_links'      => !empty($row['social_links']) ? (json_decode($row['social_links'], true) ?: []) : [],
        ];
    }
}
/**
 * Resolve an artist slug to its artist_account (for /artists/{slug} routing).
 * Table-missing safe; returns '' when not found.
 */
if (!function_exists('imc_artist_account_by_slug')) {
    function imc_artist_account_by_slug($slug) {
        global $wpdb;
        $slug = trim((string) $slug);
        if ($slug === '' || strlen($slug) > 160) { return ''; }
        $table = imc_artist_profiles_table();
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return '';
        }
        $acct = $wpdb->get_var($wpdb->prepare(
            "SELECT artist_account FROM {$table} WHERE slug = %s LIMIT 1",
            $slug
        ));
        return $acct ? (string) $acct : '';
    }
}


/**
 * v625: VERIFIED CREATORS — admin-managed allowlist of XRPL addresses.
 * The WordPress option 'imc_verified_creators' (array of classic r-addresses) is the
 * single source of truth. Helpers below are READ-ONLY with a request-level static cache
 * so a directory grid resolves from memory. No effect on any public/mint/buy/create path.
 */
if (!function_exists('imc_verified_creator_set')) {
    function imc_verified_creator_set() {
        static $set = null;
        if ($set !== null) { return $set; }
        $set = [];
        $raw = get_option('imc_verified_creators', []);
        if (is_string($raw)) {
            $raw = preg_split('/[\\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (is_array($raw)) {
            foreach ($raw as $addr) {
                $addr = trim((string) $addr);
                if ($addr !== '') { $set[$addr] = true; }
            }
        }
        return $set;
    }
}

if (!function_exists('imc_is_verified_creator')) {
    function imc_is_verified_creator($account) {
        $account = trim((string) $account);
        if ($account === '') { return false; }
        $set = imc_verified_creator_set();
        return isset($set[$account]);
    }
}

/**
 * v625: Admin settings page — "Verified Creators".
 * Administrators (manage_options) maintain the verified XRPL address list here.
 * Self-contained + nonce-protected. Creators cannot self-verify (handler never
 * touches this option). Writes ONLY the option; touches no listing/mint/buy data.
 */
if (!function_exists('imc_verified_creators_admin_menu')) {
    function imc_verified_creators_admin_menu() {
        add_menu_page(
            'Verified Creators',
            'Verified Creators',
            'manage_options',
            'imc-verified-creators',
            'imc_verified_creators_render_page',
            'dashicons-yes-alt',
            58
        );
    }
    add_action('admin_menu', 'imc_verified_creators_admin_menu');
}

if (!function_exists('imc_verified_creators_render_page')) {
    function imc_verified_creators_render_page() {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized access'); }
        $notice = ''; $error = '';
        if (isset($_POST['imc_vc_save']) && check_admin_referer('imc_verified_creators_save')) {
            $raw   = stripslashes((string) ($_POST['imc_verified_creators'] ?? ''));
            $parts = preg_split('/[\\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
            $clean = []; $rejected = [];
            foreach (($parts ?: []) as $pp) {
                $pp = trim($pp);
                if ($pp === '') { continue; }
                if (preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $pp)) {
                    $clean[$pp] = true; // associative => dedupe
                } else {
                    $rejected[] = $pp;
                }
            }
            $clean = array_keys($clean);
            update_option('imc_verified_creators', $clean, false);
            $notice = count($clean) . ' verified creator address(es) saved.';
            if (!empty($rejected)) {
                $error = count($rejected) . ' entry(ies) ignored — not valid XRPL addresses: '
                       . esc_html(implode(', ', array_slice($rejected, 0, 10)));
            }
        }
        $current = get_option('imc_verified_creators', []);
        if (is_string($current)) { $current = preg_split('/[\\s,]+/', $current, -1, PREG_SPLIT_NO_EMPTY); }
        if (!is_array($current)) { $current = []; }
        $textarea = esc_textarea(implode("\n", $current));
        ?>
        <div class="wrap">
            <h1>Verified Creators</h1>
            <p>Enter the XRPL classic addresses (<code>r...</code>) of creators to mark as <strong>Verified</strong>, one per line.
               Verified creators get a badge on their profile and in the Creators directory, and sort to the top of the directory.</p>
            <?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <?php if ($error): ?><div class="notice notice-warning is-dismissible"><p><?php echo $error; ?></p></div><?php endif; ?>
            <form method="post">
                <?php wp_nonce_field('imc_verified_creators_save'); ?>
                <textarea name="imc_verified_creators" rows="16" style="width:100%;max-width:680px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;"><?php echo $textarea; ?></textarea>
                <p>
                    <button type="submit" name="imc_vc_save" value="1" class="button button-primary">Save Verified Creators</button>
                    &nbsp;<span style="color:#666;">Currently verified: <strong><?php echo count($current); ?></strong></span>
                </p>
            </form>
        </div>
        <?php
    }
}

/**
 * v636 Task1: VERIFIED AI ARTISTS — second admin-managed allowlist (separate option).
 * Mirrors Verified Creators exactly but for AI artists. READ-ONLY helpers + own admin page.
 * Single source of truth: option 'imc_verified_ai_creators'. No effect on any mint/buy/create path.
 */
if (!function_exists('imc_verified_ai_creator_set')) {
    function imc_verified_ai_creator_set() {
        static $set = null;
        if ($set !== null) { return $set; }
        $set = [];
        $raw = get_option('imc_verified_ai_creators', []);
        if (is_string($raw)) { $raw = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY); }
        if (is_array($raw)) {
            foreach ($raw as $addr) { $addr = trim((string) $addr); if ($addr !== '') { $set[$addr] = true; } }
        }
        return $set;
    }
}
if (!function_exists('imc_is_verified_ai_creator')) {
    function imc_is_verified_ai_creator($account) {
        $account = trim((string) $account);
        if ($account === '') { return false; }
        $set = imc_verified_ai_creator_set();
        return isset($set[$account]);
    }
}
if (!function_exists('imc_verified_ai_creators_admin_menu')) {
    function imc_verified_ai_creators_admin_menu() {
        add_menu_page(
            'Verified AI Artists',
            'Verified AI Artists',
            'manage_options',
            'imc-verified-ai-creators',
            'imc_verified_ai_creators_render_page',
            'dashicons-superhero-alt',
            59
        );
    }
    add_action('admin_menu', 'imc_verified_ai_creators_admin_menu');
}
if (!function_exists('imc_verified_ai_creators_render_page')) {
    function imc_verified_ai_creators_render_page() {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized access'); }
        $notice = ''; $error = '';
        if (isset($_POST['imc_vac_save']) && check_admin_referer('imc_verified_ai_creators_save')) {
            $raw   = stripslashes((string) ($_POST['imc_verified_ai_creators'] ?? ''));
            $parts = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
            $clean = []; $rejected = [];
            foreach (($parts ?: []) as $pp) {
                $pp = trim($pp);
                if ($pp === '') { continue; }
                if (preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $pp)) { $clean[$pp] = true; }
                else { $rejected[] = $pp; }
            }
            $clean = array_keys($clean);
            update_option('imc_verified_ai_creators', $clean, false);
            $notice = count($clean) . ' verified AI artist address(es) saved.';
            if (!empty($rejected)) {
                $error = count($rejected) . ' entry(ies) ignored — not valid XRPL addresses: '
                       . esc_html(implode(', ', array_slice($rejected, 0, 10)));
            }
        }
        $current = get_option('imc_verified_ai_creators', []);
        if (is_string($current)) { $current = preg_split('/[\s,]+/', $current, -1, PREG_SPLIT_NO_EMPTY); }
        if (!is_array($current)) { $current = []; }
        $textarea = esc_textarea(implode("\n", $current));
        ?>
        <div class="wrap">
            <h1>Verified AI Artists</h1>
            <p>Enter the XRPL classic addresses (<code>r...</code>) of <strong>AI artists</strong> to mark as <strong>Verified AI Artist</strong>, one per line.
               They receive a distinct badge and sort directly <em>below</em> verified (human) artists in the Creators directory.
               An address also listed as a Verified (human) Creator takes precedence and will not show as AI.</p>
            <?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <?php if ($error): ?><div class="notice notice-warning is-dismissible"><p><?php echo $error; ?></p></div><?php endif; ?>
            <form method="post">
                <?php wp_nonce_field('imc_verified_ai_creators_save'); ?>
                <textarea name="imc_verified_ai_creators" rows="16" style="width:100%;max-width:680px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;"><?php echo $textarea; ?></textarea>
                <p>
                    <button type="submit" name="imc_vac_save" value="1" class="button button-primary">Save Verified AI Artists</button>
                    &nbsp;<span style="color:#666;">Currently verified AI: <strong><?php echo count($current); ?></strong></span>
                </p>
            </form>
        </div>
        <?php
    }
}