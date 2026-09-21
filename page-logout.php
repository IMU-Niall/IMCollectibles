<?php
/*
 * Template Name: Logout Page
 * Description: Logs out the user by clearing BOTH identity cookies (xrpl_account +
 *              xrpl_wallet_type) across every domain scope, tearing down the httponly
 *              session token, then routing into the canonical ?logout=true flow so the
 *              client-side WalletConnect session (Joey) is also disconnected.
 *
 * Session-auth (Aug 2026): the previous version cleared xrpl_account with a 4-arg,
 * domain-less setcookie (which cannot remove a cookie set with domain=.imcollectibles.io)
 * and never touched xrpl_wallet_type. That left stale identity cookies behind — the root
 * of the Joey wallet-type desync (a stale xrpl_wallet_type starves the bundle enqueue gate,
 * so Joey creators were handed an unpayable Xaman payload). This mirrors the proven clear in
 * handle_xaman_logout() exactly: both cookies, all three domain scopes.
 */

// Tear down the httponly server session token + all wp_imc_sessions rows for this wallet.
if ( function_exists('imc_session_clear') ) { imc_session_clear(); }

// Clear BOTH soft identity cookies across every scope they may have been set on.
// Params mirror the set sites (path /, domain .imcollectibles.io, secure, httponly=false,
// SameSite=Lax) and the proven teardown in handle_xaman_logout (3 domain scopes).
if ( ! headers_sent() ) {
    $imc_logout_opts = array(
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => '.imcollectibles.io',
        'secure'   => true,
        'httponly' => false,
        'samesite' => 'Lax',
    );
    foreach ( array( 'xrpl_account', 'xrpl_wallet_type' ) as $imc_ck ) {
        setcookie( $imc_ck, '', $imc_logout_opts );                                              // .imcollectibles.io
        setcookie( $imc_ck, '', array_merge( $imc_logout_opts, array( 'domain' => 'imcollectibles.io' ) ) ); // no leading dot
        setcookie( $imc_ck, '', array_merge( $imc_logout_opts, array( 'domain' => '' ) ) );                  // current domain
        unset( $_COOKIE[ $imc_ck ] );
    }
}

// Route into the canonical logout flow: ?logout=true fires joey_handle_logout +
// handle_xaman_logout (belt-and-braces re-clear) AND the joey enqueue teardown, which
// loads the wallet bundle and calls disconnect() to end the Joey WalletConnect session.
// That path terminates at /?loggedout=true. (Redirecting straight to /login/ skipped the
// WC teardown entirely.)
nocache_headers();
wp_safe_redirect( home_url( '/?logout=true' ) );
exit;