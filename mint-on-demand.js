/**
 * ============================================================================
 * FILE: mint-on-demand.js
 * PATH: /wp-content/themes/astra/xrpl-nft-marketplace/frontend/mint-on-demand.js
 * ============================================================================
 *
 * Mint-on-Demand System v3.0 — Phase 4: Multi-Mint + Server-Side Minting
 *
 * Flow:  RESERVE → PAY → MINT → CLAIM (one-by-one)
 *
 * This file handles:
 * 1. Creating listings (artist side) — unchanged
 * 2. Browsing listings (marketplace)
 * 3. Purchasing: quantity selector → reserve → pay artist → server mints → claim
 * 4. Multi-claim UI (list pending NFTs, claim individually via Xaman)
 * 5. Purchase history with unclaimed badge
 *
 * @version 3.0.0 — Phase 4
 */

(function() {
    'use strict';

    // -- v590 mobile UX (additive): same-tab Xaman for MINT + CLAIM (Leg 1 only; return_urls unchanged) --
    const modIsMobileUA = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent || '');

    // =========================================================================
    // CONFIGURATION
    // =========================================================================

    const _rawConfig = window.MINT_CONFIG || window.MOD_CONFIG || {};

    // v280: Live cookie fallback for CONFIG.account.
    // If the page was PHP-rendered without a wallet (stale tab, mobile redirect timing),
    // CONFIG.account was '' and every mint action would fail with "Please connect wallet".
    // By reading the cookie at call-time we survive all tab/reload/redirect scenarios
    // without requiring a page refresh.
    function _getCookieAccount() {
        const match = document.cookie.split('; ')
            .find(r => r.startsWith('xrpl_account='));
        if (!match) return '';
        const val = match.split('=')[1];
        return (val && /^r[1-9A-HJ-NP-Za-km-z]{25,34}$/.test(val)) ? val : '';
    }

    const CONFIG = new Proxy(_rawConfig, {
        get(target, prop) {
            // Always read xrpl_account from the live cookie so stale page renders
            // don't permanently block mint/retry actions.
            if (prop === 'account') {
                return target.account || _getCookieAccount();
            }
            return target[prop];
        }
    });

    // 4j (4 Sep 2026): buyer-facing support targets + failure copy. Single source of truth --
    // change these strings here, nowhere else.
    // 4j-3 (5 Sep 2026): IMC_SUPPORT_URL was '/support/', a page that DOES NOT EXIST YET -- so the
    // 'Contact Support' button on the permanent-failure panel 404'd, which is worse than no button
    // at all on the one screen whose entire job is reassurance. Pointed at the mailbox until that
    // page ships; when it does, this is a one-line revert to '/support/'.
    // Email and Discord are deliberately BOTH offered: not everyone will join a Discord to report
    // a problem, and a mint failure is exactly when a second channel matters.
    const IMC_SUPPORT_EMAIL = 'Support@imcollectibles.io';
    const IMC_SUPPORT_URL   = 'mailto:' + IMC_SUPPORT_EMAIL + '?subject=' + encodeURIComponent('Mint issue');
    const IMC_DISCORD_URL   = 'https://discord.gg/jRt9kZBZSn';

    const ENDPOINTS = {
        listings:     CONFIG.endpoints?.listings     || '/wp-content/themes/astra/xrpl-nft-marketplace/backend/listings-handler.php',
        mintOnDemand: CONFIG.endpoints?.mintOnDemand || '/wp-content/themes/astra/xrpl-nft-marketplace/backend/mint-on-demand-handler.php'
    };

    // v284: Check if we need to resume a payment poll (iOS Xaman return)
    resumePaymentPollIfNeeded();

    // =========================================================================
    // STATE
    // =========================================================================

    const modState = {
        // Current listing being viewed
        currentListing: null,

        // Current purchase group in progress
        currentGroup: null,

        // Polling intervals
        paymentPollInterval: null,
        claimPollInterval:   null,
        feePollInterval:     null,
        mintPollInterval:    null,

        // Pending listing (during fee payment)
        pendingListing: null,

        // Guards
        listingCompleted:    false,
        publishInProgress:   false,
        purchaseInProgress:  false,

        // v886 (Defect AM): one-shot latch for the payment-poll -> processGroup handoff.
        // Scoped to a single polling session: set false by startPaymentPolling, set true by
        // the first callback that sees a confirmed payment. Deliberately NOT consulted by the
        // free-mint / Joey / retry paths, so it can never block a legitimate manual retry.
        mintDispatched:      false
    };

    // =========================================================================
    // UTILITY FUNCTIONS
    // =========================================================================

    function formatXRP(amount) {
        return parseFloat(amount).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 6
        });
    }

    function truncateAddress(address, start = 6, end = 4) {
        if (!address || address.length < start + end) return address;
        return `${address.slice(0, start)}...${address.slice(-end)}`;
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function stringToHex(str) {
        return Array.from(str).map(c => c.charCodeAt(0).toString(16).padStart(2, '0')).join('').toUpperCase();
    }

    function showToast(message, type = 'info') {
        let container = document.getElementById('mod-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'mod-toast-container';
            container.className = 'mod-toast-container';
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.className = `mod-toast mod-toast-${type}`;
        toast.innerHTML = `
            <span class="mod-toast-icon">${type === 'success' ? '✅' : type === 'error' ? '❌' : 'ℹ️'}</span>
            <span class="mod-toast-message">${message}</span>
        `;

        container.appendChild(toast);
        setTimeout(() => {
            toast.classList.add('mod-toast-fade');
            setTimeout(() => toast.remove(), 300);
        }, 5000);
    }


    // =========================================================================
    // ARTIST: CREATE LISTING (unchanged from v2)
    // =========================================================================

    // v210: Refresh WP nonce before creating listing
    async function refreshNonce() {
        try {
            const resp = await fetch(ENDPOINTS.listings + '?action=refresh_nonce', { credentials: 'same-origin' });
            const data = await resp.json();
            if (data.success && data.data && data.data.nonce) {
                CONFIG.nonce = data.data.nonce;
                return true;
            }
        } catch (e) { console.warn('Nonce refresh failed:', e); }
        return false;
    }

    async function createListing(listingData) {
        try {
            const formData = new FormData();
            formData.append('action', 'create');
            formData.append('nonce', CONFIG.nonce);
            formData.append('artist_account', CONFIG.account);

            Object.entries(listingData).forEach(([key, value]) => {
                if (value !== null && value !== undefined) {
                    formData.append(key, typeof value === 'object' ? JSON.stringify(value) : value);
                }
            });

            // v212: Timeout for listing creation (30s)
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 30000);
            
            const response = await fetch(ENDPOINTS.listings, { 
                method: 'POST', 
                body: formData,
                signal: controller.signal 
            });
            clearTimeout(timeoutId);
            
            const data = await response.json();
            if (!data.success) throw new Error(data.error || 'Failed to create listing');
            return data.data;
        } catch (error) {
            console.error('Create listing error:', error);
            if (error.name === 'AbortError') {
                throw new Error('Listing creation timed out. Please try again.');
            }
            throw error;
        }
    }

    function showFeePaymentModal(listingData) {
        if (modState.listingCompleted) {
            showToast('You already have an active listing. Please refresh to create another.', 'info');
            return;
        }

        const modal = document.getElementById('mod-fee-modal') || createFeeModal();
        const feeAmount   = document.getElementById('mod-fee-amount');
        const listingName = document.getElementById('mod-listing-name');
        const payBtn      = document.getElementById('mod-pay-fee-btn');
        const xummSection = document.querySelector('.mod-modal-xumm');

        if (payBtn)      { payBtn.style.display = 'block'; payBtn.disabled = false; payBtn.textContent = '💳 Pay Fee with XUMM'; }
        if (xummSection) { xummSection.style.display = 'none'; }
        if (feeAmount)   feeAmount.textContent = `${formatXRP(listingData.platform_fee_xrp)} XRP`;
        if (listingName) listingName.textContent = listingData.nft_name || 'Your Listing';

        modState.pendingListing = listingData;
        payBtn?.addEventListener('click', () => initiateFeePay(listingData), { once: true });
        modal.style.display = 'flex';
    }

    function createFeeModal() {
        const modal = document.createElement('div');
        modal.id = 'mod-fee-modal';
        modal.className = 'mod-modal';
        modal.innerHTML = `
            <div class="mod-modal-content">
                <button class="mod-modal-close" onclick="document.getElementById('mod-fee-modal').style.display='none'">×</button>
                <div class="mod-modal-header">
                    <span class="mod-modal-icon">💰</span>
                    <h3>Pay Platform Fee</h3>
                </div>
                <div class="mod-modal-body">
                    <p>Pay the platform fee to publish your listing:</p>
                    <div class="mod-fee-summary">
                        <div class="mod-fee-item"><span>Listing:</span><span id="mod-listing-name">-</span></div>
                        <div class="mod-fee-item mod-fee-total"><span>Platform Fee:</span><span id="mod-fee-amount">-</span></div>
                    </div>
                    <p class="mod-fee-note">This one-time fee covers listing on the marketplace. NFTs are minted on-demand when purchased.</p>
                    <button id="mod-pay-fee-btn" class="mod-btn mod-btn-primary mod-btn-block">💳 Pay Fee with XUMM</button>
                </div>
                <div class="mod-modal-xumm" style="display:none;">
                    <div class="mod-qr-container"><img id="mod-fee-qr" src="" alt="Scan with Xaman" /></div>
                    <a id="mod-fee-deeplink" href="#" target="_blank" class="mod-btn mod-btn-secondary mod-btn-block">Open in Xaman</a>
                    <div id="mod-fee-status" class="mod-poll-status"><div class="mod-spinner"></div><span>Waiting for payment...</span></div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        return modal;
    }

    async function initiateFeePay(listingData) {
        const payBtn = document.getElementById('mod-pay-fee-btn');
        const xummSection = document.querySelector('.mod-modal-xumm');

        payBtn.disabled = true;
        payBtn.textContent = 'Creating payment...';

        try {
            // --- Joey branch (v552, additive): sign the listing fee locally, then call the
            // EXISTING publishListing (publish action is tx_hash-verified + replay-protected).
            if (window.__joeySession && window.__joeySession.live && window.imuWallet) {
                if (CONFIG.account !== window.__joeySession.account) {
                    showToast('Connected wallet does not match your account. Please reconnect.', 'error');
                    payBtn.disabled = false; payBtn.textContent = '💳 Pay Fee with XUMM'; return;
                }
                const jfd = new FormData();
                jfd.append('action', 'create_fee_payment');
                jfd.append('nonce', CONFIG.nonce);
                jfd.append('listing_id', listingData.listing_id);
                jfd.append('artist_account', CONFIG.account);
                jfd.append('wallet', 'joey');
                const jres = await fetch(ENDPOINTS.mintOnDemand, { method: 'POST', body: jfd });
                const jd = JSON.parse(await jres.text());
                if (!jd.success || !jd.data || !jd.data.txjson) throw new Error(jd.error || 'Failed to prepare fee payment');
                payBtn.textContent = 'Check your wallet to sign...';
                let jsigned;
                try { jsigned = await (window.imuJoeySign||window.imuWallet.sign)(jd.data.txjson); }
                catch (e) { const em=(e&&e.message)||''; showToast(/timed out|timeout/i.test(em)?'Signing timed out. Please try again.':/cancel/i.test(em)?'Payment cancelled.':'Payment rejected.', 'error'); payBtn.disabled=false; payBtn.textContent='💳 Pay Fee with XUMM'; return; }
                const jtx = jsigned && (jsigned.hash || jsigned.tx_hash);
                if (!jtx) { showToast('No transaction hash returned from your wallet.', 'error'); payBtn.disabled=false; payBtn.textContent='💳 Pay Fee with XUMM'; return; }
                await publishListing(listingData.listing_id, jtx);
                return;
            }

            const formData = new FormData();
            formData.append('action', 'create_fee_payment');
            formData.append('nonce', CONFIG.nonce);
            formData.append('listing_id', listingData.listing_id);
            formData.append('artist_account', CONFIG.account);

            const response = await fetch(ENDPOINTS.mintOnDemand, { method: 'POST', body: formData });
            const responseText = await response.text();
            if (!responseText) throw new Error('Empty response from server');

            const result = JSON.parse(responseText);
            if (!result.success) throw new Error(result.error || 'Failed to create payment');

            // v684: since v683 the SERVER decides the wallet from the xrpl_wallet_type cookie, so a
            // joey-cookie user whose WalletConnect session isn't live lands here and gets a Joey
            // txjson -- no qr_png, no uuid -- which would hide the pay button and start polling an
            // undefined uuid. Throw into the existing catch, which restores the button.
            if (result.data?.wallet === 'joey' && result.data?.txjson) {
                throw new Error('Wallet still connecting — please try again in a moment.');
            }

            payBtn.style.display = 'none';
            xummSection.style.display = 'block';

            const qrImg = document.getElementById('mod-fee-qr');
            const deeplink = document.getElementById('mod-fee-deeplink');
            if (qrImg && result.data?.qr_png) qrImg.src = result.data.qr_png;
            if (deeplink && result.data?.deeplink) deeplink.href = result.data.deeplink;
            if (modIsMobileUA && deeplink) deeplink.setAttribute('target', '_self'); // v590 mobile: same-tab Xaman (listing fee) -- server recovery (get_by_artist) guarantees publish even if the tab is abandoned

            startFeePolling(result.data.uuid, listingData.listing_id);
        } catch (error) {
            console.error('Fee payment error:', error);
            showToast('Failed to create payment: ' + error.message, 'error');
            payBtn.disabled = false;
            payBtn.textContent = '💳 Pay Fee with XUMM';
        }
    }

    function startFeePolling(uuid, listingId) {
        stopFeePolling();
        const statusEl = document.getElementById('mod-fee-status');

        modState.feePollInterval = setInterval(async () => {
            try {
                const response = await fetch(`${ENDPOINTS.mintOnDemand}?action=poll_payment&uuid=${uuid}`);
                const data = await response.json();

                if (data.data?.rejected) {
                    stopFeePolling();
                    if (statusEl) statusEl.innerHTML = '<span style="color:#ef4444;">❌ Payment rejected</span>';
                    return;
                }
                if (data.data?.expired) {
                    stopFeePolling();
                    if (statusEl) statusEl.innerHTML = '<span style="color:#ef4444;">⏰ Payment expired. Please try again.</span>';
                    return;
                }
                if (data.data?.signed && !data.data?.dispatched_result) {
                    if (statusEl) statusEl.innerHTML = '<div class="mod-spinner"></div><span>Signed! Waiting for blockchain confirmation...</span>';
                    return;
                }
                if (data.data?.signed && data.data?.dispatched_result === 'tesSUCCESS' && data.data?.tx_hash) {
                    if (modState.publishInProgress) return;
                    modState.publishInProgress = true;
                    stopFeePolling();
                    if (statusEl) statusEl.innerHTML = '<span style="color:#10b981;">✅ Payment confirmed!</span>';
                    await publishListing(listingId, data.data.tx_hash);
                    return;
                }
                if (data.data?.signed && data.data?.dispatched_result && data.data?.dispatched_result !== 'tesSUCCESS') {
                    stopFeePolling();
                    let errorMessage = getXrplErrorMessage(data.data.dispatched_result);
                    if (statusEl) statusEl.innerHTML = `<span style="color:#ef4444;">❌ ${errorMessage}</span>`;
                    showToast(`Payment failed: ${errorMessage}`, 'error');
                    return;
                }
            } catch (error) {
                console.error('Fee poll error:', error);
            }
        }, 3000);

        setTimeout(stopFeePolling, 30 * 60 * 1000);
    }

    function stopFeePolling() {
        if (modState.feePollInterval) { clearInterval(modState.feePollInterval); modState.feePollInterval = null; }
    }

    async function publishListing(listingId, txHash) {
        try {
            const formData = new FormData();
            formData.append('action', 'publish');
            formData.append('nonce', CONFIG.nonce);
            formData.append('listing_id', listingId);
            formData.append('artist_account', CONFIG.account);
            formData.append('tx_hash', txHash);

            const response = await fetch(ENDPOINTS.listings, { method: 'POST', body: formData });
            const data = await response.json();
            if (!data.success) throw new Error(data.error || 'Failed to publish listing');

            modState.listingCompleted = true;
            showToast('🎉 Your listing is now live! Redirecting to dashboard...', 'success');

            const modal = document.getElementById('mod-fee-modal');
            if (modal) modal.style.display = 'none';

            const mintForm = document.querySelector('.mint-wizard, .mod-mint-form, form');
            if (mintForm) { mintForm.style.opacity = '0.5'; mintForm.style.pointerEvents = 'none'; }

            // v43: Redirect to creator dashboard so artist can review their listing
            setTimeout(() => { window.location.href = '/creator-dashboard/?listing_published=1'; }, 2000);
        } catch (error) {
            console.error('Publish error:', error);
            showToast('Failed to publish: ' + error.message, 'error');
        }
    }


    // =========================================================================
    // BUYER: PURCHASE FLOW — RESERVE → PAY → MINT → CLAIM
    // =========================================================================

    /**
     * Get human-readable XRPL error message
     */
    function getXrplErrorMessage(code) {
        const messages = {
            'tecUNFUNDED_PAYMENT': 'Insufficient XRP balance',
            'tecNO_DST':          'Destination account not found',
            'tefPAST_SEQ':        'Transaction expired — please try again',
            'tecNO_PERMISSION':   'Permission denied',
            'tecOBJECT_NOT_FOUND': 'Offer not found — may have been cancelled'
        };
        return messages[code] || `Transaction failed (${code})`;
    }

    /**
     * Show purchase modal with quantity selector (Task #14)
     */
    async function showPurchaseModal(listingId) {
        if (!CONFIG.account) {
            showToast('Please connect your wallet first', 'error');
            return;
        }

        if (modState.purchaseInProgress) {
            showToast('A purchase is already in progress', 'info');
            return;
        }

        try {
            // v76: Fetch fresh listing data with buyer info for mint limits
            const response = await fetch(`${ENDPOINTS.mintOnDemand}?action=get_listing&listing_id=${listingId}&buyer=${CONFIG.account}`);
            const data = await response.json();
            if (!data.success) throw new Error(data.error || 'Failed to load listing');

            const listing = data.data.listing;
            modState.currentListing = listing;
            ppStartPoll(); // PP-2: 10s visible-tab soft-poll (no-op unless progressive)

            // Self-purchase check (Task #16)
            if (listing.artist_account === CONFIG.account) {
                showToast('You cannot purchase your own listing', 'error');
                return;
            }

            // Check for unclaimed NFTs (Task #12)
            const unclaimedResp = await fetch(
                `${ENDPOINTS.mintOnDemand}?action=check_unclaimed&account=${CONFIG.account}&taxon=${listing.collection_taxon}&artist=${listing.artist_account}`
            );
            const unclaimedData = await unclaimedResp.json();
            if (unclaimedData.success && unclaimedData.data.unclaimed_count > 0) {
                showToast(`You have ${unclaimedData.data.unclaimed_count} unclaimed NFT(s) from this collection. Please claim them first.`, 'error');
                return;
            }

            // v76: Check mint limit
            if (listing.mint_limit_enabled && listing.buyer_remaining_limit !== undefined && listing.buyer_remaining_limit <= 0) {
                showToast(`You've reached the mint limit of ${listing.mint_limit_per_wallet} for this listing`, 'error');
                return;
            }

            let available = parseInt(listing.available_editions || (listing.total_editions - listing.minted_count - (listing.reserved_count || 0)));
            
            // v76: Cap available by mint limit
            if (listing.mint_limit_enabled && listing.buyer_remaining_limit !== undefined) {
                available = Math.min(available, listing.buyer_remaining_limit);
            }
            
            let price = parseFloat(listing.price_xrp) || 0;
            // v459: When price_xrp=0, check accepted_currencies for real XRP price
            // (matches v448 fix in collections.php — multi-currency listings store price in JSON)
            if (price === 0 && Array.isArray(listing.accepted_currencies)) {
                const xrpEntry = listing.accepted_currencies.find(c => c.currency === 'XRP' && c.enabled !== false && parseFloat(c.price || 0) > 0);
                if (xrpEntry) price = parseFloat(xrpEntry.price);
            }
            let maxQty = Math.min(available, 10);

            if (available <= 0) {
                showToast('All editions sold out', 'error');
                return;
            }

            // Build modal
            const modal = document.getElementById('mod-purchase-modal') || createPurchaseModal();
            resetPurchaseModal();

            // v70: Check for allowlist benefits (set by collections.php mintFromListing)
            // v505 Phase B: benefits are tagged with the listing they were fetched for.
            // A mismatch (or stale data via the direct showPurchaseModal path) means they
            // belong to another drop — ignore them. Display-only: the server recomputes.
            const _ab = window.currentAllowlistBenefits || {};
            const benefits = (_ab.__listingId !== undefined && String(_ab.__listingId) !== String(listingId)) ? {} : _ab;
            // Consume-once: each fetch is used by exactly one modal open; a re-open via
            // mintFromListing refetches, and the direct path can never see stale data.
            window.currentAllowlistBenefits = null;
            let displayPrice = price;
            let originalPrice = price;
            let discountInfo = '';
            
            if (benefits.custom_price !== null && benefits.custom_price !== undefined) {
                displayPrice = parseFloat(benefits.custom_price);
                discountInfo = `<span class="mod-discount-badge">VIP Price!</span>`;
            } else if (benefits.discount_percent > 0) {
                displayPrice = price * (1 - benefits.discount_percent / 100);
                discountInfo = `<span class="mod-discount-badge">${benefits.discount_percent}% OFF</span>`;
            }
            
            // Store both original and display price for backend validation
            modState.originalPrice = originalPrice;
            modState.displayPrice = displayPrice;
            
            // v76: Store pricing mode for checkout
            modState.pricingMode = listing.pricing_mode || 'static';
            modState.priceUsd = listing.price_usd || null;
            modState.allowlistBenefits = benefits;

            // v382: Holder discount quantity enforcement
            const discountRemaining = (benefits.discount_allocation > 0 && benefits.discount_remaining !== undefined)
                ? parseInt(benefits.discount_remaining) : null;
            
            // v382: If allocation exhausted during early access, block minting entirely
            if (benefits.early_access_blocked) {
                const launchMsg = benefits.launch_at_display
                    ? `Public minting opens at ${benefits.launch_at_display}`
                    : 'Public minting has not started yet';
                showToast(`You've used all your early access discounted mints. ${launchMsg}`, 'error');
                return;
            }
            
            // Cap quantity selector to discount remaining when holder discount is active
            if (discountRemaining !== null && discountRemaining > 0) {
                maxQty = Math.min(maxQty, discountRemaining);
            }

            // Populate quantity selector content
            document.getElementById('mod-listing-title').textContent = listing.nft_name || 'NFT';
            document.getElementById('mod-listing-artist-name').textContent = listing.artist_name || truncateAddress(listing.artist_account);
            
            // v82: For dynamic pricing, show calculating state first
            const pricingMode = listing.pricing_mode || 'static';
            const priceUsd = listing.price_usd ? parseFloat(listing.price_usd) : null;
            const priceEl = document.getElementById('mod-price-each');
            const totalEl = document.getElementById('mod-total-price');
            
            if (pricingMode === 'pwyw') {
                // v660: PAY WHAT YOU WANT -- buyer names their price. The floor (per selected
                // currency) is shown as a note; the on-chain floor check is enforced server-side.
                modState.pwywAmount = '';
                const _fcur = modState.selectedCurrency?.currency || 'XRP';
                const _fval = parseFloat(modState.selectedCurrency?.price ?? displayPrice) || 0;
                // Task H: the v385 block above has ALREADY mirrored the wallet's allowlist
                // benefit into selectedCurrency.price / displayPrice (custom, matrix,
                // proportional, percent — the full server chain), so _fval IS the
                // benefit-aware floor. Re-discounting here would double-apply. The only
                // addition: the free-claim note when an allowlist FREE benefit (cp=0)
                // zeroes the floor under the server flag — option (a) tip flow.
                const _ffree = !!(listing.allowlist_pwyw && _fval === 0
                    && benefits && benefits.custom_price !== null && benefits.custom_price !== undefined
                    && parseFloat(benefits.custom_price) === 0);
                const _fnote = _ffree
                    ? 'Free for you 🎁 — enter 0 to claim, or tip what you like.'
                    : 'Minimum: ' + formatPrice(_fval) + ' ' + _fcur + ' — pay what you want at or above this.';
                priceEl.innerHTML = '<input id="mod-pwyw-amount" type="number" min="0" step="0.000001" placeholder="Your amount" style="width:140px;padding:7px 10px;border-radius:6px;border:1px solid rgba(212,175,55,0.45);background:rgba(0,0,0,0.25);color:inherit;font-size:1em;">'
                    + '<div id="mod-pwyw-floor-note" style="font-size:0.82em;opacity:0.78;margin-top:5px;">' + _fnote + '</div>';
                totalEl.textContent = '— ' + _fcur;
                const _pin = document.getElementById('mod-pwyw-amount');
                if (_pin) _pin.addEventListener('input', () => {
                    modState.pwywAmount = _pin.value;
                    updateTotalDisplay(parseInt(document.getElementById('mod-qty-value')?.textContent || '1'));
                });
            } else if (pricingMode === 'dynamic' && priceUsd) {
                // Show calculating state initially
                priceEl.innerHTML = '<span class="mod-calculating">⏳ Calculating live prices...</span>';
                totalEl.innerHTML = '<span class="mod-calculating">⏳</span>';
            } else if (pricingMode === 'free') {
                // v668: explicit Free Mint -- read "Free" rather than "0.00 XRP", matching
                // how free listings already display on cards.
                priceEl.textContent = 'Free';
                totalEl.textContent = 'Free';
            } else {
                // v70: Show discounted price with original price strikethrough if applicable
                if (displayPrice < originalPrice) {
                    priceEl.innerHTML = `<span class="mod-original-price">${formatXRP(originalPrice)} XRP</span> ${formatXRP(displayPrice)} XRP ${discountInfo}`;
                } else {
                    priceEl.textContent = `${formatXRP(price)} XRP`;
                }
                totalEl.textContent = `${formatXRP(displayPrice)} XRP`;
            }
            
            // v393: OE shows "Open Edition" instead of "999999 of 0" (active OEs only)
            const isOE = (listing.edition_type === 'open' || listing.is_open_edition) && !listing.open_edition_closed_at;
            document.getElementById('mod-available-count').textContent = (isOE && parseInt(listing.total_editions) === 0)
                ? 'Open Edition'
                : `${available} of ${listing.total_editions}`;
            document.getElementById('mod-qty-value').textContent = '1';
            document.getElementById('mod-qty-value').dataset.max = maxQty;
            document.getElementById('mod-qty-value').dataset.price = displayPrice; // Use discounted price
            document.getElementById('mod-qty-value').dataset.originalPrice = originalPrice; // Store original too

            if (listing.has_tiers && listing.has_tiers !== '0') {
                document.getElementById('mod-tier-notice').style.display = 'block';
            }
            
            // v70: Show allowlist notice if user has benefits
            const allowlistNotice = document.getElementById('mod-allowlist-notice');
            const allowlistBenefit = document.getElementById('mod-allowlist-benefit');
            if (allowlistNotice && benefits.is_allowlisted) {
                let benefitText = '';
                // v382: Show discount remaining when holder discount is active
                if (discountRemaining !== null && discountRemaining > 0) {
                    benefitText = `${discountRemaining} discounted mint${discountRemaining !== 1 ? 's' : ''} remaining!`;
                } else if (discountRemaining !== null && discountRemaining <= 0) {
                    benefitText = `Discount allocation used — minting at full price`;
                } else if (benefits.discount_percent > 0) {
                    benefitText = `You're getting ${benefits.discount_percent}% off!`;
                } else if (benefits.custom_price !== null && benefits.custom_price !== undefined) {
                    benefitText = `VIP pricing applied!`;
                } else if (benefits.can_early_access) {
                    benefitText = `Early access granted!`;
                } else if (benefits.max_mint) {
                    benefitText = `Limit: ${benefits.max_mint} per wallet`;
                } else {
                    benefitText = `You're on the allowlist!`;
                }
                if (allowlistBenefit) allowlistBenefit.textContent = benefitText;
                allowlistNotice.style.display = 'block';
            } else if (allowlistNotice) {
                allowlistNotice.style.display = 'none';
            }

            // Disable + button if max is 1
            document.getElementById('mod-qty-plus').disabled = (maxQty <= 1);

            // v71: Populate currency options
            // v74 FIX: Ensure currencies is always an array (handle string JSON from older listings)
            let currencies = listing.accepted_currencies;
            if (typeof currencies === 'string') {
                try {
                    currencies = JSON.parse(currencies);
                } catch (e) {
                    console.warn('Failed to parse accepted_currencies:', e);
                    currencies = null;
                }
            }
            if (!Array.isArray(currencies)) {
                currencies = [{ currency: 'XRP', price: price, enabled: true, name: 'XRP', icon: '💧' }];
            }
            
            // v83: Handle dynamic pricing mode - XRP ONLY (simplified)
            if (pricingMode === 'dynamic' && priceUsd) {
                try {
                    // Only fetch XRP price from oracle
                    const priceResp = await fetch(`/wp-json/imc-price/v1/calculate?base_usd=${priceUsd}&tokens=${encodeURIComponent(JSON.stringify([{ticker:'XRP',discount_pct:0}]))}`);
                    const priceData = await priceResp.json();
                    
                    if (priceData && priceData.prices && priceData.prices.XRP) {
                        const xrpCalc = priceData.prices.XRP;
                        const xrpAmount = xrpCalc.amount;
                        
                        // v83: Dynamic = XRP only, no other tokens
                        currencies = [{
                            currency: 'XRP',
                            price: xrpAmount,
                            original_usd: priceUsd,
                            effective_usd: priceUsd,
                            token_rate: xrpCalc.token_usd_price,
                            is_dynamic: true,
                            enabled: true,
                            name: 'XRP',
                            icon: '💧'
                        }];
                        
                        console.log('Dynamic XRP price calculated:', xrpAmount, 'XRP ($' + priceUsd + ')');
                        
                        // Update price displays
                        priceEl.innerHTML = `${formatPrice(xrpAmount)} XRP <span class="mod-usd-hint">($${priceUsd.toFixed(2)})</span>`;
                        totalEl.textContent = `${formatPrice(xrpAmount)} XRP`;
                        document.getElementById('mod-qty-value').dataset.price = xrpAmount;
                        modState.displayPrice = xrpAmount;
                    } else {
                        throw new Error('XRP price not available');
                    }
                } catch (err) {
                    console.error('Failed to fetch XRP price:', err);
                    priceEl.innerHTML = '<span class="mod-error">⚠️ Price fetch failed - try again</span>';
                    showToast('Unable to fetch live XRP rate. Please refresh.', 'warning');
                    return; // Don't proceed without price
                }
            }
            
            // ── Re-apply allowlist custom_price after dynamic pricing ────
            // Dynamic pricing overwrites displayPrice/DOM with the live XRP rate.
            // If the buyer has an allowlist custom_price (e.g. $0 free mint or
            // discounted fixed price), it must take priority over the dynamic rate.
            if (pricingMode === 'dynamic' && priceUsd && benefits.custom_price !== null && benefits.custom_price !== undefined) {
                const customPrice = parseFloat(benefits.custom_price);
                const dynamicXrp = modState.displayPrice; // The just-fetched dynamic rate
                displayPrice = customPrice;
                modState.displayPrice = customPrice;
                document.getElementById('mod-qty-value').dataset.price = customPrice;
                if (customPrice === 0) {
                    priceEl.innerHTML = `<span class="mod-original-price">${formatPrice(dynamicXrp)} XRP</span> FREE ${discountInfo}`;
                    totalEl.textContent = 'FREE';
                } else {
                    priceEl.innerHTML = `<span class="mod-original-price">${formatPrice(dynamicXrp)} XRP</span> ${formatPrice(customPrice)} XRP ${discountInfo}`;
                    totalEl.textContent = `${formatPrice(customPrice)} XRP`;
                }
            }

            let enabledCurrencies = currencies.filter(c => c.enabled !== false);
            // v668: mirror the SERVER's effective-price resolution before filtering. The XRP
            // entry in accepted_currencies can legitimately read 0 while the authoritative XRP
            // price lives in the price_xrp column (legacy rows, or a price set directly in the
            // DB). The server resolves XRP against BOTH sources, so the picker must too --
            // otherwise a genuinely XRP-priced listing would have XRP hidden here even though
            // the server would accept it. `price` above is already that resolved value.
            enabledCurrencies.forEach(c => {
                if (String(c.currency || '').toUpperCase() === 'XRP'
                    && !(parseFloat(c.price) > 0) && price > 0) {
                    c.price = price;
                }
            });
            // v667: don't offer a currency the listing isn't actually priced in. A token-only
            // listing stores XRP at 0, which the backend guard already refuses -- so showing it
            // just invites a rejected click. Mirrors that guard's scope exactly:
            //   - FREE  -> untouched (a free listing is legitimately 0 in everything)
            //   - PWYW  -> untouched (a 0 floor legitimately means "pay any amount")
            const imcAnyPriced = enabledCurrencies.some(c => parseFloat(c.price || 0) > 0);
            if (imcAnyPriced && pricingMode !== 'free' && pricingMode !== 'pwyw') {
                enabledCurrencies = enabledCurrencies.filter(c => parseFloat(c.price || 0) > 0);
            }
            
            // v385: Apply holder discount to currency prices for correct UI display.
            // Without this, modState.selectedCurrency.price retains the PUBLIC price,
            // causing updateTotalDisplay() to show public price when qty > 1.
            // The backend applies its own discount independently — this is UI-only.
            // v728 (A2/G6): custom_price now also reaches TOKEN prices when the server
            // flag is live (listing.allowlist_proportional_pricing from get_listing):
            // 0 = free in ANY currency; otherwise the custom/XRP-base ratio applies.
            // Branch order mirrors the server exactly: custom price beats percent.
            // v731 (A4b): a per-token rule can apply even with no base benefit, so the
            // gate widens when the server sent a rules map (flag-gated server-side).
            const imcHasMatrix = benefits.currency_rules && Object.keys(benefits.currency_rules).length > 0;
            if (displayPrice < originalPrice || imcHasMatrix) {
                enabledCurrencies.forEach(c => {
                    if (c.currency === 'XRP') {
                        if (displayPrice < originalPrice) c.price = displayPrice;
                    } else if (imcHasMatrix && benefits.currency_rules[c.currency]
                               && benefits.custom_price !== 0 && parseFloat(c.price) > 0) {
                        // Per-token rule REPLACES the base benefit for this token; multiple
                        // lists' rules -> buyer-best. Mirrors the server exactly.
                        let imcBest = null;
                        for (const r of benefits.currency_rules[c.currency]) {
                            const p = r.mode === 'price' ? parseFloat(r.value) : parseFloat(c.price) * (1 - parseFloat(r.value) / 100);
                            if (imcBest === null || p < imcBest) imcBest = p;
                        }
                        if (imcBest !== null) c.price = imcBest;
                    } else if (listing.allowlist_proportional_pricing
                               && benefits.custom_price !== null && benefits.custom_price !== undefined) {
                        const _imcCp = parseFloat(benefits.custom_price);
                        if (_imcCp === 0) {
                            c.price = 0;
                        } else if (originalPrice > 0) {
                            c.price = parseFloat(c.price) * (_imcCp / originalPrice);
                        }
                        // no XRP base (token-only listing): leave the token price untouched —
                        // matches the server's "no benefit, steer to percent" rule
                    } else if (benefits.discount_percent > 0) {
                        // Apply percentage discount to non-XRP currencies too
                        c.price = parseFloat(c.price) * (1 - benefits.discount_percent / 100);
                    }
                });
            }
            const currencySelector = document.getElementById('mod-currency-selector');
            const currencyOptions = document.getElementById('mod-currency-options');
            
            // v83: Only show currency selector for static mode with multiple tokens
            // v661: currency picker now shows for BOTH static (fixed prices) and pwyw
            // (per-currency minimums). Static behaviour is unchanged.
            if ((pricingMode === 'static' || pricingMode === 'pwyw') && enabledCurrencies.length > 1) {
                currencyOptions.innerHTML = enabledCurrencies.map((curr, idx) => {
                    const currPrice = parseFloat(curr.price);
                    const discount = curr.discount_percent || curr.discount_pct ? `<span class="mod-currency-discount">-${curr.discount_percent || curr.discount_pct}%</span>` : '';
                    const icon = curr.icon || (curr.currency === 'XRP' ? '💧' : '🪙');
                    const name = curr.display_name || curr.name || curr.currency;
                    const usdHint = '';
                    return `
                        <button type="button" class="mod-currency-option ${idx === 0 ? 'selected' : ''}" 
                                data-currency="${curr.currency}" 
                                data-price="${currPrice}"
                                data-issuer="${curr.issuer || ''}"
                                onclick="window.mintOnDemand.selectCurrency(this)">
                            <span class="mod-currency-icon">${icon}</span>
                            <span class="mod-currency-name">${name}</span>
                            <span class="mod-currency-price">${pricingMode === 'pwyw' ? 'min ' : ''}${formatPrice(currPrice)} ${curr.currency}${usdHint}</span>
                            ${discount}
                            ${curr.currency !== 'XRP' ? `<span class="mod-currency-info imc-token-info" data-ticker="${curr.currency}" data-issuer="${curr.issuer || ''}" title="How to pay with ${curr.currency}">?</span>` : ''}
                        </button>
                    `;
                }).join('');
                currencySelector.style.display = 'block';

                // v696: the "i" on each non-XRP row opens the shared trustline+buy popover.
                // Its own click handler stops propagation so it never triggers selectCurrency.
                currencyOptions.querySelectorAll('.mod-currency-info').forEach(el => {
                    el.addEventListener('click', (e) => {
                        e.stopPropagation(); e.preventDefault();
                        if (window.imcTrustline) {
                            window.imcTrustline.openTokenActions(el.dataset.ticker, el.dataset.issuer, { account: CONFIG.account });
                        }
                    });
                });
                
                // Store currency data
                modState.currencies = enabledCurrencies;
                modState.selectedCurrency = enabledCurrencies[0];
            } else {
                currencySelector.style.display = 'none';
                modState.currencies = enabledCurrencies;
                modState.selectedCurrency = enabledCurrencies[0] || { currency: 'XRP', price: price };
            }

            // ── v715: shared issuer-fee note ────────────────────────────────────────────
            // Returns '' unless the token's issuer actually charges a transfer fee, so every
            // existing listing renders byte-identically. transfer_rate arrives per-currency on
            // the listing payload (see get_listing).
            function imcFeeNoteHtml(cur, unitPrice) {
                const rate = parseFloat(cur && cur.transfer_rate);
                if (!isFinite(rate) || rate <= 1) return '';
                const pct = Math.round((rate - 1) * 1000000) / 10000;   // 1.0033 -> 0.33
                const nets = (parseFloat(unitPrice) > 0)
                    ? ' \u2014 creator receives ~' + formatPrice(parseFloat(unitPrice) / rate) + ' ' + cur.currency
                    : '';
                return '<div class="mod-fee-note" style="font-size:0.8em;opacity:0.75;margin-top:4px;">'
                    + pct + '% issuer fee' + nets + '</div>';
            }

            // PWYW gets its note HERE rather than at the input render, because that render runs
            // BEFORE modState.selectedCurrency is assigned just above -- reading it there would
            // name a stale currency (or fall back to XRP) on the first open. Appended after the
            // existing floor note, so the name-your-price input itself is left untouched.
            if (pricingMode === 'pwyw') {
                const _imcPwywNote = document.getElementById('mod-pwyw-floor-note');
                const _imcPwywCur  = modState.selectedCurrency;
                if (_imcPwywNote && _imcPwywCur) {
                    const _imcPwywHtml = imcFeeNoteHtml(_imcPwywCur, parseFloat(_imcPwywCur.price) || 0);
                    if (_imcPwywHtml) _imcPwywNote.insertAdjacentHTML('afterend', _imcPwywHtml);
                }
            }

            // v667: a token-only listing now selects its first PRICED token by default, so the
            // headline Price/Total (rendered earlier from price_xrp) would still read "0.00 XRP".
            // Re-render both from the selected currency. Dynamic (oracle) and PWYW (the
            // name-your-price input lives in this element) are deliberately left alone.
            if (pricingMode !== 'dynamic' && pricingMode !== 'pwyw' && modState.selectedCurrency) {
                const imcSelCur = modState.selectedCurrency;
                const imcSelPrice = parseFloat(imcSelCur.price) || 0;
                if (String(imcSelCur.currency || '').toUpperCase() !== 'XRP' && imcSelPrice > 0) {
                    // v699 (Phase 5): a single-token listing has no currency selector to hang the
                    // "?" on, so add it beside the headline Price. Multi-currency listings get
                    // their "?" from the selector rows, so only do this when there's one currency.
                    const imcBadge = (enabledCurrencies.length === 1)
                        ? `<span class="imc-token-info" data-ticker="${imcSelCur.currency}" data-issuer="${imcSelCur.issuer || ''}" title="How to pay with ${imcSelCur.currency}" style="cursor:pointer;">?</span>`
                        : '';
                    priceEl.innerHTML = `${formatPrice(imcSelPrice)} ${imcSelCur.currency}${imcBadge}`;
                    // v715: if this token's issuer charges a transfer fee, say what the creator
                    // actually receives. The buyer pays the listed price either way -- the fee is
                    // DEDUCTED from the total -- so the honest framing is about the creator, not a
                    // surcharge on the buyer. Renders nothing when there is no fee, so XMEME /
                    // $HORDE / XFT and every other token look exactly as they do today.
                    priceEl.insertAdjacentHTML('afterend', imcFeeNoteHtml(imcSelCur, imcSelPrice));
                    totalEl.textContent = `${formatPrice(imcSelPrice)} ${imcSelCur.currency}`;
                    document.getElementById('mod-qty-value').dataset.price = imcSelPrice;
                    modState.displayPrice = imcSelPrice;
                    if (imcBadge) {
                        const imcQ = priceEl.querySelector('.imc-token-info');
                        if (imcQ) imcQ.addEventListener('click', (e) => {
                            e.stopPropagation(); e.preventDefault();
                            if (window.imcTrustline) window.imcTrustline.openTokenActions(imcSelCur.currency, imcSelCur.issuer || '', { account: CONFIG.account });
                        });
                    }
                }
            }

            // Show step 0 (quantity)
            updatePurchaseStep(0);
            modal.style.display = 'flex';

        } catch (error) {
            console.error('Show purchase modal error:', error);
            showToast(error.message, 'error');
        }
    }

    /**
     * Create purchase modal with all steps
     */
    function createPurchaseModal() {
        const modal = document.createElement('div');
        modal.id = 'mod-purchase-modal';
        modal.className = 'mod-modal';
        modal.innerHTML = `
            <div class="mod-modal-content mod-modal-purchase">
                <button class="mod-modal-close" onclick="window.mintOnDemand.closePurchaseModal()">×</button>

                <div class="mod-modal-header">
                    <span class="mod-modal-icon">🎵</span>
                    <h3 id="mod-modal-title">Mint NFT</h3>
                </div>

                <!-- Progress Steps -->
                <div class="mod-steps">
                    <div class="mod-step mod-step-active" data-step="0">
                        <div class="mod-step-number">1</div>
                        <div class="mod-step-label">Select</div>
                    </div>
                    <div class="mod-step-connector"></div>
                    <div class="mod-step" data-step="1">
                        <div class="mod-step-number">2</div>
                        <div class="mod-step-label">Pay</div>
                    </div>
                    <div class="mod-step-connector"></div>
                    <div class="mod-step" data-step="2">
                        <div class="mod-step-number">3</div>
                        <div class="mod-step-label">Mint</div>
                    </div>
                    <div class="mod-step-connector"></div>
                    <div class="mod-step" data-step="3">
                        <div class="mod-step-number">4</div>
                        <div class="mod-step-label">Claim</div>
                    </div>
                </div>

                <!-- Step 0: Quantity Selector (Task #14) -->
                <div class="mod-step-content" id="mod-step-0">
                    <div class="mod-purchase-summary">
                        <h4 id="mod-listing-title" style="margin:0 0 4px;">-</h4>
                        <p id="mod-listing-artist-name" style="margin:0 0 12px;opacity:0.7;">-</p>
                        <div class="mod-purchase-item"><span>Price:</span><span id="mod-price-each">-</span></div>
                        <div class="mod-purchase-item"><span>Available:</span><span id="mod-available-count">-</span></div>
                    </div>

                    <div class="mod-qty-selector">
                        <label>Quantity:</label>
                        <div class="mod-qty-controls">
                            <button class="mod-qty-btn" id="mod-qty-minus" onclick="window.mintOnDemand.adjustQty(-1)">−</button>
                            <span class="mod-qty-value" id="mod-qty-value">1</span>
                            <button class="mod-qty-btn" id="mod-qty-plus" onclick="window.mintOnDemand.adjustQty(1)">+</button>
                        </div>
                    </div>

                    <!-- v71: Currency Selector -->
                    <div class="mod-currency-selector" id="mod-currency-selector" style="display:none;">
                        <label>Pay with:</label>
                        <div class="mod-currency-options" id="mod-currency-options">
                            <!-- Populated dynamically -->
                        </div>
                    </div>

                    <div class="mod-purchase-item mod-fee-total" style="margin-top:12px;">
                        <span>Total:</span>
                        <span id="mod-total-price">-</span>
                    </div>

                    <div id="mod-tier-notice" class="mod-tier-notice" style="display:none;">
                        🎲 This listing has rarity tiers — your tier will be randomly assigned!
                    </div>

                    <div id="mod-allowlist-notice" class="mod-allowlist-notice" style="display:none;">
                        ✨ <strong>Allowlist Active!</strong> <span id="mod-allowlist-benefit"></span>
                    </div>

                    <button class="mod-btn mod-btn-primary mod-btn-block" id="mod-reserve-btn" onclick="window.mintOnDemand.startPurchase()">
                        🛒 Mint Now
                    </button>
                </div>

                <!-- Step 1: Payment -->
                <div class="mod-step-content" id="mod-step-1" style="display:none;">
                    <p class="mod-purchase-note">Payment goes directly to the artist. Sign with Xaman:</p>
                    <div class="mod-qr-container"><img id="mod-purchase-qr" src="" alt="Scan with Xaman" /></div>
                    <a id="mod-purchase-deeplink" href="#" target="_blank" class="mod-btn mod-btn-primary mod-btn-block">Open in Xaman</a>
                    <div id="mod-purchase-status" class="mod-poll-status"><div class="mod-spinner"></div><span>Waiting for payment...</span></div>
                </div>

                <!-- Step 2: Minting -->
                <div class="mod-step-content" id="mod-step-2" style="display:none;">
                    <div class="mod-minting-animation">
                        <div class="mod-minting-icon">⚡</div>
                        <h4>Minting Your NFT<span id="mod-mint-plural"></span>...</h4>
                        <p>Payment received! Your NFT<span id="mod-mint-plural2"></span> are being minted on the XRP Ledger.</p>
                        <div class="mod-progress-bar"><div class="mod-progress-fill" id="mod-mint-progress"></div></div>
                        <p class="mod-minting-note" id="mod-mint-note">This usually takes 10-60 seconds</p>
                    </div>
                </div>

                <!-- Step 3: Claim (multi-claim, one-by-one) -->
                <div class="mod-step-content" id="mod-step-3" style="display:none;">
                    <div class="mod-claim-ready">
                        <div class="mod-claim-icon">🎉</div>
                        <h4>Your NFTs are Ready!</h4>
                        <p>Claim each NFT by signing with your wallet:</p>
                    </div>
                    <div id="mod-claim-list" class="mod-claim-list"></div>
                    <div id="mod-claim-qr-section" style="display:none;">
                        <div class="mod-qr-container"><img id="mod-claim-qr" src="" alt="Scan with Xaman" /></div>
                        <a id="mod-claim-deeplink" href="#" target="_blank" class="mod-btn mod-btn-primary mod-btn-block">🎁 Claim in Xaman</a>
                        <div id="mod-claim-status" class="mod-poll-status"><div class="mod-spinner"></div><span>Waiting for claim...</span></div>
                    </div>
                </div>

                <!-- Success -->
                <div class="mod-step-content" id="mod-step-success" style="display:none;">
                    <div class="mod-success-animation">
                        <div class="mod-success-icon">✅</div>
                        <h4>All NFTs Claimed!</h4>
                        <p>Your NFTs have been transferred to your wallet.</p>
                        <a id="mod-view-nft-btn" href="/my-nfts/" class="mod-btn mod-btn-primary">View My NFTs</a>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modal);
        return modal;
    }

    /**
     * Reset modal to initial state
     */
    function resetPurchaseModal() {
        modState.currentGroup = null;
        modState.purchaseInProgress = false;
        modState.mintDispatched = false; // v886 (AM): belt -- a fresh modal is a fresh handoff
        stopAllPolling();

        // Reset step visibility
        for (let i = 0; i <= 3; i++) {
            const el = document.getElementById(`mod-step-${i}`);
            if (el) el.style.display = (i === 0) ? 'block' : 'none';
        }
        const success = document.getElementById('mod-step-success');
        if (success) success.style.display = 'none';

        // Reset step indicators
        document.querySelectorAll('.mod-step').forEach(el => {
            el.classList.remove('mod-step-active', 'mod-step-complete');
            if (el.dataset.step === '0') el.classList.add('mod-step-active');
        });

        // Reset reserve button
        const btn = document.getElementById('mod-reserve-btn');
        if (btn) { btn.disabled = false; btn.innerHTML = '🎵 Mint Now'; }
        
        // v70: Reset tier and allowlist notices
        const tierNotice = document.getElementById('mod-tier-notice');
        const allowlistNotice = document.getElementById('mod-allowlist-notice');
        if (tierNotice) tierNotice.style.display = 'none';
        if (allowlistNotice) allowlistNotice.style.display = 'none';

        // v299: Clear claim step state so reopening the modal from the dashboard
        // "Claim NFT" button doesn't show stale claim items, QR codes, or spinner
        // text from a previous session.
        const claimList = document.getElementById('mod-claim-list');
        if (claimList) claimList.innerHTML = '';
        const claimQr = document.getElementById('mod-claim-qr-section');
        if (claimQr) claimQr.style.display = 'none';
        const claimStatus = document.getElementById('mod-claim-status');
        if (claimStatus) claimStatus.innerHTML = '<div class="mod-spinner"></div><span>Waiting for claim...</span>';
        const claimQrImg = document.getElementById('mod-claim-qr');
        if (claimQrImg) claimQrImg.src = '';
    }

    /**
     * Adjust quantity (Task #14)
     */
    function adjustQty(delta) {
        const el = document.getElementById('mod-qty-value');
        const max = parseInt(el.dataset.max || 10);
        let qty = parseInt(el.textContent) + delta;

        qty = Math.max(1, Math.min(max, qty));
        el.textContent = qty;

        // v71: Update total with selected currency
        updateTotalDisplay(qty);

        // Update button states
        document.getElementById('mod-qty-minus').disabled = (qty <= 1);
        document.getElementById('mod-qty-plus').disabled  = (qty >= max);
    }
    
    // v71: Select payment currency
    function selectCurrency(btn) {
        // Update selected state
        document.querySelectorAll('.mod-currency-option').forEach(el => el.classList.remove('selected'));
        btn.classList.add('selected');
        
        // Get currency data
        const currency = btn.dataset.currency;
        const price = parseFloat(btn.dataset.price);
        const issuer = btn.dataset.issuer || null;
        
        // Find full currency config
        const currencyConfig = modState.currencies?.find(c => c.currency === currency) || { currency, price };
        modState.selectedCurrency = currencyConfig;
        // v660: PWYW -- refresh the floor note for the newly selected currency.
        if (modState.pricingMode === 'pwyw') {
            const _note = document.getElementById('mod-pwyw-floor-note');
            if (_note) _note.textContent = 'Minimum: ' + formatPrice(parseFloat(currencyConfig.price) || 0) + ' ' + currencyConfig.currency + ' — pay what you want at or above this.';
            // v661: the entered amount is currency-specific -- reset it when switching currency
            // so a value typed for one token can't be misread as the next token's amount.
            const _pin = document.getElementById('mod-pwyw-amount');
            if (_pin) _pin.value = '';
            modState.pwywAmount = '';
        }
        
        // Update price display
        const qty = parseInt(document.getElementById('mod-qty-value')?.textContent || '1');
        document.getElementById('mod-qty-value').dataset.price = price;
        updateTotalDisplay(qty);
    }
    
    // ═══════════════════════════════════════════════════════════════════
    // PP-2: PROGRESSIVE PRICING (client). Pure display + the confirm-gate UX.
    // The server (PP-1) is the sole pricing authority: it recomputes under the
    // lock and pauses via 409 price_moved when the step beat the buyer. All
    // math here mirrors imc_pp_unit/imc_pp_ladder exactly, seeded from the
    // MATERIALIZED current price (R13) the client is already rendering.
    // Every branch keys off progressive_json being present+enabled+static, so
    // a listing without a config renders exactly the pre-PP-2 modal.
    // ═══════════════════════════════════════════════════════════════════
    function ppInfo() {
        const l = modState.currentListing;
        if (!l || !l.progressive_json) return null;
        const ppMode = (l.pricing_mode || 'static');
        if (ppMode !== 'static' && ppMode !== 'dynamic') return null;
        let cfg = l.progressive_json;
        if (typeof cfg === 'string') { try { cfg = JSON.parse(cfg); } catch (e) { return null; } }
        if (!cfg || !cfg.enabled || !cfg.increments) return null;
        // PP-5: dynamic = single USD increment; the climb displays in USD (the truth --
        // the token amount floats with the rate as it always has). Seeded from the
        // MATERIALIZED price_usd. usd:true routes $ formatting + total-display guard.
        if (ppMode === 'dynamic') {
            const uInc = parseFloat(cfg.increments.USD || 0);
            const uCur = parseFloat(l.price_usd || 0);
            if (!(uInc > 0) || !(uCur > 0)) return null;
            const uCap = (cfg.cap && cfg.cap.USD != null) ? parseFloat(cfg.cap.USD) : null;
            return { cur: 'USD', usd: true, inc: uInc, cap: uCap, current: uCur,
                     capped: (uCap !== null && uCur >= uCap) };
        }
        const sel = modState.selectedCurrency || { currency: 'XRP' };
        const cur = (sel.currency || 'XRP').toUpperCase();
        const hex = String(sel.currency_hex || '').toUpperCase();
        let inc = null, key = null;
        for (const k of Object.keys(cfg.increments)) {
            const ku = k.toUpperCase();
            if (ku === cur || (hex && ku === hex)) { inc = parseFloat(cfg.increments[k]); key = ku; break; }
        }
        if (!inc || inc <= 0) return null;
        const cap = (cfg.cap && cfg.cap[key] != null) ? parseFloat(cfg.cap[key]) : null;
        const current = parseFloat(sel.price || modState.displayPrice || 0);
        if (!(current > 0)) return null;
        return { cur: sel.currency || 'XRP', inc: inc, cap: cap, current: current,
                 capped: (cap !== null && current >= cap) };
    }
    // Mirrors imc_pp_unit: current + k steps, capped. Full precision; display-only.
    function ppUnitPrice(current, inc, k, cap) {
        let p = current + inc * Math.max(0, k);
        if (cap !== null && cap > 0 && p > cap) p = cap;
        return p;
    }
    // Mirrors imc_pp_ladder: each unit in the batch pays its own step (R2/R10).
    function ppLadder(current, inc, qty, cap) {
        let total = 0, first = null, last = null, capped = false, parts = [];
        for (let k = 0; k < Math.max(1, qty); k++) {
            const u = ppUnitPrice(current, inc, k, cap);
            if (cap !== null && cap > 0 && u >= cap) capped = true;
            if (first === null) first = u;
            last = u; total += u; parts.push(u);
        }
        return { total: total, first: first, last: last, capped: capped, parts: parts };
    }
    // The explainer line under the price (approved copy).
    function ppRenderExplainer() {
        const pp = ppInfo();
        let el = document.getElementById('mod-pp-explainer');
        if (!pp) { if (el) el.style.display = 'none'; return; }
        const priceEl = document.getElementById('mod-price-each');
        if (!el && priceEl && priceEl.parentNode) {
            el = document.createElement('div');
            el.id = 'mod-pp-explainer';
            el.style.cssText = 'margin:6px 0 2px;font-size:0.85em;opacity:0.9;';
            priceEl.parentNode.insertBefore(el, priceEl.nextSibling);
        }
        if (!el) return;
        el.style.display = 'block';
        const ppF = pp.usd ? (v => '$' + (+v).toFixed(2)) : (v => `${formatPrice(v)} ${pp.cur}`);
        el.innerHTML = pp.capped
            ? `<strong>Top price reached</strong> — capped at ${ppF(pp.cap)}`
            : `📈 <strong>Progressive pricing</strong> — the price rises <strong>${ppF(pp.inc)}</strong> with every mint · Next: <strong>${ppF(ppUnitPrice(pp.current, pp.inc, 1, pp.cap))}</strong>`;
    }
    // The R11 pause (approved copy): rendered on the server's 409 price_moved.
    function ppShowPriceMoved(data) {
        const sel = modState.selectedCurrency || { currency: 'XRP' };
        const cur = data.currency || sel.currency || 'XRP';
        const newP = parseFloat(data.current_price);
        const oldP = parseFloat(data.expected_price);
        const ppc = ppInfo();
        const n = (ppc && ppc.inc > 0) ? Math.max(1, Math.round((newP - oldP) / ppc.inc)) : 1;
        const old = document.getElementById('mod-pp-moved');
        if (old) old.remove();
        const ov = document.createElement('div');
        ov.id = 'mod-pp-moved';
        ov.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.72);z-index:100002;display:flex;align-items:center;justify-content:center;padding:20px;';
        ov.innerHTML = `<div style="background:#181825;border:1px solid rgba(255,255,255,0.14);border-radius:14px;max-width:380px;width:100%;padding:22px;text-align:center;color:#fff;">
            <h4 style="margin:0 0 10px;">The price just moved.</h4>
            <p style="margin:0 0 18px;opacity:0.9;">It's now <strong>${formatPrice(newP)} ${cur}</strong> — ${n} mint(s) happened while you were deciding.</p>
            <button id="mod-pp-continue" style="display:block;width:100%;padding:12px;border:none;border-radius:9px;background:#7c5cff;color:#fff;font-weight:700;cursor:pointer;margin-bottom:9px;">Continue at ${formatPrice(newP)} ${cur}</button>
            <button id="mod-pp-cancel" style="display:block;width:100%;padding:11px;border:1px solid rgba(255,255,255,0.25);border-radius:9px;background:transparent;color:#fff;cursor:pointer;">Cancel</button>
        </div>`;
        document.body.appendChild(ov);
        document.getElementById('mod-pp-cancel').onclick = () => ov.remove();
        document.getElementById('mod-pp-continue').onclick = () => {
            ov.remove();
            // adopt the authoritative price, re-render, resubmit with the new expected.
            if (modState.selectedCurrency) modState.selectedCurrency.price = newP;
            modState.displayPrice = newP;
            const pe = document.getElementById('mod-price-each');
            if (pe) pe.textContent = `${formatPrice(newP)} ${cur}`;
            updateTotalDisplay(parseInt(document.getElementById('mod-qty-value')?.textContent || '1'));
            ppRenderExplainer();
            startPurchase();
        };
    }
    // The 10s soft-poll: only while the modal is open AND the tab is visible;
    // focus-refresh on visibilitychange; torn down in closePurchaseModal.
    function ppStartPoll() {
        ppStopPoll();
        if (!ppInfo()) return;
        modState.ppPoll = setInterval(ppRefreshListing, 10000);
        modState.ppVisHandler = () => { if (!document.hidden) ppRefreshListing(); };
        document.addEventListener('visibilitychange', modState.ppVisHandler);
    }
    function ppStopPoll() {
        if (modState.ppPoll) { clearInterval(modState.ppPoll); modState.ppPoll = null; }
        if (modState.ppVisHandler) { document.removeEventListener('visibilitychange', modState.ppVisHandler); modState.ppVisHandler = null; }
    }
    async function ppRefreshListing() {
        if (document.hidden) return;
        if (modState.purchaseInProgress) return; // never mutate prices mid-reserve
        const l = modState.currentListing;
        if (!l || !ppInfo()) return;
        try {
            const r = await fetch(`${ENDPOINTS.mintOnDemand}?action=get_listing&listing_id=${l.id}&buyer=${CONFIG.account}`);
            const j = await r.json();
            if (!j.success || !j.data || !j.data.listing) return;
            const nl = j.data.listing;
            modState.currentListing.progressive_json = nl.progressive_json;
            if (!Array.isArray(nl.accepted_currencies)) return;
            modState.currentListing.accepted_currencies = nl.accepted_currencies;
            const sel = modState.selectedCurrency;
            if (!sel) return;
            // PP-5: dynamic tracks the materialized price_usd instead of a currency entry.
            const ppNow = ppInfo();
            if (ppNow && ppNow.usd) {
                const nUsd = parseFloat(nl.price_usd || 0);
                const oUsd = parseFloat(modState.currentListing.price_usd || 0);
                modState.currentListing.price_usd = nl.price_usd;
                if (nUsd > 0 && oUsd > 0 && nUsd !== oUsd) {
                    ppRenderExplainer();
                    updateTotalDisplay(parseInt(document.getElementById('mod-qty-value')?.textContent || '1'));
                    showToast(`Price just moved: now $${nUsd.toFixed(2)}`, 'info');
                }
                return;
            }
            const m2 = nl.accepted_currencies.find(c => (c.currency || '').toUpperCase() === (sel.currency || '').toUpperCase());
            if (m2 && parseFloat(m2.price) > 0 && parseFloat(m2.price) !== parseFloat(sel.price)) {
                sel.price = parseFloat(m2.price);
                modState.displayPrice = sel.price;
                const pe = document.getElementById('mod-price-each');
                if (pe) pe.textContent = `${formatPrice(sel.price)} ${sel.currency}`;
                updateTotalDisplay(parseInt(document.getElementById('mod-qty-value')?.textContent || '1'));
                ppRenderExplainer();
                showToast(`Price just moved: now ${formatPrice(sel.price)} ${sel.currency}`, 'info');
            }
        } catch (e) { /* best-effort — the confirm gate is the safety net */ }
    }

    // v71: Update total price display with currency
    function updateTotalDisplay(qty) {
        const curr = modState.selectedCurrency || { currency: 'XRP', price: modState.displayPrice || 0 };
        const totalEl = document.getElementById('mod-total-price');
        // v660/v661: PWYW total is driven by the buyer's chosen amount; show a dash until
        // they enter one, otherwise amount x qty in the selected currency.
        if (modState.pricingMode === 'pwyw') {
            const amt = parseFloat(modState.pwywAmount) || 0;
            if (totalEl) totalEl.textContent = (amt > 0) ? `${formatPrice(amt * qty)} ${curr.currency}` : `— ${curr.currency}`;
            return;
        }
        // v668: a Free Mint stays "Free" at any quantity.
        if (modState.pricingMode === 'free') {
            if (totalEl) totalEl.textContent = 'Free';
            return;
        }
        // PP-2: progressive ladder -- each unit pays its own step; breakdown always shown
        // for qty>1 (Q1 ruling). Seeded from the materialized current price.
        const ppT = ppInfo();
        const bkEl0 = document.getElementById('mod-pp-breakdown');
        if (ppT) {
            const lad = ppLadder(ppT.current, ppT.inc, qty, ppT.cap);
            // PP-5: dynamic totals stay with the existing oracle flow (token amount floats
            // with the rate) -- the USD ladder renders in the breakdown only.
            if (totalEl && !ppT.usd) totalEl.textContent = `${formatPrice(lad.total)} ${curr.currency}`;
            let bk = bkEl0;
            if (!bk && totalEl && totalEl.parentNode && totalEl.parentNode.parentNode) {
                bk = document.createElement('div');
                bk.id = 'mod-pp-breakdown';
                bk.style.cssText = 'margin-top:5px;font-size:0.82em;opacity:0.85;text-align:right;';
                totalEl.parentNode.parentNode.insertBefore(bk, totalEl.parentNode.nextSibling);
            }
            if (bk) {
                if (qty > 1) {
                    bk.style.display = 'block';
                    bk.innerHTML = ppT.usd
                        ? `${lad.parts.map(p => '$' + (+p).toFixed(2)).join(' + ')} = <strong>$${(+lad.total).toFixed(2)}</strong>${lad.capped ? ' · capped' : ''} · converts at checkout`
                        : `${lad.parts.map(p => formatPrice(p)).join(' + ')} = <strong>${formatPrice(lad.total)} ${curr.currency}</strong>${lad.capped ? ' · capped' : ''}`;
                } else if (lad.capped) {
                    bk.style.display = 'block';
                    bk.innerHTML = 'Top price reached';
                } else {
                    bk.style.display = 'none';
                }
            }
            ppRenderExplainer();
            return;
        }
        if (bkEl0) bkEl0.style.display = 'none';
        const total = parseFloat(curr.price) * qty;
        if (totalEl) {
            totalEl.textContent = `${formatPrice(total)} ${curr.currency}`;
        }
    }
    
    // v71: Format price based on currency
    function formatPrice(amount) {
        if (amount >= 1000) return amount.toLocaleString(undefined, { maximumFractionDigits: 2 });
        if (amount >= 1) return amount.toFixed(2);
        return amount.toFixed(6).replace(/\.?0+$/, '');
    }

    /**
     * Update purchase step UI
     */
    function updatePurchaseStep(step) {
        document.querySelectorAll('.mod-step').forEach(el => {
            const s = parseInt(el.dataset.step);
            el.classList.remove('mod-step-active', 'mod-step-complete');
            if (s < step) el.classList.add('mod-step-complete');
            if (s === step) el.classList.add('mod-step-active');
        });

        for (let i = 0; i <= 3; i++) {
            const content = document.getElementById(`mod-step-${i}`);
            if (content) content.style.display = (i === step) ? 'block' : 'none';
        }

        const success = document.getElementById('mod-step-success');
        if (success) success.style.display = (step > 3) ? 'block' : 'none';
    }


    // ─── STEP 1: RESERVE + PAY ──────────────────────────────────────────

    /**
     * Start purchase: reserve editions + show payment QR
     */
    async function startPurchase() {
        if (modState.purchaseInProgress) return;
        modState.purchaseInProgress = true;

        const listing = modState.currentListing;
        if (!listing) { showToast('No listing selected', 'error'); return; }

        const qty = parseInt(document.getElementById('mod-qty-value')?.textContent || '1');
        const reserveBtn = document.getElementById('mod-reserve-btn');
        if (reserveBtn) { reserveBtn.disabled = true; reserveBtn.innerHTML = '<span class="mod-spinner-small"></span> Reserving...'; }

        try {
            // v242: Refresh nonce before POSTing — WP nonces expire after 12-24h.
            // Without this, a user who opens the modal and leaves their tab idle
            // overnight will get a 403 when they finally click Mint.
            await refreshNonce();

            const formData = new FormData();
            formData.append('action', 'reserve_purchase');
            formData.append('nonce', CONFIG.nonce);
            formData.append('listing_id', listing.id);
            formData.append('buyer_account', CONFIG.account);
            formData.append('quantity', qty);
            
            // v71: Include selected currency
            const selectedCurrency = modState.selectedCurrency || { currency: 'XRP' };
            formData.append('payment_currency', selectedCurrency.currency);

            // PP-2: send the price the buyer is looking at -- the server's confirm gate
            // (R11) pauses BEFORE any reservation or QR if the step moved meanwhile.
            const ppX = ppInfo();
            if (ppX) formData.append('pp_expected_price', ppX.current);

            // v660: PWYW -- send the buyer's chosen amount (server enforces >= per-currency floor).
            if (modState.pricingMode === 'pwyw') {
                formData.append('pwyw_amount', parseFloat(modState.pwywAmount) || 0);
            }

            // Joey: sign locally instead of via XUMM. Enforce account-binding first.
            const joeyLiveB = !!(window.__joeySession && window.__joeySession.live && window.imuWallet);
            if (joeyLiveB && CONFIG.account !== window.__joeySession.account) {
                showToast('Connected wallet does not match your account. Please reconnect.', 'error');
                modState.purchaseInProgress = false;
                if (reserveBtn) { reserveBtn.disabled = false; reserveBtn.innerHTML = '🎵 Mint Now'; }
                return;
            }
            if (joeyLiveB) formData.append('wallet', 'joey');

            const response = await fetch(ENDPOINTS.mintOnDemand, { method: 'POST', body: formData });
            const data = await response.json();

            // PP-2 (R11): the step moved while the buyer was deciding. Pause -- never a
            // raw error. json_error merges extras at TOP LEVEL, so they are read here.
            if (!data.success && data.price_moved) {
                modState.purchaseInProgress = false;
                if (reserveBtn) { reserveBtn.disabled = false; reserveBtn.innerHTML = '🎵 Mint Now'; }
                ppShowPriceMoved(data);
                return;
            }
            if (!data.success) throw new Error(data.error || 'Failed to reserve');

            modState.currentGroup = data.data;

            // PP-2: the authoritative server ladder on the payment step (approved copy).
            if (data.data.pp) {
                const spp = data.data.pp;
                const bk2 = document.getElementById('mod-pp-breakdown');
                if (bk2) {
                    bk2.style.display = 'block';
                    bk2.innerHTML = (parseInt(data.data.quantity) > 1)
                        ? `Mints #${spp.step + 1}–#${spp.step + parseInt(data.data.quantity)} · Your ladder: ${formatPrice(spp.current)} → ${formatPrice(spp.last_in_batch)} ${data.data.currency} · total <strong>${formatPrice(data.data.total_price)} ${data.data.currency}</strong>`
                        : `Your price: <strong>${formatPrice(spp.current)} ${data.data.currency}</strong>`;
                }
            }

            // Joey buy: sign the returned Payment txjson locally, then run the SAME
            // process_group(group_id, tx_hash) verify+mint path Xaman uses.
            if (data.data.wallet === 'joey' && data.data.txjson) {
                // v556: present the clean imuJoeySign overlay (4-min countdown) as the sole
                // signing UI -- defer the modal's minting step until AFTER the sign succeeds.
                let signedJ;
                try { signedJ = await (window.imuJoeySign||window.imuWallet.sign)(data.data.txjson); }
                catch (e) {
                    const emJ = (e && e.message) || '';
                    const msgJ = /timed out|timeout/i.test(emJ) ? 'Payment timed out. Please try again.'
                               : /cancel/i.test(emJ) ? 'Payment cancelled.'
                               : 'Payment rejected.';
                    showToast(msgJ, 'error');
                    modState.purchaseInProgress = false;
                    if (reserveBtn) { reserveBtn.disabled = false; reserveBtn.innerHTML = '🎵 Mint Now'; }
                    closePurchaseModal();
                    return;
                }
                const txHashJ = signedJ && (signedJ.hash || signedJ.tx_hash);
                if (!txHashJ) {
                    showToast('No transaction hash returned from your wallet.', 'error');
                    modState.purchaseInProgress = false;
                    if (reserveBtn) { reserveBtn.disabled = false; reserveBtn.innerHTML = '🎵 Mint Now'; }
                    closePurchaseModal();
                    return;
                }
                // v687 (Layer 1): record the payment hash the instant it exists.
                // Xaman's recovery key is written server-side BEFORE signing, so Xaman
                // purchases always self-heal. Joey has no pre-signature key -- if this tab
                // dies before processGroup() lands, the payment is on-ledger with no DB
                // record at all. keepalive:true lets this request
                // survive tab close and navigation. Fire-and-forget: never awaited, never
                // blocks the mint, and any failure is ignored because processGroup() below
                // remains the primary path.
                try {
                    const rjtFd = new FormData();
                    rjtFd.append('action', 'record_joey_tx');
                    rjtFd.append('group_id', data.data.group_id);
                    rjtFd.append('account', CONFIG.account);
                    rjtFd.append('tx_hash', txHashJ);
                    rjtFd.append('nonce', CONFIG.nonce);
                    fetch(ENDPOINTS.mintOnDemand, { method: 'POST', body: rjtFd, keepalive: true }).catch(function () {});
                } catch (e) { /* backstop must never block the mint */ }
                const pluralJ = qty > 1 ? 's' : '';
                document.querySelectorAll('#mod-mint-plural, #mod-mint-plural2').forEach(el => el.textContent = pluralJ);
                updatePurchaseStep(2);
                const mintNoteJ = document.getElementById('mod-mint-note');
                if (mintNoteJ) mintNoteJ.textContent = 'Minting your NFT' + pluralJ + '...';
                processGroup(data.data.group_id, txHashJ);
                return;
            }

            // ── FREE MINT HANDLER ────────────────────────────────────
            // When allowlist custom_price = 0, backend skips XUMM payment
            // and returns free_mint=true. Go directly to minting step.
            if (data.data.free_mint) {
                const plural = qty > 1 ? 's' : '';
                document.querySelectorAll('#mod-mint-plural, #mod-mint-plural2').forEach(el => el.textContent = plural);
                
                // Skip payment step (step 1) — jump to minting step (step 2)
                updatePurchaseStep(2);
                
                const mintNote = document.getElementById('mod-mint-note');
                if (mintNote) mintNote.textContent = `Free mint! Minting ${qty} NFT${plural}...`;
                
                // Call processGroup directly with synthetic tx_hash
                processGroup(data.data.group_id, 'FREE_MINT');
                return;
            }

            // Update plurals
            const plural = qty > 1 ? 's' : '';
            document.querySelectorAll('#mod-mint-plural, #mod-mint-plural2').forEach(el => el.textContent = plural);

            // Show payment QR
            updatePurchaseStep(1);

            const qrImg = document.getElementById('mod-purchase-qr');
            const deeplink = document.getElementById('mod-purchase-deeplink');

            // v562: if the reservation returned no payable artifact (no QR and no deeplink),
            // don't sit on a silent "Waiting for payment..." spinner with a dead box.
            // (Server Item 1 now rolls back such reservations; this is the client backstop.)
            if (!data.data.qr_png && !data.data.deeplink) {
                document.getElementById('mod-purchase-status').innerHTML =
                    '<span style="color:#ef4444;">Couldn\'t load the payment request. Please try again.</span>';
                modState.purchaseInProgress = false;
                if (reserveBtn) { reserveBtn.disabled = false; reserveBtn.innerHTML = '🎵 Mint Now'; }
                return;
            }

            // v562: detect a QR image that fails to load (XUMM CDN/network), so the buyer
            // isn't left staring at a broken image under a spinner. The Xaman deeplink still
            // works, so point them there instead of dead-ending.
            if (qrImg) {
                qrImg.onerror = function () {
                    const st = document.getElementById('mod-purchase-status');
                    if (st) st.innerHTML = '<span style="color:#f59e0b;">Payment QR didn\'t load. Tap "Open in Xaman" below to pay, or close and try again.</span>';
                };
            }
            if (qrImg && data.data.qr_png)     qrImg.src = data.data.qr_png;
            if (deeplink && data.data.deeplink) deeplink.href = data.data.deeplink;
            if (modIsMobileUA && deeplink) deeplink.setAttribute('target', '_self'); // v590 mobile: open Xaman in same tab -- no orphan tab

            // v71: Show currency in status
            const currencyDisplay = data.data.currency_display || data.data.currency || 'XRP';
            document.getElementById('mod-purchase-status').innerHTML = `<div class="mod-spinner"></div><span>Waiting for ${currencyDisplay} payment...</span>`;

            savePaymentPollState(data.data.uuid, data.data.group_id);
            startPaymentPolling(data.data.uuid, data.data.group_id);

        } catch (error) {
            console.error('Reserve error:', error);
            showToast(error.message, 'error');
            modState.purchaseInProgress = false;
            if (reserveBtn) { reserveBtn.disabled = false; reserveBtn.innerHTML = '🎵 Mint Now'; }
        }
    }


    // ─── STEP 1B: POLL PAYMENT ──────────────────────────────────────────

    // v284: iOS Xaman payment resume.
    // On iOS, tapping the Xaman deeplink uses a universal link that navigates
    // the browser tab to Xaman app and suspends it. JS polling stops.
    // When Xaman completes, it fires its return_url (/collections/?purchase_pending=1)
    // which RESUMES the suspended tab via visibilitychange.
    // We store the active poll state in sessionStorage so the resume handler can
    // restart polling after the tab wakes up.
    function savePaymentPollState(uuid, groupId) {
        try {
            sessionStorage.setItem('_imc_pay_uuid',    uuid);
            sessionStorage.setItem('_imc_pay_group',   String(groupId));
            sessionStorage.setItem('_imc_pay_ts',      String(Date.now()));
        } catch(e) {}
    }
    function clearPaymentPollState() {
        try {
            sessionStorage.removeItem('_imc_pay_uuid');
            sessionStorage.removeItem('_imc_pay_group');
            sessionStorage.removeItem('_imc_pay_ts');
        } catch(e) {}
    }
    function resumePaymentPollIfNeeded() {
        try {
            const uuid    = sessionStorage.getItem('_imc_pay_uuid');
            const groupId = sessionStorage.getItem('_imc_pay_group');
            const ts      = parseInt(sessionStorage.getItem('_imc_pay_ts') || '0');
            if (!uuid || !groupId) return;
            const age = Date.now() - ts;
            // Only resume if stored within last 30 minutes and page has purchase_pending param
            const params = new URLSearchParams(window.location.search);
            if (age < 1800000 && params.get('purchase_pending') === '1') {
                clearPaymentPollState();
                console.log('[MOD] Resuming payment poll after iOS Xaman return, uuid=' + uuid);
                // v295 FIX: Always create the modal if it doesn't exist.
                // Previously used `if (modal)` guard — on a fresh page load to
                // /collections/ after Xaman redirect the modal doesn't exist yet,
                // so the UI setup was silently skipped and the user saw nothing.
                // Note: the primary path is now the dashboard handler; this is a
                // fallback for Android where the original tab sometimes survives.
                const modal = document.getElementById('mod-purchase-modal') || createPurchaseModal();
                modal.style.display = 'flex';
                updatePurchaseStep(1);
                const statusEl = document.getElementById('mod-purchase-status');
                if (statusEl) statusEl.innerHTML = '<div class="mod-spinner"></div><span>Confirming payment...</span>';
                startPaymentPolling(uuid, parseInt(groupId, 10));
            } else if (age >= 1800000) {
                clearPaymentPollState(); // expired
            }
        } catch(e) {}
    }

    // Also resume on visibilitychange (tab wakes from background on iOS)
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) resumePaymentPollIfNeeded();
    });

    function startPaymentPolling(uuid, groupId) {
        stopPaymentPolling();
        modState.mintDispatched = false; // v886 (AM): fresh session, fresh latch
        modState.paymentPollInFlight = false; // M1: fresh session, fresh in-flight guard

        modState.paymentPollInterval = setInterval(async () => {
            if (modState.paymentPollInFlight) return;   // M1: one poll in flight at a time (the AM latch below guards dispatch; this guards overlap)
            modState.paymentPollInFlight = true;
            try {
                const response = await fetch(`${ENDPOINTS.mintOnDemand}?action=poll_payment&uuid=${uuid}`);
                const data = await response.json();
                const statusEl = document.getElementById('mod-purchase-status');

                // Signed but waiting for confirmation
                if (data.data?.signed && !data.data?.dispatched_result) {
                    if (statusEl) statusEl.innerHTML = '<div class="mod-spinner"></div><span>Signed! Waiting for blockchain confirmation...</span>';
                    return;
                }

                // CRITICAL: Check dispatched_result === tesSUCCESS
                if (data.data?.signed && data.data?.dispatched_result === 'tesSUCCESS' && data.data?.tx_hash) {
                    // v886 (Defect AM): setInterval fires on a fixed cadence regardless of whether
                    // the previous ASYNC callback has finished. poll_payment boots WordPress and
                    // queries XUMM, so several callbacks are routinely in flight at once. When the
                    // payment confirms, EVERY one of them reaches this branch -- and
                    // stopPaymentPolling() below can only cancel FUTURE ticks, never the callbacks
                    // already awaiting their fetch. Seen in practice: a dozen
                    // process_group POSTs in four seconds for a single mint, each a full WordPress
                    // bootstrap. Harmless per-mint (process_group returns already_minted /
                    // minting_in_progress before taking the lock or writing anything) but it is
                    // ~12x wasted FPM capacity per mint, and a large drop is exactly the
                    // pressure that surfaced this.
                    // The latch makes the handoff genuinely one-shot.
                    if (modState.mintDispatched) return;
                    modState.mintDispatched = true;
                    stopPaymentPolling();
                    clearPaymentPollState(); // v284: clear iOS resume state
                    if (statusEl) statusEl.innerHTML = '<span style="color:#10b981;">✅ Payment confirmed!</span>';

                    // Store tx_hash for retry capability
                    if (modState.currentGroup) {
                        modState.currentGroup.payment_tx_hash = data.data.tx_hash;
                    }

                    // v42: Wait 3 seconds for XRPL ledger validation before verifying.
                    // XUMM tesSUCCESS means accepted into open ledger, not yet validated.
                    // XRPL closes ledgers every 3-5 seconds. Server also retries.
                    setTimeout(() => {
                        updatePurchaseStep(2);
                        processGroup(groupId, data.data.tx_hash);
                    }, 3000);
                    return;
                }

                // Failed transaction
                if (data.data?.signed && data.data?.dispatched_result && data.data?.dispatched_result !== 'tesSUCCESS') {
                    stopPaymentPolling();
                    const msg = getXrplErrorMessage(data.data.dispatched_result);
                    if (statusEl) statusEl.innerHTML = `<span style="color:#ef4444;">❌ ${msg}</span>`;
                    showToast(`Payment failed: ${msg}`, 'error');
                    modState.purchaseInProgress = false;
                    return;
                }

                // Rejected
                if (data.data?.rejected) {
                    stopPaymentPolling();
                    if (statusEl) statusEl.innerHTML = '<span style="color:#ef4444;">❌ Payment rejected</span>';
                    modState.purchaseInProgress = false;
                    return;
                }

                // Expired
                if (data.data?.expired) {
                    stopPaymentPolling();
                    if (statusEl) statusEl.innerHTML = '<span style="color:#ef4444;">⏰ Payment expired. Please try again.</span>';
                    modState.purchaseInProgress = false;
                }

            } catch (error) {
                console.error('Payment poll error:', error);
            } finally {
                modState.paymentPollInFlight = false;   // M1
            }
        }, 3000);

        // v562: when the 30-min poll window elapses with no completed payment, don't die
        // silently under a stale "Waiting for payment..." spinner. The reservation has
        // expired server-side (cleanup releases the slot), so tell the buyer they can mint
        // again. Only overwrite if still in the waiting/just-signed state.
        setTimeout(() => {
            stopPaymentPolling();
            modState.purchaseInProgress = false;
            clearPaymentPollState();
            const st = document.getElementById('mod-purchase-status');
            if (st && /Waiting for|Signed!/.test(st.textContent || '')) {
                st.innerHTML = '<span style="color:#9a9aa2;">\u231b Reservation expired. Your slot has been released \u2014 you can mint again.</span>';
            }
        }, 30 * 60 * 1000);
    }

    function stopPaymentPolling() {
        if (modState.paymentPollInterval) { clearInterval(modState.paymentPollInterval); modState.paymentPollInterval = null; }
    }


    // ─── STEP 2: PROCESS GROUP (ASYNC — polls for progress) ───────────

    /**
     * Submit payment proof and poll for mint progress.
     * The server returns immediately after verifying payment,
     * then mints NFTs in the background. We poll get_group_status
     * every 3s to update the progress bar and detect completion.
     */
    async function processGroup(groupId, txHash) {
        const mintNote = document.getElementById('mod-mint-note');
        const progressBar = document.getElementById('mod-mint-progress');
        const qty = modState.currentGroup?.quantity || 1;

        if (mintNote) mintNote.textContent = `Verifying payment...`;
        if (progressBar) progressBar.style.width = '5%';

        try {
            // v242: Refresh nonce before the process_group POST. The payment
            // step may have taken several minutes (user slow to sign in Xaman),
            // pushing the nonce close to or past its expiry window.
            await refreshNonce();

            // ── Phase A: Submit payment hash (fast response) ──
            const formData = new FormData();
            formData.append('action', 'process_group');
            formData.append('nonce', CONFIG.nonce);
            formData.append('group_id', groupId);
            formData.append('tx_hash', txHash);

            const response = await fetch(ENDPOINTS.mintOnDemand, { method: 'POST', body: formData });
            const data = await response.json();

            if (!data.success) throw new Error(data.error || 'Payment verification failed');

            const status = data.data.status;

            // If already fully minted (re-submit), skip to claim
            if (status === 'already_minted') {
                if (mintNote) mintNote.textContent = '✅ Already minted!';
                if (progressBar) progressBar.style.width = '100%';
                setTimeout(() => loadClaimData(groupId), 500);
                return;
            }

            if (mintNote) mintNote.textContent = `Minting ${qty} NFT${qty > 1 ? 's' : ''}... This may take a moment.`;
            if (progressBar) progressBar.style.width = '10%';

            // ── Phase B: Poll get_group_status every 1.5s (v591) ──
            await pollMintProgress(groupId, qty, mintNote, progressBar);

        } catch (error) {
            console.error('Process group error:', error);
            // v886 (Defect AB): processGroup is called WITHOUT await from the Joey and free-mint
            // branches (they call it then return), so those callers can never clear this guard
            // themselves. Left set, a failed mint leaves purchaseInProgress=true and the buyer
            // cannot start another purchase without a page reload. Clearing it here -- on the
            // error path only -- restores the retry affordance without touching the success path,
            // where resetPurchaseModal() already handles it.
            modState.purchaseInProgress = false;
            const mintContent = document.getElementById('mod-step-2');

            // v452: Distinguish poll timeout (NFTs still processing) from actual failures
            if (error.message && error.message.startsWith('POLL_TIMEOUT:')) {
                const timeoutMsg = error.message.replace('POLL_TIMEOUT:', '');
                showToast(timeoutMsg, 'info');
                if (mintContent) {
                    mintContent.innerHTML = `
                        <div class="mod-error">
                            <div class="mod-error-icon">⏳</div>
                            <h4>Still Processing</h4>
                            <p>${escapeHtml(timeoutMsg)}</p>
                            <p>Check your <strong>Purchase History</strong> in the Dashboard for updates.</p>
                            <a href="/trading-hub-dashboard/" class="mod-btn mod-btn-primary" style="margin-top:12px;">
                                📋 Go to Dashboard
                            </a>
                        </div>
                    `;
                }
            } else {
                // 4j: a raw internal string ('Prepare failed: HTTP 500') and a red cross are the
                // wrong answer to something the system re-drives on its own within ~10 minutes.
                // `recoverable` comes from the server and mirrors the healer's own predicate, so
                // this can never promise a rescue that will not happen. `is_free` stops the
                // "payment was received" line appearing on a free mint, where none was taken.
                // Default when the field is absent (stale cache) is RECOVERABLE -- the calm copy
                // is the safe default: it never says a mint failed that is actually in flight.
                const _sd     = modState.lastStatusData || {};
                const _rec    = (_sd.recoverable !== false);
                const _isFree = (_sd.is_free === true);
                if (_rec) {
                    showToast('Just a small hiccup — your NFT is on its way.', 'info');
                    if (mintContent) {
                        mintContent.innerHTML = `
                        <div class="mod-error">
                            <div class="mod-error-icon">⏳</div>
                            <h4>Just a small hiccup</h4>
                            <p>Your NFT is safe and on its way. We are still processing your mint. No action needed.</p>
                            <p>Check back in to your Offers Hub shortly.</p>
                            <p class="mod-ref">Reference: ${groupId}</p>
                            <a href="/trading-hub-dashboard/" class="mod-btn mod-btn-primary" style="margin-top:12px;">
                                📋 Go to Offers Hub
                            </a>
                        </div>
                    `;
                    }
                } else {
                    showToast('We could not complete this mint — please contact support.', 'error');
                    if (mintContent) {
                        mintContent.innerHTML = `
                        <div class="mod-error">
                            <div class="mod-error-icon">⚠️</div>
                            <h4>We couldn't complete this mint</h4>
                            <p>This one needs a look from us. Nothing has been lost — please contact support with reference ${groupId}.</p>
                            ${_isFree ? '' : '<p>Your payment was received.</p>'}
                            <a href="${IMC_DISCORD_URL}" target="_blank" rel="noopener" class="mod-btn mod-btn-primary" style="margin-top:12px;">
                                💬 Create Ticket
                            </a>
                            <a href="${IMC_SUPPORT_URL}" class="mod-btn mod-btn-secondary" style="margin-left:8px;">
                                Contact Support
                            </a>
                            <a href="/trading-hub-dashboard/" class="mod-btn mod-btn-secondary" style="margin-left:8px;">
                                📋 Go to Dashboard
                            </a>
                        </div>
                    `;
                    }
                }
            }
        }
    }

    /**
     * Poll the server for mint progress until complete, failed, or timeout.
     */
    async function pollMintProgress(groupId, totalQty, mintNote, progressBar) {
        const POLL_INTERVAL = 1500;   // v591: 1.5s -- faster progress updates. Polls read-only get_group_status (group + purchase rows); no mint/tier/edition/dedup side effects.
        // v452: Scale poll timeout to batch size. Each NFT needs ~15-30s
        // (IPFS pin + XRPL mint + sell offer). Plus lock contention buffer.
        // qty=1 → 6 min (unchanged), qty=5 → 10 min, qty=10 → 20 min
        const MAX_POLLS = Math.max(240, totalQty * 80); // v591: doubled alongside the halved interval so the wall-clock timeout window is UNCHANGED (qty=1->6min, qty=5->10min, qty=10->20min)
        let polls = 0;
        let inFlight = false;   // M1: setInterval never waits for its own fetch (v886 AM autopsy) -- one poll in flight at a time
        let nextAllowedAt = 0;  // M7b: the server says how soon to ask again (poll_after_ms); ticks before that are skipped
        const startedAt = Date.now();
        const MAX_WAIT_MS = (typeof CONFIG !== 'undefined' && CONFIG.m7bMaxWaitMs) ? CONFIG.m7bMaxWaitMs : 7200000;   // M7b: 2h absolute cap for zombie tabs

        return new Promise((resolve, reject) => {
            const interval = setInterval(async () => {
                polls++;
                if (Date.now() < nextAllowedAt) return;   // M7b: honour the server's cadence
                if (inFlight) return;   // M1: counted (wall-clock timeout unchanged) but not sent
                inFlight = true;

                try {
                    const url = `${ENDPOINTS.mintOnDemand}?action=get_group_status&group_id=${groupId}&buyer_account=${encodeURIComponent(CONFIG.account)}`;
                    const resp = await fetch(url);
                    const data = await resp.json();
                    // 4j: stash the whole payload so the failure branch can read `recoverable`
                    // and `is_free` without a second fetch. Absent fields default to the calm copy.
                    modState.lastStatusData = data.data || {};

                    if (!data.success) {
                        console.warn('Poll error:', data.error);
                        // Don't fail yet — server might still be working
                        if (polls > 5) {
                            clearInterval(interval);
                            reject(new Error(data.error || 'Status check failed'));
                        }
                        return;
                    }

                    const group = data.data.group;
                    const purchases = data.data.purchases || [];
                    nextAllowedAt = Date.now() + (parseInt(data.data.poll_after_ms) || 0);   // M7b: 0 when the handler predates M7b = today's cadence
                    const queueAhead = (data.data.queue_ahead !== undefined && data.data.queue_ahead !== null) ? parseInt(data.data.queue_ahead) : null;
                    const minted = parseInt(group.mint_progress) || 0;
                    const status = group.status;
                    // M8-b: the ledger truth -- purchases whose NFT is already minted on-chain,
                    // and how long the group has sat in 'minting' without progress movement.
                    const mintedOnChain = purchases.filter(p => p && p.nftoken_id).length;
                    const stalledSec = parseInt(data.data.stalled_seconds) || 0;

                    // Update progress bar
                    const pct = Math.max(10, Math.round((minted / totalQty) * 100));
                    if (progressBar) progressBar.style.width = `${Math.min(pct, 95)}%`;
                    if (mintNote) {
                        if (status === 'minting') {
                            if (stalledSec > 120 && mintedOnChain > 0) {
                                // M8-b state 3: a self-heal window (the 6142 class). Say the truth.
                                mintNote.innerHTML = `<strong>Your NFT${totalQty > 1 ? 's are' : ' is'} minted on the ledger \u2014 delivery is finalizing.</strong><br>`
                                    + `This can take a little while. You can safely close this page and claim from your <a href="/trading-hub-dashboard/"><strong>Purchase History</strong></a> shortly.`;
                            } else if (mintedOnChain > minted) {
                                // M8-b state 2: the phase gap -- minted on-chain, claim offers in flight.
                                mintNote.textContent = `${mintedOnChain} of ${totalQty} minted on the ledger \u2014 creating your claim offers...`;
                            } else {
                                mintNote.textContent = `Minting... ${minted} of ${totalQty} complete`;
                            }
                        } else if (status === 'paid') {
                            if (queueAhead !== null) {
                                // M7b: the queued state is first-class. Stage 1 guarantees the slot and M3 guarantees
                                // delivery, so this is a promise the system now keeps -- the buyer can leave.
                                const etaMin = Math.max(1, Math.ceil((parseInt(data.data.eta_seconds) || 0) / 60));
                                const pos = queueAhead + 1;
                                mintNote.innerHTML = `<strong>High demand — your payment is verified and your edition is reserved.</strong><br>` +
                                    `You're approximately <strong>#${pos}</strong> in the mint queue` + (queueAhead > 0 ? ` (about ${etaMin} min)` : '') + `.<br>` +
                                    `You can close this page — your NFT will appear in your <a href="/trading-hub-dashboard/"><strong>Purchase History</strong></a> as soon as it's minted.`;
                                polls = 0;   // M7b: a queue is not a stall -- the timeout below means MY mint stopped advancing, never that the line is long
                            } else {
                                mintNote.textContent = `Queued — waiting for server...`;
                            }
                        }
                    }

                    // ── COMPLETE ──
                    if (status === 'minted') {
                        clearInterval(interval);
                        if (progressBar) progressBar.style.width = '100%';
                        if (mintNote) mintNote.textContent = `✅ ${minted} of ${totalQty} minted!`;

                        // Store minted purchases for claim flow
                        modState.currentGroup = {
                            ...modState.currentGroup,
                            status: 'minted',
                            minted: minted,
                            total: totalQty,
                            purchases: purchases
                        };

                        setTimeout(() => {
                            updatePurchaseStep(3);
                            showMultiClaimUI(purchases);
                        }, 1200);

                        resolve();
                        return;
                    }

                    // ── PARTIAL SUCCESS (v242) ──
                    // Some NFTs minted, some failed. Show what's claimable + retry notice.
                    if (status === 'partial') {
                        clearInterval(interval);
                        const failed = totalQty - minted;
                        if (progressBar) progressBar.style.width = Math.round((minted / totalQty) * 100) + '%';
                        if (mintNote) mintNote.textContent = `⚠️ ${minted} of ${totalQty} minted — ${failed} failed`;
                        // 4j: the rest are being re-driven automatically; and "if you were charged"
                        // is meaningless on a free mint. Server-supplied is_free decides the wording.
                        const _pFree = (data.data && data.data.is_free === true);
                        showToast(
                            `${minted} of ${totalQty} minted. We are still processing the remaining ${failed}. No action needed.`
                            + (_pFree ? '' : ' If you were charged for them, contact support.'),
                            'info'
                        );

                        modState.currentGroup = {
                            ...modState.currentGroup,
                            status: 'partial',
                            minted: minted,
                            total: totalQty,
                            purchases: purchases
                        };

                        // Still move to claim step for the minted ones
                        setTimeout(() => {
                            updatePurchaseStep(3);
                            showMultiClaimUI(purchases);
                        }, 1200);

                        resolve();
                        return;
                    }

                    // ── FAILED ──
                    if (status === 'failed') {
                        clearInterval(interval);
                        const errMsg = group.error_message || 'Minting failed';
                        reject(new Error(errMsg));
                        return;
                    }

                    // ── TIMEOUT ──
                    if (polls >= MAX_POLLS || (Date.now() - startedAt) > MAX_WAIT_MS) {   // M7b: + absolute 2h cap
                        clearInterval(interval);
                        // v452: Use POLL_TIMEOUT: prefix so catch block shows distinct UI
                        reject(new Error('POLL_TIMEOUT:Minting is taking longer than expected. Your NFTs are still being processed in the background.'));
                    }

                } catch (e) {
                    console.warn('Poll fetch error:', e);
                    if (polls >= MAX_POLLS) {
                        clearInterval(interval);
                        reject(new Error('POLL_TIMEOUT:Connection interrupted. Your NFTs may still be processing in the background.'));
                    }
                } finally {
                    inFlight = false;   // M1
                }
            }, POLL_INTERVAL);
        });
    }

    /**
     * Load claim data for an already-minted group (used on re-submit or page reload)
     */
    async function loadClaimData(groupId) {
        try {
            const url = `${ENDPOINTS.mintOnDemand}?action=get_group_status&group_id=${groupId}&buyer_account=${encodeURIComponent(CONFIG.account)}`;
            const resp = await fetch(url);
            const data = await resp.json();
            if (data.success) {
                updatePurchaseStep(3);
                showMultiClaimUI(data.data.purchases || []);
            }
        } catch (e) {
            console.error('loadClaimData error:', e);
        }
    }


    // ─── STEP 3: MULTI-CLAIM UI (one-by-one, Approach A) ────────────────

    /**
     * Show list of minted NFTs with individual claim buttons (Task #13)
     */
    /**
     * Task G (Aug 2026): pre-claim NFT preview popup.
     * Presentation only — no payment/claim logic. Image chain:
     * store thumb (img.php?nft=) → listing/purchase cover → fallback svg.
     */
    function viewPreClaim(nftId, coverCid) {
        if (!/^[A-Fa-f0-9]{64}$/.test(String(nftId || ''))) return;
        let ov = document.getElementById('mod-viewpreclaim-overlay');
        if (ov) ov.remove();
        const cover = coverCid || String((modState.currentListing && modState.currentListing.cover_ipfs) || '').replace('ipfs://', '');
        const primary = 'https://metadata.imcollectibles.io/img.php?nft=' + encodeURIComponent(nftId) + '&thumb=1';
        const fallback = cover ? ('https://metadata.imcollectibles.io/img.php?url=' + encodeURIComponent('ipfs://' + cover) + '&thumb=1') : '/wp-content/uploads/fallback-nft.svg';
        ov = document.createElement('div');
        ov.id = 'mod-viewpreclaim-overlay';
        ov.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.78);z-index:2147483647;display:flex;align-items:center;justify-content:center;padding:20px';
        ov.innerHTML = `
            <div style="background:#14141f;border:1px solid #2e2e46;border-radius:16px;max-width:420px;width:100%;overflow:hidden;box-shadow:0 18px 60px rgba(0,0,0,0.6)">
                <div style="display:flex;justify-content:flex-end;padding:8px 10px 0"><button id="mod-vpc-close" style="background:none;border:none;color:#9a9ab4;font-size:1.5rem;cursor:pointer;line-height:1">×</button></div>
                <div style="padding:0 22px"><div style="aspect-ratio:1/1;background:#101020;border-radius:12px;overflow:hidden">
                    <img src="${primary}" style="width:100%;height:100%;object-fit:cover" onerror="this.onerror=null;this.src='${fallback}'">
                </div></div>
                <div style="margin:16px 22px 0;padding:10px 14px;border-radius:10px;background:rgba(201,168,76,0.12);border:1px solid rgba(201,168,76,0.4);color:#e8d9a0;font-size:0.85rem;text-align:center;font-weight:600">
                    🔒 Master Revealed once Claimed!
                </div>
                <div style="display:flex;gap:10px;justify-content:center;padding:16px 22px 20px">
                    <a href="/nft/${encodeURIComponent(nftId)}/" target="_blank" rel="noopener" class="mod-btn mod-btn-small">Open Full Page ↗</a>
                    <button id="mod-vpc-close2" class="mod-btn mod-btn-primary mod-btn-small">Close</button>
                </div>
            </div>`;
        const close = () => ov.remove();
        ov.addEventListener('click', (e) => { if (e.target === ov) close(); });
        // Fix3: if the purchase/claim modal is open, append INSIDE it — whatever
        // promotes that modal above max-z siblings, we inherit by being its last
        // child (later sibling of .mod-modal-content paints above it). Body fallback
        // covers the purchase-history context where no modal is open.
        const modHost = document.getElementById('mod-purchase-modal');
        const hostVisible = modHost && window.getComputedStyle(modHost).display !== 'none';
        (hostVisible ? modHost : document.body).appendChild(ov);
        document.getElementById('mod-vpc-close').addEventListener('click', close);
        document.getElementById('mod-vpc-close2').addEventListener('click', close);
    }

    function showMultiClaimUI(purchases) {
        const listEl = document.getElementById('mod-claim-list');
        if (!listEl) return;

        const mintedPurchases = purchases.filter(p => p.mint_status === 'minted' && p.sell_offer_id);

        if (mintedPurchases.length === 0) {
            listEl.innerHTML = '<p style="text-align:center;color:#ef4444;">No NFTs ready to claim. Check your purchase history.</p>';
            return;
        }

        const header = document.querySelector('#mod-step-3 h4');
        if (header) header.textContent = mintedPurchases.length === 1 ? 'Your NFT is Ready!' : `Your ${mintedPurchases.length} NFTs are Ready!`;

        listEl.innerHTML = mintedPurchases.map(p => `
            <div class="mod-claim-item" id="mod-claim-item-${p.id}">
                <div class="mod-claim-info">
                    <span class="mod-claim-edition">Edition #${p.edition_number}</span>
                    ${p.tier_name ? `<span class="mod-claim-tier mod-tier-badge">${escapeHtml(p.tier_name)}</span>` : ''}
                </div>
                ${p.nftoken_id ? `<button class="mod-btn mod-btn-small mod-view-preclaim-btn"
                        onclick="window.mintOnDemand.viewPreClaim('${p.nftoken_id}')">
                    👁️ View NFT
                </button>` : ''}
                <button class="mod-btn mod-btn-primary mod-btn-small mod-claim-btn"
                        id="mod-claim-btn-${p.id}"
                        onclick="window.mintOnDemand.claimSingle(${p.id})">
                    🎁 Claim
                </button>
            </div>
        `).join('');

        // Hide QR section initially
        document.getElementById('mod-claim-qr-section').style.display = 'none';
    }

    /**
     * J1-a (Sep 2026): LIVE Joey state, replacing the boot-time snapshot.
     *
     * window.__joeySession is written ONCE by an async reconnect() in joey-login.php
     * and never updated again, so it is wrong in BOTH directions:
     *   - undefined while the WalletConnect relay round-trip is still in flight, so a
     *     genuinely connected user reads as not-Joey (the boot race);
     *   - stale true after the session dies, so we post wallet=joey and sign() then
     *     throws "imuWallet: not connected" anyway.
     * The bundle exposes isConnected() -> !!Ut, and Ut is nulled by the bundle's own
     * session_delete handler, so it actually tracks teardown. Nothing in the theme
     * called it before this.
     *
     * Deliberately SYNCHRONOUS. reconnect() awaits a WalletConnect SignClient init,
     * which is network-bound AND runs for Xaman users too (the bundle is loaded for
     * every logged-in user since the heal fix), so any blocking pre-wait here would
     * add seconds to every Xaman claim. The authoritative answer now comes from the
     * session row server-side (J1-c); this flag only ever ADDS confidence.
     */
    function imcJoeyNow() {
        var w = window.imuWallet;
        if (!w) return { live: false, account: '' };
        try {
            return {
                live:    !!(typeof w.isConnected === 'function' && w.isConnected()),
                account: (typeof w.getAccount === 'function' && w.getAccount()) || ''
            };
        } catch (e) { return { live: false, account: '' }; }
    }

    /**
     * J1-b (Sep 2026): one best-effort re-pair. Same call joey-login.php makes at
     * boot and idempotent (it re-reads the existing WC session), but JE.init() has
     * no timeout of its own, so it is raced against one. Never throws: the caller
     * re-reads imcJoeyNow() and decides.
     */
    async function imcJoeyRepair(maxMs) {
        var w = window.imuWallet;
        if (!w || typeof w.reconnect !== 'function') return false;
        try {
            await Promise.race([
                w.reconnect(),
                new Promise(function (r) { setTimeout(r, maxMs || 6000); })
            ]);
        } catch (e) { /* a failed re-pair is not an error here -- we re-check below */ }
        return imcJoeyNow().live;
    }

    /**
     * Claim a single NFT (Approach A — one at a time via Xaman)
     */
    async function claimSingle(purchaseId) {
        const claimBtn = document.getElementById(`mod-claim-btn-${purchaseId}`);
        if (claimBtn) { claimBtn.disabled = true; claimBtn.innerHTML = '<span class="mod-spinner-small"></span>'; }

        try {
            // v275: Refresh nonce before claim — the claim step can happen many
            // minutes after the payment step, which may have expired the nonce.
            await refreshNonce();

            const formData = new FormData();
            formData.append('action', 'claim_nft');
            formData.append('nonce', CONFIG.nonce);
            formData.append('purchase_id', purchaseId);
            formData.append('buyer_account', CONFIG.account);

            // J1-a: these four lines change as ONE unit. The old line 2178 dereferenced
            // window.__joeySession.account, which was safe ONLY because the line above it
            // had already proved __joeySession was truthy. Swapping the detector alone
            // would make it throw "Cannot read properties of undefined (reading 'account')".
            const joeyC     = imcJoeyNow();
            const joeyLiveC = joeyC.live;
            if (joeyLiveC && joeyC.account && CONFIG.account !== joeyC.account) {
                showToast('Connected wallet does not match your account. Please reconnect.', 'error');
                if (claimBtn) { claimBtn.disabled = false; claimBtn.innerHTML = '🎁 Claim'; }
                return;
            }
            if (joeyLiveC) formData.append('wallet', 'joey');

            const response = await fetch(ENDPOINTS.mintOnDemand, { method: 'POST', body: formData });
            const data = await response.json();

            // v275: Handle nonce expiry — auto-refresh and retry once
            if (!data.success && data.nonce_expired) {
                console.warn('Claim nonce expired, refreshing and retrying...');
                await refreshNonce();
                const retryFormData = new FormData();
                retryFormData.append('action', 'claim_nft');
                retryFormData.append('nonce', CONFIG.nonce);
                retryFormData.append('purchase_id', purchaseId);
                retryFormData.append('buyer_account', CONFIG.account);
                if (joeyLiveC) retryFormData.append('wallet', 'joey');
                const retryResp = await fetch(ENDPOINTS.mintOnDemand, { method: 'POST', body: retryFormData });
                const retryData = await retryResp.json();
                if (!retryData.success) throw new Error(retryData.error || 'Failed to create claim');
                // Use retry response going forward
                Object.assign(data, retryData);
            } else if (!data.success) {
                throw new Error(data.error || 'Failed to create claim');
            }

            // Joey claim: sign the AcceptOffer txjson locally, then confirm delivery.
            if (data.data.wallet === 'joey' && data.data.txjson) {
                const claimStatusJ = document.getElementById('mod-claim-status');
                if (claimStatusJ) claimStatusJ.innerHTML = '<div class="mod-spinner"></div><span>Check your wallet to sign the claim...</span>';
                if (data.data.nftoken_id) modState.lastClaimedNftId = data.data.nftoken_id;

                // J1-b: the server has decided this is a Joey claim, so this is the right
                // moment -- and the only moment -- to pay for a re-pair. Every other
                // value-action in the theme bounces here with the v684 message
                // (trading.js:1256, mint-on-demand.js:317, page-nft-single.php:4077, ...).
                // claimSingle was the ONLY one missing it, and that is precisely why FREE
                // mints failed: a free mint returns at the free_mint branch BEFORE the
                // handler's Joey branch, so the wallet is never exercised at purchase and
                // this is the first and only wallet call in the whole lifecycle.
                if (!imcJoeyNow().live) {
                    if (claimStatusJ) claimStatusJ.innerHTML = '<div class="mod-spinner"></div><span>Reconnecting your wallet...</span>';
                    await imcJoeyRepair();
                }
                if (!imcJoeyNow().live) {
                    const msgR = 'Wallet still connecting — please try again in a moment.';
                    showToast(msgR, 'error');
                    // claimFromHistory AUTO-FIRES claimSingle (v299), so on the dashboard
                    // route the buyer clicked nothing and the modal simply opened. A toast
                    // alone would be missed -- write it into the modal too.
                    if (claimStatusJ) claimStatusJ.innerHTML = '<span style="color:#f59e0b;">' + msgR + '</span>';
                    if (claimBtn) { claimBtn.disabled = false; claimBtn.innerHTML = '🎁 Claim'; }
                    return;
                }

                let signedC;
                try {
                    // Guarded: when the bundle failed to load entirely, the old expression
                    // (window.imuJoeySign||window.imuWallet.sign) threw a TypeError on
                    // undefined rather than a message anyone could act on.
                    const signerC = window.imuJoeySign || (window.imuWallet && window.imuWallet.sign);
                    if (typeof signerC !== 'function') throw new Error('Wallet not available');
                    signedC = await signerC(data.data.txjson);
                }
                catch (e) {
                    const emC = (e && e.message) || '';
                    // J1-b: do NOT flatten "imuWallet: not connected" into "Claim rejected."
                    // That destroyed the only diagnostic the buyer could report back, and is
                    // why the original report reached us as a vague Xaman complaint.
                    const msgC = /timed out|timeout/i.test(emC) ? 'Claim timed out. Please try again.'
                               : /cancel/i.test(emC) ? 'Claim cancelled.'
                               : /not connected|not available/i.test(emC) ? 'Your Joey wallet disconnected. Reopen Joey, then tap Claim again.'
                               : 'Claim rejected.';
                    showToast(msgC, 'error');
                    if (claimStatusJ) claimStatusJ.innerHTML = '<span style="color:#ef4444;">' + msgC + '</span>';
                    if (claimBtn) { claimBtn.disabled = false; claimBtn.innerHTML = '🎁 Claim'; }
                    return;
                }
                const txHashC = signedC && (signedC.hash || signedC.tx_hash);
                if (!txHashC) {
                    showToast('No transaction hash returned from your wallet.', 'error');
                    if (claimStatusJ) claimStatusJ.innerHTML = '<span style="color:#ef4444;">No transaction hash returned. Please try again.</span>';
                    if (claimBtn) { claimBtn.disabled = false; claimBtn.innerHTML = '🎁 Claim'; }
                    return;
                }
                await confirmDelivery(purchaseId, txHashC);
                if (claimBtn) { claimBtn.disabled = true; claimBtn.innerHTML = '✓ Claimed'; claimBtn.classList.add('mod-btn-claimed'); }
                showToast('NFT claimed!', 'success');
                // v559: mirror the Xaman claim completion -- only finish + redirect once EVERY
                // edition is claimed; otherwise stay in the modal so the next Claim button can be used.
                const allBtnsJ = document.querySelectorAll('.mod-claim-btn');
                const allClaimedJ = [...allBtnsJ].every(b => b.disabled);
                if (allClaimedJ) {
                    const warmIdJ = modState.currentListing?.id || modState.currentListing?.listing_id;
                    if (warmIdJ) { fetch(`${ENDPOINTS.mintOnDemand}?action=warm_image_cache&listing_id=${warmIdJ}`).catch(() => {}); }
                    setTimeout(() => {
                        updatePurchaseStep(4);
                        showToast('🎉 All NFTs claimed!', 'success');
                        const claimedNftIdJ = modState.lastClaimedNftId;
                        const totalMintedJ  = modState.currentGroup?.total || modState.currentGroup?.quantity || 1;
                        setTimeout(() => {
                            if (totalMintedJ === 1 && claimedNftIdJ) { window.location.href = `/nft/${claimedNftIdJ}/`; }
                            else { window.location.href = '/trading-hub-dashboard/'; }
                        }, 2200);
                    }, 800);
                } else {
                    showToast('✅ NFT claimed! Tap the next Claim button below.', 'success');
                }
                return;
            }

            // Show QR section
            const qrSection = document.getElementById('mod-claim-qr-section');
            if (qrSection) qrSection.style.display = 'block';

            const qrImg = document.getElementById('mod-claim-qr');
            const deeplink = document.getElementById('mod-claim-deeplink');
            if (qrImg && data.data.qr_png)     qrImg.src = data.data.qr_png;
            if (deeplink && data.data.deeplink) deeplink.href = data.data.deeplink;
            if (modIsMobileUA && deeplink) deeplink.setAttribute('target', '_self'); // v590 mobile: open Xaman in same tab -- no orphan tab

            const claimStatusEl = document.getElementById('mod-claim-status');
            if (claimStatusEl) claimStatusEl.innerHTML = `
                <div class="mod-spinner"></div>
                <span>Claiming Edition #${data.data.edition_number}${data.data.tier_name ? ' (' + data.data.tier_name + ')' : ''}...</span>
            `;

            // v275: Store nftoken_id so post-claim redirect goes to the NFT page
            if (data.data.nftoken_id) {
                modState.lastClaimedNftId = data.data.nftoken_id;
            }

            // Poll for claim completion
            startClaimPolling(data.data.uuid, purchaseId);

        } catch (error) {
            console.error('Claim error:', error);
            showToast('Failed to claim: ' + error.message, 'error');
            if (claimBtn) { claimBtn.disabled = false; claimBtn.innerHTML = '🎁 Claim'; }
        }
    }

    /**
     * Poll for claim completion — FIXED: checks dispatched_result (Task #11)
     */
    function startClaimPolling(uuid, purchaseId) {
        stopClaimPolling();

        // v294: Save claim poll state to sessionStorage so the trading hub
        // dashboard can detect an in-progress claim if the modal page was
        // replaced by the Xaman return_url. The dashboard's claim_complete
        // handler is the primary recovery path, but this state lets the
        // modal resume on Android where the tab may survive.
        try {
            sessionStorage.setItem('_imc_claim_uuid',       uuid);
            sessionStorage.setItem('_imc_claim_purchase',   String(purchaseId));
            sessionStorage.setItem('_imc_claim_ts',         String(Date.now()));
        } catch(e) {}

        modState.claimPollInterval = setInterval(async () => {
            try {
                const response = await fetch(`${ENDPOINTS.mintOnDemand}?action=poll_claim&uuid=${uuid}`);
                const data = await response.json();
                const statusEl = document.getElementById('mod-claim-status');

                // Signed but waiting for confirmation
                if (data.data?.signed && !data.data?.dispatched_result) {
                    if (statusEl) statusEl.innerHTML = '<div class="mod-spinner"></div><span>Signed! Confirming on blockchain...</span>';
                    return;
                }

                // ✅ FIX (Task #11): Check dispatched_result === tesSUCCESS
                if (data.data?.signed && data.data?.dispatched_result === 'tesSUCCESS') {
                    stopClaimPolling();

                    const txHash = data.data.tx_hash || '';

                    // Mark delivered server-side
                    await confirmDelivery(purchaseId, txHash);

                    if (statusEl) statusEl.innerHTML = '<span style="color:#10b981;">✅ Claimed!</span>';

                    // Update UI: mark this item as claimed
                    const claimItem = document.getElementById(`mod-claim-item-${purchaseId}`);
                    if (claimItem) {
                        const btn = claimItem.querySelector('.mod-claim-btn');
                        if (btn) { btn.innerHTML = '✅ Claimed'; btn.disabled = true; btn.classList.add('mod-btn-claimed'); }
                    }

                    // Hide QR section
                    document.getElementById('mod-claim-qr-section').style.display = 'none';

                    // Check if all claimed
                    const allBtns = document.querySelectorAll('.mod-claim-btn');
                    const allClaimed = [...allBtns].every(b => b.disabled);

                    if (allClaimed) {
                        // v309: Fire-and-forget VPS image cache warm for this listing.
                        // Hits img.php for every unique tier cover CID so all edition
                        // images are on VPS disk before the collection page is loaded.
                        // nocache=1 is included to bust any poisoned cache entries (#5 fix).
                        const warmListingId = modState.currentListing?.id || modState.currentListing?.listing_id;
                        if (warmListingId) {
                            fetch(`${ENDPOINTS.mintOnDemand}?action=warm_image_cache&listing_id=${warmListingId}`)
                                .catch(() => {}); // Intentionally fire-and-forget
                        }

                        setTimeout(() => {
                            updatePurchaseStep(4); // Shows success
                            showToast('🎉 All NFTs claimed!', 'success');
                            // v304: Redirect logic:
                            //   qty=1 → /nft/TOKEN_ID/ (direct to NFT page)
                            //   qty>1 → /trading-hub-dashboard/?recently_minted=1&group_id=X
                            //           Dashboard shows a "Recently Minted" banner with all NFTs.
                            const claimedNftId = modState.lastClaimedNftId;
                            const totalMinted  = modState.currentGroup?.total || modState.currentGroup?.quantity || 1;
                            setTimeout(() => {
                                // v305: Always redirect cleanly to the dashboard.
                                // Recently Minted section loads automatically from purchase history —
                                // no URL params needed. Single-mint also uses this path so mobile
                                // Xaman return URL never produces a Whoops/200 error.
                                if (totalMinted === 1 && claimedNftId) {
                                    window.location.href = `/nft/${claimedNftId}/`;
                                } else {
                                    window.location.href = '/trading-hub-dashboard/';
                                }
                            }, 2200);
                        }, 800);
                    } else {
                        showToast('✅ NFT claimed! Tap the next Claim button below.', 'success');
                    }
                    return;
                }

                // Failed claim
                if (data.data?.signed && data.data?.dispatched_result && data.data?.dispatched_result !== 'tesSUCCESS') {
                    stopClaimPolling();
                    const msg = getXrplErrorMessage(data.data.dispatched_result);
                    if (statusEl) statusEl.innerHTML = `<span style="color:#ef4444;">❌ ${msg}</span>`;

                    // Re-enable claim button
                    const claimBtn = document.getElementById(`mod-claim-btn-${purchaseId}`);
                    if (claimBtn) { claimBtn.disabled = false; claimBtn.innerHTML = '🎁 Retry'; }
                    return;
                }

                // Rejected
                if (data.data?.rejected) {
                    stopClaimPolling();
                    if (statusEl) statusEl.innerHTML = '<span style="color:#ef4444;">❌ Claim rejected</span>';
                    const claimBtn = document.getElementById(`mod-claim-btn-${purchaseId}`);
                    if (claimBtn) { claimBtn.disabled = false; claimBtn.innerHTML = '🎁 Retry'; }
                    return;
                }

                // Expired
                if (data.data?.expired) {
                    stopClaimPolling();
                    if (statusEl) statusEl.innerHTML = '<span style="color:#ef4444;">⏰ Claim expired</span>';
                    const claimBtn = document.getElementById(`mod-claim-btn-${purchaseId}`);
                    if (claimBtn) { claimBtn.disabled = false; claimBtn.innerHTML = '🎁 Retry'; }
                }

            } catch (error) {
                console.error('Claim poll error:', error);
            }
        }, 3000);

        // J1-d (Sep 2026): this used to be a bare setTimeout(stopClaimPolling, ...).
        // stopClaimPolling() only clears the interval and sessionStorage -- it renders
        // nothing -- so an unresolved claim left a spinner turning forever with no error
        // and no way forward. Say something instead, and hand the button back.
        setTimeout(function () {
            if (!modState.claimPollInterval) return;   // already finished; nothing to say
            stopClaimPolling();
            const stEl = document.getElementById('mod-claim-status');
            if (stEl) stEl.innerHTML = '<span style="color:#f59e0b;">Still waiting for this claim to confirm. '
                + 'Your NFT is safe — reopen Pending Claims from the trading hub dashboard to try again.</span>';
            const btnEl = document.getElementById('mod-claim-btn-' + purchaseId);
            if (btnEl) { btnEl.disabled = false; btnEl.innerHTML = '🎁 Claim'; }
        }, 30 * 60 * 1000);
    }

    function stopClaimPolling() {
        if (modState.claimPollInterval) { clearInterval(modState.claimPollInterval); modState.claimPollInterval = null; }
        try {
            sessionStorage.removeItem('_imc_claim_uuid');
            sessionStorage.removeItem('_imc_claim_purchase');
            sessionStorage.removeItem('_imc_claim_ts');
        } catch(e) {}
    }

    /**
     * Confirm delivery server-side (Task #15)
     */
    async function confirmDelivery(purchaseId, txHash) {
        try {
            const formData = new FormData();
            formData.append('action', 'confirm_delivery');
            formData.append('nonce', CONFIG.nonce);
            formData.append('purchase_id', purchaseId);
            formData.append('buyer_account', CONFIG.account);
            formData.append('tx_hash', txHash);

            await fetch(ENDPOINTS.mintOnDemand, { method: 'POST', body: formData });
        } catch (err) {
            console.error('Confirm delivery error:', err);
        }
    }


    // =========================================================================
    // HELPER: Close modal + stop all polling
    // =========================================================================

    function stopAllPolling() {
        stopPaymentPolling();
        stopClaimPolling();
        stopFeePolling();
        if (modState.mintPollInterval) { clearInterval(modState.mintPollInterval); modState.mintPollInterval = null; }
    }

    function closePurchaseModal() {
        const modal = document.getElementById('mod-purchase-modal');
        if (modal) modal.style.display = 'none';

        stopAllPolling();
        ppStopPoll(); // PP-2: kill the price soft-poll with the modal

        // v61 FIX: Check if any NFTs were claimed (buttons have mod-btn-claimed class)
        const claimedBtns = document.querySelectorAll('.mod-claim-btn.mod-btn-claimed');
        const hadClaims = claimedBtns.length > 0;
        
        modState.currentGroup = null;
        modState.currentListing = null;
        modState.purchaseInProgress = false;
        
        // v61 FIX: Refresh page if any claims were made so user sees their NFTs
        if (hadClaims) {
            window.location.reload();
        }
    }


    // =========================================================================
    // MARKETPLACE: LISTING CARDS
    // =========================================================================

    async function loadMarketplaceListings(options = {}) {
        const { type = '', sort = 'newest', limit = 24, offset = 0, container = '#mod-listings-grid' } = options;
        const containerEl = document.querySelector(container);
        if (!containerEl) return;

        containerEl.innerHTML = '<div class="mod-loading"><div class="mod-spinner"></div><span>Loading listings...</span></div>';

        try {
            const params = new URLSearchParams({ action: 'get_marketplace', type, sort, limit, offset });
            const response = await fetch(`${ENDPOINTS.listings}?${params}`);
            const data = await response.json();
            if (!data.success) throw new Error(data.error || 'Failed to load listings');

            renderListingCards(data.data.listings, containerEl);

            if (data.data.total > limit) {
                renderPagination(data.data.total, limit, offset, containerEl);
            }
        } catch (error) {
            console.error('Load listings error:', error);
            containerEl.innerHTML = `<div class="mod-error"><p>Failed to load listings: ${error.message}</p></div>`;
        }
    }

    function renderListingCards(listings, container) {
        if (!listings || listings.length === 0) {
            container.innerHTML = `<div class="mod-empty"><span class="mod-empty-icon">🎵</span><p>No listings available yet</p></div>`;
            return;
        }

        const html = listings.map(listing => {
            const available = listing.available_editions ?? (listing.total_editions - listing.minted_count - (listing.reserved_count || 0));
            // v393: Open editions are never sold out while active (closed OEs display as sold out)
            const isOpenEdition = (listing.edition_type === 'open' || listing.is_open_edition) && !listing.open_edition_closed_at;
            const soldOut = !isOpenEdition && available <= 0;
            
            // v233: Detect scheduled future mints
            const isScheduled = listing.launch_type === 'scheduled' && listing.launch_at
                             && new Date(listing.launch_at.includes('Z') ? listing.launch_at : listing.launch_at + 'Z') > new Date();

            // v393: OE editions display
            const editionsStr = (isOpenEdition && parseInt(listing.total_editions) === 0)
                ? `${listing.minted_count} minted (Open Edition)`
                : `${available} of ${listing.total_editions} available`;

            // v459: Effective price for card display (matches v448 collections.php fallback)
            let cardPrice = parseFloat(listing.price_xrp) || 0;
            if (cardPrice === 0 && Array.isArray(listing.accepted_currencies)) {
                const xrpC = listing.accepted_currencies.find(c => c.currency === 'XRP' && c.enabled !== false && parseFloat(c.price || 0) > 0);
                if (xrpC) cardPrice = parseFloat(xrpC.price);
            }

            return `
                <div class="mod-listing-card" data-listing-id="${listing.id}">
                    <div class="mod-listing-cover">
                        <img src="https://ipfs.io/ipfs/${listing.cover_ipfs.replace('ipfs://', '')}"
                             alt="${escapeHtml(listing.nft_name)}" loading="lazy"
                             onerror="this.src='/wp-content/themes/astra/images/default-cover.png'">
                        <span class="mod-listing-type">${({musicvideo:'🎬',album:'💿',art:'🎨',film:'🎥'}[listing.nft_type] || '🎵')}</span>
                        ${listing.has_tiers && listing.has_tiers !== '0' ? '<span class="mod-listing-tiered">🎲 Tiers</span>' : ''}
                        ${soldOut ? '<span class="mod-listing-soldout">SOLD OUT</span>' : ''}
                        ${isOpenEdition && !soldOut ? '<span class="mod-listing-tiered" style="background:rgba(16,185,129,0.9)">Open Mint</span>' : ''}
                        ${isScheduled ? '<span class="mod-scheduled-badge">🚀 Coming Soon</span>' : ''}
                    </div>
                    <div class="mod-listing-info">
                        <h4 class="mod-listing-name">${escapeHtml(listing.nft_name)}</h4>
                        <p class="mod-listing-artist">${escapeHtml(listing.artist_name || truncateAddress(listing.artist_account))}</p>
                        <div class="mod-listing-meta">
                            <span class="mod-listing-editions">${editionsStr}</span>
                            <span class="mod-listing-royalty" title="Artist royalty">${(listing.transfer_fee / 1000).toFixed(1)}%</span>
                        </div>
                        <div class="mod-listing-footer">
                            <span class="mod-listing-price">${formatXRP(cardPrice)} XRP</span>
                            <button class="mod-mint-btn ${soldOut || isScheduled ? 'mod-btn-disabled' : ''}"
                                    ${soldOut || isScheduled ? 'disabled' : ''}
                                    onclick="window.mintOnDemand.showPurchaseModal(${listing.id})">
                                ${soldOut ? 'Sold Out' : isScheduled ? '🚀 Coming Soon' : '🛒 Mint'}
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        container.innerHTML = html;
    }

    function renderPagination(total, limit, offset, container) {
        const totalPages = Math.ceil(total / limit);
        const currentPage = Math.floor(offset / limit) + 1;

        const paginationEl = document.createElement('div');
        paginationEl.className = 'mod-pagination';

        let html = '';
        if (currentPage > 1) html += `<button onclick="window.mintOnDemand.loadPage(${currentPage - 1})">← Prev</button>`;
        html += `<span class="mod-page-info">Page ${currentPage} of ${totalPages}</span>`;
        if (currentPage < totalPages) html += `<button onclick="window.mintOnDemand.loadPage(${currentPage + 1})">Next →</button>`;

        paginationEl.innerHTML = html;
        container.appendChild(paginationEl);
    }


    // =========================================================================
    // PURCHASE HISTORY (with unclaimed badges)
    // =========================================================================

    async function loadPurchaseHistory(options = {}) {
        const { limit = 50, offset = 0, container = '#mod-purchase-history' } = options;
        const containerEl = document.querySelector(container);
        if (!containerEl || !CONFIG.account) return;

        containerEl.innerHTML = '<div class="mod-loading"><div class="mod-spinner"></div><span>Loading purchases...</span></div>';

        try {
            const params = new URLSearchParams({ action: 'get_buyer_purchases', account: CONFIG.account, limit, offset });
            const response = await fetch(`${ENDPOINTS.mintOnDemand}?${params}`);
            const data = await response.json();
            if (!data.success) throw new Error(data.error || 'Failed to load purchases');
            renderPurchaseHistory(data.data, containerEl);
        } catch (error) {
            console.error('Load purchase history error:', error);
            containerEl.innerHTML = `<div class="mod-error"><p>Failed to load: ${error.message}</p></div>`;
        }
    }

    function renderPurchaseHistory(purchases, container) {
        if (!purchases || purchases.length === 0) {
            container.innerHTML = `
                <div class="mod-empty">
                    <span class="mod-empty-icon">🛒</span>
                    <p>No purchases yet</p>
                    <a href="/marketplace/" class="mod-btn mod-btn-primary">Browse Marketplace</a>
                </div>
            `;
            return;
        }

        const html = purchases.map(purchase => `
            <div class="mod-purchase-row" data-purchase-id="${purchase.id}">
                <div class="mod-purchase-cover">
                    <img src="https://ipfs.io/ipfs/${(purchase.cover_ipfs || '').replace('ipfs://', '')}"
                         alt="${escapeHtml(purchase.nft_name)}"
                         onerror="this.src='/wp-content/themes/astra/images/default-cover.png'">
                </div>
                <div class="mod-purchase-details">
                    <h4>${escapeHtml(purchase.nft_name || 'NFT')}</h4>
                    <p class="mod-purchase-edition">${purchase.edition_number > 0 ? 'Edition #' + purchase.edition_number : (purchase.mint_status === 'failed' ? 'Mint Failed' : '⏳ Pending')}${purchase.edition_number > 0 && purchase.tier_name ? ' · ' + escapeHtml(purchase.tier_name) : ''}</p>
                    <p class="mod-purchase-date">${new Date(purchase.created_at).toLocaleDateString()}</p>
                </div>
                <div class="mod-purchase-status">${getPurchaseStatusBadge(purchase)}</div>
                <div class="mod-purchase-actions">${getPurchaseActions(purchase)}</div>
            </div>
        `).join('');

        container.innerHTML = `<div class="mod-purchase-list">${html}</div>`;
    }

    function getPurchaseStatusBadge(purchase) {
        if (purchase.delivered == 1)
            return '<span class="mod-badge mod-badge-success">✅ Delivered</span>';
        if (purchase.mint_status === 'claimed')
            return '<span class="mod-badge mod-badge-success">✅ Claimed</span>';
        if (purchase.mint_status === 'minted' && purchase.sell_offer_id)
            return '<span class="mod-badge mod-badge-warning">🎁 Ready to Claim</span>';
        if (purchase.mint_status === 'minting')
            return '<span class="mod-badge mod-badge-info">⚡ Minting...</span>';
        if (purchase.mint_status === 'paid')
            return '<span class="mod-badge mod-badge-info">⏳ Processing...</span>';
        if (purchase.mint_status === 'failed')
            return '<span class="mod-badge mod-badge-error">❌ Failed</span>';
        if (purchase.mint_status === 'cancelled')
            return '<span class="mod-badge mod-badge-muted">🚫 Cancelled</span>';
        return '<span class="mod-badge mod-badge-pending">⏳ Pending</span>';
    }

    function getPurchaseActions(purchase) {
        if (purchase.delivered == 1 || purchase.mint_status === 'claimed') {
            return `<a href="/nft/${purchase.nftoken_id}/" class="mod-btn mod-btn-small">View NFT</a>`;
        }
        if (purchase.mint_status === 'minted' && purchase.sell_offer_id) {
            const vBtn = purchase.nftoken_id
                ? `<button class="mod-btn mod-btn-small" onclick="window.mintOnDemand.viewPreClaim('${purchase.nftoken_id}', '${String(purchase.cover_ipfs || '').replace('ipfs://', '')}')">👁️ View</button> `
                : '';
            return `${vBtn}<button class="mod-btn mod-btn-primary mod-btn-small" onclick="window.mintOnDemand.claimFromHistory(${purchase.id})">🎁 Claim</button>`;
        }
        // v275: Failed mints — show Retry button so user can re-trigger minting
        // without having to contact support. process_group resets failed purchases to 'paid'.
        if (purchase.mint_status === 'failed' && purchase.group_id) {
            return `<button class="mod-btn mod-btn-warning mod-btn-small"
                        onclick="window.mintOnDemand._retryFromHistory(${purchase.group_id})"
                        title="${Number(purchase.price_xrp) > 0 ? 'Your payment was received. ' : ''}Click to retry minting.">
                        🔄 Retry Mint</button>`;
        }

        // v889 (Defect E): 'paid' and 'minting' are the two states a stuck mint actually sits
        // in, and until now NEITHER offered any action -- the dashboard rendered a bare
        // "Processing..." badge with no button, while the per-wallet limit counted the row and
        // blocked a fresh mint. The system was kindest to the WORSE failure: a hard-failed
        // purchase got a Retry button, a recoverable one got a dead end. Buyers were
        // left stranded that way.
        //
        // AGE-GATED, deliberately. Both states are also the NORMAL states of a healthy mint in
        // flight, so an unconditional button would appear on every purchase seconds after the
        // buyer clicks Mint, inviting retries mid-mint and re-creating the redundant dispatch
        // storm the v886 poll latch just removed. stale_minutes is computed SERVER-side against
        // the same clock that wrote updated_at, so no browser-timezone skew can trip it early.
        // A healthy mint completes in 10-60s; anything still here after 10 minutes is stuck.
        const IMC_STUCK_MINUTES = 10;
        if ((purchase.mint_status === 'paid' || purchase.mint_status === 'minting')
            && purchase.group_id
            && Number(purchase.stale_minutes) >= IMC_STUCK_MINUTES) {
            return `<button class="mod-btn mod-btn-warning mod-btn-small"
                        onclick="window.mintOnDemand._retryFromHistory(${purchase.group_id})"
                        title="This mint hasn't completed. Click to retry.">
                        🔄 Retry Mint</button>`;
        }
        return '';
    }

    /**
     * Claim from purchase history page
     */
    async function claimFromHistory(purchaseId) {
        const modal = document.getElementById('mod-purchase-modal') || createPurchaseModal();
        resetPurchaseModal();
        updatePurchaseStep(3);

        // Show single-item claim list
        const listEl = document.getElementById('mod-claim-list');
        listEl.innerHTML = `
            <div class="mod-claim-item" id="mod-claim-item-${purchaseId}">
                <div class="mod-claim-info"><span>Loading...</span></div>
                <button class="mod-btn mod-btn-primary mod-btn-small mod-claim-btn"
                        id="mod-claim-btn-${purchaseId}" disabled>
                    <span class="mod-spinner-small"></span>
                </button>
            </div>
        `;
        document.getElementById('mod-claim-qr-section').style.display = 'none';

        modal.style.display = 'flex';

        // Fetch purchase details
        try {
            const resp = await fetch(`${ENDPOINTS.mintOnDemand}?action=get_purchase&id=${purchaseId}&buyer_account=${encodeURIComponent(CONFIG.account)}`);
            const data = await resp.json();
            if (!data.success) throw new Error(data.error);

            const p = data.data;
            listEl.innerHTML = `
                <div class="mod-claim-item" id="mod-claim-item-${p.id}">
                    <div class="mod-claim-info">
                        <span class="mod-claim-edition">${escapeHtml(p.nft_name)} — Edition #${p.edition_number}</span>
                        ${p.tier_name ? `<span class="mod-claim-tier mod-tier-badge">${escapeHtml(p.tier_name)}</span>` : ''}
                    </div>
                    <button class="mod-btn mod-btn-primary mod-btn-small mod-claim-btn"
                            id="mod-claim-btn-${p.id}"
                            onclick="window.mintOnDemand.claimSingle(${p.id})">
                        🎁 Claim
                    </button>
                </div>
            `;

            // v299: Auto-trigger claimSingle immediately — eliminates the confusing
            // extra "Claim" button click inside the modal. Dashboard "Claim NFT" →
            // modal opens → QR appears in one step. The manual button remains as a
            // visible retry anchor if the auto-call fails.
            claimSingle(p.id);

        } catch (err) {
            listEl.innerHTML = `<p style="color:#ef4444;">Failed to load: ${err.message}</p>`;
        }
    }


    // =========================================================================
    // LEGACY COMPATIBILITY
    // =========================================================================

    /**
     * Legacy initiatePurchase — redirects to showPurchaseModal
     */
    async function initiatePurchase(listingId) {
        return showPurchaseModal(listingId);
    }

    /**
     * Legacy claimPurchase — redirects to claimFromHistory
     */
    async function claimPurchase(purchaseId) {
        return claimFromHistory(purchaseId);
    }


    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    function init() {
        const marketplaceGrid = document.getElementById('mod-listings-grid');
        if (marketplaceGrid) loadMarketplaceListings();

        const historyContainer = document.getElementById('mod-purchase-history');
        if (historyContainer && CONFIG.account) loadPurchaseHistory();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }


    // =========================================================================
    // EXPORT PUBLIC API
    // =========================================================================

    window.mintOnDemand = {
        // Artist functions
        refreshNonce,
        createListing,
        showFeePaymentModal,
        publishListing,

        // Buyer functions (new Phase 4 flow)
        showPurchaseModal,
        startPurchase,
        adjustQty,
        selectCurrency,  // v71: Currency selection
        claimSingle,
        claimFromHistory,
        viewPreClaim,
        closePurchaseModal,

        // Legacy compatibility
        initiatePurchase,
        claimPurchase,

        // Marketplace functions
        loadMarketplaceListings,
        loadPage: (page) => loadMarketplaceListings({ offset: (page - 1) * 24 }),

        // History functions
        loadPurchaseHistory,

        // Retry / recovery (called from error UI in active flow)
        _retryProcess: (groupId) => {
            const txHash = modState.currentGroup?.payment_tx_hash;
            if (groupId && txHash) {
                // v402: Restore mint progress UI — error HTML replaced the original
                // mod-mint-note and mod-mint-progress elements that processGroup needs
                const step2 = document.getElementById('mod-step-2');
                if (step2) {
                    step2.innerHTML = `
                        <div class="mod-minting-animation">
                            <div class="mod-minting-icon">⚡</div>
                            <h4>Retrying Mint...</h4>
                            <p>Payment verified — re-attempting mint on the XRP Ledger.</p>
                            <div class="mod-progress-bar"><div class="mod-progress-fill" id="mod-mint-progress"></div></div>
                            <p class="mod-minting-note" id="mod-mint-note">Retrying... this may take 10-60 seconds</p>
                        </div>
                    `;
                }
                updatePurchaseStep(2);
                processGroup(groupId, txHash);
            } else {
                showToast('Cannot retry — missing transaction data. Please contact support.', 'error');
            }
        },

        // v275: Retry from purchase history (tx_hash fetched fresh from DB)
        _retryFromHistory: async (groupId) => {
            if (!groupId) return;
            try {
                showToast('Loading purchase data...', 'info');
                const resp = await fetch(
                    `${ENDPOINTS.mintOnDemand}?action=get_group_status&group_id=${groupId}&buyer_account=${encodeURIComponent(CONFIG.account)}`
                );
                const data = await resp.json();
                if (!data.success) throw new Error(data.error || 'Failed to load group');

                const txHash = data.data?.payment_tx_hash;
                if (!txHash) {
                    showToast('Cannot retry — payment hash not found. Please contact support with Group ID: ' + groupId, 'error');
                    return;
                }
                // Re-open the modal and trigger minting
                const modal = document.getElementById('mod-purchase-modal') || createPurchaseModal();
                resetPurchaseModal();
                modal.style.display = 'flex';
                modState.currentGroup = { ...data.data, payment_tx_hash: txHash };
                updatePurchaseStep(2);
                processGroup(groupId, txHash);
            } catch (err) {
                showToast('Retry failed: ' + err.message, 'error');
            }
        },

        // v295: Called by the trading-hub-dashboard payment_pending handler.
        // Handles Xaman mobile payment return when the original /collections/ tab
        // is gone. Two paths:
        //   A) Group already paid/minted (uuid was signed while tab was gone) →
        //      fetch tx_hash from DB and call processGroup directly.
        //   B) Group still reserved (UUID not yet signed / in-flight) →
        //      poll poll_payment until tesSUCCESS, then call processGroup.
        resumePaymentFromDashboard: async (groupId, paymentUuid) => {
            if (!groupId) { showToast('Missing group ID — cannot resume payment.', 'error'); return; }

            try {
                // Fetch group status first to determine which path to take
                const statusResp = await fetch(
                    `${ENDPOINTS.mintOnDemand}?action=get_group_status&group_id=${groupId}&buyer_account=${encodeURIComponent(CONFIG.account)}`
                );
                const statusData = await statusResp.json();

                if (!statusData.success) {
                    showToast('Could not load purchase data. Please contact support.', 'error');
                    return;
                }

                const group = statusData.data?.group || statusData.data;
                const status   = group?.status;
                const txHash   = statusData.data?.payment_tx_hash || group?.payment_tx_hash;
                const uuid     = paymentUuid || group?.payment_xumm_uuid;

                // PATH A: Payment already confirmed server-side
                if (txHash && ['paid', 'minting', 'minted', 'partial', 'failed'].includes(status)) {
                    const modal = document.getElementById('mod-purchase-modal') || createPurchaseModal();
                    resetPurchaseModal();
                    modal.style.display = 'flex';
                    modState.currentGroup = { ...group, payment_tx_hash: txHash };
                    updatePurchaseStep(2);
                    // M7b: dispatch ONLY when there is something to dispatch. This runs on EVERY Xaman deeplink
                    // return (the purchase return_url is ?payment_pending=1), so for a group already 'minting'
                    // or 'minted' it used to re-POST process_group -- a full WordPress bootstrap the server's
                    // early exits absorbed. Mirror processGroup's own post-dispatch paths instead.
                    if (status === 'minting') {
                        const rMintNote = document.getElementById('mod-mint-note');
                        const rProgress = document.getElementById('mod-mint-progress');
                        const rQty = parseInt(group?.quantity) || 1;
                        if (rMintNote) rMintNote.textContent = `Minting ${rQty} NFT${rQty > 1 ? 's' : ''}... This may take a moment.`;
                        if (rProgress) rProgress.style.width = '10%';
                        pollMintProgress(groupId, rQty, rMintNote, rProgress).catch(() => {});
                        return;
                    }
                    if (status === 'minted') {
                        const rMintNote = document.getElementById('mod-mint-note');
                        const rProgress = document.getElementById('mod-mint-progress');
                        if (rMintNote) rMintNote.textContent = '✅ Already minted!';
                        if (rProgress) rProgress.style.width = '100%';
                        setTimeout(() => loadClaimData(groupId), 500);
                        return;
                    }
                    processGroup(groupId, txHash);
                    return;
                }

                // PATH B: Group still reserved — payment not yet confirmed server-side.
                // Poll Xaman until signed, then trigger processGroup.
                if (!uuid) {
                    showToast('Payment reference not found. If you signed in Xaman, check Pending Claims in a few minutes.', 'info');
                    return;
                }

                const modal = document.getElementById('mod-purchase-modal') || createPurchaseModal();
                resetPurchaseModal();
                modal.style.display = 'flex';
                updatePurchaseStep(1);
                const statusEl = document.getElementById('mod-purchase-status');
                if (statusEl) statusEl.innerHTML = '<div class="mod-spinner"></div><span>Confirming your payment...</span>';

                // Re-use existing payment poll infrastructure
                // Set currentGroup with both 'id' and 'group_id' keys since different
                // parts of the codebase use different keys.
                modState.currentGroup = { ...group, id: groupId, group_id: groupId };
                startPaymentPolling(uuid, groupId);

            } catch (err) {
                console.error('[MOD] resumePaymentFromDashboard error:', err);
                showToast('Failed to resume payment: ' + err.message, 'error');
            }
        },

        // Expose startPaymentPolling for external callers
        _startPaymentPolling: startPaymentPolling,

        // State access (debugging)
        getState: () => modState
    };

})();