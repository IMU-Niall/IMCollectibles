/**
 * IMC Trustline Checker v86
 * Path: /xrpl-nft-marketplace/frontend/trustline-checker.js
 * 
 * Utility for checking XRPL trustlines and showing set trustline UI
 * Used in minting, offers, and trading flows
 */

window.imcTrustline = (function() {
    'use strict';
    
    const trustlineCache = new Map();
    const CACHE_TTL = 60000; // 1 minute
    
    /**
     * Check if account has trustline for a token
     */
    async function checkTrustline(account, ticker, issuer) {
        if (!account || !ticker) return { has_trustline: false, error: 'Account and ticker required' };
        if (ticker.toUpperCase() === 'XRP') return { has_trustline: true, is_native: true };
        
        // v691: issuer is optional but decisive — passing the listing's own issuer lets the
        // server verify creator-custom tokens that live only on the listing, not the registry.
        const cacheKey = `${account}_${ticker}_${issuer || ''}`;
        const cached = trustlineCache.get(cacheKey);
        if (cached && Date.now() - cached.timestamp < CACHE_TTL) return cached.result;
        
        try {
            // Note: price-oracle.php expects 'wallet' param, not 'account'
            const issuerQS = issuer ? `&issuer=${encodeURIComponent(issuer)}` : '';
            const resp = await fetch(`/wp-admin/admin-ajax.php?action=imc_check_trustline&wallet=${encodeURIComponent(account)}&ticker=${encodeURIComponent(ticker)}${issuerQS}`);
            const data = await resp.json();
            
            if (data.success) {
                const result = {
                    has_trustline: data.data.has_trustline,
                    ticker: data.data.ticker,
                    issuer: data.data.issuer,
                    name: data.data.name,
                    trustline_url: data.data.trustline_url,
                    // v715: issuer transfer fee multiplier (1.0 = none). Cached alongside the
                    // rest of the result, so the popover reads it without a second request.
                    transfer_rate: data.data.transfer_rate
                };
                trustlineCache.set(cacheKey, { result, timestamp: Date.now() });
                return result;
            }
            return { has_trustline: false, error: data.data?.message || 'Check failed' };
        } catch (err) {
            console.error('Trustline check error:', err);
            return { has_trustline: false, error: err.message };
        }
    }
    
    /**
     * Check multiple tokens at once
     */
    async function checkMultiple(account, tickers) {
        const results = {};
        await Promise.all(tickers.map(async t => { results[t] = await checkTrustline(account, t); }));
        return results;
    }
    
    /**
     * Clear cache (call after user sets a trustline)
     */
    function clearCache(account = null) {
        if (account) {
            for (const key of trustlineCache.keys()) {
                if (key.startsWith(account + '_')) trustlineCache.delete(key);
            }
        } else {
            trustlineCache.clear();
        }
    }
    
    /**
     * Show trustline required modal/prompt
     */
    function showPrompt(tokenInfo, onVerified = null) {
        const existing = document.getElementById('imc-trustline-modal');
        if (existing) existing.remove();
        
        const modal = document.createElement('div');
        modal.id = 'imc-trustline-modal';
        modal.innerHTML = `
            <div class="itm-overlay" onclick="document.getElementById('imc-trustline-modal').remove()"></div>
            <div class="itm-content">
                <button class="itm-close" onclick="document.getElementById('imc-trustline-modal').remove()">×</button>
                <div class="itm-icon">⚠️</div>
                <h3>Trustline Required</h3>
                <p>To receive <strong>${escapeHtml(tokenInfo.name || tokenInfo.ticker)}</strong>, you need to set a trustline first.</p>
                <p class="itm-desc">A trustline allows your wallet to hold this token. This is a standard XRPL requirement.</p>
                <div class="itm-actions">
                    ${tokenInfo.trustline_url ? `
                        <a href="${escapeHtml(tokenInfo.trustline_url)}" target="_blank" class="itm-btn itm-btn-primary" onclick="window.imcTrustline.clearCache()">
                            🔗 Set Trustline
                        </a>
                    ` : `
                        <div class="itm-manual">
                            <p>Set trustline for <code>${escapeHtml(tokenInfo.ticker)}</code></p>
                            <p>Issuer: <code>${escapeHtml(tokenInfo.issuer)}</code></p>
                        </div>
                    `}
                    <button class="itm-btn itm-btn-verify" id="itm-verify-btn">✓ Verify Trustline</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        
        // Verify button handler
        document.getElementById('itm-verify-btn').onclick = async function() {
            const btn = this;
            btn.disabled = true;
            btn.textContent = '⏳ Checking...';
            
            const account = window.xrplAccount || getCookie('xrpl_account');
            if (!account) {
                showToast('Connect wallet first', 'warning');
                btn.disabled = false;
                btn.textContent = '✓ Verify Trustline';
                return;
            }
            
            clearCache(account);
            const result = await checkTrustline(account, tokenInfo.ticker);
            
            if (result.has_trustline) {
                modal.remove();
                showToast(`Trustline for ${tokenInfo.ticker} confirmed!`, 'success');
                if (onVerified) onVerified(tokenInfo.ticker);
            } else {
                btn.disabled = false;
                btn.textContent = '✓ Verify Trustline';
                showToast('Trustline not found. Please set it and try again.', 'warning');
            }
        };
        
        return modal;
    }
    
    /**
     * v692: Wallet-aware "set trustline" for a token.
     * Joey/WalletConnect → build the TrustSet server-side, sign locally with imuJoeySign, then
     * clear cache + re-check (a TrustSet is self-proving, so no on-chain reconcile is needed).
     * Xaman → open the parametric deeplink in a new tab; the caller's Verify path re-checks.
     * issuer is optional (server resolves it) but passing the listing's own issuer covers
     * creator-custom tokens. onDone(true|false|null): true=confirmed, false=not yet, null=external.
     */
    async function setTrustline(ticker, issuer, account, onDone) {
        account = account || window.xrplAccount || getCookie('xrpl_account');
        // v694: guarantee onDone always fires (false = not set / cancelled / failed) so callers
        // like openTokenActions never leave a Set-Trustline button stuck mid-sign.
        const fail = (msg, type) => { if (msg) showToast(msg, type || 'error'); if (onDone) onDone(false); };
        if (!ticker || !account) return fail('Connect your wallet first', 'warning');
        if (ticker.toUpperCase() === 'XRP') { if (onDone) onDone(true); return; } // native — no trustline

        let build;
        try {
            const qs = `ticker=${encodeURIComponent(ticker)}&issuer=${encodeURIComponent(issuer || '')}&account=${encodeURIComponent(account)}`;
            const resp = await fetch(`/wp-admin/admin-ajax.php?action=imc_build_trustset&${qs}`);
            const data = await resp.json();
            if (!data.success) throw new Error(data.data?.message || 'Failed to prepare trustline');
            build = data.data;
        } catch (err) {
            return fail('Could not prepare trustline: ' + err.message, 'error');
        }

        const joeyLive = !!(window.__joeySession && window.__joeySession.live && window.imuWallet);
        if (joeyLive) {
            if (window.__joeySession.account && account !== window.__joeySession.account) {
                return fail('Connected wallet does not match your account. Please reconnect.', 'error');
            }
            if (!build.txjson) return fail('Could not prepare trustline transaction.', 'error');
            let signed;
            try {
                signed = await (window.imuJoeySign || window.imuWallet.sign)(build.txjson);
            } catch (e) {
                const em = (e && e.message) || '';
                return fail(/timed out|timeout/i.test(em) ? 'Signing timed out. Please try again.'
                    : /cancel/i.test(em) ? 'Trustline cancelled.' : 'Trustline rejected.', 'error');
            }
            const tx = signed && (signed.hash || signed.tx_hash);
            if (!tx) return fail('No transaction hash returned from your wallet.', 'error');
            clearCache(account);
            const res = await checkTrustline(account, ticker, issuer);
            if (res.has_trustline) {
                showToast(`${ticker} trustline set!`, 'success');
                if (onDone) onDone(true);
            } else {
                showToast('Trustline signed — confirming on-ledger, try again in a moment.', 'info');
                if (onDone) onDone(false);
            }
            return;
        }

        // Xaman: open the deeplink; the caller's Verify button re-checks after signing.
        if (build.trustline_url) {
            window.open(build.trustline_url, '_blank');
            clearCache(account);
            if (onDone) onDone(null);
        } else {
            return fail('No trustline link available for this token.', 'warning');
        }
    }

    /**
     * v694: Reusable token-actions popover. The "i" next to a non-XRP token price opens this:
     * it runs the trustline check, then offers Set Trustline (wallet-aware, via setTrustline)
     * and Buy {TICKER} (XPMarket DEX). Composes the existing checkTrustline + setTrustline
     * primitives — same component on the listing card and the mint-popup token rows.
     * opts: { account, name }.
     */
    async function openTokenActions(ticker, issuer, opts = {}) {
        if (!ticker || ticker.toUpperCase() === 'XRP') return; // non-XRP only
        const account = opts.account || window.xrplAccount || getCookie('xrpl_account') || '';
        const name = opts.name || ticker;
        const buyUrl = issuer
            ? `https://xpmarket.com/dex/${encodeURIComponent(ticker)}-${encodeURIComponent(issuer)}/XRP`
            : '';

        const old = document.getElementById('imc-token-actions');
        if (old) old.remove();

        const modal = document.createElement('div');
        modal.id = 'imc-token-actions';
        modal.innerHTML = `
            <div class="ita-overlay"></div>
            <div class="ita-content" role="dialog" aria-label="${escapeHtml(ticker)} options">
                <button class="ita-close" aria-label="Close">×</button>
                <div class="ita-head">
                    <span class="ita-ticker">${escapeHtml(ticker)}</span>
                    ${name !== ticker ? `<span class="ita-name">${escapeHtml(name)}</span>` : ''}
                </div>
                <div class="ita-status" id="ita-status"><span class="ita-spinner"></span> Checking trustline…</div>
                <div class="ita-actions" id="ita-actions"></div>
            </div>`;
        document.body.appendChild(modal);

        const close = () => modal.remove();
        modal.querySelector('.ita-overlay').addEventListener('click', close);
        modal.querySelector('.ita-close').addEventListener('click', close);

        const statusEl  = modal.querySelector('#ita-status');
        const actionsEl = modal.querySelector('#ita-actions');
        const buyBtnHtml = buyUrl
            ? `<button type="button" class="ita-btn ita-btn-buy" id="ita-buy">Buy ${escapeHtml(ticker)}</button>`
            : '';

        function wireBuy() {
            const b = modal.querySelector('#ita-buy');
            if (b) b.addEventListener('click', () => window.open(buyUrl, '_blank'));
        }
        function showHas() {
            statusEl.innerHTML = `<span class="ita-ok">✓ Trustline set — ready to pay with ${escapeHtml(ticker)}</span>`;
            actionsEl.innerHTML = buyBtnHtml;
            wireBuy();
        }
        function showMissing() {
            statusEl.innerHTML = `<span class="ita-warn">You need a ${escapeHtml(ticker)} trustline to pay with it</span>`;
            actionsEl.innerHTML = `<button type="button" class="ita-btn ita-btn-set" id="ita-set">Set Trustline</button>` + buyBtnHtml;
            wireBuy();
            const setBtn = modal.querySelector('#ita-set');
            let mode = 'set'; // 'set' → sign a TrustSet; 'verify' → re-check after an external (Xaman) sign
            setBtn.addEventListener('click', async () => {
                if (mode === 'verify') {
                    setBtn.disabled = true; setBtn.textContent = 'Checking…';
                    clearCache(account);
                    const r = await checkTrustline(account, ticker, issuer);
                    if (r.has_trustline) return showHas();
                    setBtn.disabled = false; setBtn.textContent = 'Verify Trustline';
                    return;
                }
                // v719: Joey renders its OWN signing overlay with a 4-minute countdown. The
                // mint flow already established this pattern (v556 in mint-on-demand.js:
                // "present the clean imuJoeySign overlay (4-min countdown) as the SOLE signing
                // UI"), and a button caption underneath it just competes with a clearer,
                // time-bounded prompt the user is already looking at.
                //
                // Xaman is the opposite case: setTrustline() opens an external tab and shows
                // nothing in-page, so without this caption the button would sit disabled with
                // no explanation at all. Hence the wallet check rather than removing it.
                //
                // Detection mirrors setTrustline()'s own joeyLive test exactly, so the two can
                // never disagree about which path is about to run.
                setBtn.disabled = true;
                const joeyOverlay = !!(window.__joeySession && window.__joeySession.live && window.imuWallet);
                if (!joeyOverlay) { setBtn.textContent = 'Check your wallet…'; }
                setTrustline(ticker, issuer, account, (result) => {
                    if (result === true) return showHas();            // Joey: confirmed on-ledger
                    setBtn.disabled = false;
                    if (result === null) { mode = 'verify'; setBtn.textContent = 'Verify Trustline'; } // Xaman: signed externally
                    else { setBtn.textContent = 'Set Trustline'; }    // Joey: not yet confirmed — allow retry
                });
            });
        }

        if (!account) {
            statusEl.innerHTML = `<span class="ita-warn">Connect your wallet to check the ${escapeHtml(ticker)} trustline</span>`;
            actionsEl.innerHTML = buyBtnHtml;
            wireBuy();
            return;
        }
        const res = await checkTrustline(account, ticker, issuer);
        if (res.has_trustline) showHas(); else showMissing();

        // ── v715: explain the issuer's transfer fee, if this token charges one ──
        //
        // Deliberately rendered here, ONCE, rather than inside showHas()/showMissing() --
        // those two are re-entered as the Set/Verify flow progresses, and duplicating the
        // note into both would mean it flickers away and back on every state change.
        //
        // WORDING IS ABOUT THE CREATOR, NOT THE BUYER, and that is the accurate framing:
        // the fee is DEDUCTED from the total, so the buyer pays exactly the listed price
        // and it is the creator who receives slightly less. Saying "you pay a fee" would
        // be plainly wrong. It is also the ISSUER's fee, not IMCollectibles' -- the copy
        // says so, because a buyer seeing an unexplained shortfall will assume it is ours.
        const feeRate = parseFloat(res && res.transfer_rate);
        if (isFinite(feeRate) && feeRate > 1) {
            const pct = Math.round((feeRate - 1) * 1000000) / 10000; // 1.0033 -> 0.33
            const note = document.createElement('div');
            note.className = 'ita-fee';
            note.style.cssText = 'margin-top:10px;padding:8px 10px;border-radius:6px;'
                + 'background:rgba(212,175,55,0.08);border:1px solid rgba(212,175,55,0.35);'
                + 'font-size:0.8rem;line-height:1.45;opacity:0.95;';
            // v718: the headline fee stays visible; the explanation is collapsed behind an (i).
            // Most people need the number every time and the reasoning once, so leading with a
            // four-line paragraph made the popover heavier than the fact it was conveying.
            note.innerHTML =
                  '<div class="ita-fee-row" style="display:flex;align-items:center;gap:6px;justify-content:center;">'
                +   '<strong>' + escapeHtml(ticker) + ' has a ' + pct + '% issuer fee</strong>'
                +   '<button type="button" class="ita-fee-info" aria-expanded="false"'
                +     ' aria-label="What is the ' + escapeHtml(ticker) + ' issuer fee?"'
                +     ' style="width:16px;height:16px;flex:0 0 16px;padding:0;line-height:14px;'
                +     'border-radius:50%;border:1px solid rgba(212,175,55,0.6);background:transparent;'
                +     'color:#d4af37;font-size:11px;font-style:italic;cursor:pointer;">i</button>'
                + '</div>'
                + '<div class="ita-fee-detail" hidden style="margin-top:6px;opacity:0.85;">'
                +   'Set by the token issuer, not IMCollectibles. You pay the listed price — '
                +   'the fee comes out of the amount the creator receives.'
                + '</div>';
            const feeBtn = note.querySelector('.ita-fee-info');
            const feeDet = note.querySelector('.ita-fee-detail');
            if (feeBtn && feeDet) {
                feeBtn.addEventListener('click', function () {
                    const open = feeDet.hasAttribute('hidden');
                    if (open) { feeDet.removeAttribute('hidden'); } else { feeDet.setAttribute('hidden', ''); }
                    feeBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
                });
            }
            actionsEl.insertAdjacentElement('afterend', note);
        }
    }

    /**
     * Get supported tokens from Token Manager
     */
    async function getTokens(context = 'all') {
        try {
            const resp = await fetch(`/wp-admin/admin-ajax.php?action=imc_get_tokens&context=${context}`);
            const data = await resp.json();
            return data.success ? data.data.tokens : [];
        } catch (err) {
            console.error('Failed to fetch tokens:', err);
            return [];
        }
    }
    
    /**
     * Build token dropdown options HTML
     */
    async function buildTokenOptions(context = 'mint', excludeXrp = false) {
        const tokens = await getTokens(context);
        return tokens
            .filter(t => !excludeXrp || t.ticker !== 'XRP')
            .map(t => `<option value="${t.ticker}" data-issuer="${t.issuer || ''}" data-icon="${t.icon}">${t.icon} ${t.ticker} - ${t.name}</option>`)
            .join('');
    }
    
    // Helpers
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    
    function getCookie(name) {
        const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? decodeURIComponent(match[2]) : null;
    }
    
    function showToast(message, type = 'info') {
        if (window.showToast) window.showToast(message, type);
        else console.log(`[${type}] ${message}`);
    }
    
    // Inject CSS
    const style = document.createElement('style');
    style.textContent = `
        #imc-trustline-modal { position: fixed; inset: 0; z-index: 999999; display: flex; align-items: center; justify-content: center; }
        #imc-trustline-modal .itm-overlay { position: absolute; inset: 0; background: rgba(0,0,0,0.8); backdrop-filter: blur(4px); }
        #imc-trustline-modal .itm-content { position: relative; background: #1a1a2e; border: 1px solid #d4af37; border-radius: 16px; padding: 2rem; max-width: 400px; width: 90%; text-align: center; color: #fff; animation: itmFadeIn 0.2s ease; }
        @keyframes itmFadeIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        #imc-trustline-modal .itm-close { position: absolute; top: 1rem; right: 1rem; background: none; border: none; color: #888; font-size: 1.5rem; cursor: pointer; }
        #imc-trustline-modal .itm-close:hover { color: #fff; }
        #imc-trustline-modal .itm-icon { font-size: 3rem; margin-bottom: 1rem; }
        #imc-trustline-modal h3 { color: #d4af37; margin: 0 0 1rem; font-size: 1.3rem; }
        #imc-trustline-modal p { color: #ccc; margin: 0 0 0.75rem; line-height: 1.5; }
        #imc-trustline-modal .itm-desc { font-size: 0.85rem; color: #999 !important; }
        #imc-trustline-modal .itm-actions { display: flex; flex-direction: column; gap: 0.75rem; margin-top: 1.5rem; }
        #imc-trustline-modal .itm-btn { display: block; padding: 0.75rem 1.5rem; border-radius: 8px; font-size: 1rem; font-weight: 600; text-decoration: none; cursor: pointer; border: none; text-align: center; transition: all 0.2s; }
        #imc-trustline-modal .itm-btn-primary { background: linear-gradient(135deg, #d4af37, #f0d875); color: #1a1a2e; }
        #imc-trustline-modal .itm-btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(212,175,55,0.4); }
        #imc-trustline-modal .itm-btn-verify { background: #10b981; color: #fff; }
        #imc-trustline-modal .itm-btn-verify:hover { background: #059669; }
        #imc-trustline-modal .itm-btn-verify:disabled { opacity: 0.6; cursor: not-allowed; }
        #imc-trustline-modal .itm-manual { background: #2a2a3e; padding: 1rem; border-radius: 8px; font-size: 0.85rem; }
        #imc-trustline-modal .itm-manual code { background: #333; padding: 2px 6px; border-radius: 4px; font-size: 0.8rem; }
    `;
    document.head.appendChild(style);

    // v694: token-actions popover styles (own #imc-token-actions / .ita-* namespace,
    // deliberately separate from the .itm-* trustline modal above so the two never collide).
    const itaStyle = document.createElement('style');
    itaStyle.textContent = `
        #imc-token-actions { position: fixed; inset: 0; z-index: 1000000; display: flex; align-items: center; justify-content: center; }
        #imc-token-actions .ita-overlay { position: absolute; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(3px); }
        #imc-token-actions .ita-content { position: relative; background: #1a1a2e; border: 1px solid #d4af37; border-radius: 14px; padding: 1.5rem 1.5rem 1.25rem; max-width: 340px; width: 88%; text-align: center; color: #fff; animation: itaIn 0.18s ease; }
        @keyframes itaIn { from { opacity: 0; transform: scale(0.96); } to { opacity: 1; transform: scale(1); } }
        #imc-token-actions .ita-close { position: absolute; top: 0.6rem; right: 0.75rem; background: none; border: none; color: #888; font-size: 1.4rem; line-height: 1; cursor: pointer; }
        #imc-token-actions .ita-close:hover { color: #fff; }
        #imc-token-actions .ita-head { display: flex; flex-direction: column; gap: 2px; margin-bottom: 0.9rem; }
        #imc-token-actions .ita-ticker { color: #d4af37; font-size: 1.25rem; font-weight: 700; letter-spacing: 0.02em; }
        #imc-token-actions .ita-name { color: #aaa; font-size: 0.8rem; }
        #imc-token-actions .ita-status { font-size: 0.9rem; margin-bottom: 1rem; min-height: 1.2rem; line-height: 1.4; }
        #imc-token-actions .ita-ok { color: #4ade80; }
        #imc-token-actions .ita-warn { color: #ffc107; }
        #imc-token-actions .ita-spinner { display: inline-block; width: 12px; height: 12px; border: 2px solid rgba(212,175,55,0.3); border-top-color: #d4af37; border-radius: 50%; animation: itaSpin 0.7s linear infinite; vertical-align: middle; margin-right: 4px; }
        @keyframes itaSpin { to { transform: rotate(360deg); } }
        #imc-token-actions .ita-actions { display: flex; flex-direction: column; gap: 0.6rem; }
        #imc-token-actions .ita-btn { display: block; width: 100%; padding: 0.7rem 1rem; border-radius: 8px; font-size: 0.95rem; font-weight: 600; cursor: pointer; border: none; transition: all 0.15s; }
        #imc-token-actions .ita-btn-set { background: #10b981; color: #fff; }
        #imc-token-actions .ita-btn-set:hover { background: #059669; }
        #imc-token-actions .ita-btn-set:disabled { opacity: 0.6; cursor: not-allowed; }
        #imc-token-actions .ita-btn-buy { background: linear-gradient(135deg, #d4af37, #f0d875); color: #1a1a2e; }
        #imc-token-actions .ita-btn-buy:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(212,175,55,0.35); }
    `;
    document.head.appendChild(itaStyle);
    
    return { checkTrustline, checkMultiple, clearCache, setTrustline, openTokenActions, showPrompt, getTokens, buildTokenOptions };
})();