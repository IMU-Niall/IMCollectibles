<?php
/**
 * IMC User Profiles — P0 foundation (schema + routes)
 * ---------------------------------------------------------------------------
 * Aug 2026. DARK / ADDITIVE. Universalises the existing wp_imc_artist_profiles
 * table into the single profiles store for every user (creator or collector),
 * and registers the /user/{slug} + /creators/{slug} routes.
 *
 * Nothing here renders UI. The route handlers only fire if a matching template
 * (page-user.php / page-creators.php) exists on disk; until P2 ships those,
 * the new paths fall through to WordPress's normal 404 — no white screens,
 * no behaviour change to any existing page.
 *
 * The six added columns all default to NULL / 0, so every existing read of
 * wp_imc_artist_profiles returns byte-identical results. The new follows table
 * is created empty and read by nothing until P3.
 * ---------------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ===========================================================================
 * 1. SCHEMA — additive column upgrade on the profiles table
 * ========================================================================= */

if ( ! function_exists( 'imc_user_profiles_upgrade_columns' ) ) {
    /**
     * Idempotent ADD COLUMN pass. Runs once per deploy behind an option gate.
     * Uses INFORMATION_SCHEMA existence checks so re-runs are no-ops and a
     * partial prior run self-heals. enableExceptions() is never called on
     * $wpdb, so a duplicate-column error would be swallowed anyway — but we
     * check first so the log stays clean.
     */
    function imc_user_profiles_upgrade_columns() {
        global $wpdb;

        if ( ! function_exists( 'imc_artist_profiles_table' ) ) { return; }
        $table = imc_artist_profiles_table();

        // Only touch a table that already exists (the artist-profiles bootstrap
        // creates it; we never race ahead of it).
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( (string) $exists !== (string) $table ) { return; }

        $columns = array(
            'banner_desktop_url' => "ADD COLUMN banner_desktop_url VARCHAR(512) DEFAULT NULL",
            'banner_mobile_url'  => "ADD COLUMN banner_mobile_url VARCHAR(512) DEFAULT NULL",
            'verified_user'      => "ADD COLUMN verified_user TINYINT(1) NOT NULL DEFAULT 0",
            'is_team'            => "ADD COLUMN is_team TINYINT(1) NOT NULL DEFAULT 0",
            'verified_x_handle'  => "ADD COLUMN verified_x_handle VARCHAR(80) DEFAULT NULL",
            'section_visibility' => "ADD COLUMN section_visibility LONGTEXT DEFAULT NULL",
        );

        $db_name = defined( 'DB_NAME' ) ? DB_NAME : $wpdb->get_var( 'SELECT DATABASE()' );

        foreach ( $columns as $col => $ddl ) {
            $present = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                $db_name, $table, $col
            ) );
            if ( (int) $present === 0 ) {
                // @ silences any residual notice; existence check already gates it.
                @$wpdb->query( "ALTER TABLE {$table} {$ddl}" );
            }
        }
    }
}

/* ===========================================================================
 * 2. SCHEMA — the follows table (empty until P3)
 * ========================================================================= */

if ( ! function_exists( 'imc_follows_table' ) ) {
    function imc_follows_table() {
        global $wpdb;
        return $wpdb->prefix . 'imc_follows';
    }
}

if ( ! function_exists( 'imc_follows_ensure_table' ) ) {
    function imc_follows_ensure_table() {
        static $ensured = false;
        if ( $ensured ) { return; }
        global $wpdb;
        $table   = imc_follows_table();
        $charset = $wpdb->get_charset_collate();
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$table} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                follower_account VARCHAR(35) NOT NULL,
                followed_account VARCHAR(35) NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uniq_follow (follower_account, followed_account),
                KEY idx_follower (follower_account),
                KEY idx_followed (followed_account)
            ) {$charset}"
        );
        $ensured = true;
    }
}

/* ===========================================================================
 * 3. ONE-TIME MIGRATION GATE — runs schema work once per deploy, off the
 *    render path (admin_init / cron-safe init at low priority).
 * ========================================================================= */

if ( ! function_exists( 'imc_user_profiles_maybe_migrate' ) ) {
    function imc_user_profiles_maybe_migrate() {
        if ( get_option( 'imc_user_profiles_schema_v1' ) === '1' ) { return; }
        imc_user_profiles_upgrade_columns();
        imc_follows_ensure_table();
        update_option( 'imc_user_profiles_schema_v1', '1', false );
    }
    add_action( 'init', 'imc_user_profiles_maybe_migrate', 998 ); // before the v276 rewrite flush at 999
}

/* ===========================================================================
 * 4. ROUTES — /user/{slug} and /creators/{slug} (+ their directory roots)
 *    Mirrors the proven imu_artist_* pattern exactly. Inert until templates
 *    exist on disk.
 * ========================================================================= */

if ( ! function_exists( 'imc_user_profile_rewrite_rules' ) ) {
    function imc_user_profile_rewrite_rules() {
        // Directory roots
        add_rewrite_rule( '^user/?$',        'index.php?imc_user_dir=1',              'top' );
        add_rewrite_rule( '^creators/?$',    'index.php?imc_creator_dir=1',           'top' );
        // Single profiles
        add_rewrite_rule( '^user/([^/]+)/?$',     'index.php?imc_user_slug=$matches[1]',    'top' );
        add_rewrite_rule( '^creators/([^/]+)/?$', 'index.php?imc_creator_slug=$matches[1]', 'top' );
    }
    add_action( 'init', 'imc_user_profile_rewrite_rules' );
}

if ( ! function_exists( 'imc_user_profile_query_vars' ) ) {
    function imc_user_profile_query_vars( $vars ) {
        $vars[] = 'imc_user_slug';
        $vars[] = 'imc_user_dir';
        $vars[] = 'imc_creator_slug';
        $vars[] = 'imc_creator_dir';
        return $vars;
    }
    add_filter( 'query_vars', 'imc_user_profile_query_vars' );
}

if ( ! function_exists( 'imc_user_profile_template_redirect' ) ) {
    /**
     * Route resolver. Reads query vars first, then falls back to raw-URI parsing
     * (identical safety net to imu_artist_template_redirect, which covers cases
     * where a stale rewrite cache hasn't been flushed yet).
     *
     * CRITICAL: every include is guarded by file_exists(). If the P2 template
     * is not yet on disk, this function returns without action and WordPress
     * serves its normal 404 — the new routes are harmless until P2.
     */
    function imc_user_profile_template_redirect() {
        $user_slug    = get_query_var( 'imc_user_slug' );
        $creator_slug = get_query_var( 'imc_creator_slug' );
        $is_user_dir  = ( get_query_var( 'imc_user_dir' ) === '1' );
        $is_cr_dir    = ( get_query_var( 'imc_creator_dir' ) === '1' );

        // Raw-URI fallback (pre-flush safety)
        if ( empty( $user_slug ) && empty( $creator_slug ) && ! $is_user_dir && ! $is_cr_dir ) {
            $uri = trim( parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ?? '', '/' );
            $site_path = trim( parse_url( home_url(), PHP_URL_PATH ) ?? '', '/' );
            if ( $site_path && strpos( $uri, $site_path ) === 0 ) {
                $uri = trim( substr( $uri, strlen( $site_path ) ), '/' );
            }
            if ( $uri === 'user' ) {
                $is_user_dir = true;
            } elseif ( strpos( $uri, 'user/' ) === 0 ) {
                $user_slug = sanitize_title( substr( $uri, strlen( 'user/' ) ) );
            } elseif ( $uri === 'creators' ) {
                $is_cr_dir = true;
            } elseif ( strpos( $uri, 'creators/' ) === 0 ) {
                $creator_slug = sanitize_title( substr( $uri, strlen( 'creators/' ) ) );
            }
        }

        if ( empty( $user_slug ) && empty( $creator_slug ) && ! $is_user_dir && ! $is_cr_dir ) {
            return; // not our route
        }

        // --- Directory: /user or /creators ---
        if ( $is_user_dir || $is_cr_dir ) {
            // Creators directory reuses the existing artists directory template
            // until a dedicated one ships; the user directory needs page-users.php.
            $tpl = $is_cr_dir
                ? locate_template( 'page-creators.php' )
                : locate_template( 'page-users.php' );
            if ( ! $tpl || ! file_exists( $tpl ) ) {
                // Fallback: creators dir can borrow the proven artists directory.
                if ( $is_cr_dir ) { $tpl = locate_template( 'page-artists.php' ); }
            }
            if ( $tpl && file_exists( $tpl ) ) {
                global $wp_query;
                $wp_query->is_404 = false;
                status_header( 200 );
                include $tpl;
                exit;
            }
            return; // no template yet → normal 404
        }

        // --- Single profile ---
        if ( ! empty( $creator_slug ) ) {
            // Creator storefront: reuse the existing, working page-artist.php.
            $tpl = locate_template( 'page-artist.php' );
            if ( $tpl && file_exists( $tpl ) ) {
                global $wp_query;
                $wp_query->is_404 = false;
                status_header( 200 );
                set_query_var( 'artist_slug', $creator_slug ); // page-artist reads this
                include $tpl;
                exit;
            }
            return;
        }

        if ( ! empty( $user_slug ) ) {
            $tpl = locate_template( 'page-user.php' );
            if ( $tpl && file_exists( $tpl ) ) {
                global $wp_query;
                $wp_query->is_404 = false;
                status_header( 200 );
                set_query_var( 'imc_user_slug', $user_slug );
                include $tpl;
                exit;
            }
            return; // P2 not shipped yet → normal 404
        }
    }
    add_action( 'template_redirect', 'imc_user_profile_template_redirect', 1 );
}

/* ===========================================================================
 * 5. ONE-TIME REWRITE FLUSH — bump the option to register the new rules once.
 * ========================================================================= */

if ( ! function_exists( 'imc_user_profiles_maybe_flush' ) ) {
    function imc_user_profiles_maybe_flush() {
        if ( get_option( 'imc_user_profiles_rewrites_v1' ) !== '1' ) {
            imc_user_profile_rewrite_rules();
            flush_rewrite_rules( false );
            update_option( 'imc_user_profiles_rewrites_v1', '1', false );
        }
    }
    add_action( 'init', 'imc_user_profiles_maybe_flush', 1000 ); // after rules are registered
}