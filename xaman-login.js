/**
 * Xaman Login Handler - MOBILE DEEPLINK FIX v2
 * 
 * Key fix: Don't navigate original tab away when opening deeplink!
 * Use hidden iframe to trigger deeplink, keeps original page alive for polling.
 */

let currentUUID = null;
let pollInterval = null;

document.addEventListener('DOMContentLoaded', function() {
    const loginBtn = document.getElementById('xaman-login-btn');
    
    // Check if returning from Xaman (recovery)
    checkPendingLogin();
    
    if (loginBtn) {
        loginBtn.addEventListener('click', function() {
            console.log('Button clicked, fetching QR...');
            // Fix D (Aug 2026): clear ALL THREE domain scopes, matching page-logout.php's
            // proven teardown. The single domain-less delete below could never remove a
            // cookie set with domain=.imcollectibles.io, so a stale identity survived here.
            document.cookie = 'xrpl_account=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
            document.cookie = 'xrpl_account=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain=.imcollectibles.io';
            document.cookie = 'xrpl_account=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain=imcollectibles.io';
            sessionStorage.removeItem('pending_xaman_uuid');
            
            // Show loading
            loginBtn.disabled = true;
            loginBtn.innerHTML = 'Loading...';
            
            fetch('/xumm-proxy.php?signin=true')
                .then(response => {
                    if (!response.ok) throw new Error('Network error: ' + response.status);
                    return response.json();
                })
                .then(data => {
                    console.log('Signin response:', data);
                    const qrCodeDiv = document.getElementById('xaman-qr-code');
                    const instruction = document.getElementById('xaman-sign-instruction');
                    
                    if (data.error) {
                        loginBtn.disabled = false;
                        loginBtn.innerHTML = 'Connect Wallet';
                        qrCodeDiv.innerHTML = '<p style="color:#ff6b6b;">' + 
                            (data.error.includes('Max payloads') ? 'Login limit reached. Try again later.' : 'Login failed. Please try again.') + '</p>';
                        return;
                    }
                    
                    if (data.qr && data.uuid) {
                        currentUUID = data.uuid;
                        
                        // Store for recovery
                        sessionStorage.setItem('pending_xaman_uuid', data.uuid);
                        sessionStorage.setItem('pending_xaman_time', Date.now().toString());
                        
                        loginBtn.style.display = 'none';
                        
                        const isMobile = /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
                        
                        // Build UI - note: button instead of <a> tag!
                        qrCodeDiv.innerHTML = `
                            ${!isMobile ? `<img src="${data.qr}" alt="Scan with Xaman" style="max-width:200px;border-radius:12px;" /><br>` : ''}
                            <button type="button" id="open-xaman-btn" 
                                style="display:inline-block; margin-top:15px; padding:14px 28px; background:linear-gradient(135deg,#3052ff,#2040dd); color:white; border:none; border-radius:8px; font-weight:600; font-size:16px; cursor:pointer;">
                                ${isMobile ? '📱 Open Xaman Wallet' : '📱 Open in Xaman App'}
                            </button>
                            <p id="login-status" style="margin-top:15px; color:#888; font-size:14px;">
                                ${isMobile ? 'Tap button, sign in Xaman, then return here' : 'Scan QR or click button'}
                            </p>
                            <div id="login-spinner" style="display:none; margin-top:15px;">
                                <div style="width:24px;height:24px;border:3px solid #333;border-top-color:#d4af37;border-radius:50%;animation:spin 1s linear infinite;margin:0 auto;"></div>
                                <p style="color:#d4af37;margin-top:8px;">Waiting for signature...</p>
                            </div>
                            <style>@keyframes spin{to{transform:rotate(360deg)}}</style>
                        `;
                        
                        if (instruction) instruction.style.display = 'block';
                        
                        // IMPORTANT: Use button click handler, not <a href>
                        document.getElementById('open-xaman-btn').addEventListener('click', function() {
                            console.log('Opening Xaman deeplink...');
                            document.getElementById('login-spinner').style.display = 'block';
                            document.getElementById('login-status').textContent = 'Opening Xaman... Sign and return here.';
                            
                            // Method 1: Try hidden iframe first (doesn't navigate away)
                            openDeeplinkViaIframe(data.deeplink);
                            
                            // Method 2: Fallback - delayed location change
                            // If iframe didn't work, this will trigger after 500ms
                            setTimeout(function() {
                                // Only do this if user is still on this page
                                if (document.visibilityState === 'visible') {
                                    console.log('Iframe may not have worked, trying location...');
                                    // Use a temporary anchor with target blank
                                    var a = document.createElement('a');
                                    a.href = data.deeplink;
                                    a.target = '_blank';
                                    a.rel = 'noopener';
                                    a.click();
                                }
                            }, 800);
                        });
                        
                        // Start polling immediately
                        startPolling(currentUUID);
                        
                    } else {
                        loginBtn.disabled = false;
                        loginBtn.innerHTML = 'Connect Wallet';
                        qrCodeDiv.innerHTML = '<p style="color:#ff6b6b;">Failed to load. Please refresh.</p>';
                    }
                })
                .catch(error => {
                    console.error('Signin error:', error);
                    loginBtn.disabled = false;
                    loginBtn.innerHTML = 'Connect Wallet';
                    alert('Connection error. Please try again.');
                });
        });
    }
    
    // Resume polling when user returns to page
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible' && currentUUID) {
            console.log('Page visible, checking status...');
            checkSignInStatus(currentUUID);
            setTimeout(() => checkSignInStatus(currentUUID), 1500);
        }
    });
});

/**
 * Open deeplink via hidden iframe - doesn't navigate current page away
 */
function openDeeplinkViaIframe(deeplink) {
    console.log('Opening deeplink via iframe:', deeplink);
    
    // Remove any existing iframe
    var existing = document.getElementById('xaman-deeplink-iframe');
    if (existing) existing.remove();
    
    // Create hidden iframe
    var iframe = document.createElement('iframe');
    iframe.id = 'xaman-deeplink-iframe';
    iframe.style.cssText = 'display:none;width:0;height:0;border:none;position:absolute;';
    document.body.appendChild(iframe);
    
    // Set src to trigger deeplink
    try {
        iframe.src = deeplink;
    } catch(e) {
        console.log('Iframe deeplink error:', e);
    }
    
    // Clean up after 5 seconds
    setTimeout(function() {
        var el = document.getElementById('xaman-deeplink-iframe');
        if (el) el.remove();
    }, 5000);
}

/**
 * Check for pending login on page load (recovery mechanism)
 */
function checkPendingLogin() {
    var pendingUUID = sessionStorage.getItem('pending_xaman_uuid');
    var pendingTime = parseInt(sessionStorage.getItem('pending_xaman_time') || '0');
    
    if (pendingUUID && (Date.now() - pendingTime) < 300000) {
        console.log('Found pending login:', pendingUUID);
        currentUUID = pendingUUID;
        
        // Show waiting UI
        var qrDiv = document.getElementById('xaman-qr-code');
        var loginBtn = document.getElementById('xaman-login-btn');
        if (qrDiv && loginBtn) {
            loginBtn.style.display = 'none';
            qrDiv.innerHTML = `
                <div id="login-spinner" style="margin-top:15px;">
                    <div style="width:24px;height:24px;border:3px solid #333;border-top-color:#d4af37;border-radius:50%;animation:spin 1s linear infinite;margin:0 auto;"></div>
                    <p style="color:#d4af37;margin-top:8px;">Checking sign-in status...</p>
                </div>
                <p id="login-status" style="margin-top:10px;color:#888;font-size:14px;"></p>
                <style>@keyframes spin{to{transform:rotate(360deg)}}</style>
            `;
        }
        
        // Start checking
        checkSignInStatus(pendingUUID);
        startPolling(pendingUUID);
    }
}

/**
 * Start polling for signin status
 */
function startPolling(uuid) {
    if (pollInterval) clearInterval(pollInterval);
    
    console.log('Starting polling for:', uuid);
    pollInterval = setInterval(function() {
        checkSignInStatus(uuid);
    }, 2000);
    
    // Stop after 5 minutes
    setTimeout(function() {
        if (pollInterval) {
            clearInterval(pollInterval);
            console.log('Polling timeout');
            var status = document.getElementById('login-status');
            if (status) status.innerHTML = 'Timed out. <a href="#" onclick="location.reload()">Try again</a>';
        }
    }, 300000);
}

/**
 * Check signin status with server
 */
function checkSignInStatus(uuid) {
    if (!uuid) return;
    
    fetch('/xumm-proxy.php?check_uuid=' + uuid + '&t=' + Date.now())
        .then(function(r) { return r.json(); })
        .then(function(data) {
            console.log('Status check:', data);
            
            if (data.signed === true && data.account) {
                // SUCCESS!
                if (pollInterval) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                }
                
                sessionStorage.removeItem('pending_xaman_uuid');
                sessionStorage.removeItem('pending_xaman_time');
                
                // Fix D (Aug 2026): the client-side xrpl_account write was REMOVED here.
                // xumm-proxy.php sets xrpl_account server-side in this very poll response, on
                // the canonical '.imcollectibles.io' scope, so this line was redundant -- and
                // harmful: written from JS it had no domain attribute, i.e. HOST-ONLY, which
                // produced a second cookie of the same name competing with the server's.
                // It also fired for ANY signed payload carrying an account (the handler returns
                // signed:true for nft_offer types too), so an offer signature could set identity.
                // v684: mark the wallet type so the handlers (authoritative on this cookie since
                // v683) don't keep serving Joey txjson to a user who has switched to Xaman.
                // Canonical '.imcollectibles.io' scope on purpose -- matches joey-login.php's
                // writer, so there is only ever ONE xrpl_wallet_type cookie in the jar.
                document.cookie = 'xrpl_wallet_type=xaman; path=/; domain=.imcollectibles.io; max-age=' + (86400*30) + '; secure; SameSite=Lax';
                
                console.log('SUCCESS! Redirecting...');
                
                var status = document.getElementById('login-status');
                if (status) {
                    status.innerHTML = '✅ Signed in! Redirecting...';
                    status.style.color = '#4ade80';
                }
                
                setTimeout(function() {
                    window.location.href = data.redirect || '/';
                }, 500);
            }
        })
        .catch(function(e) {
            console.log('Status check error:', e);
        });
}