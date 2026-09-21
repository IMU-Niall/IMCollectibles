<?php
/**
 * imc-session-auth.php - httponly, proof-bound wallet sessions.
 *
 * Mints and resolves an HMAC session token tied to a wallet that has been
 * cryptographically proven at login (Xaman or Joey). Exposes
 * imc_session_require_wallet(), the identity resolver the backend handlers call
 * instead of trusting a client-supplied account.
 *
 * IMC_SESSION_TOKEN_ONLY controls whether the legacy cookie fallback is
 * permitted; production runs token-only.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'IMC_SESSION_TOKENS_ENABLED' ) ) define( 'IMC_SESSION_TOKENS_ENABLED', true );
if ( ! defined( 'IMC_SESSION_TTL' ) )            define( 'IMC_SESSION_TTL', 30 * DAY_IN_SECONDS );
if ( ! defined( 'IMC_SESSION_COOKIE' ) )         define( 'IMC_SESSION_COOKIE', 'imc_session' );
// When TRUE, the wallet is resolved ONLY from the httponly, proof-bound session
// token and the legacy cookie fallback is refused everywhere. Production runs
// token-only; the value is set in wp-config.
if ( ! defined( 'IMC_SESSION_TOKEN_ONLY' ) )     define( 'IMC_SESSION_TOKEN_ONLY', true );

/**
 * Create wp_imc_sessions (dbDelta, idempotent) - mirrors create_cooldown_table.
 */
function imc_session_create_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'imc_sessions';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        token_hash varchar(64) NOT NULL,
        wallet varchar(64) NOT NULL,
        wallet_type varchar(16) NOT NULL DEFAULT 'xaman',
        surface varchar(16) NOT NULL DEFAULT 'web',
        created int(11) NOT NULL DEFAULT 0,
        expires int(11) NOT NULL DEFAULT 0,
        last_seen int(11) NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY token_hash (token_hash),
        KEY wallet (wallet),
        KEY expires (expires)
    ) $charset_collate;";
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}
add_action( 'after_setup_theme', 'imc_session_create_table' );

/**
 * Mint a session token for a PROVEN wallet and set the httponly cookie.
 * Call ONLY after cryptographic login proof (Xaman meta.signed, uuid-matched).
 * Token is HMAC-signed over a server-generated nonce, keyed on the WordPress salt.
 * Server stores hash(rand) only - a DB leak reveals no usable token.
 *
 * @return bool true if a token was minted and the cookie set.
 */
function imc_session_mint( $wallet, $wallet_type = 'xaman', $surface = 'web', $return_token = false ) {
    // I1-1a: $return_token flips this from a COOKIE mint to a BEARER mint. A native
    // app has no cookie jar, so it needs the token in the response body instead.
    // Everything else - the token shape, the server row, the hash-at-rest - is
    // identical, so there is still exactly one place where sessions are created.
    // Returns the token STRING on success (truthy) or false, so the 12 existing
    // call sites, which pass 1-3 args and test truthiness, are unaffected.
    // S1f-1: $surface records WHERE the session lives ('web' | 'imup3'). It is
    // deliberately separate from $wallet_type, which records WHICH WALLET proved
    // control ('xaman' | 'joey') - an app user still connects via one of those.
    // Defaulted to 'web' so every existing call site is unchanged.
    $surface = ( $surface === 'imup3' ) ? 'imup3' : 'web';
    if ( ! IMC_SESSION_TOKENS_ENABLED ) return false;
    if ( ! preg_match( '/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $wallet ) ) return false;
    if ( ! $return_token && headers_sent() ) return false; // cookie could not be set - do not strand a row

    global $wpdb;
    $table = $wpdb->prefix . 'imc_sessions';
    $now   = time();

    // Session-auth 2a-b (Aug 2026): IDEMPOTENT MINT. If this browser already holds a
    // VALID token for this SAME wallet, reuse it instead of minting a second row.
    // Xaman's multi-hop redirect calls mint 2-3 times per login; without this each
    // hop created a fresh row+cookie. We refresh last_seen and return the existing one.
    // A bearer mint never reuses: the caller holds no cookie to reuse FROM, and each
    // app login is deliberately its own session (Q75 - two devices, two sessions).
    if ( ! $return_token && ! empty( $_COOKIE[ IMC_SESSION_COOKIE ] ) ) {
        $ep = explode( '.', $_COOKIE[ IMC_SESSION_COOKIE ] );
        if ( count( $ep ) === 3 && preg_match( '/^[0-9a-f]{64}$/', $ep[1] ) ) {
            $existing = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, wallet, surface FROM $table WHERE token_hash = %s AND expires > %d",
                hash( 'sha256', $ep[1] ), $now
            ), ARRAY_A );
            if ( $existing && ! empty( $existing['wallet'] ) && $existing['wallet'] === $wallet
                 && ( $existing['surface'] ?? 'web' ) === $surface ) {
                $wpdb->update( $table, [ 'last_seen' => $now ], [ 'id' => (int) $existing['id'] ], [ '%d' ], [ '%d' ] );
                return true; // reuse the existing valid session - no new row, no new cookie
            }
        }
    }

    // Cheap prune of long-expired rows (keeps the table small forever).
    $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE expires < %d", $now - DAY_IN_SECONDS ) );

    $exp  = $now + IMC_SESSION_TTL;
    $rand = bin2hex( random_bytes( 32 ) );
    $sig  = hash_hmac( 'sha256', $exp . '.' . $rand, wp_salt( 'logged_in' ) );

    $inserted = $wpdb->insert( $table, [
        'token_hash'  => hash( 'sha256', $rand ),
        'wallet'      => $wallet,
        'wallet_type' => sanitize_key( $wallet_type ),
        'surface'     => $surface,
        'created'     => $now,
        'expires'     => $exp,
        'last_seen'   => $now,
    ], [ '%s', '%s', '%s', '%s', '%d', '%d', '%d' ] );
    if ( false === $inserted ) return false;

    // I1-1a: bearer mint - hand the token back and set no cookie. The row is identical
    // to a web row apart from surface='imup3', so resolve/expiry/logout all behave the
    // same. NEVER log this value: it is a 30-day bearer for a wallet identity.
    if ( $return_token ) {
        return $exp . '.' . $rand . '.' . $sig;
    }

    setcookie( IMC_SESSION_COOKIE, $exp . '.' . $rand . '.' . $sig, [
        'expires'  => $exp,
        'path'     => '/',
        'domain'   => '.imcollectibles.io',
        'secure'   => true,
        'httponly' => true,   // JS cannot read or forge this - the whole point
        'samesite' => 'Lax',
    ] );
    return true;
}

/**
 * Resolve the wallet bound to the current request's session token.
 * Verifies expiry + HMAC (constant-time) + server-side row. Returns '' on any
 * failure - callers fall back to their legacy path during Phase 2a.
 */
function imc_session_resolve_wallet() {
    // S1f-1: BEARER FIRST, then the cookie. IMUP3 is a native app with no cookie
    // jar, so it carries the same token in an Authorization header. Browsers send
    // no Authorization header, so the web path below is reached exactly as before.
    $raw     = '';
    $is_app  = false;
    $hdr     = '';
    if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
        $hdr = $_SERVER['REDIRECT_HTTP_AUTHORIZATION']; // some FCGI setups
    } elseif ( function_exists( 'apache_request_headers' ) ) {
        // 1c: THE THIRD READ. Some server configurations expose the Authorization
        // header through neither $_SERVER key - mod_php behind certain proxy or
        // rewrite setups strips it from $_SERVER but leaves it visible here.
        //
        // IMUTV has always read all three (its REST layer) and this function
        // read only two. It has not mattered so far because the only app caller is
        // imup3-session-resolve.php, which normalises the header before calling.
        // I1-4's verifier will call it from a DIFFERENT server context, so the gap
        // becomes reachable - and a login that works on one host and silently fails
        // on another is the worst kind of bug to chase.
        //
        // The header name is case-insensitive per RFC 7230, hence the strtolower.
        $headers = apache_request_headers();
        if ( is_array( $headers ) ) {
            foreach ( $headers as $key => $value ) {
                if ( strtolower( $key ) === 'authorization' ) {
                    $hdr = trim( $value );
                    break;
                }
            }
        }
    }
    if ( $hdr !== '' && preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', $hdr, $m ) ) {
        $raw    = $m[1];
        $is_app = true;
    } elseif ( ! empty( $_COOKIE[ IMC_SESSION_COOKIE ] ) ) {
        $raw = $_COOKIE[ IMC_SESSION_COOKIE ];
    }
    if ( $raw === '' ) return '';

    $parts = explode( '.', $raw );
    if ( count( $parts ) !== 3 ) return '';
    list( $exp, $rand, $sig ) = $parts;

    if ( ! ctype_digit( $exp ) || (int) $exp < time() ) return '';
    if ( ! preg_match( '/^[0-9a-f]{64}$/', $rand ) || ! preg_match( '/^[0-9a-f]{64}$/', $sig ) ) return '';

    $expected = hash_hmac( 'sha256', $exp . '.' . $rand, wp_salt( 'logged_in' ) );
    if ( ! hash_equals( $expected, $sig ) ) return '';

    global $wpdb;
    $table = $wpdb->prefix . 'imc_sessions';
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, wallet, surface FROM $table WHERE token_hash = %s AND expires > %d",
        hash( 'sha256', $rand ), time()
    ), ARRAY_A );
    if ( ! $row || empty( $row['wallet'] ) ) return '';

    // S1f-1 (Q77): a Bearer must resolve an APP row and a cookie a WEB row. A
    // captured web cookie value therefore cannot be replayed as an app bearer.
    $row_surface = $row['surface'] ?? 'web';
    if ( $is_app && $row_surface !== 'imup3' ) return '';
    if ( ! $is_app && $row_surface !== 'web' )   return '';

    // Touch last_seen at most hourly (avoid a write per request).
    $wpdb->query( $wpdb->prepare(
        "UPDATE $table SET last_seen = %d WHERE id = %d AND last_seen < %d",
        time(), (int) $row['id'], time() - HOUR_IN_SECONDS
    ) );

    return $row['wallet'];
}

/**
 * Tear down the current session token: delete the server row and expire the
 * cookie across the same three domain scopes the legacy logout uses.
 */
function imc_session_clear() {
    // Session-auth 2a-b (Aug 2026): LOG OUT EVERYWHERE. Resolve the wallet bound to
    // this token, delete this row, then delete ALL sessions for that wallet across
    // every device. One logout kills every session (Xaman's redirect flow mints
    // several rows per login - phone signin-complete, ?xrpl_login= redirect, desktop
    // poll - so a per-row delete always left siblings behind).
    global $wpdb;
    $table = $wpdb->prefix . 'imc_sessions';
    if ( ! empty( $_COOKIE[ IMC_SESSION_COOKIE ] ) ) {
        $parts = explode( '.', $_COOKIE[ IMC_SESSION_COOKIE ] );
        if ( count( $parts ) === 3 && preg_match( '/^[0-9a-f]{64}$/', $parts[1] ) ) {
            // Find the wallet this token belongs to, then wipe every row for it.
            $th     = hash( 'sha256', $parts[1] );
            $wallet = $wpdb->get_var( $wpdb->prepare( "SELECT wallet FROM $table WHERE token_hash = %s", $th ) );
            if ( $wallet ) {
                // S1f-1: scoped to WEB. The wallet-wide wipe exists because Xaman's
                // multi-hop redirect mints 2-3 rows per web login and a per-row delete
                // left siblings alive - that behaviour is preserved in full. It simply
                // no longer reaches across to an installed app: closing a browser tab
                // must not sign you out of IMUP3. A deliberate "sign out all devices"
                // control lands at P5 and will do the unscoped delete on purpose.
                $wpdb->delete( $table, [ 'wallet' => $wallet, 'surface' => 'web' ], [ '%s', '%s' ] );
            } else {
                // Token not found (already pruned) - still clear this exact row if present.
                $wpdb->delete( $table, [ 'token_hash' => $th ], [ '%s' ] );
            }
        }
    }
    // Belt-and-braces: if the soft cookie names a wallet, clear all its sessions too
    // (covers a logout where the httponly token cookie didn't arrive but the soft one did).
    if ( ! empty( $_COOKIE['xrpl_account'] ) && preg_match( '/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $_COOKIE['xrpl_account'] ) ) {
        // S1f-1: scoped to WEB for the same reason as the token wipe above. Scoping
        // one and not the other would re-open the global wipe through the back door.
        $wpdb->delete( $table, [ 'wallet' => sanitize_text_field( $_COOKIE['xrpl_account'] ), 'surface' => 'web' ], [ '%s', '%s' ] );
    }
    if ( headers_sent() ) return;
    $opts = [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => '.imcollectibles.io',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    setcookie( IMC_SESSION_COOKIE, '', $opts );
    setcookie( IMC_SESSION_COOKIE, '', array_merge( $opts, [ 'domain' => 'imcollectibles.io' ] ) );
    setcookie( IMC_SESSION_COOKIE, '', array_merge( $opts, [ 'domain' => '' ] ) );
    unset( $_COOKIE[ IMC_SESSION_COOKIE ] );
}

/**
 * Phase 1 (profiles prep, Aug 2026): unified caller-identity resolver for
 * mutation surfaces (artist profile, watchlist, chat, tasks, notification
 * prefs). Token-first via imc_session_resolve_wallet(); the legacy
 * xrpl_account cookie is accepted ONLY while IMC_SESSION_TOKEN_ONLY is
 * false - the same gate every IMU surface uses, so one wp-config flip
 * closes them all at once.
 *
 * If $posted_account is non-empty and differs from the resolved wallet the
 * call is REJECTED (error 'mismatch') rather than silently overridden, so
 * tampering surfaces in each caller's log.
 *
 * Returns array: ['ok' => bool, 'wallet' => string, 'error' => 'auth'|'mismatch'|''].
 */
if ( ! function_exists( 'imc_session_require_wallet' ) ) {
	function imc_session_require_wallet( $posted_account = '' ) {
		$wallet = function_exists( 'imc_session_resolve_wallet' ) ? imc_session_resolve_wallet() : '';

		if ( $wallet === '' && ( ! defined( 'IMC_SESSION_TOKEN_ONLY' ) || ! IMC_SESSION_TOKEN_ONLY ) ) {
			$legacy = isset( $_COOKIE['xrpl_account'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['xrpl_account'] ) ) : '';
			if ( preg_match( '/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/', $legacy ) ) {
				$wallet = $legacy;
			}
		}

		if ( $wallet === '' ) {
			return array( 'ok' => false, 'wallet' => '', 'error' => 'auth' );
		}

		$posted_account = trim( (string) $posted_account );
		if ( $posted_account !== '' && $posted_account !== $wallet ) {
			return array( 'ok' => false, 'wallet' => $wallet, 'error' => 'mismatch' );
		}

		return array( 'ok' => true, 'wallet' => $wallet, 'error' => '' );
	}
}

if ( ! function_exists( 'imc_session_wallet' ) ) {
	/**
	 * Page-side identity resolver (Fix 1a, Aug 2026). Token-first via
	 * imc_session_resolve_wallet(); the legacy xrpl_account cookie is accepted
	 * ONLY while IMC_SESSION_TOKEN_ONLY is false - the same gate require_wallet
	 * uses at its fallback. Returns a bare, regex-valid r-address string, or ''
	 * when neither a token nor (pre-flip) a valid soft cookie is present. For
	 * page-render gates that previously read $_COOKIE['xrpl_account'] directly.
	 */
	function imc_session_wallet() {
		$wallet = function_exists( 'imc_session_resolve_wallet' ) ? imc_session_resolve_wallet() : '';
		if ( $wallet === '' && ( ! defined( 'IMC_SESSION_TOKEN_ONLY' ) || ! IMC_SESSION_TOKEN_ONLY ) ) {
			$legacy = isset( $_COOKIE['xrpl_account'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['xrpl_account'] ) ) : '';
			if ( preg_match( '/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/', $legacy ) ) {
				$wallet = $legacy;
			}
		}
		return $wallet;
	}
}

/**
 * J1-c (Sep 2026): read the PROVEN wallet type ('xaman' | 'joey') for the
 * current session. ADDITIVE -- imc_session_resolve_wallet() is deliberately left
 * byte-for-byte untouched, because it is the hot, security-critical path that
 * every gated surface depends on.
 *
 * WHY THIS EXISTS: claim_nft (and 15 sibling sites) decided "is this a Joey
 * user?" from $_COOKIE['xrpl_wallet_type'] -- a cookie that imu-wallet.bundle.js
 * DELETES from its own session_delete handler (jl('xrpl_wallet_type')). A Joey
 * user whose WalletConnect session ended therefore lost the only signal the
 * server had, and was handed a Xaman XUMM payload he could not possibly sign.
 * wp_imc_sessions has carried the real answer since the token flip -- wallet_type
 * is written by imc_session_mint() AFTER on-ledger proof (joey_login_verify) --
 * it simply had no reader.
 *
 * SAFETY: gated on imc_session_resolve_wallet() returning a wallet FIRST, so the
 * type is only ever read for a token the proven resolver has ALREADY accepted
 * (expiry, HMAC signature and surface binding are all enforced there, and
 * token_hash is UNIQUE so this is the very same row). The token re-read below is
 * a deliberate self-contained copy rather than a refactor of the resolver: if the
 * two ever drift, this returns '' and every caller falls back to its existing
 * cookie behaviour. It fails SAFE.
 *
 * Returns 'xaman' | 'joey' | '' (no resolvable session).
 */
if ( ! function_exists( 'imc_session_wallet_type' ) ) {
	function imc_session_wallet_type() {
		// Gate: only proceed for a token the proven resolver has already accepted.
		$wallet = function_exists( 'imc_session_resolve_wallet' ) ? imc_session_resolve_wallet() : '';
		if ( $wallet === '' ) { return ''; }

		// Re-acquire the same raw token, in the same order the resolver uses
		// (Bearer first for IMUP3, then the web cookie).
		$raw = '';
		$hdr = '';
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$hdr = $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$hdr = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		} elseif ( function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $key => $value ) {
					if ( strtolower( $key ) === 'authorization' ) { $hdr = trim( $value ); break; }
				}
			}
		}
		if ( $hdr !== '' && preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', $hdr, $m ) ) {
			$raw = $m[1];
		} elseif ( ! empty( $_COOKIE[ IMC_SESSION_COOKIE ] ) ) {
			$raw = $_COOKIE[ IMC_SESSION_COOKIE ];
		}
		if ( $raw === '' ) { return ''; }

		$parts = explode( '.', $raw );
		if ( count( $parts ) !== 3 ) { return ''; }
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $parts[1] ) ) { return ''; }

		global $wpdb;
		$table = $wpdb->prefix . 'imc_sessions';
		$type  = $wpdb->get_var( $wpdb->prepare(
			"SELECT wallet_type FROM $table WHERE token_hash = %s AND expires > %d",
			hash( 'sha256', $parts[1] ), time()
		) );

		$type = is_string( $type ) ? strtolower( trim( $type ) ) : '';
		return ( $type === 'joey' || $type === 'xaman' ) ? $type : '';
	}
}