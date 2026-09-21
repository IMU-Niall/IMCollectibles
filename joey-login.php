<?php
/**
 * Joey / WalletConnect login — Phase 8.2 FINAL (ADDITIVE, admin-gated; connect-only / single-tap)
 * ---------------------------------------------------------------------------
 * - Parallel [joey_login] shortcode. DOES NOT touch the Xaman flow.
 * - Kill-switches: IMC_JOEY_ENABLED (master), IMC_JOEY_PUBLIC_TEASER (public "coming soon").
 * - Admin gate: current_user_can('manage_options').
 * - Branding: Joey orange (#F06000) + js/joeywalletlogo.png.
 *
 * FLOW (2a-c PROOF-FIRST, Aug 2026):
 *   1) connect() establishes the WalletConnect session and returns the account.
 *   2) joey_login_challenge issues a server nonce; the wallet signs an AccountSet carrying it.
 *   3) joey_login_verify checks the tx on-ledger (validated + tesSUCCESS + Account + memo=nonce),
 *      then mints the httponly imc_session token and sets the identity cookies. Proof-first:
 *      connect-without-sign sets NOTHING. (The old proof-less joey_login_connect is RETIRED.)
 *
 * Master-file access is token-gated (imc_session, 2b); the soft xrpl_account cookie is only a
 * UI hint. Every value-action (buy/mint/list/tip) is signed per-transaction and re-verified
 * on-chain, so it cannot move/sell/mint anything without the real key at signing time.
 */

if (!defined('ABSPATH')) { exit; }

if (!defined('IMC_JOEY_ENABLED'))        { define('IMC_JOEY_ENABLED', true); }
if (!defined('IMC_JOEY_PUBLIC_TEASER'))  { define('IMC_JOEY_PUBLIC_TEASER', true); }

/** Clear the wallet-type marker on logout (parallel to handle_xaman_logout; functions.php untouched). */
add_action('init', 'joey_handle_logout', 5);
function joey_handle_logout() {
    $is_logout = (isset($_GET['logout']) && $_GET['logout'] === 'true')
              || (isset($_GET['loggedout']) && $_GET['loggedout'] === 'true');
    if ($is_logout && isset($_COOKIE['xrpl_wallet_type'])) {
        setcookie('xrpl_wallet_type', '', array(
            'expires' => time() - 3600, 'path' => '/', 'domain' => '.imcollectibles.io',
            'secure' => true, 'httponly' => false, 'samesite' => 'Lax'
        ));
    }
    // Session-auth 2a-b: tear down the httponly session token on Joey logout too.
    // Harmless today (Joey doesn't mint yet); correct once 2a-c ships Joey minting.
    if ($is_logout && function_exists('imc_session_clear')) { imc_session_clear(); }
}

/**
 * RETIRED (Aug 2026): joey_ajax_login_connect was the PRE-PROOF login path — it set the
 * identity cookies from a POSTed account with only a nonce + regex check, NO proof. It was
 * superseded by the 2a-c PROOF-FIRST flow (connect → joey_login_challenge → sign AccountSet
 * → joey_login_verify, which verifies on-ledger then mints an imc_session token). Nothing
 * calls this endpoint anymore (verified: zero .js / action= references). Its hooks are removed
 * so it is unreachable, and the body is neutered to a hard rejection as belt-and-braces: if it
 * is ever re-reached it grants NO identity and points the caller at the proof flow. Removing it
 * closes a public, proof-less cookie-setter that could otherwise plant a bogus xrpl_wallet_type.
 */
function joey_ajax_login_connect() {
    // Retired: proof-less identity is no longer permitted. Use the proof-first flow
    // (joey_login_challenge → sign → joey_login_verify). Grant nothing here.
    wp_send_json_error(array('reason' => 'retired', 'use' => 'proof-first login'), 410);
}

/**
 * Session persistence (Phase 8.3 — step 0): on transaction-capable pages, load the wallet bundle
 * and rehydrate the WalletConnect session so a logged-in Joey user is ready to sign. ADMIN-GATED
 * and cookie-gated — the public (and admins using Xaman) never load any of this. Additive only:
 * does NOT touch the Xaman marketplace enqueues or the trading scripts.
 *
 * Exposes window.__joeySession = { live:boolean, account:string|null, checked:true } for the
 * value-action flows (Buy, etc.) to consume. live=false with a joey cookie => session expired =>
 * the action flow re-pairs before signing (never a silent failure).
 */
add_action('wp_enqueue_scripts', 'joey_enqueue_wallet_on_tx_pages', 100);
function joey_enqueue_wallet_on_tx_pages() {
    if (!IMC_JOEY_ENABLED) { return; } // v555: Joey public (was admin-gated); still cookie-gated below (only joey sessions load the bundle)

    // (8.3 step 0b) Logout teardown: on a logout URL, terminate any lingering WalletConnect session
    // on the wallet side via disconnect() (sends WC sessionDelete to Joey). Cookie-independent — the
    // WC session in browser storage is the source of truth, so this works even as cookies clear.
    // Does NOT touch the Xaman shortcode's own client-side logout clearing.
    $joey_is_logout = (isset($_GET['logout']) && $_GET['logout'] === 'true')
                   || (isset($_GET['loggedout']) && $_GET['loggedout'] === 'true');
    if ($joey_is_logout) {
        $fs0  = __DIR__ . '/js/imu-wallet.bundle.js';
        $ver0 = file_exists($fs0) ? filemtime($fs0) : '0.2.0';
        wp_enqueue_script('imu-wallet', get_template_directory_uri() . '/js/imu-wallet.bundle.js', array(), $ver0, true);
        $teardown = <<<JS
(function(){
  function td(){
    if(!window.imuWallet){ return setTimeout(td,150); }
    window.imuWallet.reconnect().then(function(acct){
      if(acct){ return window.imuWallet.disconnect().then(function(){ try{console.log('[joey] logout: WC session terminated');}catch(e){} }); }
    }).catch(function(){});
  }
  if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', td); } else { td(); }
})();
JS;
        wp_add_inline_script('imu-wallet', $teardown, 'after');
        return;
    }

    // Load for ANY logged-in user (has a valid xrpl_account), not just xrpl_wallet_type=joey.
    // WHY (Aug 2026 heal fix): gating the bundle on the wallet_type cookie created a circular
    // trap — a Joey user whose xrpl_wallet_type went stale/wrong (e.g. an incomplete logout) got
    // no bundle, so window.imuWallet never existed, so reconnect() never ran, so the cookie could
    // never self-correct. The boot script below now uses reconnect() (a genuine live WC session)
    // as the source of truth and heals the cookie. Loading the bundle for a Xaman user is INERT
    // (pure module-def IIFE; no auto-connect, no cookie writes on load) and reconnect() returns
    // null for them (no WC session) so they are never mis-flagged as Joey. Precedent: the logout
    // teardown branch above already enqueues the bundle ungated.
    $xrpl_acct = function_exists('imc_session_wallet') ? imc_session_wallet() : '';
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $xrpl_acct)) { return; } // logged-out → load nothing
    // (8.3 step 0c) Cover ALL front-end signing surfaces — single-NFT, collections, creator profiles,
    // dashboard, main, burn-to-earn, stats, tasks, redeem, recent-mints, my-nfts, create-nft, frequency
    // fountain — including dynamic routes that are hard to detect precisely.
    // wp_enqueue_scripts only fires on normal front-end views (not AJAX/REST).

    $fs  = __DIR__ . '/js/imu-wallet.bundle.js';
    $ver = file_exists($fs) ? filemtime($fs) : '0.2.0';
    wp_enqueue_script('imu-wallet', get_template_directory_uri() . '/js/imu-wallet.bundle.js', array(), $ver, true);

    $boot = <<<JS
(function(){
  function boot(){
    if(!window.imuWallet){ return setTimeout(boot,150); }
    var m=document.cookie.match(/(?:^|;\\s*)xrpl_wallet_type=([^;]+)/);
    // Heal fix (Aug 2026): the LIVE WalletConnect session is the source of truth, NOT the
    // xrpl_wallet_type cookie. reconnect() returns an account ONLY for a genuine Joey WC
    // session; null for a Xaman user (no WC session). account => really Joey: mark live AND
    // correct xrpl_wallet_type if it drifted (breaks the circular trap). null => leave the
    // cookie untouched (never auto-switch a Xaman user to joey).
    window.imuWallet.reconnect().then(function(acct){
      if(acct){
        var cur=m?decodeURIComponent(m[1]):'';
        if(cur!=='joey'){
          try{ document.cookie='xrpl_wallet_type=joey; path=/; domain=.imcollectibles.io; secure; samesite=Lax; max-age='+(60*60*24*30); }catch(e){}
          try{ console.log('[joey] boot-heal: corrected xrpl_wallet_type -> joey'); }catch(e){}
        }
        window.__joeySession={live:true, account:acct, checked:true};
      } else {
        window.__joeySession={live:false, account:null, checked:true};
      }
      try{ console.log('[joey] boot-reconnect:', window.__joeySession); }catch(e){}
    }).catch(function(){ window.__joeySession={live:false,account:null,checked:true}; });
  }
  if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
JS;
    wp_add_inline_script('imu-wallet', $boot, 'after');

    // Phase 8.4 (additive): global Joey sign helper -- timeout/guidance + desktop QR + mobile deep-link.
    $joeysign = <<<'JS'
(function(){
  if (window.imuJoeySign) return;
  var MID='imu-joey-sign-modal';
  function el(){ return document.getElementById(MID); }
  function fmt(sec){ var m=Math.floor(sec/60), x=sec%60; return m+':'+(x<10?'0':'')+x; }
  function ensure(){
    var m=el(); if(m) return m;
    m=document.createElement('div'); m.id=MID;
    m.style.cssText='position:fixed;top:0;left:0;right:0;bottom:0;z-index:2147483647;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,0.65);';
    m.innerHTML='<div style="background:#15151d;color:#fff;max-width:340px;width:88%;border-radius:16px;padding:24px;text-align:center;font-family:inherit;box-shadow:0 12px 48px rgba(0,0,0,0.55);">'+
      '<div id="imu-joey-title" style="font-size:16px;font-weight:600;margin-bottom:8px;">Approve in Joey</div>'+
      '<div id="imu-joey-msg" style="font-size:13px;opacity:0.82;margin-bottom:12px;line-height:1.45;"></div>'+
      '<div id="imu-joey-fee" style="display:none;font-size:12px;font-weight:600;color:#f1c40f;margin-bottom:12px;letter-spacing:0.3px;">XRPL Network Fee Applies '+
        '<button id="imu-joey-info-btn" type="button" aria-label="What is this?" style="display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;margin-left:2px;padding:0;vertical-align:middle;background:transparent;border:1px solid #f1c40f;border-radius:50%;color:#f1c40f;font-size:11px;line-height:1;cursor:pointer;">i</button>'+
      '</div>'+
      '<div id="imu-joey-info" style="display:none;font-size:12px;opacity:0.85;margin-bottom:12px;line-height:1.5;text-align:left;background:rgba(241,196,15,0.08);border:1px solid rgba(241,196,15,0.25);border-radius:10px;padding:12px 14px;"></div>'+
      '<div id="imu-joey-count" style="font-size:22px;font-weight:700;letter-spacing:1px;margin-bottom:6px;font-variant-numeric:tabular-nums;">4:00</div>'+
      '<div id="imu-joey-nudge" style="display:none;font-size:12px;color:#f1c40f;margin-bottom:12px;line-height:1.4;">Still waiting \u2014 open Joey to approve, or Cancel and try again.</div>'+
      '<img id="imu-joey-qr" alt="Scan with Joey" style="display:none;width:220px;height:220px;background:#fff;border-radius:12px;padding:8px;margin:0 auto 14px;" />'+
      '<a id="imu-joey-open" href="#" rel="noopener" style="display:none;background:#6c5ce7;color:#fff;text-decoration:none;padding:11px 18px;border-radius:10px;font-size:14px;font-weight:600;">Open in Joey</a>'+
      '<div style="margin-top:14px;"><button id="imu-joey-cancel" type="button" style="background:transparent;border:1px solid rgba(255,255,255,0.22);color:#fff;padding:8px 16px;border-radius:10px;font-size:13px;cursor:pointer;">Cancel</button></div>'+
      '</div>';
    document.body.appendChild(m); return m;
  }
  function hide(){ var m=el(); if(m) m.style.display='none'; }
  var TIMEOUT_MS=240000, NUDGE_MS=90000;
  window.imuJoeySign=function(txjson, opts){
    var w=window.imuWallet;
    if(!w || typeof w.sign!=='function') return Promise.reject(new Error('Wallet not available'));
    opts=opts||{};
    var isMobile=/Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent||'');
    var origOpen=window.open;
    var m=ensure();
    var titleEl=m.querySelector('#imu-joey-title');
    var msg=m.querySelector('#imu-joey-msg');
    var countEl=m.querySelector('#imu-joey-count');
    var nudgeEl=m.querySelector('#imu-joey-nudge');
    var qr=m.querySelector('#imu-joey-qr');
    var openBtn=m.querySelector('#imu-joey-open');
    var cancelBtn=m.querySelector('#imu-joey-cancel');
    var feeEl=m.querySelector('#imu-joey-fee');
    var infoBtn=m.querySelector('#imu-joey-info-btn');
    var infoEl=m.querySelector('#imu-joey-info');
    qr.style.display='none'; openBtn.style.display='none'; nudgeEl.style.display='none';
    // Default (offers, etc.) -- unchanged. Login passes opts to relabel + reveal the fee/info.
    titleEl.textContent = opts.title || 'Approve in Joey';
    msg.textContent = opts.hint || 'Open your Joey wallet and approve this transaction.';
    if(infoEl){ infoEl.style.display='none'; }
    var onInfo=null;
    if(opts.info){
      if(feeEl){ feeEl.style.display='block'; }
      if(infoEl){ infoEl.textContent=opts.info; }
      if(infoBtn){ onInfo=function(){ if(infoEl){ infoEl.style.display=(infoEl.style.display==='none'?'block':'none'); } }; infoBtn.addEventListener('click', onInfo); }
    } else {
      if(feeEl){ feeEl.style.display='none'; }
    }
    countEl.textContent=fmt(Math.floor(TIMEOUT_MS/1000));
    m.style.display='flex';
    // v558: overlay is already at max z-index, but mint-on-demand modals escape into a higher
    // stacking context and cover the countdown -- hide any open .mod-modal while signing, restore on cleanup.
    var _imcHidden=[];
    try{ document.querySelectorAll('.mod-modal').forEach(function(md){ if(getComputedStyle(md).display!=='none'){ _imcHidden.push([md, md.style.display]); md.style.display='none'; } }); }catch(e){}
    var startTs=Date.now();
    var cancelReject, timeoutReject;
    var cancelP=new Promise(function(_,rej){ cancelReject=rej; });
    var timeoutP=new Promise(function(_,rej){ timeoutReject=rej; });
    function onCancel(){ try{ cancelReject(new Error('Cancelled')); }catch(e){} }
    cancelBtn.addEventListener('click', onCancel);
    var timer=setInterval(function(){
      var elapsed=Date.now()-startTs;
      var remain=Math.max(0, TIMEOUT_MS-elapsed);
      countEl.textContent=fmt(Math.ceil(remain/1000));
      if(elapsed>=NUDGE_MS){ nudgeEl.style.display='block'; }
      if(remain<=0){ clearInterval(timer); try{ timeoutReject(new Error('Timed out')); }catch(e){} }
    }, 1000);
    window.open=function(url,target,feat){
      try{
        if(typeof url==='string' && /[?&](requestId|sessionTopic)=/.test(url)){
          openBtn.href=url; openBtn.style.display='inline-block';
          if(isMobile){ return origOpen.call(window,url,target,feat); }
          msg.textContent='Scan this with Joey on your phone \u2014 or approve in your already-open wallet.';
          if(typeof w.toQRDataURL==='function'){
            Promise.resolve().then(function(){ return w.toQRDataURL(url); }).then(function(d){
              if(d && typeof d==='string' && d.indexOf('data:')===0){ qr.src=d; qr.style.display='block'; }
            }).catch(function(){});
          }
          return null;
        }
      }catch(e){}
      return origOpen.call(window,url,target,feat);
    };
    function cleanup(){ clearInterval(timer); window.open=origOpen; cancelBtn.removeEventListener('click', onCancel); if(onInfo && infoBtn){ infoBtn.removeEventListener('click', onInfo); } if(infoEl){ infoEl.style.display='none'; } hide(); try{ _imcHidden.forEach(function(p){ p[0].style.display=p[1]||''; }); }catch(e){} }
    return Promise.race([ w.sign(txjson), cancelP, timeoutP ])
      .then(function(res){ cleanup(); return res; })
      .catch(function(err){ cleanup(); throw err; });
  };
})();
JS;
    wp_add_inline_script('imu-wallet', $joeysign, 'after');
}

add_shortcode('joey_login', 'joey_login_shortcode');
function joey_login_shortcode() {
    if (!IMC_JOEY_ENABLED) { return ''; }

    $logo = get_template_directory_uri() . '/js/joeywalletlogo.png';

    if (false) { // v555: Joey is PUBLIC now -- connect UI renders for everyone; entry point is the hidden /joey-test page only (teaser branch retained, disabled).
        if (!IMC_JOEY_PUBLIC_TEASER) { return ''; }
        return '<div class="joey-login-container" style="text-align:center; padding:8px 20px;">'
             . '<button type="button" class="joey-btn-soon" '
             . 'onclick="var m=this.parentNode.querySelector(\'.joey-soon-msg\'); if(m){m.style.display=\'block\';}" '
             . 'style="display:inline-flex; align-items:center; justify-content:center; gap:9px; padding:11px 22px; background:#2a1a0e; color:#F06000; border:1px solid #F06000; border-radius:8px; cursor:pointer; font-weight:600; opacity:.9;">'
             . '<img src="' . esc_url($logo) . '" alt="Joey Wallet" style="width:22px; height:22px; border-radius:5px; display:block;" />'
             . '<span>Login with Joey &mdash; Coming Soon</span></button>'
             . '<p class="joey-soon-msg" style="display:none; margin-top:8px; color:#F06000; font-size:14px;">'
             . 'Joey Wallet login is coming soon. For now, please use Xaman above.</p>'
             . '</div>';
    }

    // ---- Admin only ----
    $bundle_fs  = __DIR__ . '/js/imu-wallet.bundle.js';
    $bundle_ver = file_exists($bundle_fs) ? filemtime($bundle_fs) : '0.2.0';
    wp_enqueue_script('imu-wallet', get_template_directory_uri() . '/js/imu-wallet.bundle.js', array(), $bundle_ver, true);

    $uid = uniqid();
    $btn = 'joey-login-btn-' . $uid;
    $qr  = 'joey-qr-' . $uid;
    $ins = 'joey-instr-' . $uid;
    $ajax = admin_url('admin-ajax.php');
    $wpnonce = wp_create_nonce('joey_login');

    $inline = <<<JS
(function(){
  var AJAX="{$ajax}", NONCE="{$wpnonce}";
  function wire(){
    var c=document.querySelector('.joey-login-container[data-instance-id="{$uid}"]');
    if(!c){ return; }
    var btn=c.querySelector('#{$btn}'), qr=c.querySelector('#{$qr}'), ins=c.querySelector('#{$ins}');
    if(!btn||!qr||!ins||!window.imuWallet){ return setTimeout(wire,150); }
    function renderQR(uri){
      window.imuWallet.toQRDataURL(uri).then(function(png){
        qr.innerHTML='<img src="'+png+'" alt="Scan with Joey" style="max-width:200px; border-radius:8px;" /><br>'+
          '<a href="joey://settings/wc?uri='+encodeURIComponent(uri)+'" style="display:inline-block;margin-top:10px;padding:10px 22px;background:#F06000;color:#fff;text-decoration:none;border-radius:8px;font-weight:700;">Open in Joey</a>';
      });
      ins.style.display='block'; ins.textContent='Scan with Joey, or tap "Open in Joey" on mobile.';
    }
    function form(obj){ var p=new URLSearchParams(); for(var k in obj){ p.set(k,obj[k]); } return p.toString(); }
    // v721 (session-auth 2a-c): PROOF-FIRST Joey login. connect() establishes the
    // WalletConnect session ONLY (no cookie). We then request a server nonce, sign a
    // no-op AccountSet carrying it, submit on-ledger, and the server verifies + mints
    // identity. Abandon before signing => no cookie, no token, simply not logged in.
    var OH='/wp-content/themes/astra/xrpl-nft-marketplace/backend/offer-handler.php';
    function toHex(s){ var h=''; for(var i=0;i<s.length;i++){ h+=('0'+s.charCodeAt(i).toString(16)).slice(-2); } return h.toUpperCase(); }
    btn.addEventListener('click', function(){
      btn.style.display='none'; ins.style.display='block'; ins.textContent='Starting Joey...';
      var ACCT=null;
      // Returning users already have a live WC session (boot-reconnect); reuse it.
      var pre=(window.__joeySession && window.__joeySession.live && window.__joeySession.account)
        ? Promise.resolve(window.__joeySession.account)
        : window.imuWallet.connect({ onUri: renderQR });
      pre.then(function(acct){
        ACCT=acct;
        ins.textContent='Requesting a one-time sign-in code...';
        return fetch(OH,{method:'POST',credentials:'include',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:form({action:'joey_login_challenge',account:acct})})
          .then(function(r){return r.json();});
      }).then(function(cj){
        if(!cj||!cj.success||!cj.nonce){ throw new Error((cj&&cj.error)||'could not start sign-in'); }
        ins.textContent='Awaiting Account Verification...';
        var tx={ TransactionType:'AccountSet', Account:ACCT, Memos:[{ Memo:{ MemoData: toHex(cj.nonce) } }] };
        var LOGIN_OPTS={
          title:'Awaiting Account Verification',
          hint:'Open your Joey wallet and approve this transaction.',
          info:'Account Verification: Joey will ask you to sign an empty Account Set transaction, acting as an on-chain message that proves this wallet is yours. It changes nothing about your account or settings \u2014 it\u2019s the wallet equivalent of a signature on a login form. XRPL network fees only.'
        };
        var signer=(window.imuJoeySign||window.imuWallet.sign);
        return signer(tx, LOGIN_OPTS).then(function(signed){
          var txHash=signed && (signed.hash || signed.tx_hash);
          if(!txHash){ throw new Error('No transaction hash returned from your wallet.'); }
          ins.textContent='Confirming your sign-in on-ledger...';
          return fetch(OH,{method:'POST',credentials:'include',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:form({action:'joey_login_verify',tx_hash:txHash,nonce:cj.nonce,account:ACCT})})
            .then(function(r){return r.json();});
        });
      }).then(function(vj){
        if(!vj||!vj.success){ throw new Error((vj&&vj.error)||'sign-in verification failed'); }
        ins.textContent='Welcome, '+vj.account+'. Redirecting...';
        window.location.href='https://imcollectibles.io/';
      }).catch(function(e){
        ins.textContent='Joey login failed: '+((e&&e.message)?e.message:e);
        btn.style.display='';
      });
    });
  }
  if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', wire); } else { wire(); }
})();
JS;
    wp_add_inline_script('imu-wallet', $inline, 'after');

    ob_start();
    ?>
    <div class="joey-login-container" data-instance-id="<?php echo esc_attr($uid); ?>" style="text-align:center; padding:8px 20px;">
        <button id="<?php echo esc_attr($btn); ?>" class="joey-btn"
            onmouseover="this.style.background='#FF6E0A';" onmouseout="this.style.background='#F06000';"
            style="display:inline-flex; align-items:center; justify-content:center; gap:10px; padding:11px 24px; background:#F06000; color:#fff; border:none; border-radius:8px; cursor:pointer; font-weight:700; font-size:15px; box-shadow:0 3px 10px rgba(240,96,0,.45); transition:background .15s;">
            <img src="<?php echo esc_url($logo); ?>" alt="Joey Wallet" style="width:24px; height:24px; border-radius:5px; display:block;" />
            <span>Login with Joey</span>
        </button>
        <div id="<?php echo esc_attr($qr); ?>" style="margin-top:15px;"></div>
        <p id="<?php echo esc_attr($ins); ?>" style="display:none; margin-top:10px; color:#fff;">Connect with Joey...</p>
    </div>
    <?php
    return ob_get_clean();
}


/**
 * v555: Hidden /joey-test entry page. Joey is PUBLIC now, but its sign-in is surfaced ONLY here
 * (commented out of the main /login during the soft launch). Renders the [joey_login] connect UI.
 * Master kill-switch: set IMC_JOEY_ENABLED=false to disable Joey AND this page (404) instantly.
 */
add_action('template_redirect', 'joey_test_page_route', 1);
function joey_test_page_route() {
    $path = strtolower(trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/'));
    if ($path !== 'joey-test') { return; }
    if (!defined('IMC_JOEY_ENABLED') || !IMC_JOEY_ENABLED) {
        status_header(404); nocache_headers();
        global $wp_query; if ($wp_query) { $wp_query->set_404(); }
        return; // fall through to the theme's normal 404 template
    }
    nocache_headers();
    $ui = do_shortcode('[joey_login]'); // registers/enqueues the imu-wallet bundle + inline connect script
    status_header(200);
    ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Joey Wallet &mdash; Test Sign-In</title>
    <?php wp_head(); ?>
</head>
<body style="margin:0; background:#0e0e10; color:#fff; font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
    <div style="max-width:520px; margin:0 auto; padding:56px 20px; text-align:center;">
        <h1 style="color:#F06000; font-size:24px; margin:0 0 8px;">Joey Wallet &mdash; Test Sign-In</h1>
        <p style="color:#bbb; font-size:15px; line-height:1.5; margin:0 0 24px;">Connect with Joey to test the marketplace. After signing in you&rsquo;ll return to the marketplace and can use Joey to sign across every flow.</p>
        <?php echo $ui; ?>
        <p style="margin-top:30px;"><a href="<?php echo esc_url(home_url('/')); ?>" style="color:#888; font-size:13px; text-decoration:none;">&larr; Back to marketplace</a></p>
    </div>
    <?php wp_footer(); ?>
</body>
</html>
<?php
    exit;
}