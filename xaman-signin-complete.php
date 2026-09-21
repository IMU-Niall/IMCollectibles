<?php
/**
 * xaman-signin-complete.php - MOBILE FIX v3
 * 
 * Handles redirect FROM Xaman after signing.
 * 
 * CRITICAL FIX: Xaman uses {id} placeholder, not {uuid}
 * So we accept both ?id= and ?uuid= parameters
 */

require_once __DIR__ . '/wp-load.php';

// Logging
$log_file = __DIR__ . '/logs/xumm-webhook.log';
if (!file_exists(dirname($log_file))) mkdir(dirname($log_file), 0755, true);

function logMsg($msg) {
    global $log_file;
    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "[signin-complete] " . $msg . "\n", FILE_APPEND);
}

$is_mobile = preg_match('/Mobi|Android|iPhone|iPad|iPod/i', $_SERVER['HTTP_USER_AGENT'] ?? '');

// Accept BOTH 'id' and 'uuid' parameters (Xaman sends 'id')
$uuid_raw = $_GET['id'] ?? $_GET['uuid'] ?? '';
$uuid = sanitize_text_field($uuid_raw);

logMsg("=== NEW REQUEST ===");
logMsg("URL: " . $_SERVER['REQUEST_URI']);
// SEC (20 Sep 2026): full $_GET dump removed -- it logged the Xaman payload UUID.
logMsg("GET params: " . (isset($_GET['id']) || isset($_GET['uuid']) ? '(payload id present)' : '(none)'));
logMsg("UUID (from id or uuid): '$uuid'");
logMsg("Mobile: " . ($is_mobile ? 'Yes' : 'No'));

$account = null;
$error = null;

// API credentials
$api_key = defined('XUMM_API_KEY') ? XUMM_API_KEY : '';
$api_secret = defined('XUMM_API_SECRET') ? XUMM_API_SECRET : '';

if (empty($api_key) || empty($api_secret)) {
    $error = 'Server configuration error';
    logMsg("ERROR: Missing API credentials");
} else {
    global $wpdb;
    $table_name = $wpdb->prefix . 'xumm_status';
    
    // Check if UUID is valid format
    $valid_uuid = !empty($uuid) && 
                  $uuid !== '{id}' &&
                  $uuid !== '{uuid}' && 
                  $uuid !== 'undefined' &&
                  strlen($uuid) > 30 &&
                  preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid);
    
    logMsg("UUID valid: " . ($valid_uuid ? 'Yes' : 'No'));
    
    if ($valid_uuid) {
        // Try database first
        $db_status = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE uuid = %s AND type = 'signin'", $uuid
        ), ARRAY_A);
        
        if ($db_status && $db_status['signed'] && $db_status['account']) {
            $account = $db_status['account'];
            $account_proof = 'uuid'; // 2a: DB row matched the exact uuid, webhook-verified
            $wpdb->update($table_name, ['claimed' => 1], ['uuid' => $uuid]);
            logMsg("SUCCESS from DB: $account");
        } else {
            // Check Xumm API with retries (give webhook time to fire)
            logMsg("Checking Xumm API...");
            
            for ($attempt = 1; $attempt <= 6; $attempt++) {
                logMsg("API attempt $attempt/6");
                
                $ch = curl_init("https://xumm.app/api/v1/platform/payload/$uuid");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => ['X-API-Key: ' . $api_key, 'X-API-Secret: ' . $api_secret],
                    CURLOPT_TIMEOUT => 15
                ]);
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                $data = json_decode($response, true);
                
                if ($http_code === 200 && isset($data['meta'])) {
                    $signed = !empty($data['meta']['signed']);
                    $resp_account = $data['response']['account'] ?? null;
                    
                    logMsg("API: signed=$signed, account=$resp_account");
                    
                    if ($signed && $resp_account) {
                        $account = $resp_account;
                        $account_proof = 'uuid'; // 2a: live API check, meta.signed === true
                        
                        // Save to database
                        $wpdb->replace($table_name, [
                            'uuid' => $uuid,
                            'account' => $account,
                            'signed' => 1,
                            'timestamp' => time(),
                            'claimed' => 1,
                            'type' => 'signin',
                            'redirect' => 'https://imcollectibles.io/'
                        ]);
                        
                        logMsg("SUCCESS from API: $account");
                        break;
                    }
                }
                
                // Wait before retry (webhook might still be processing)
                if ($attempt < 6) {
                    logMsg("Waiting 2s for webhook...");
                    sleep(2);
                }
            }
        }
    }
    
    // FALLBACK: Check for ANY recent unclaimed signin (within last 2 minutes)
    if (!$account) {
        logMsg("Primary lookup failed, checking recent signins...");
        
        $recent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name 
             WHERE type = 'signin' AND signed = 1 AND (claimed = 0 OR claimed IS NULL) 
             AND timestamp > %d 
             ORDER BY timestamp DESC LIMIT 1",
            time() - 120
        ), ARRAY_A);
        
        if ($recent && $recent['account']) {
            $account = $recent['account'];
            $account_proof = 'fallback'; // 2a: recent-unclaimed fallback - NEVER mint on this path
            $found_uuid = $recent['uuid'];
            logMsg("FALLBACK SUCCESS: $found_uuid -> $account");
            $wpdb->update($table_name, ['claimed' => 1], ['uuid' => $found_uuid]);
        } else {
            logMsg("No recent signins found");
            $error = 'Sign-in not completed. Please try again.';
        }
    }
}

// Set cookie if we have an account
if ($account) {
    logMsg("Setting cookies for: $account");
    
    // Session-auth 2a (Step 2, Aug 2026): mint the httponly HMAC session token,
    // but ONLY on uuid-matched proof paths. The 2-minute fallback must NOT mint:
    // two users signing in inside the same window plus one lost uuid could cross
    // accounts - a wrong soft cookie is a recoverable mixup, a wrong signed
    // token is not. Fallback users keep the soft cookie (dual-accept) and
    // collect a token on their next clean login.
    if (function_exists('imc_session_mint') && (isset($account_proof) && $account_proof === 'uuid')) {
        // Fix 3 (Aug 2026): CONSUME-ONCE TICKET for the ?xrpl_login= redirect belt.
        // This is the uuid-matched proof path (the ONLY path that mints), so it is
        // the correct place to vouch that this account was JUST proven. functions.php
        // honours ?xrpl_login=<account> only if this ticket exists, then deletes it -
        // so a shared / back-nav / restored ?xrpl_login URL finds no ticket and cannot
        // mint. Server-side, so it still works across the mobile cross-browser hop.
        set_transient('imc_xlogin_' . md5($account), 1, 300);
        if (imc_session_mint($account, 'xaman')) {
            logMsg("IMC-SESSION: token minted (uuid-matched proof)");
        } else {
            logMsg("IMC-SESSION: mint unavailable or failed (non-fatal)");
        }
    } else {
        logMsg("IMC-SESSION: token NOT minted (proof=" . (isset($account_proof) ? $account_proof : 'none') . ")");
    }
    
    // v281: Single canonical cookie with domain=.imcollectibles.io
    // Setting multiple cookies with different domain scopes causes document.cookie
    // to contain duplicate entries, breaking split-based getCookieValue() readers.
    setcookie('xrpl_account', $account, [
        'expires'  => time() + (86400 * 30),
        'path'     => '/',
        'domain'   => '.imcollectibles.io',
        'secure'   => true,
        'httponly' => false,
        'samesite' => 'Lax'
    ]);

    // v684: mark the wallet type on the Xaman side too. Since v683 the handlers read
    // xrpl_wallet_type as authoritative; joey-login.php was its only writer, so a user who
    // signed in with Joey and later switched to Xaman WITHOUT hitting a logout URL kept a
    // stale 'joey' marker for up to 30 days and was served Joey txjson they could not sign.
    // Params byte-identical to xrpl_account above (v281: single canonical domain scope).
    setcookie('xrpl_wallet_type', 'xaman', [
        'expires'  => time() + (86400 * 30),
        'path'     => '/',
        'domain'   => '.imcollectibles.io',
        'secure'   => true,
        'httponly' => false,
        'samesite' => 'Lax'
    ]);
}

logMsg("Final: account=" . ($account ?? 'NULL') . ", error=" . ($error ?? 'none'));
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $account ? 'Welcome!' : 'Sign-In'; ?> | IMCollectibles</title>
    <style>
        * { box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #0a0a0f 0%, #1a1a2e 100%);
            color: #fff; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            min-height: 100vh; 
            margin: 0;
            padding: 20px;
        }
        .box { 
            background: rgba(26,26,46,0.95); 
            border: 1px solid rgba(212,175,55,0.3); 
            border-radius: 16px; 
            padding: 40px; 
            text-align: center; 
            max-width: 400px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }
        .icon { font-size: 48px; margin-bottom: 15px; }
        h1 { color: #d4af37; font-size: 24px; margin: 0 0 15px 0; }
        p { color: #aaa; margin: 15px 0; line-height: 1.5; }
        .account { 
            background: rgba(212,175,55,0.1); 
            border: 1px solid rgba(212,175,55,0.3); 
            border-radius: 8px; 
            padding: 12px; 
            font-family: 'Courier New', monospace; 
            font-size: 11px; 
            word-break: break-all; 
            color: #d4af37; 
            margin: 20px 0;
        }
        .btn { 
            display: inline-block; 
            padding: 14px 32px; 
            background: linear-gradient(135deg, #d4af37 0%, #b8960c 100%); 
            color: #000; 
            text-decoration: none; 
            border-radius: 8px; 
            font-weight: 600;
            font-size: 16px;
            border: none;
            cursor: pointer;
        }
        .btn-secondary { 
            background: transparent; 
            border: 1px solid rgba(212,175,55,0.5); 
            color: #d4af37; 
            margin-top: 10px;
        }
        .spinner { 
            width: 32px; height: 32px; 
            border: 3px solid rgba(212,175,55,0.2); 
            border-top-color: #d4af37; 
            border-radius: 50%; 
            animation: spin 0.8s linear infinite; 
            margin: 20px auto;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .debug { font-size: 11px; color: #555; margin-top: 20px; }
    </style>
</head>
<body>
<div class="box">
<?php if ($account): ?>
    <div class="icon">✅</div>
    <h1>Welcome!</h1>
    <p>You're now signed in</p>
    <div class="account"><?php echo esc_html($account); ?></div>
    <div class="spinner" id="spinner"></div>
    <p id="status">Redirecting...</p>
    <a href="https://imcollectibles.io/" class="btn" id="btn" style="display:none;">Continue</a>
    
    <script>
        (function() {
            var account = '<?php echo esc_js($account); ?>';
            
            // Set cookies via JS (multiple formats for compatibility)
            // v281: Single canonical cookie write — duplicate domain variants break getCookieValue()
            document.cookie = 'xrpl_account=' + encodeURIComponent(account) + '; path=/; domain=.imcollectibles.io; max-age=' + (86400*30) + '; secure; SameSite=Lax';
            // v684: mirror the wallet-type marker (see the server-side setcookie above).
            document.cookie = 'xrpl_wallet_type=xaman; path=/; domain=.imcollectibles.io; max-age=' + (86400*30) + '; secure; SameSite=Lax';
            // v279: Also store in localStorage so any tab on the same origin can detect
            // the sign-in immediately without waiting for a page reload.
            try {
                localStorage.setItem('imc_xrpl_account', account);
                localStorage.setItem('imc_xrpl_login_ts', Date.now().toString());
            } catch(e) {}
            
            console.log('Cookies set for:', account);
            
            // Clear session storage from login page
            try { 
                sessionStorage.removeItem('pending_xaman_uuid'); 
                sessionStorage.removeItem('pending_xaman_time');
            } catch(e) {}
            
            // v279: Pass account in URL param as belt-and-suspenders for mobile browsers
            // where WKWebView/Chrome Custom Tab cookie isolation prevents Set-Cookie propagation.
            // functions.php picks up ?xrpl_login=ACCOUNT and sets the cookie server-side.
            setTimeout(function() {
                window.location.href = 'https://imcollectibles.io/?xrpl_login=' + encodeURIComponent(account);
            }, 1500);
            
            // Show button after 3s as fallback
            setTimeout(function() {
                document.getElementById('spinner').style.display = 'none';
                document.getElementById('btn').style.display = 'inline-block';
                document.getElementById('status').textContent = 'Click to continue';
            }, 3000);
        })();
    </script>
    
<?php else: ?>
    <div class="icon">⚠️</div>
    <h1 style="color:#ff6b6b;">Sign-In Issue</h1>
    <p><?php echo esc_html($error ?? 'Unable to complete sign-in.'); ?></p>
    <a href="https://imcollectibles.io/login/" class="btn">Try Again</a>
    <br>
    <a href="https://imcollectibles.io/" class="btn btn-secondary">Go to Homepage</a>
    <div class="debug">
        UUID received: <?php echo esc_html($uuid ?: 'none'); ?>
    </div>
<?php endif; ?>
</div>
</body>
</html>