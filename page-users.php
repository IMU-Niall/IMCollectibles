<?php
/*
Template Name: User Directory
P2 (User Profiles, Aug 2026): /user (bare) now means MY profile.
Logged in -> redirect to /user/{slug-or-account}. Logged out -> login with return.
A browsable public directory ships at P5 alongside the /creators/ filter.
*/
$imc_ud_wallet = function_exists('imc_session_resolve_wallet') ? imc_session_resolve_wallet() : '';
if ($imc_ud_wallet !== '') {
    $imc_ud_target = $imc_ud_wallet;
    if (function_exists('imc_artist_profile_get')) {
        $imc_ud_row = imc_artist_profile_get($imc_ud_wallet);
        if (!empty($imc_ud_row['slug'])) { $imc_ud_target = $imc_ud_row['slug']; }
    }
    wp_safe_redirect(home_url('/user/' . rawurlencode($imc_ud_target) . '/'), 302);
    exit;
}
wp_safe_redirect(home_url('/login/?redirect=' . rawurlencode(home_url('/user/'))), 302);
exit;