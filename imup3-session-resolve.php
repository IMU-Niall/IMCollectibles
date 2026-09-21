<?php
/**
 * IMUP3 session resolve — bearer -> wallet  (S1h-1)
 * File: imup3-session-resolve.php
 * Path: /wp-content/themes/astra/xrpl-nft-marketplace/backend/imup3-session-resolve.php
 *
 * Cross-surface identity: another IMU surface forwards an IMUP3 bearer here, and
 * IMC answers with the wallet it belongs to. The reverse direction is handled by
 * the surface that issued the bearer.
 *
 * GET/POST, Authorization: Bearer <an HMAC-signed, server-minted token>
 *   200 {"success":true,"wallet":"r...","surface":"imup3","expires":1234567890}
 *   401 {"success":false,"error":"invalid_session"}
 *
 * SECURITY NOTES
 *   - Resolution is delegated entirely to imc_session_resolve_wallet() (S1f-1):
 *     three-part token, unexpired, 64-hex parts, constant-time HMAC, AND a live
 *     server row. Nothing is re-implemented here.
 *   - Per S1f-1/Q77 a Bearer resolves only a surface='imup3' row, so a captured
 *     web cookie value cannot be replayed here.
 *   - Public by design: the endpoint
 *     reveals nothing a holder of the bearer does not already have, and without a
 *     valid bearer it reveals nothing at all.
 *   - No writes. No user data. No market fields.
 */

// WordPress bootstrap — the proven form used by every handler in this directory.
$wp_load = dirname(__FILE__, 5) . '/wp-load.php';
if (!file_exists($wp_load)) {
    $wp_load = dirname(__FILE__, 6) . '/wp-load.php';
}
require_once $wp_load;

header('Content-Type: application/json; charset=utf-8');
// A session answer must never be cached by an intermediary.
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');

if (!function_exists('imc_session_resolve_wallet')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'session_layer_unavailable']);
    exit;
}

// imc_session_resolve_wallet() reads HTTP_AUTHORIZATION / REDIRECT_HTTP_AUTHORIZATION.
// Some SAPIs expose the header only via apache_request_headers(); normalise first so
// this endpoint behaves the same everywhere.
if (empty($_SERVER['HTTP_AUTHORIZATION']) && empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
    && function_exists('apache_request_headers')) {
    foreach ((array) apache_request_headers() as $k => $v) {
        if (strtolower($k) === 'authorization') {
            $_SERVER['HTTP_AUTHORIZATION'] = trim($v);
            break;
        }
    }
}

$wallet = imc_session_resolve_wallet();

if ($wallet === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'invalid_session']);
    exit;
}

// Echo back the surface and expiry so the caller can cache with confidence and
// never outlive the session it is describing.
$surface = 'imup3';
$expires = 0;
global $wpdb;
$hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $hdr, $m)) {
    $parts = explode('.', $m[1]);
    if (count($parts) === 3 && preg_match('/^[0-9a-f]{64}$/', $parts[1])) {
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT surface, expires FROM {$wpdb->prefix}imc_sessions WHERE token_hash = %s",
            hash('sha256', $parts[1])
        ), ARRAY_A);
        if ($row) {
            $surface = (string) ($row['surface'] ?? 'imup3');
            $expires = (int) ($row['expires'] ?? 0);
        }
    }
}

echo json_encode([
    'success' => true,
    'wallet'  => $wallet,
    'surface' => $surface,
    'expires' => $expires,
]);