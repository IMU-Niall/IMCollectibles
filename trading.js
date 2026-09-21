// Path: /public_html/wp-content/themes/astra/xrpl-nft-marketplace/frontend/trading.js
// Revised: Escrow/swap logic REMOVED for compliance. Direct peer-to-peer offers only.
// Version: 2.2.0 - v95 Cleanup (auctions removed)

// v351: Collection slug helper — must match PHP imu_collection_slugify()
// Slug format: slugify(name) + '-' + taxon  e.g. "My Cool Art" + 42 → "my-cool-art-42"
// IMU's 5 named collections use their registered pretty slugs (set in data-slug attributes by PHP)
const imc_slugify = s => (s || '').toLowerCase().trim().replace(/[^a-z0-9\s\-]/g, '').replace(/[\s\-]+/g, '-').replace(/^-+|-+$/g, '') || 'collection';
const imc_col_url = (col) => {
    if (col.slug) return `/collections/${col.slug}/`;
    return `/collections/${imc_slugify(col.name || col.collection_name || 'collection')}-${col.taxon || col.collection_taxon || 0}/`;
};

// Centralize magic constants (ESCROW REMOVED)
window.config = {
    platformFeePercent: 1.5, // % for buy/sell offers
    xftIssuer: 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt',
    maxPollAttempts: 30,
    pollIntervalMs: 10000,
    maxSignedAttempts: 6,
    nonceRetryMax: 3,
    minQrVisibleMs: 1200,
    maxWsRetries: 10
    // REMOVED: swapFeeXRP, swapFeeXFT, escrowAccount, wsUrl
    // REMOVED: auctionBidIncrementPercent (auctions removed in v94)
};

// Put this near the top of trading.js
const FRIENDLY_SLUGS = new Set(['guardians', 'frequencies', 'ledger', 'lasvegas']);

// Collection-based royalty fallback (mirrors backend mapping)
window.collectionRoyaltyMap = window.collectionRoyaltyMap || {
    'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR:0': { royaltyPercent: 7, name: 'Guardians' },
    'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga:717825': { royaltyPercent: 6, name: 'Protectors of the Frequencies' },
    'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt:1056369418': { royaltyPercent: 6, name: 'Protectors of the Ledger' },
    'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR:777': { royaltyPercent: 7, name: 'Las Vegas' }
};

// NFT image proxy helper — constructs cached proxy URL from nftokenID
window.nftImgProxy = function(nftokenID, thumb = true) {
    if (!nftokenID || !/^[0-9A-Fa-f]{64}$/.test(nftokenID)) return null;
    return `https://metadata.imcollectibles.io/img.php?nft=${nftokenID}${thumb ? '&thumb=1' : ''}`;
};

// Global error handler for image loading — with proxy→raw fallback
window.handleImageError = function(img, nftId) {
    // Don't handle errors for the fallback SVG itself
    if (img.src && img.src.includes('fallback-nft.svg')) return;

    let retry = parseInt(img.dataset.retryCount || '0');
    const rawUrl = img.dataset.rawImage;

    // Preserve the original src on first error so retries always start clean
    if (!img.dataset.originalSrc) img.dataset.originalSrc = img.src;
    const originalSrc = img.dataset.originalSrc;

    // Helper: append cache-bust params without breaking img.php?url=ipfs://... query strings
    const bustUrl = (url, extra) => {
        const stripped = url.replace(/[?&]t=\d+/g, '').replace(/[?&]nocache=1/g, '').replace(/&&/g, '&').replace(/[?&]$/, '');
        const sep = stripped.includes('?') ? '&' : '?';
        return stripped + sep + `t=${Date.now()}` + (extra ? `&${extra}` : '');
    };

    if (retry < 2) {
        // Auto-retries 1–2: cache-bust the original src; retry 2 adds nocache=1 to
        // force img.php to delete any stale/poisoned VPS cache file and re-fetch
        retry++;
        img.dataset.retryCount = retry;
        const extra = retry === 2 ? 'nocache=1' : '';
        setTimeout(() => {
            img.src = bustUrl(originalSrc, extra);
        }, 1000 * retry);

    } else if (retry === 2 && rawUrl && rawUrl !== originalSrc) {
        // Auto-retry 3: fall back to the alternate source (img.php?nft=TOKEN_ID proxy)
        retry++;
        img.dataset.retryCount = retry;
        setTimeout(() => { img.src = bustUrl(rawUrl, ''); }, 1000);

    } else {
        // All auto-retries exhausted — show fallback + Retry button
        img.src = '/wp-content/uploads/fallback-nft.svg';
        const card = img.closest('.nft-card');
        if (!card) return;

        card.classList.add('error');
        const existingBtn = card.querySelector('.refresh-nft');
        if (existingBtn) existingBtn.remove();

        const refreshBtn = document.createElement('button');
        refreshBtn.className = 'refresh-nft';
        refreshBtn.textContent = 'Retry';

        const doRetry = (e) => {
            e.stopPropagation();
            e.stopImmediatePropagation();
            e.preventDefault();

            // Show loading state immediately so user knows something is happening
            card.classList.remove('error');
            const wrapper = img.closest('.nft-image-wrapper');
            if (wrapper) wrapper.classList.add('loading');
            refreshBtn.remove();

            // Force a fresh fetch: nocache=1 tells img.php to delete the VPS disk
            // cache entry and re-fetch from Pinata/IPFS gateways, bypassing any
            // stale or poisoned cache file. Use originalSrc so we always hit the
            // correct img.php?url=ipfs://CID endpoint regardless of what nftId is.
            const base = img.dataset.originalSrc || `https://metadata.imcollectibles.io/img.php?nft=${encodeURIComponent(nftId)}`;
            const sep  = base.includes('?') ? '&' : '?';
            const retryUrl = `${base}${sep}nocache=1&t=${Date.now()}`;

            // Reset retry counter so auto-retries fire again if this also fails,
            // but mark as a manual retry so we don't re-show Retry after one more cycle
            img.dataset.retryCount  = '0';
            img.dataset.manualRetry = '1';
            img.src = retryUrl;
        };

        refreshBtn.addEventListener('click',      doRetry);
        refreshBtn.addEventListener('pointerup',  doRetry);
        refreshBtn.addEventListener('pointerdown', (e) => { e.stopPropagation(); });
        refreshBtn.addEventListener('mouseup',    (e) => { e.stopPropagation(); });
        refreshBtn.addEventListener('mousedown',  (e) => { e.stopPropagation(); });
        refreshBtn.addEventListener('touchend',   doRetry);
        card.appendChild(refreshBtn);

        console.warn(`Image load failed for NFT ${nftId} after auto-retries`);
    }
};

// Timeout wrapper for fetch
async function fetchWithTimeout(input, init = {}, ms = 15000) {
    const ctrl = new AbortController();
    const id = setTimeout(() => ctrl.abort(), ms);
    try {
        return await fetch(input, { ...init, signal: ctrl.signal });
    } finally {
        clearTimeout(id);
    }
}

// XSS escaper
function esc(s) {
    return String(s ?? '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// Amount formatter (updated to include currency)
function fmt(amountStr, currency, decimals = 6) {
    const m = String(amountStr).match(/^(\d+)(?:\.(\d+))?/);
    if (!m) return `${amountStr} ${currency}`;
    const frac = (m[2] || '').slice(0, decimals);
    const normalized = frac ? `${m[1]}.${frac}` : m[1];
    return `${new Intl.NumberFormat(undefined, { maximumFractionDigits: decimals }).format(Number(normalized))} ${currency}`;
}

/**
 * imcProxyImage — route ANY IPFS URL through img.php.  (Aug 2026)
 *
 * ⚠ WHY THIS EXISTS: ipfs.io sends Cross-Origin-Resource-Policy: same-origin, so a
 *   browser REFUSES to render its images cross-origin:
 *       ERR_BLOCKED_BY_RESPONSE.NotSameOrigin
 *   The fetch itself is a clean 200 in ~0.18s server-side, which is why every curl
 *   test passed while pages showed nothing. Measured: the large majority of cards broken
 *   on collections.php, the trading hub's offer cards likewise, and the home page's
 *   Browse Collections carousel rendered cards with ZERO-HEIGHT images — which reads
 *   as an empty section, not as broken images.
 *
 * ★ MATCH THE PATH, NOT THE HOST. The previous checks tested a HOST LIST:
 *   /(ipfs|cloudflare-ipfs)\.com/ — and `ipfs.com` IS NOT A REAL DOMAIN, so every
 *   ipfs.io URL fell through to the raw branch. The /ipfs/ path is the invariant.
 *   Same defect, same session, in THREE files: collections.php, trading-hub-dashboard.php
 *   and here — and IMUP3's isValidStreamUrl carried it until F0a generalised it too.
 *
 * ⚠ IDEMPOTENT: an already-proxied URL is returned untouched, so this is safe to
 *   apply to values that may have been proxied upstream.
 */
function imcProxyImage(u, thumb) {
    if (!u || typeof u !== 'string') return u;
    if (u.includes('img.php')) return u;                     // already proxied
    const q = thumb ? '&thumb=1' : '';
    if (u.startsWith('ipfs://')) {
        return 'https://metadata.imcollectibles.io/img.php?url=' + encodeURIComponent(u) + q;
    }
    if (u.includes('/ipfs/')) {
        const cid = u.replace(/^.*\/ipfs\//, '');
        return 'https://metadata.imcollectibles.io/img.php?url=' + encodeURIComponent('ipfs://' + cid) + q;
    }
    return u;   // a plain https asset (e.g. images.improtectors.com) — leave it alone
}

// L3 (Aug 2026): thumb-by-default. The call-site sweep found 12 of 13 consumers are
// CARDS or small previews; the one hero (the detail modal's fullImage) passes false.
// Measured before this change: one homepage visit fired 149 FULL-SIZE ?url= requests
// (~45MB to one phone) because this function never passed the thumb flag.
function safeImgSrc(u, thumb = true) {
    try {
        const url = new URL(u);
        // ⚠ WAS: `return u` for ANY http(s) URL — which passed raw ipfs.io straight
        //   through to <img src>, where CORP blocks it.
        if (['http:', 'https:'].includes(url.protocol)) return imcProxyImage(u, thumb);
        if (u.startsWith('ipfs://')) {
            return imcProxyImage(u, thumb);
        }
        return '/wp-content/uploads/fallback-nft.svg';
    } catch { return '/wp-content/uploads/fallback-nft.svg'; }
}

function unwrapWpJson(resp) {
    const hasData = resp && typeof resp === 'object' && 'data' in resp;
    return {
        ok: resp && resp.success === true,
        data: hasData ? resp.data : (resp || {}),
        error: (resp && resp.error) || (hasData && resp.data && resp.data.error) || null,
    };
}

// QR Popup Helpers
let __lastQrOpenedAt = 0;

function openQrPopup({ qr, deeplink, offerId }) {
    const qrPopup       = document.getElementById('qr-code-popup');
    const qrImage       = document.getElementById('qr-code-image');
    const deeplinkA     = document.getElementById('qr-deeplink');
    const signingStatus = document.getElementById('signing-status');
    const offerIdSpan   = document.getElementById('qr-offer-id');

    if (!qrPopup || !qrImage || !deeplinkA || !signingStatus || !offerIdSpan) {
        console.error('[QR] popup elements missing');
        showToast('QR code UI unavailable.', 'error', { position: 'center' });
        throw new Error('QR popup elements missing');
    }

    // v198: Safe QR image — hide on error, never show NFT placeholder
    qrImage.onerror = function() { this.style.display = 'none'; };
    qrImage.style.display = '';
    qrImage.src = qr || '';
    deeplinkA.href = deeplink || '#';
    offerIdSpan.textContent = offerId || '';
    signingStatus.textContent = 'Waiting for signature...';

    qrPopup.dataset.locked = 'true';
    qrPopup.dataset.forUuid = offerId || '';
    document.body.classList.add('imc-modal-open'); // v198
    qrPopup.style.display = 'flex';

    __lastQrOpenedAt = Date.now();
}

function safeCloseQrPopup() {
    const qrPopup = document.getElementById('qr-code-popup');
    if (!qrPopup) return;
    if (qrPopup.dataset.locked === 'true') {
        console.warn('[QR] safeClose called but still locked; ignoring');
        return;
    }
    qrPopup.style.display = 'none';
    document.body.classList.remove('imc-modal-open'); // v198
}

// Watchdog: keep QR open while locked
(function mountQrWatchdog() {
    const tick = () => {
        const qrPopup = document.getElementById('qr-code-popup');
        if (!qrPopup) return;
        if (qrPopup.dataset.locked === 'true' && qrPopup.style.display === 'none') {
            console.warn('[QR] Popup was hidden while locked; restoring...');
            qrPopup.style.display = 'flex';
        }
    };
    setInterval(tick, 400);
})();

// Normalize Xumm proxy response
function readProxy(obj) {
    const data = obj?.data || obj;
    const payloadUuid = data?.uuid || data?.payload_uuidv4 || data?.payloadUuid || obj?.uuid || obj?.payload_uuidv4 || obj?.payloadUuid || null;
    const type = (
        data?.type ||
        data?.tx_type ||
        obj?.type ||
        obj?.tx_type ||
        data?.custom_meta?.type ||
        data?.custom_meta?.blob?.type ||
        obj?.custom_meta?.type ||
        obj?.custom_meta?.blob?.type ||
        'unknown'
    ).toLowerCase();
    return {
        raw: obj,
        signed: !!(data?.signed || obj?.signed || obj?.data?.signed),
        account: data?.account || data?.user || obj?.account || obj?.user || obj?.data?.account || null,
        type: type,
        txHash: data?.tx_hash || data?.txid || obj?.tx_hash || obj?.txid || obj?.data?.txid || null,
        payloadUuid: payloadUuid,
        error: obj?.error || obj?.data?.error || null,
    };
}

// Debounce utility
function debounce(fn, delay) {
    let timer;
    return function(...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), delay);
    };
}

// DOMContentLoaded listener
document.addEventListener('DOMContentLoaded', () => {
    // v198: Global onerror for QR image — hide instead of showing NFT placeholder
    const qrImg = document.getElementById('qr-code-image');
    if (qrImg) {
        qrImg.addEventListener('error', function() { this.style.display = 'none'; });
    }
    
    if (!window.xrplMarketplace || !xrplMarketplace.endpoints) {
        console.error('xrplMarketplace endpoints not found');
        const grid = document.getElementById('global-nft-grid');
        grid?.insertAdjacentHTML('afterbegin', '<p class="error">Marketplace configuration missing. Please refresh.</p>');
        return;
    }

    // DOM elements
    const nftGrid = document.getElementById('global-nft-grid');
    const recentDropsGrid = document.getElementById('recent-drops-grid');
    const searchInput = document.getElementById('marketplace-search-input');
    const searchButton = document.getElementById('marketplace-search-btn');
    const toastContainer = document.getElementById('toast-container');
    const nftGridLoading = document.querySelector('#global-nft-grid .nft-grid-loading');
    const filterSelect = document.getElementById('nft-filter');
    const sortSelect = document.getElementById('nft-sort');
    const titleSelect = document.getElementById('nft-title-filter'); // v504 Phase A2 (rendered server-side on IMC collections with 2+ titles)
    let titleState = ''; // v504 Phase A2: '' = All NFTs
    let mineState = false; // v522: '⭐ My NFTs' owned-only filter
    const prevPageButton = document.getElementById('prev-page');
    const nextPageButton = document.getElementById('next-page');
    const pageInfo = document.getElementById('page-info');
    const myHubBtn = document.getElementById('my-hub-btn');
    const frequencyFountainBtn = document.getElementById('frequency-fountain-btn');
    const tasksBtn = document.getElementById('tasks-btn');
    const myHubPopup = document.getElementById('my-hub-popup');
    const viewAllAuctions = document.getElementById('view-all-auctions');
    const viewAllDrops = document.getElementById('view-all-drops');

    // State variables
    let currentPage = 1;
    let totalNfts = 0;
    let perPage = Math.min(20, window.innerWidth <= 768 ? 8 : 15);
    let filterState = 'all';
    let sortState = 'recent';
    let userAccount = '';
    let isLoading = false;
    let compareNfts = [];
    let watchlistCache = null;
    const gridHandlers = new WeakMap();
    let _royaltyWarnedSession = false;
    
    // v86: Token and trustline state
    let _offerTokensLoaded = false;
    let _currentTrustlineOk = true;
    
    // Collection state
    let allCollections = [];
    let displayedCollections = [];
    let currentCollectionsPage = 1;
    let collectionsPerPage = 20;
    
    // Custom collection state
    let customIssuer = '';
    let customTaxon = '';
    let hasMoreNfts = true;
    let isFetchingNfts = false;
    let isFetchingCollections = false;
    
    // Infinite scroll for NFT grid
    function prefetchMore() {
        // v331: kept for any remaining callers; real infinite scroll now uses attachNftSentinel
        if (isFetchingNfts || !hasMoreNfts) return;
        isFetchingNfts = true;
        loadNFTs(currentPage + 1, true).finally(() => { isFetchingNfts = false; });
    }

    // v331 2A: Infinite scroll via IntersectionObserver sentinel on the window.
    // The old nftGrid.scroll listener only fired if the grid had overflow-y:scroll
    // (fixed-height internal scroll). The grid should grow naturally with the page —
    // so we attach a sentinel *after* the grid and observe it against the viewport.
    let _nftSentinel = null;
    let _nftSentinelObs = null;

    function attachNftSentinel() {
        // Remove any existing sentinel + observer
        if (_nftSentinelObs) { _nftSentinelObs.disconnect(); _nftSentinelObs = null; }
        if (_nftSentinel) { _nftSentinel.remove(); _nftSentinel = null; }

        if (!nftGrid || !hasMoreNfts || isFetchingNfts) return;

        _nftSentinel = document.createElement('div');
        _nftSentinel.style.cssText = 'height:1px;width:100%;';
        nftGrid.after(_nftSentinel);

        _nftSentinelObs = new IntersectionObserver(entries => {
            if (!entries[0].isIntersecting) return;
            // CP-C2 SENTINEL GUARD (layer 2 of 2).
            // Layer 1 is structural: this sentinel is inserted with nftGrid.after(),
            // so it lives inside .marketplace-section = the NFTs panel. When another
            // tab is active that panel is display:none, the sentinel has no layout
            // box, and IntersectionObserver cannot report isIntersecting - so this
            // callback never runs. Layer 2 exists because that guarantee depends on
            // the panel being hidden with DISPLAY: visibility/opacity/height:0 all
            // keep the box and would silently paginate a background grid. If the
            // hiding mechanism is ever changed, this still holds the line.
            if (window.imcActiveTab && window.imcActiveTab !== 'nfts') return;
            if (isFetchingNfts || !hasMoreNfts) return;
            isFetchingNfts = true;
            loadNFTs(currentPage + 1, true).finally(() => {
                isFetchingNfts = false;
                // Re-attach sentinel after load in case there's more
                if (hasMoreNfts) attachNftSentinel();
            });
        }, { rootMargin: '300px' });

        _nftSentinelObs.observe(_nftSentinel);
    }

    
    // ---------- NFT LIST CACHE (10 min TTL) ----------
    const NFT_LIST_CACHE_TTL = 10 * 60 * 1000;
    function cacheKeyForList(scope, sort) {
        return `nft_list_cache::${scope}::${sort || 'recent'}`;
    }
    function cacheGetJSON(key) {
        try {
            const raw = localStorage.getItem(key);
            if (!raw) return null;
            const { ts, data } = JSON.parse(raw);
            if (!ts || (Date.now() - ts) > NFT_LIST_CACHE_TTL) return null;
            return Array.isArray(data) ? data : null;
        } catch { return null; }
    }
    function cacheSetJSON(key, data) {
        try { localStorage.setItem(key, JSON.stringify({ ts: Date.now(), data })); } catch {}
    }

    // Progressive load constants
    const PAGE_FIRST = 25;
    const PAGE_NEXT  = 100;

    // Build a stable scope key for caching per view + sort
    function getScopeKey() {
        if (filterState === 'custom' && customIssuer && customTaxon) {
            return `col:${customIssuer}:${customTaxon}`;
        }
        if (FRIENDLY_SLUGS.has(filterState)) {
            const map = {
                guardians:   { issuer: 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR', taxon: 0 },
                frequencies: { issuer: 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga', taxon: 717825 },
                ledger:      { issuer: 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt', taxon: 1056369418 },
                lasvegas:    { issuer: 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR', taxon: 777 }
            }[filterState];
            return `col:${map.issuer}:${map.taxon}`;
        }
        return 'hub:all';
    }

    async function verifyCurrentOwner(nftId, expectedOwner) {
        try {
            const url = `${xrplMarketplace.endpoints.xummProxy}?action=check_nft_owner&nft_id=${encodeURIComponent(nftId)}&t=${Date.now()}&_wpnonce=${encodeURIComponent(xrplMarketplace.nonce)}`;
            const r = await fetchWithTimeout(url, { credentials: 'include' }, 8000);
            const j = await r.json();
            const owner = j?.owner;
            if (!owner) return { ok: false, owner: null };
            if (expectedOwner && owner !== expectedOwner) return { ok: false, owner };
            return { ok: true, owner };
        } catch (e) {
            console.warn('verifyCurrentOwner failed', e);
            return { ok: false, owner: null };
        }
    }

    // Apply fixed height to grids for scrolling
    const applyGridStyles = () => {
        const grids = [nftGrid, document.getElementById('nft-collections-grid')];
        grids.forEach(g => {
            if (g) {
                g.style.maxHeight = '80vh';
                g.style.overflowY = 'auto';
                g.style.overflowX = 'hidden';
            }
        });
    };
    applyGridStyles();
    
    // --- Discount (single source of truth) ---
    let __userDiscountCache = 0;
    async function computeUserDiscount() {
        if (__userDiscountCache > 0) return __userDiscountCache;
        if (!userAccount) return 0;

        try {
            const nfts = await loadUserNFTs(true);
            let guardians = 0, frequencies = 0, ledger = 0, lasvegas = 0;
            nfts.forEach(nft => {
                const issuer = nft.Issuer || nft.issuer || '';
                const taxon  = +(nft.NFTokenTaxon || nft.taxon || 0);
                if (issuer === 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR' && taxon === 0) guardians++;
                else if (issuer === 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga' && taxon === 717825) frequencies++;
                else if (issuer === 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt' && taxon === 1056369418) ledger++;
                else if (issuer === 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR' && taxon === 777) lasvegas++;
            });
            const fl = frequencies + ledger;
            let disc = 0;
            if (fl >= 11) disc = 50;
            else if (fl >= 8) disc = 40;
            else if (fl >= 5) disc = 30;
            else if (fl >= 3) disc = 20;
            else if (fl >= 1) disc = 10;
            if (guardians >= 1 || lasvegas >= 1) disc = Math.max(disc, 50);
            if (guardians >= 1 && lasvegas >= 1) disc = 100;
            __userDiscountCache = disc;
            return disc;
        } catch (err) {
            console.error('Discount calc error:', err);
            return 0;
        }
    }

    // Initialize userAccount
    if (typeof xrpl_account !== 'undefined' && xrpl_account) {
        userAccount = xrpl_account;
        console.log('XRPL Account from global:', userAccount);
    } else {
        const cookie = document.cookie.split('; ').find(row => row.startsWith('xrpl_account='));
        if (cookie) {
            userAccount = cookie.split('=')[1];
            console.log('XRPL Account from cookie:', userAccount);
        }
        if (!userAccount) {
            const root = document.getElementById('xrpl-marketplace-root') || document.querySelector('.xrpl-marketplace-root');
            if (root && root.dataset.account) {
                userAccount = root.dataset.account;
                console.log('XRPL Account from data-account:', userAccount);
            }
        }
    }
    if (userAccount && !/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/.test(userAccount)) {
        console.warn('Invalid XRPL account format:', userAccount);
        userAccount = '';
    }
    if (!userAccount) {
        console.warn('No valid XRPL account detected. Prompting for login.');
        showToast('Please log in with Xaman to access account features.', 'warning');
    } else {
        console.log('Validated XRPL Account:', userAccount);
    }
    
    // Auto-load for dashboard page
    if (document.getElementById('trading-hub-dashboard')) {
        loadMyHubData();
    }

    // Auto-load for collection page
    if (document.getElementById('nft-collection-page')) {
        const root = document.getElementById('nft-collection-page');
        customIssuer = root.dataset.issuer;
        customTaxon = root.dataset.taxon;
        if (customIssuer && customTaxon) {
            filterState = 'custom';
        }
        // v331 2A: Default to listed-first on collection pages so buyers see what's for sale
        sortState = 'listed';
        if (sortSelect) sortSelect.value = 'listed';
        loadNFTs(1);
    }

    // =====================================================================
    // CP-C2 - COLLECTION TAB SHELL
    // Hash-routed, deep-linkable, and lazy: a tab fetches on FIRST CLICK and
    // never on page load. CP-C3 fills the three stubs by hanging loaders off
    // the marked hook below - the shell itself does not change.
    // =====================================================================
    (function initCollectionTabs() {
        const tabRoot = document.getElementById('nft-collection-page');
        if (!tabRoot) return;
        const tabBtns = tabRoot.querySelectorAll('.imc-tab[data-imc-tab]');
        if (!tabBtns.length) return;

        const VALID = ['nfts', 'activity', 'stats', 'holders'];
        // NFTs is already painted server-side + by loadNFTs(1) above, so it counts
        // as loaded from the start. The rest flip on first activation.
        const tabLoaded = { nfts: true, activity: false, stats: false, holders: false };

        // Read by the infinite-scroll sentinel guard in attachNftSentinel().
        window.imcActiveTab = 'nfts';

        function showTab(id, syncHash) {
            if (VALID.indexOf(id) === -1) id = 'nfts';
            const target = tabRoot.querySelector('.imc-tab[data-imc-tab="' + id + '"]');
            // A disabled tab (not yet built) can still be reached via a stale hash -
            // fall back rather than showing an inert panel with no way back.
            if (!target || target.disabled) { id = 'nfts'; }

            window.imcActiveTab = id;

            tabBtns.forEach(t => {
                const on = (t.dataset.imcTab === id);
                t.classList.toggle('is-active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            // Every panel member switches together - the grid section AND the
            // sort/filter toolbar both carry data-imc-panel="nfts".
            tabRoot.querySelectorAll('[data-imc-panel]').forEach(pnl => {
                pnl.classList.toggle('is-active', pnl.dataset.imcPanel === id);
            });

            // replaceState, not pushState: the hash stays shareable without turning
            // every tab click into a back-button step.
            if (syncHash) { try { history.replaceState(null, '', '#' + id); } catch (e) {} }

            if (!tabLoaded[id]) {
                tabLoaded[id] = true;
                // CP-C3 HOOK: load this tab's content here, once.
                if (id === 'holders' && typeof window.imcLoadHolders === 'function') {
                    window.imcLoadHolders();
                }
                if (id === 'activity' && typeof window.imcLoadActivity === 'function') {
                    window.imcLoadActivity();
                }
            }
        }

        tabBtns.forEach(t => {
            t.addEventListener('click', () => {
                if (t.disabled) return;
                showTab(t.dataset.imcTab, true);
            });
        });

        // Deep link on boot (#stats etc). Only acts on a hash we own, so an
        // in-page anchor hash from anywhere else is left alone.
        const bootHash = (window.location.hash || '').replace('#', '');
        if (bootHash && VALID.indexOf(bootHash) !== -1) showTab(bootHash, false);

        window.addEventListener('hashchange', () => {
            const h = (window.location.hash || '').replace('#', '');
            if (h && VALID.indexOf(h) !== -1) showTab(h, false);
        });
    })();

    // Infinite scroll for collections grid
    const collectionsGrid = document.getElementById('nft-collections-grid');
    if (collectionsGrid) {
        collectionsGrid.addEventListener('scroll', debounce(() => {
            const totalPages = Math.ceil(displayedCollections.length / collectionsPerPage);
            if (isFetchingCollections || currentCollectionsPage >= totalPages) return;
            const { scrollTop, scrollHeight, clientHeight } = collectionsGrid;
            if (scrollTop + clientHeight >= scrollHeight - 100) {
                isFetchingCollections = true;
                renderCollectionsPaginated(currentCollectionsPage + 1);
                isFetchingCollections = false;
            }
        }, 200));
    }

    // Utility to show toast notifications
    function showToast(message, type = 'info', options = {}) {
        if (!toastContainer) { console.warn('Toast container missing:', message); return; }
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;
        toast.setAttribute('role', 'status');
        toast.style.position = options.position === 'center' ? 'fixed' : 'relative';
        if (options.position === 'center') {
            toast.style.top = '50%';
            toast.style.left = '50%';
            toast.style.transform = 'translate(-50%, -50%)';
        }
        toastContainer.appendChild(toast);
        setTimeout(() => toast.remove(), 4000);
    }

    // updateOfferUI (for buy/sell offers)
    function updateOfferUI(offer) {
        const offersPreview = document.getElementById('offers-preview');
        if (!offersPreview) return;

        const offerIdEsc = esc(offer.offer_id);
        const existing = offersPreview.querySelector(`[data-offer-id="${offerIdEsc}"]`);
        const isOwner = offer.owner === userAccount;
        const isTarget = offer.target_account === userAccount;

        if (existing) {
            if (offer.status !== 'pending' && offer.status !== 'active') {
                existing.remove();
                if (!offersPreview.querySelector('.offer-bubble')) {
                    offersPreview.querySelector('#outgoing-offers').innerHTML = '<h4>Outgoing Offers</h4><p>No outgoing offers.</p>';
                    offersPreview.querySelector('#incoming-offers').innerHTML = '<h4>Incoming Offers</h4><p>No incoming offers.</p>';
                }
            } else {
                const container = document.createElement('div');
                container.className = 'offer-bubble';
                container.dataset.offerId = offerIdEsc;
                container.innerHTML = `
                    <div class="offer-content">
                        <img src="${safeImgSrc((window.nftImgProxy(offer.nft_id, true) || offer.image || '/wp-content/uploads/fallback-nft.svg'))}" alt="${esc(offer.nft_name || 'Unnamed NFT')}" class="offer-nft-image">
                        <div class="offer-details">
                            <span class="offer-nft-name">${esc(offer.nft_name || 'Unnamed NFT')}</span>
                            <span class="offer-amount">Amount: ${fmt(offer.amount, offer.currency_display || offer.currency)}</span>
                        </div>
                    </div>
                    <div class="offer-actions">
                        ${offer.status === 'pending' && isTarget ? `
                            <button class="offer-action" data-offer-id="${offerIdEsc}" data-action="accept">Accept</button>
                            <button class="offer-action" data-offer-id="${offerIdEsc}" data-action="decline">Decline</button>
                        ` : offer.status === 'pending' && isOwner ? `
                            <button class="offer-action" data-offer-id="${offerIdEsc}" data-action="cancel">Cancel</button>
                        ` : `<span>Status: ${esc(offer.status)}</span>`}
                    </div>
                `;
                existing.outerHTML = container.outerHTML;
            }
        } else if (offer.status === 'pending') {
            const container = document.createElement('div');
            container.className = 'offer-bubble';
            container.dataset.offerId = offerIdEsc;
            container.innerHTML = `
                <div class="offer-content">
                    <img src="${safeImgSrc((window.nftImgProxy(offer.nft_id, true) || offer.image || '/wp-content/uploads/fallback-nft.svg'))}" alt="${esc(offer.nft_name || 'Unnamed NFT')}" class="offer-nft-image">
                    <div class="offer-details">
                        <span class="offer-nft-name">${esc(offer.nft_name || 'Unnamed NFT')}</span>
                        <span class="offer-amount">Amount: ${fmt(offer.amount, offer.currency_display || offer.currency)}</span>
                    </div>
                </div>
                <div class="offer-actions">
                    ${isTarget ? `
                        <button class="offer-action" data-offer-id="${offerIdEsc}" data-action="accept">Accept</button>
                        <button class="offer-action" data-offer-id="${offerIdEsc}" data-action="decline">Decline</button>
                    ` : isOwner ? `
                        <button class="offer-action" data-offer-id="${offerIdEsc}" data-action="cancel">Cancel</button>
                    ` : `<span>Status: ${esc(offer.status)}</span>`}
                </div>
            `;
            const targetContainer = isOwner ? offersPreview.querySelector('#outgoing-offers') : offersPreview.querySelector('#incoming-offers');
            targetContainer.appendChild(container);
        }

        updateOfferTimers();
        setupButtonListeners();
    }

    // updateOfferTimers
    function updateOfferTimers() {
        // Timer logic for any timed elements
        document.querySelectorAll('.offer-timer').forEach(timer => {
            if (timer._tick) return;
            const expiry = new Date(timer.dataset.expiry);
            const tick = () => {
                const diff = Math.max(0, expiry - new Date());
                const h = Math.floor(diff / 3600000);
                const m = Math.floor((diff % 3600000) / 60000);
                const s = Math.floor((diff % 60000) / 1000);
                timer.textContent = diff > 0 ? `${h}h ${m}m ${s}s` : 'Expired';
            };
            tick();
            timer._tick = setInterval(tick, 1000);
        });
    }

    // updateWatchlistUI
    function updateWatchlistUI(nftId, isWatched) {
        document.querySelectorAll(`.nft-card[data-nft-id="${nftId}"]`).forEach(card => {
            const btn = card.querySelector('.watchlist-toggle');
            if (btn) {
                btn.dataset.action = isWatched ? 'remove' : 'add';
                btn.textContent = isWatched ? '★' : '☆';
                btn.classList.toggle('active', isWatched);
                card.classList.toggle('watched', isWatched);
                btn.setAttribute('aria-pressed', isWatched);
            }
        });
    }

    // loadUserNFTs with caching
    async function loadUserNFTs(silent = false) {
        if (!userAccount) {
            if (!silent) showToast('Please log in with Xaman to load your NFTs.', 'warning', { position: 'center' });
            return [];
        }
        try {
            const cacheKey = `user_nfts_cache:${userAccount}`;
            const cached = localStorage.getItem(cacheKey);
            if (cached) {
                const parsed = JSON.parse(cached);
                if (parsed.timestamp > Date.now() - 300000) {
                    console.log('Using cached user NFTs');
                    return parsed.nfts;
                }
            }
            const res = await fetchWithTimeout(`${xrplMarketplace.ajax_url}?action=nft_loader&account=${encodeURIComponent(userAccount)}&t=${Date.now()}&nonce=${encodeURIComponent(xrplMarketplace.nonce)}`, {}, 20000);
            if (!res.ok) throw new Error(`HTTP ${res.status}: ${res.statusText}`);
            const data = await res.json();
            if (!data.success || !Array.isArray(data.nfts)) {
                console.warn('Invalid NFT data:', data);
                if (!silent) showToast('No NFTs found in your wallet.', 'warning', { position: 'center' });
                return [];
            }
            console.log('Loaded user NFTs:', data.nfts);
            localStorage.setItem(cacheKey, JSON.stringify({ timestamp: Date.now(), nfts: data.nfts }));
            return data.nfts;
        } catch (err) {
            console.error('User NFT fetch error:', err);
            if (!silent) showToast('Failed to load your NFTs. Please try again.', 'error', { position: 'center' });
            return [];
        }
    }

    // loadMyHubData - Buy/Sell offers only (SWAP SECTIONS REMOVED)
    async function loadMyHubData() {
        const qrPopup = document.getElementById('qr-code-popup');
        if (qrPopup?.dataset.locked === "true") {
            console.log('QR modal is active – skipping loadMyHubData');
            return;
        }
        
        // Check if we're on the new Cafe-style dashboard page (which has its own loader)
        const cafeStyleDashboard = document.getElementById('offer-sections');
        if (cafeStyleDashboard) {
            console.log('Cafe-style dashboard detected – inline JS handles loading');
            return; // The inline JS in trading-hub-dashboard.php handles this page
        }

        if (!userAccount) {
            const offers = document.getElementById('offers-preview');
            offers && (offers.innerHTML = '<h4 class="section-title-bubble">Outgoing NFT Offers</h4><p>Please log in with Xaman to view outgoing offers.</p><h4 class="section-title-bubble">Incoming NFT Offers</h4><p>Please log in with Xaman to view incoming offers.</p>');
            showToast('Please log in with Xaman to view hub data.', 'warning', { position: 'center' });
            return;
        }

        const offersPreview = document.getElementById('offers-preview');
        if (!offersPreview) {
            // Not on a hub page that needs this function - silently skip
            console.log('Not on hub page – skipping loadMyHubData');
            return;
        }

        // Show loading state with separate sections
        offersPreview.innerHTML = `
            <div id="outgoing-offers" class="offer-preview-container">
                <h4 class="section-title-bubble">📤 Outgoing Offers</h4>
                <div class="loading-skeleton">Loading your offers...</div>
            </div>
            <div id="incoming-offers" class="offer-preview-container">
                <h4 class="section-title-bubble">📥 Incoming Offers</h4>
                <div class="loading-skeleton">Checking for offers on your NFTs...</div>
            </div>
            <p class="ledger-source" style="text-align:center;color:#666;font-size:0.8rem;margin-top:1rem;">Data from XRPL ledger • Always real-time</p>
        `;

        const outgoingContainer = document.getElementById('outgoing-offers');
        const incomingContainer = document.getElementById('incoming-offers');

        // Helper to render a single offer bubble based on category
        const renderOfferBubble = (offer, category) => {
            // Determine display based on category
            const isTransfer = offer.is_transfer || offer.is_gift || offer.is_transfer_request || offer.amount === 0;
            
            // Format amount helper
            const formatAmt = (amt, curr) => {
                if (amt === 0) return 'Free (0 XRP)';
                return fmt(amt, curr);
            };
            
            const amountText = formatAmt(offer.amount, offer.currency_display || offer.currency);
            
            if (category === 'incoming_transfer') {
                // Incoming transfer - someone is sending me an NFT (I need to accept their SELL offer)
                return `
                    <div class="offer-bubble incoming-gift" data-offer-id="${esc(offer.offer_id)}" data-nft-id="${esc(offer.nft_id)}" data-accept-type="sell_offer">
                        <div class="offer-content">
                            <img src="${safeImgSrc((window.nftImgProxy(offer.nft_id, true) || offer.nft_image || '/wp-content/uploads/fallback-nft.svg'))}" alt="${esc(offer.nft_name)}" class="offer-nft-image">
                            <div class="offer-details">
                                <span class="offer-nft-name">${esc(offer.nft_name || 'Incoming NFT')}</span>
                                <span class="offer-type-badge gift-badge">🎁 Incoming Transfer</span>
                                <span class="offer-amount">Price: <span class="amount-value">${amountText}</span></span>
                                <span class="offer-from">From: ${esc((offer.from || offer.offerer || '').slice(0,6))}...${esc((offer.from || offer.offerer || '').slice(-4))}</span>
                            </div>
                        </div>
                        <div class="offer-actions">
                            <button class="offer-action accept-btn" data-offer-id="${esc(offer.offer_id)}" data-action="accept_sell">Claim NFT</button>
                        </div>
                    </div>`;
                    
            } else if (category === 'outgoing_transfer') {
                // Outgoing transfer - I'm sending an NFT to someone
                return `
                    <div class="offer-bubble outgoing-transfer" data-offer-id="${esc(offer.offer_id || '')}" data-nft-id="${esc(offer.nft_id)}">
                        <div class="offer-content">
                            <img src="${safeImgSrc((window.nftImgProxy(offer.nft_id, true) || offer.nft_image || '/wp-content/uploads/fallback-nft.svg'))}" alt="${esc(offer.nft_name)}" class="offer-nft-image">
                            <div class="offer-details">
                                <span class="offer-nft-name">${esc(offer.nft_name || 'Unnamed NFT')}</span>
                                <span class="offer-type-badge transfer-badge">📤 Sending To</span>
                                <span class="offer-to">To: ${esc((offer.destination || offer.recipient || '').slice(0,6))}...${esc((offer.destination || offer.recipient || '').slice(-4))}</span>
                                <span class="offer-amount">Price: <span class="amount-value">${amountText}</span></span>
                            </div>
                        </div>
                        <div class="offer-actions">
                            <button class="offer-action cancel-btn" data-offer-id="${esc(offer.offer_id || '')}" data-action="cancel">Cancel</button>
                        </div>
                    </div>`;
                    
            } else if (category === 'offers_received') {
                // Buy offer on my NFT (I accept their BUY offer)
                const typeLabel = isTransfer ? '🔄 Transfer Request' : '💰 Buy Offer';
                const netText = isTransfer ? 'Free transfer' : `You receive: ${fmt(offer.net_amount, offer.currency_display || offer.currency)}`;
                
                return `
                    <div class="offer-bubble offer-received ${isTransfer ? 'transfer-request' : ''}" data-offer-id="${esc(offer.offer_id)}" data-nft-id="${esc(offer.nft_id)}" data-accept-type="buy_offer">
                        <div class="offer-content">
                            <img src="${safeImgSrc((window.nftImgProxy(offer.nft_id, true) || offer.nft_image || '/wp-content/uploads/fallback-nft.svg'))}" alt="${esc(offer.nft_name)}" class="offer-nft-image">
                            <div class="offer-details">
                                <span class="offer-nft-name">${esc(offer.nft_name || 'Unnamed NFT')}</span>
                                <span class="offer-type-badge">${typeLabel}</span>
                                <span class="offer-amount">Offer: <span class="amount-value">${amountText}</span></span>
                                <span class="offer-from">From: ${esc((offer.offerer || offer.from || '').slice(0,6))}...${esc((offer.offerer || offer.from || '').slice(-4))}</span>
                                <span class="offer-net">${netText}</span>
                            </div>
                        </div>
                        <div class="offer-actions">
                            <button class="offer-action accept-btn" data-offer-id="${esc(offer.offer_id)}" data-action="accept">Accept</button>
                            <button class="offer-action decline-btn" data-offer-id="${esc(offer.offer_id)}" data-action="decline">Decline</button>
                        </div>
                    </div>`;
                    
            } else if (category === 'listed_item') {
                // My listing - v94: All listings are GTC
                return `
                    <div class="offer-bubble my-listing" data-offer-id="${esc(offer.offer_id || '')}" data-nft-id="${esc(offer.nft_id)}">
                        <div class="offer-content">
                            <img src="${safeImgSrc((window.nftImgProxy(offer.nft_id, true) || offer.nft_image || '/wp-content/uploads/fallback-nft.svg'))}" alt="${esc(offer.nft_name)}" class="offer-nft-image">
                            <div class="offer-details">
                                <span class="offer-nft-name">${esc(offer.nft_name || 'Unnamed NFT')}</span>
                                <span class="offer-type-badge listing-badge">🏷️ Listed</span>
                                <span class="offer-amount">Price: <span class="amount-value">${amountText}</span></span>
                                <span class="offer-status status-active">✓ On-chain</span>
                            </div>
                        </div>
                        <div class="offer-actions">
                            <button class="offer-action cancel-btn" data-offer-id="${esc(offer.offer_id || '')}" data-action="cancel">Cancel Listing</button>
                        </div>
                    </div>`;
                    
            } else if (category === 'offer_made') {
                // Buy offer I made
                return `
                    <div class="offer-bubble offer-made" data-offer-id="${esc(offer.offer_id || '')}" data-nft-id="${esc(offer.nft_id)}">
                        <div class="offer-content">
                            <img src="${safeImgSrc((window.nftImgProxy(offer.nft_id, true) || offer.nft_image || '/wp-content/uploads/fallback-nft.svg'))}" alt="${esc(offer.nft_name)}" class="offer-nft-image">
                            <div class="offer-details">
                                <span class="offer-nft-name">${esc(offer.nft_name || 'Unnamed NFT')}</span>
                                <span class="offer-type-badge offer-badge">🛒 My Offer</span>
                                <span class="offer-amount">Offered: <span class="amount-value">${amountText}</span></span>
                                <span class="offer-status status-active">✓ On-chain</span>
                            </div>
                        </div>
                        <div class="offer-actions">
                            <button class="offer-action cancel-btn" data-offer-id="${esc(offer.offer_id || '')}" data-action="cancel">Cancel Offer</button>
                        </div>
                    </div>`;
            }
            
            // Fallback for unknown category (legacy support)
            return `
                <div class="offer-bubble" data-offer-id="${esc(offer.offer_id || '')}" data-nft-id="${esc(offer.nft_id)}">
                    <div class="offer-content">
                        <img src="${safeImgSrc((window.nftImgProxy(offer.nft_id, true) || offer.nft_image || '/wp-content/uploads/fallback-nft.svg'))}" alt="${esc(offer.nft_name)}" class="offer-nft-image">
                        <div class="offer-details">
                            <span class="offer-nft-name">${esc(offer.nft_name || 'Unnamed NFT')}</span>
                            <span class="offer-amount">${amountText}</span>
                        </div>
                    </div>
                </div>`;
        };

        try {
            // v396: Removed hardcoded test wallet/NFT arrays — incoming transfers
            // are now detected server-side via Bithomp sellDestination API.
            // The old get_dashboard_offers endpoint still accepts these params
            // but they're no longer needed for dynamic transfer discovery.
            const knownSenders = '';
            const knownNftIds = '';
            
            // Show loading state
            outgoingContainer.innerHTML = `<h4 class="section-title-bubble">📤 My Offers &amp; Listings</h4><p class="loading-msg">Loading...</p>`;
            incomingContainer.innerHTML = `<h4 class="section-title-bubble">📥 Incoming Offers</h4><p class="loading-msg">Loading offers... This may take a moment.</p>`;
            
            // Use new comprehensive endpoint
            const dashboardRes = await fetchWithTimeout(
                `${xrplMarketplace.endpoints.offerHandler}?action=get_dashboard_v2&account=${encodeURIComponent(userAccount)}&senders=${encodeURIComponent(knownSenders)}&nft_ids=${encodeURIComponent(knownNftIds)}&max_nfts=50&t=${Date.now()}&nonce=${encodeURIComponent(xrplMarketplace.nonce)}`, 
                {}, 180000  // 3 minute timeout
            );
            const data = await dashboardRes.json();
            console.log('Dashboard offers loaded:', data);

            if (!data.success) {
                throw new Error(data.error || 'Failed to load offers');
            }
            
            // ===== RENDER OUTGOING SECTION =====
            // Combines: listed_items, outgoing_transfers, offers_made
            let outgoingHtml = '';
            
            // Listed Items
            if (data.listed_items?.length > 0) {
                outgoingHtml += `<div class="offer-category"><h5>🏷️ Listed Items (${data.listed_items.length})</h5>`;
                outgoingHtml += data.listed_items.map(o => renderOfferBubble(o, 'listed_item')).join('');
                outgoingHtml += `</div>`;
            }
            
            // Outgoing Transfers
            if (data.outgoing_transfers?.length > 0) {
                outgoingHtml += `<div class="offer-category"><h5>📤 Outgoing Transfers (${data.outgoing_transfers.length})</h5>`;
                outgoingHtml += data.outgoing_transfers.map(o => renderOfferBubble(o, 'outgoing_transfer')).join('');
                outgoingHtml += `</div>`;
            }
            
            // Offers Made
            if (data.offers_made?.length > 0) {
                outgoingHtml += `<div class="offer-category"><h5>🛒 Offers Made (${data.offers_made.length})</h5>`;
                outgoingHtml += data.offers_made.map(o => renderOfferBubble(o, 'offer_made')).join('');
                outgoingHtml += `</div>`;
            }
            
            const outgoingTotal = (data.listed_items?.length || 0) + (data.outgoing_transfers?.length || 0) + (data.offers_made?.length || 0);
            if (outgoingTotal > 0) {
                outgoingContainer.innerHTML = `<h4 class="section-title-bubble">📤 My Offers &amp; Listings (${outgoingTotal})</h4>${outgoingHtml}`;
            } else {
                outgoingContainer.innerHTML = `<h4 class="section-title-bubble">📤 My Offers &amp; Listings</h4><p class="no-offers-msg">No active listings or offers</p>`;
            }
            
            // ===== RENDER INCOMING SECTION =====
            // Combines: incoming_transfers, offers_received
            let incomingHtml = '';
            
            // Incoming Transfers (gifts/airdrops) - SHOW FIRST
            if (data.incoming_transfers?.length > 0) {
                incomingHtml += `<div class="offer-category"><h5>🎁 Incoming Transfers (${data.incoming_transfers.length})</h5>`;
                incomingHtml += data.incoming_transfers.map(o => renderOfferBubble(o, 'incoming_transfer')).join('');
                incomingHtml += `</div>`;
            }
            
            // Offers Received (buy offers on my NFTs)
            if (data.offers_received?.length > 0) {
                incomingHtml += `<div class="offer-category"><h5>💰 Offers Received (${data.offers_received.length})</h5>`;
                incomingHtml += data.offers_received.map(o => renderOfferBubble(o, 'offers_received')).join('');
                incomingHtml += `</div>`;
            }
            
            const incomingTotal = (data.incoming_transfers?.length || 0) + (data.offers_received?.length || 0);
            if (incomingTotal > 0) {
                incomingContainer.innerHTML = `<h4 class="section-title-bubble">📥 Incoming Offers (${incomingTotal})</h4>${incomingHtml}`;
            } else {
                incomingContainer.innerHTML = `<h4 class="section-title-bubble">📥 Incoming Offers</h4><p class="no-offers-msg">No incoming offers or transfers</p>`;
            }
            
            setupButtonListeners();

            updateOfferTimers();
        } catch (err) {
            console.warn('Failed to load hub data:', err);
            
            // Check if outgoing loaded successfully (no loading-skeleton means it loaded)
            const outgoingLoaded = outgoingContainer && !outgoingContainer.querySelector('.loading-skeleton');
            const incomingLoaded = incomingContainer && !incomingContainer.querySelector('.loading-skeleton');
            
            // Only update sections that are still in loading state
            if (!incomingLoaded && incomingContainer) {
                // Incoming timed out - show friendly message
                const isTimeout = err.name === 'AbortError' || err.message.includes('abort');
                const msg = isTimeout 
                    ? 'Timed out checking your NFTs for offers. <button onclick="window.loadMyHubData()" class="retry-link">Retry</button>'
                    : 'Could not load - <button onclick="window.loadMyHubData()" class="retry-link">try again</button>';
                incomingContainer.innerHTML = `<h4 class="section-title-bubble">📥 Incoming Offers</h4><p class="no-offers-msg">${msg}</p>`;
            }
            
            if (!outgoingLoaded && outgoingContainer) {
                outgoingContainer.innerHTML = '<h4 class="section-title-bubble">📤 Outgoing Offers</h4><p class="no-offers-msg">Failed to load. <button onclick="window.loadMyHubData()" class="retry-link">Retry</button></p>';
            }
            
            // Only show toast if BOTH sections failed
            if (!outgoingLoaded && !incomingLoaded) {
                showToast('Could not load offers: ' + err.message, 'error', { position: 'center' });
            }
        }
    }
    
    // Make loadMyHubData globally accessible for retry buttons
    window.loadMyHubData = loadMyHubData;

    // Remove a single offer from the DOM without full refresh
    function removeOfferFromDOM(offerId) {
        const offerBubble = document.querySelector(`.offer-bubble[data-offer-id="${offerId}"]`);
        if (offerBubble) {
            offerBubble.style.transition = 'opacity 0.3s, transform 0.3s';
            offerBubble.style.opacity = '0';
            offerBubble.style.transform = 'scale(0.9)';
            setTimeout(() => {
                offerBubble.remove();
                // Update counts in headers
                updateOfferCounts();
            }, 300);
        }
    }

    // Update the offer count numbers in section headers
    function updateOfferCounts() {
        const outgoingContainer = document.getElementById('outgoing-offers');
        const incomingContainer = document.getElementById('incoming-offers');
        
        if (outgoingContainer) {
            const outgoingCount = outgoingContainer.querySelectorAll('.offer-bubble').length;
            const header = outgoingContainer.querySelector('.section-title-bubble');
            if (header) {
                header.textContent = outgoingCount > 0 ? `📤 Outgoing Offers (${outgoingCount})` : '📤 Outgoing Offers';
            }
            if (outgoingCount === 0 && !outgoingContainer.querySelector('.no-offers-msg')) {
                outgoingContainer.innerHTML += '<p class="no-offers-msg">No outgoing offers</p>';
            }
        }
        
        if (incomingContainer) {
            const incomingCount = incomingContainer.querySelectorAll('.offer-bubble').length;
            const header = incomingContainer.querySelector('.section-title-bubble');
            if (header) {
                header.textContent = incomingCount > 0 ? `📥 Incoming Offers (${incomingCount})` : '📥 Incoming Offers';
            }
            if (incomingCount === 0 && !incomingContainer.querySelector('.no-offers-msg')) {
                incomingContainer.innerHTML += '<p class="no-offers-msg">No incoming offers</p>';
            }
        }
    }

    // handleOfferAction (accept/cancel/decline)
    async function handleOfferAction(e) {
        if (!userAccount) {
            showToast('Please log in with your XRPL account to manage offers.', 'error', { position: 'center' });
            return;
        }
        const btn = e.target;
        const offerId = btn.dataset.offerId || '';
        const uuid = btn.dataset.uuid || '';
        const action = btn.dataset.action;
        btn.disabled = true;

        try {
            if (action === 'accept') {

                // --- Joey branch (v551, additive): accept_offer is Joey-wired server-side
                // (joey_verify_accept_offer). Sign the NFTokenAcceptOffer locally; no QR popup.
                if (window.__joeySession && window.__joeySession.live && window.imuWallet) {
                    if (userAccount !== window.__joeySession.account) {
                        throw new Error('Connected wallet does not match your account. Please reconnect.');
                    }
                    const jbody = new URLSearchParams({ action: 'accept_offer', account: userAccount, offer_id: offerId, nonce: xrplMarketplace.nonce, wallet: 'joey' });
                    const jres = await fetchWithTimeout(xrplMarketplace.endpoints.offerHandler, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: jbody }, 10000);
                    const jdata = await jres.json();
                    if (!jdata.success || !jdata.txjson) throw new Error(jdata.error || 'Failed to prepare accept_offer');
                    showToast('Check your wallet to sign...', 'success', { position: 'center' });
                    let jsigned;
                    try { jsigned = await (window.imuJoeySign||window.imuWallet.sign)(jdata.txjson); }
                    catch (e) { const em = (e && e.message) || ''; throw new Error(/timed out|timeout/i.test(em) ? 'Signing timed out. Please try again.' : /cancel/i.test(em) ? 'Signing cancelled.' : 'Signing was rejected.'); }
                    const jtx = jsigned && (jsigned.hash || jsigned.tx_hash);
                    if (!jtx) throw new Error('No transaction hash returned from your wallet.');
                    const jvfd = new FormData();
                    jvfd.append('action', 'joey_verify_accept_offer');
                    jvfd.append('tx_hash', jtx);
                    jvfd.append('nft_id', jdata.nft_id || '');
                    jvfd.append('nonce', xrplMarketplace.nonce);
                    const jvr = await fetch(xrplMarketplace.endpoints.offerHandler, { method: 'POST', body: jvfd });
                    const jvd = await jvr.json();
                    if (!jvd.success) throw new Error(jvd.error || 'Verification failed');
                    showToast('Offer accepted successfully.', 'success', { position: 'center' });
                    loadMyHubData();
                    return;
                }

                const body = new URLSearchParams({
                    action: 'accept_offer',
                    account: userAccount,
                    offer_id: offerId,
                    nonce: xrplMarketplace.nonce
                });
                const res = await fetchWithTimeout(xrplMarketplace.endpoints.offerHandler, { 
                    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body 
                }, 10000);
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to accept offer');
                // v684: since v683 the SERVER decides the wallet (xrpl_wallet_type cookie). A user
                // holding a joey cookie whose WalletConnect session isn't live takes this Xaman
                // path but receives a Joey txjson -- no qr, no payload_uuid -- which would open a
                // blank, locked signing modal polling an undefined uuid. Bounce cleanly instead.
                // A genuine Xaman response never carries wallet:'joey', so this cannot fire on one.
                if (data.wallet === 'joey' && data.txjson) throw new Error('Wallet still connecting — please try again in a moment.');
                
                document.getElementById('qr-code-image').src = data.qr || ''; // v198: was fallback-nft.svg
                document.getElementById('qr-offer-id').textContent = offerId;
                document.getElementById('qr-deeplink').href = data.deeplink || '#';
                document.getElementById('signing-status').textContent = 'Waiting for signature...';
                document.getElementById('retry-offer').style.display = 'none';
                document.getElementById('close-qr-popup').style.display = 'none';
                document.getElementById('qr-code-popup').dataset.locked = 'true';
                document.getElementById('qr-code-popup').style.display = 'flex';

                await pollSigningStatus(data.payload_uuid, offerId, 'nft_offer_accept');
                showToast('Offer accepted successfully.', 'success', { position: 'center' });
                loadMyHubData();
            } else if (action === 'accept_sell') {

                // --- Joey branch (v551, additive): accept_sell is Joey-wired server-side
                // (joey_verify_accept_offer). Sign the NFTokenAcceptOffer locally; no QR popup.
                if (window.__joeySession && window.__joeySession.live && window.imuWallet) {
                    if (userAccount !== window.__joeySession.account) {
                        throw new Error('Connected wallet does not match your account. Please reconnect.');
                    }
                    const jurl = xrplMarketplace.endpoints.offerHandler + '?action=accept_sell&offer_id=' + encodeURIComponent(offerId) + '&account=' + encodeURIComponent(userAccount) + '&nonce=' + encodeURIComponent(xrplMarketplace.nonce) + '&skip_verify=1&wallet=joey';
                    const jres = await fetchWithTimeout(jurl, { method: 'GET' }, 10000);
                    const jdata = await jres.json();
                    if (!jdata.success || !jdata.txjson) throw new Error(jdata.error || 'Failed to prepare accept_sell');
                    showToast('Check your wallet to sign the claim...', 'success', { position: 'center' });
                    let jsigned;
                    try { jsigned = await (window.imuJoeySign||window.imuWallet.sign)(jdata.txjson); }
                    catch (e) { const em = (e && e.message) || ''; throw new Error(/timed out|timeout/i.test(em) ? 'Signing timed out. Please try again.' : /cancel/i.test(em) ? 'Signing cancelled.' : 'Signing was rejected.'); }
                    const jtx = jsigned && (jsigned.hash || jsigned.tx_hash);
                    if (!jtx) throw new Error('No transaction hash returned from your wallet.');
                    const jvfd = new FormData();
                    jvfd.append('action', 'joey_verify_accept_offer');
                    jvfd.append('tx_hash', jtx);
                    jvfd.append('nft_id', jdata.nft_id || '');
                    jvfd.append('nonce', xrplMarketplace.nonce);
                    const jvr = await fetch(xrplMarketplace.endpoints.offerHandler, { method: 'POST', body: jvfd });
                    const jvd = await jvr.json();
                    if (!jvd.success) throw new Error(jvd.error || 'Verification failed');
                    showToast('NFT claimed successfully!', 'success', { position: 'center' });
                    loadMyHubData();
                    return;
                }

                // v197: Accept a sell offer (claim incoming transfer/gift)
                // Backend accept_sell is a GET endpoint
                const url = `${xrplMarketplace.endpoints.offerHandler}?action=accept_sell&offer_id=${encodeURIComponent(offerId)}&account=${encodeURIComponent(userAccount)}&nonce=${encodeURIComponent(xrplMarketplace.nonce)}&skip_verify=1`;
                const res = await fetchWithTimeout(url, { method: 'GET' }, 10000);
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to claim NFT');
                // v684: see accept_offer above -- server-decided Joey response on the Xaman path.
                if (data.wallet === 'joey' && data.txjson) throw new Error('Wallet still connecting — please try again in a moment.');
                
                document.getElementById('qr-code-image').src = data.qr_code || data.qr || ''; // v198: was fallback-nft.svg
                document.getElementById('qr-offer-id').textContent = offerId.slice(0, 12) + '...';
                document.getElementById('qr-deeplink').href = data.deeplink || '#';
                document.getElementById('signing-status').textContent = 'Sign to claim NFT...';
                document.getElementById('retry-offer').style.display = 'none';
                document.getElementById('close-qr-popup').style.display = 'none';
                document.getElementById('qr-code-popup').dataset.locked = 'true';
                document.getElementById('qr-code-popup').style.display = 'flex';

                await pollSigningStatus(data.payload_uuid, offerId, 'nft_offer_accept');
                showToast('NFT claimed successfully!', 'success', { position: 'center' });
                loadMyHubData();
            } else if (action === 'cancel') {
                // Cancel on-chain offer - all offers shown are from ledger
                // --- Joey branch (v548, additive): POST cancel_offer is already
                // Joey-wired server-side (joey_verify_cancel_offer, v546). Sign locally.
                if (window.__joeySession && window.__joeySession.live && window.imuWallet) {
                    if (userAccount !== window.__joeySession.account) {
                        throw new Error('Connected wallet does not match your account. Please reconnect.');
                    }
                    const jbody = new URLSearchParams({ action: 'cancel_offer', account: userAccount, offer_id: offerId, nonce: xrplMarketplace.nonce, wallet: 'joey' });
                    const jres = await fetchWithTimeout(xrplMarketplace.endpoints.offerHandler, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: jbody }, 10000);
                    const jdata = await jres.json();
                    if (!jdata.success || !jdata.txjson) throw new Error(jdata.error || 'Failed to prepare cancel');
                    showToast('Check your wallet to sign the cancel...', 'success', { position: 'center' });
                    let jsigned;
                    try { jsigned = await (window.imuJoeySign||window.imuWallet.sign)(jdata.txjson); }
                    catch (e) { throw new Error('Signing was cancelled or failed.'); }
                    const jtx = jsigned && (jsigned.hash || jsigned.tx_hash);
                    if (!jtx) throw new Error('No transaction hash returned from your wallet.');
                    const jvfd = new FormData();
                    jvfd.append('action', 'joey_verify_cancel_offer');
                    jvfd.append('tx_hash', jtx);
                    jvfd.append('nonce', xrplMarketplace.nonce);
                    const jvr = await fetch(xrplMarketplace.endpoints.offerHandler, { method: 'POST', body: jvfd });
                    const jvd = await jvr.json();
                    if (!jvd.success) throw new Error(jvd.error || 'Cancel verification failed');
                    showToast('Offer cancelled successfully.', 'success', { position: 'center' });
                    removeOfferFromDOM(offerId);
                    return;
                }
                const body = new URLSearchParams({
                    action: 'cancel_offer',
                    account: userAccount,
                    offer_id: offerId,
                    nonce: xrplMarketplace.nonce
                });
                const res = await fetchWithTimeout(xrplMarketplace.endpoints.offerHandler, { 
                    method: 'POST', 
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, 
                    body: body 
                }, 10000);
                const data = await res.json();
                
                // v684: see accept_offer above. This site already tests payload_uuid, so a Joey
                // response silently no-ops rather than locking -- still a dead end, so surface it.
                if (data.wallet === 'joey' && data.txjson) throw new Error('Wallet still connecting — please try again in a moment.');
                
                if (data.success && data.payload_uuid) {
                    // Need to sign cancellation on-chain
                    document.getElementById('qr-code-image').src = data.qr || ''; // v198: was fallback-nft.svg
                    document.getElementById('qr-offer-id').textContent = offerId.slice(0, 12) + '...';
                    document.getElementById('qr-deeplink').href = data.deeplink || '#';
                    document.getElementById('signing-status').textContent = 'Sign to cancel offer...';
                    document.getElementById('retry-offer').style.display = 'none';
                    document.getElementById('close-qr-popup').style.display = 'none';
                    document.getElementById('qr-code-popup').dataset.locked = 'true';
                    document.getElementById('qr-code-popup').style.display = 'flex';
                    
                    // Poll with expected type to prevent false positives from cached signin
                    await pollSigningStatus(data.payload_uuid, offerId, 'nft_offer_cancel');
                    showToast('Offer cancelled successfully.', 'success', { position: 'center' });
                    // Just remove this offer from DOM - no full refresh needed!
                    removeOfferFromDOM(offerId);
                } else if (!data.success) {
                    throw new Error(data.error || 'Failed to cancel offer.');
                }
            } else {
                // Handle decline and other actions
                const body = new URLSearchParams({
                    action: action,
                    account: userAccount,
                    offer_id: offerId,
                    nonce: xrplMarketplace.nonce
                });
                const res = await fetchWithTimeout(xrplMarketplace.endpoints.offerHandler, { 
                    method: 'POST', 
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, 
                    body: body 
                }, 10000);
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const data = await res.json();
                if (data.success) {
                    showToast(`Offer ${action}d successfully.`, 'success', { position: 'center' });
                    // Just remove from DOM - no full refresh needed
                    removeOfferFromDOM(offerId);
                } else {
                    throw new Error(data.error || `Failed to ${action} offer.`);
                }
            }
        } catch (err) {
            console.error(`Offer ${action} error:`, err);
            showToast(`Failed to ${action} offer: ${err.message}`, 'error', { position: 'center' });
        } finally {
            btn.disabled = false;
        }
    }

    // loadNFTs: supports cached first paint + progressive background paging
    async function loadNFTs(page = 1, append = false) {
        if (isLoading) return;
        isLoading = true;

        const limit = (page === 1 && !append) ? PAGE_FIRST : PAGE_NEXT;

        if (prevPageButton) prevPageButton.disabled = true;
        if (nextPageButton) nextPageButton.disabled = true;
        if (nftGridLoading) nftGridLoading.classList.add('active');

        let url;
        const nonce = encodeURIComponent(xrplMarketplace.nonce);
        const t = Date.now();
        const scopeKey = getScopeKey();
        const sortForBackend = sortState === 'listed' ? 'price-asc' : sortState; // v331: 'listed' = listed-first, maps to existing price-asc logic
        const sort = encodeURIComponent(sortForBackend);

        const baseUrl = `${xrplMarketplace.ajax_url}?action=nft_loader&t=${t}&nonce=${nonce}`;

        if (filterState === 'custom' && customIssuer && customTaxon) {
            // v404: Offset accounts for page 1 using PAGE_FIRST (25) while subsequent pages use PAGE_NEXT (100)
            const offset = (page <= 1) ? 0 : (PAGE_FIRST + (page - 2) * limit);
            url = `${baseUrl}&issuer=${encodeURIComponent(customIssuer)}&taxon=${encodeURIComponent(customTaxon)}&limit=${limit}&offset=${offset}&page=${page}&sort=${sort}`;
        } else if (FRIENDLY_SLUGS.has(filterState)) {
            const map = {
                guardians:   { issuer: 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR', taxon: 0 },
                frequencies: { issuer: 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga', taxon: 717825 },
                ledger:      { issuer: 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt', taxon: 1056369418 },
                lasvegas:    { issuer: 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR', taxon: 777 }
            }[filterState];
            const offset = (page <= 1) ? 0 : (PAGE_FIRST + (page - 2) * limit);
            url = `${baseUrl}&issuer=${encodeURIComponent(map.issuer)}&taxon=${encodeURIComponent(map.taxon)}&limit=${limit}&offset=${offset}&page=${page}&sort=${sort}`;
        } else {
            url = `${baseUrl}&page=${page}&limit=${limit}&sort=${sort}`;
        }
        // v504 Phase A2: thread the title filter through to nft_loader (collection pages only)
        if (titleState && filterState === 'custom') {
            url += '&title=' + encodeURIComponent(titleState);
        }
        // v522: thread the "My NFTs" owner filter through to nft_loader (collection pages only)
        if (mineState && filterState === 'custom' && userAccount) {
            url += '&owner=' + encodeURIComponent(userAccount);
        }

        // Try cached first page for instant paint
        if (page === 1 && !append && !titleState && !mineState) { // v504 A2 / v522: bypass list cache when filtered
            const ck = cacheKeyForList(scopeKey, sortState);
            const cached = cacheGetJSON(ck);
            if (cached && cached.length) {
                // v332: Sort cached data before instant-paint so stale cache order is corrected
                let cachedSlice = cached.slice(0, limit);
                if (sortState === 'listed' || sortState === 'price-asc') {
                    cachedSlice.sort((a, b) => {
                        const aAmt = parseFloat(a.offer_amount) || 0;
                        const bAmt = parseFloat(b.offer_amount) || 0;
                        if (aAmt > 0 && bAmt <= 0) return -1;
                        if (aAmt <= 0 && bAmt > 0) return 1;
                        if (aAmt <= 0 && bAmt <= 0) return 0;
                        return aAmt - bAmt;
                    });
                }
                await renderNFTs(cachedSlice, false, nftGrid);
                currentPage = 1;
                perPage = limit;
                totalNfts = Math.max(totalNfts || 0, cached.length);
                if (nftGridLoading) nftGridLoading.classList.remove('active');
                // v306 FIX: Do NOT call prefetchMore() here.
                // The network request below always fires regardless of cache hit, and
                // calls prefetchMore() itself on completion. If the network response
                // returns in under 300ms (common for indexed collections), both timers
                // fire with isFetchingNfts=false between them → page 2 appended twice.
            }
        }

        try {
            const res = await fetchWithTimeout(url, {}, 20000);
            if (!res.ok) throw new Error(`Status ${res.status}: ${res.statusText}`);
            const data = await res.json();

            if (!data.success || !Array.isArray(data.nfts)) {
                showToast(data.error || 'No NFTs found.', 'error');
                return;
            }

            // v404: Use local page — server returns page:1 for offset-based requests
            currentPage = page;
            perPage     = limit;
            totalNfts   = data.total || Math.max(totalNfts, (currentPage - 1) * limit + data.nfts.length);

            // v332: Client-side listed-first guarantee. The server runs price-asc sort but
            // client-side cache (instant paint) may have rendered stale order. Re-sort here
            // so the final rendered grid is always: listed (cheapest → expensive) then unlisted.
            // This is a lightweight sort on the already-fetched JSON — no extra network call.
            if (sortState === 'listed' || sortState === 'price-asc') {
                const asc = (sortState !== 'price-desc');
                data.nfts.sort((a, b) => {
                    const aAmt = parseFloat(a.offer_amount) || 0;
                    const bAmt = parseFloat(b.offer_amount) || 0;
                    const aL = aAmt > 0;
                    const bL = bAmt > 0;
                    if (aL && !bL) return -1;
                    if (!aL && bL) return 1;
                    if (!aL && !bL) return 0;
                    return asc ? aAmt - bAmt : bAmt - aAmt;
                });
            }

            await renderNFTs(data.nfts, !(page === 1 && !append), nftGrid);

            if (page === 1 && !titleState && !mineState) { // v504 Phase A2 / CP-A: never cache a title- OR owner-filtered page under the unfiltered key
                const ck = cacheKeyForList(scopeKey, sortState);
                const stash = (data.nfts || []).slice(0, 300);
                cacheSetJSON(ck, stash);
            }

            // v404: Stop if server returns empty page; prevents infinite reload
            hasMoreNfts = data.nfts.length > 0 && ((currentPage * limit) < totalNfts || data.nfts.length >= limit);

            updatePagination();
            setupButtonListeners();

            // v331 2A: Attach IntersectionObserver sentinel instead of eager prefetch
            if (hasMoreNfts) setTimeout(() => attachNftSentinel(), 300);
        } catch (err) {
            console.error('NFT fetch error:', err);
            showToast(`Failed to load NFTs: ${err.message}`, 'error');
        } finally {
            isLoading = false;
            if (nftGridLoading) nftGridLoading.classList.remove('active');
            if (prevPageButton) prevPageButton.disabled = currentPage === 1;
            if (nextPageButton) nextPageButton.disabled = !hasMoreNfts;
        }
    }

    // loadRecentDrops
    async function loadRecentDrops() {
        if (!recentDropsGrid) return;
        try {
            const res = await fetchWithTimeout(`${xrplMarketplace.ajax_url}?action=nft_loader&recent=1&limit=8&t=${Date.now()}&nonce=${encodeURIComponent(xrplMarketplace.nonce)}`, {}, 15000);
            if (!res.ok) throw new Error(`Status ${res.status}`);
            const data = await res.json();
            if (data.success && data.nfts.length > 0) {
                await renderNFTs(data.nfts, false, recentDropsGrid);
            } else {
                recentDropsGrid.innerHTML = '<p>No recent drops available.</p>';
            }
        } catch (err) {
            console.error('Recent drops error:', err);
            recentDropsGrid.innerHTML = '<p>Failed to load recent drops.</p>';
        }
    }
    
  // Browse Collections - loads from VPS via WordPress proxy (same-origin, no CORS)
    let browseCollectionsOffset = 0;
    const browseCollectionsLimit = 10; // Show 10 in slider
    let browseFilterType = 'all';
    
    // IMU Collection issuers to filter out from browse
    const imuIssuers = [
        'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR',
        'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga',
        'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt',
        'rHsrif6nHTkmyh38W7JmYjairPWhq5P3AH'
    ];

    // Listing nft_types map for access filters (issuer_taxon → nft_type)
    const browseListingTypeMap = (() => {
        try {
            const root = document.getElementById('xrpl-marketplace-root');
            return root ? JSON.parse(root.dataset.listingTypes || '{}') : {};
        } catch(e) { return {}; }
    })();

    async function loadBrowseCollections() {
        const slider = document.getElementById('browse-collections-slider');
        if (!slider) return;
        
        slider.innerHTML = Array.from({length: 5}, () =>
            '<div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:60%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:40%;height:12px;margin-top:0.5rem"></div></div></div>'
        ).join('');
        
        try {
            // Fetch via WordPress proxy - get more to allow for filtering
            const proxyUrl = xrplMarketplace.endpoints?.myNftsHandler || '/wp-content/themes/astra/xrpl-nft-marketplace/backend/my-nfts-handler.php';
            
            // v330: removed force=1 — WP transient serves pre-verified data; no need to re-verify on every carousel load
            const url = `${proxyUrl}?action=get_vps_collections&limit=100&offset=0&nonce=${encodeURIComponent(xrplMarketplace.nonce)}`;
            
            const res = await fetchWithTimeout(url, {}, 15000);
            
            if (!res.ok) throw new Error(`Status ${res.status}`);
            const data = await res.json();
            
            if (!data.success || !Array.isArray(data.collections)) {
                throw new Error(data.error || 'No collections');
            }
            
            // Filter out IMU collections first
            let collections = data.collections.filter(col => !imuIssuers.includes(col.issuer));
            
            // Apply content type filter (client-side)
            // v178: Access filters fetch from listings-handler (IMC collections may not be on VPS yet)
            // Standard filters still filter VPS data by content_type
            if (browseFilterType !== 'all') {
                const accessToNftType = {
                    'music_access': 'music',
                    'album_access': 'album',
                    'musicvideo_access': 'musicvideo',
                    'art_access': 'art',
                    'film_access': 'film'
                };
                
                if (accessToNftType[browseFilterType]) {
                    // Access filter: fetch from listings-handler and render as collection cards
                    const listingsUrl = xrplMarketplace.endpoints?.listingsHandler || 
                        '/wp-content/themes/astra/xrpl-nft-marketplace/backend/listings-handler.php';
                    const nftType = accessToNftType[browseFilterType];
                    const listRes = await fetchWithTimeout(
                        `${listingsUrl}?action=get_marketplace&type=${nftType}&limit=30&include_sold_out=1&include_paused=1&_=${Date.now()}`, {}, 15000
                    );
                    const listData = await listRes.json();
                    
                    if (listData.success && listData.data?.listings?.length > 0) {
                        const imcCollections = groupListingsByCollection(listData.data.listings);
                        slider.innerHTML = imcCollections.map(col => renderMintCollectionCard(col)).join('');
                        console.log(`Loaded ${imcCollections.length} IMC ${nftType} collections (access filter)`);
                        setupSlider('browse-collections-slider', 'slider-prev-browse', 'slider-next-browse');
                    } else {
                        slider.innerHTML = `<div class="no-collections-message">No ${nftType} access collections found yet. Be the first to mint!</div>`;
                    }
                    return; // Done — skip VPS rendering below
                } else {
                    // Standard filter: show all XRPL collections of that media type
                    collections = collections.filter(col => {
                        const type = col.content_type || 'image';
                        return type === browseFilterType;
                    });
                }
            }
            
            // Must have a real image URL and indexed NFTs
            // v328: also reject collections whose stored image IS the fallback SVG
            collections = collections.filter(col => {
                const img = col.image || col.image_proxy || '';
                return img
                    && !img.includes('placehold')
                    && !img.includes('fallback-nft')
                    && (col.indexed_count || col.total_supply || 0) > 0;
            });
            
            // ── SHUFFLE: v409 — IMC-minted collections first, then external ──
            // Split into IMC (has_imc_listing) and external, shuffle each, merge
            const imcCols = collections.filter(c => c.has_imc_listing);
            const extCols = collections.filter(c => !c.has_imc_listing);
            for (let i = imcCols.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [imcCols[i], imcCols[j]] = [imcCols[j], imcCols[i]]; }
            for (let i = extCols.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [extCols[i], extCols[j]] = [extCols[j], extCols[i]]; }
            collections = [...imcCols, ...extCols];
            
            // v311 FIX: Removed image preload verification block.
            // It fired 30 concurrent Pinata fetches (6s timeout each). Cold cache = all
            // timeout → verified=[] → "No collections found." Cards have onerror fallback.
            collections = collections.slice(0, browseCollectionsLimit);
            
            if (collections.length === 0) {
                slider.innerHTML = `<div class="no-collections-message">No ${browseFilterType === 'all' ? '' : browseFilterType + ' '}collections found. Try a different filter!</div>`;
                return;
            }
            
            // Render as slider cards (same format as IMU Collections)
            slider.innerHTML = collections.map(col => {
                // v328: Route all IPFS URLs through our img.php proxy — far more reliable than
                // raw ipfs.io / cloudflare-ipfs gateways which are frequently slow or blocked.
                // ★ One call replaces three branches. The middle one tested a HOST LIST
                //   containing `ipfs.com` — a domain that does not exist — so every
                //   ipfs.io cover fell to the raw `else` and was CORP-blocked by the
                //   browser. The cards rendered but their images were zero-height,
                //   which is why this section looked EMPTY rather than broken.
                let rawImage = col.image || col.image_proxy || '';
                let image = imcProxyImage(rawImage, true);
                const name = col.name || `Collection #${col.taxon}`;
                const nftCount = col.indexed_count || col.total_supply || '?';
                const typeIcon = col.content_type === 'audio' ? '♪' : col.content_type === 'video' ? '▶' : '';
                
                return `
                    <div class="collection-card browse-card" 
                         data-issuer="${esc(col.issuer)}"
                         data-taxon="${col.taxon}"
                         data-type="${col.content_type || 'image'}"
                         data-slug="${esc(col.slug || '')}"
                         onclick="window.location.href='${imc_col_url(col)}'">
                        <div class="nft-image-wrapper">
                            <img src="${esc(image)}" alt="${esc(name)}" loading="lazy" 
                                 onerror="var _c=this.closest('.collection-card,.th-featured-card,.mint-collection-card');if(_c){_c.classList.add('imc-card-hidden');_c.style.setProperty('display','none','important');}">
                            ${typeIcon ? `<span class="type-badge">${typeIcon}</span>` : ''}
                        </div>
                        <h3>${esc(name)}</h3>
                        <p>📦 ${nftCount} NFTs</p>
                    </div>
                `;
            }).join('');
            
            console.log(`Loaded ${collections.length} browse collections (filter: ${browseFilterType})`);
            
            // Setup slider controls
            setupSlider('browse-collections-slider', 'slider-prev-browse', 'slider-next-browse');
            
        } catch (err) {
            console.error('Browse collections error:', err);
            slider.innerHTML = '<div class="no-collections-message">Failed to load collections. Please try again later.</div>';
        }
    }
    
    // v140: Unified Browse Collections Dropdown Handler
    const browseFilterSelect = document.getElementById('browse-filter-select');
    if (browseFilterSelect) {
        browseFilterSelect.addEventListener('change', function() {
            browseFilterType = this.value;
            loadBrowseCollections();
        });
    }

    // v137: Recent Mints Type Filter Handler
    const mintsTypeFilter = document.getElementById('mints-type-filter');
    if (mintsTypeFilter) {
        mintsTypeFilter.addEventListener('change', function() {
            mintsFilterType = this.value;
            loadRecentMintsV4();
        });
    }

    // loadCollections
    async function loadCollections() {
        const grid = document.getElementById('nft-collections-grid');
        if (!grid) return;
        grid.innerHTML = Array.from({length: 12}, () =>
            '<div class="nft-card collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:65%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:90%;height:12px;margin-top:0.5rem"></div></div></div>'
        ).join('');
        try {
            const res = await fetchWithTimeout(`${xrplMarketplace.endpoints.collectionsHandler}?action=get_collections&t=${Date.now()}&nonce=${encodeURIComponent(xrplMarketplace.nonce)}`, {}, 20000);
            if (!res.ok) throw new Error(`Status ${res.status}`);
            const data = await res.json();
            if (!data.success || !Array.isArray(data.collections)) throw new Error(data.error || 'No collections');
            allCollections = data.collections;
            displayedCollections = [...allCollections];
            renderCollectionsPaginated(currentCollectionsPage);
        } catch (err) {
            console.error('Collections fetch error:', err);
            grid.innerHTML = '<p>Failed to load collections.</p>';
            showToast(`Failed to load collections: ${err.message}`, 'error');
        }
    }

    // renderCollections
    async function renderCollections(collections, append = false, targetGrid = document.getElementById('nft-collections-grid')) {
        if (!targetGrid) return;
        if (!append) targetGrid.innerHTML = '';
        const frag = document.createDocumentFragment();
        for (const col of collections) {
            const card = document.createElement('div');
            card.className = 'nft-card collection-card visible';
            card.dataset.issuer = col.issuer;
            card.dataset.taxon = col.taxon;
            card.dataset.volume = col.volume || 0;
            card.dataset.owners = col.owners || 0;
            card.dataset.priority = col.priority ? 'true' : 'false';
            card.dataset.slug = col.slug || `${col.issuer}-${col.taxon}`;
            card.dataset.nft = JSON.stringify({ name: col.name, image: col.image });
            card.innerHTML = `
                <div class="nft-image-wrapper loading">
                    <img src="${safeImgSrc(col.image)}" alt="${esc(col.name)}" class="nft-image" loading="lazy" onload="this.parentNode.classList.remove('loading')" onerror="window.handleImageError(this, '${col.name}')">
                    <div class="nft-loading-spinner"></div>
                </div>
                <h4>${esc(col.name)}</h4>
                <div class="stats">
                    Owners: ${col.owners || 'N/A'} | 
                    NFTs: ${col.nfts || 'N/A'} | 
                    Floor: ${fmt(col.floor_price || 0, 'XRP')} | 
                    Volume: ${fmt(col.volume || 0, 'XRP')}
                </div>
                <div class="button-row">
                    <button class="view-collection-btn nft-view-more" aria-label="Explore ${esc(col.name)}">Explore</button>
                </div>
            `;
            frag.appendChild(card);
        }
        targetGrid.appendChild(frag);
        const loading = targetGrid.querySelector('.nft-grid-loading');
        if (loading) loading.remove();
        setupButtonListeners();
    }

    // Get filtered and sorted collections
    function getDisplayedCollections() {
        const query = document.getElementById('collection-search')?.value.toLowerCase() || '';
        const sortVal = document.getElementById('collection-sort')?.value || 'alphabetical';

        let filtered = allCollections.filter(col => 
            col.name.toLowerCase().includes(query)
        );

        filtered.sort((a, b) => {
            if (a.priority && !b.priority) return -1;
            if (!a.priority && b.priority) return 1;
            if (sortVal === 'alphabetical') return a.name.localeCompare(b.name);
            if (sortVal === 'volume-desc') return (parseFloat(b.volume) || 0) - (parseFloat(a.volume) || 0);
            if (sortVal === 'owners-desc') return (parseInt(b.owners) || 0) - (parseInt(a.owners) || 0);
            return 0;
        });

        return filtered;
    }

    // Render paginated collections
    function renderCollectionsPaginated(page) {
        displayedCollections = getDisplayedCollections();
        const start = (page - 1) * collectionsPerPage;
        const pageData = displayedCollections.slice(start, start + collectionsPerPage);
        renderCollections(pageData, false);
        currentCollectionsPage = page;
        updateCollectionsPagination(page);
    }

    // Update collections pagination UI
    function updateCollectionsPagination(page) {
        const totalPages = Math.ceil(displayedCollections.length / collectionsPerPage);
        const pageInfoEl = document.getElementById('collections-page-info');
        const prevBtn = document.getElementById('prev-collections-page');
        const nextBtn = document.getElementById('next-collections-page');
        if (pageInfoEl) pageInfoEl.textContent = `Page ${page} of ${totalPages}`;
        if (prevBtn) prevBtn.disabled = page === 1;
        if (nextBtn) nextBtn.disabled = page >= totalPages;
    }

    // Initialize collections
    loadCollections();

    // handleViewCollection
    function handleViewCollection(e) {
        const card = e.target.closest('.collection-card');
        if (!card) return;

        const issuer = card.dataset.issuer;
        const taxon  = card.dataset.taxon;
        const slug   = (card.dataset.slug || '').toLowerCase();

        const path = (slug && FRIENDLY_SLUGS.has(slug))
            ? `/collections/${slug}/`
            : `/collections/${issuer}-${taxon}/`;

        window.location.href = path;
    }

    // Collection card click handler
    function handleCollectionCardClick(e) {
        if (e.target.closest('.view-collection-btn')) return;
        const card = e.target.closest('.collection-card');
        if (!card) return;

        const issuer = card.dataset.issuer;
        const taxon  = card.dataset.taxon;
        const slug   = (card.dataset.slug || '').toLowerCase();

        const path = (slug && FRIENDLY_SLUGS.has(slug))
            ? `/collections/${slug}/`
            : `/collections/${issuer}-${taxon}/`;

        window.location.href = path;
    }

    // View all collections
    document.getElementById('view-all-collections')?.addEventListener('pointerup', () => {
        window.location.href = '/collections/';
    });

    // Collection search/sort
    document.getElementById('collection-search')?.addEventListener('input', debounce(filterCollections, 300));
    document.getElementById('collection-sort')?.addEventListener('change', sortCollections);

    function filterCollections(e) {
        currentCollectionsPage = 1;
        renderCollectionsPaginated(1);
    }

    function sortCollections(e) {
        currentCollectionsPage = 1;
        renderCollectionsPaginated(1);
    }

    // renderNFTs
    async function renderNFTs(nfts, append = false, targetGrid = nftGrid) {
        if (!targetGrid) {
            console.log('renderNFTs: Grid not available on this page, skipping');
            return;
        }
        if (!append) {
            targetGrid.innerHTML = '<div class="nft-grid-loading active skeleton-container">' + Array.from({length: 8}, () => 
                '<div class="nft-card skeleton-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info"><div class="skeleton-text skeleton-shimmer" style="width:60%"></div></div></div>'
            ).join('') + '</div>';
        }
        const frag = document.createDocumentFragment();
        
        // Render cards IMMEDIATELY with no ownership data — don't block on network
        const ownershipMap = {};
        const watchedSet = new Set();

        for (const nft of nfts) {
            try {
                if (!nft || !nft.nftokenID) {
                    console.warn('Invalid NFT data:', nft);
                    continue;
                }
                const meta = nft.metadata || {};
                const name = meta.name || 'Unnamed NFT';
                const rawImage = meta.image || '';
                const hasDirectImage = rawImage && !rawImage.includes('placehold.co') && rawImage.startsWith('http');
                const proxyThumb = window.nftImgProxy(nft.nftokenID, true);
                // Prefer direct IPFS/Pinata image from mint DB if available (no indexer wait)
                // Fall back to img.php proxy for VPS-indexed NFTs
                const image = safeImgSrc(hasDirectImage ? rawImage : (proxyThumb || rawImage || '/wp-content/uploads/fallback-nft.svg'));
                const fullImage = safeImgSrc(hasDirectImage ? rawImage : (window.nftImgProxy(nft.nftokenID, false) || rawImage || '/wp-content/uploads/fallback-nft.svg'), false); // L3: HERO — feeds the detail modal via dataset.nft; must stay full-size
                // Fallback: the OTHER source (proxy if using direct, direct if using proxy)
                const fallbackImage = safeImgSrc(hasDirectImage ? (proxyThumb || '') : (rawImage || ''));
                const description = meta.description || 'No description available';
                const attributes = Array.isArray(meta.attributes) ? meta.attributes : [];
                const issuer = nft.issuer || 'Unknown';
                const taxon = nft.taxon || 0;
                const owner = nft.owner || 'Unknown';
                // v521: server-driven ownership flag — immediate, reliable (nft.owner = buyer_account or on-chain holder). Async loadUserNFTs reveal kept as accuracy bonus.
                const isOwnedServer = !!(userAccount && owner && owner !== 'Unknown' && String(owner) === String(userAccount));
                const royaltyPercent = nft.royalty_percent || 0;
                const creatorWallet = nft.creator_wallet || issuer;
                const isOwned = ownershipMap[nft.nftokenID] || false;
                const onlyXrp = Boolean(nft.only_xrp ?? (typeof nft.flags==='object' && nft.flags?.lsfOnlyXRP));
                
                // Media type detection
                const contentType = nft.content_type || 'image';
                const isAudio = contentType === 'audio';
                const isVideo = contentType === 'video';
                const hasMedia = isAudio || isVideo;
                
                // Skip media icon on collection pages (only show on Trading Hub & Browse Collections)
                const isCollectionPage = filterState === 'custom';
                const showMediaIcon = hasMedia && !isCollectionPage;

                // CP-B (D9/D10): on collection pages lead with the edition number, because
                // CSS ellipsis truncates the TAIL and the tail is the only part that differs
                // ("Amplifier (Free Me Edition) #..." x200). Opt-in via the SAME isCollectionPage
                // flag the media-icon rule already uses, so browse/search labels are untouched.
                // `name` itself is NEVER reassigned - alt text and dataset.nft must keep the
                // true on-chain name. Falls back to the whole name whenever the pattern misses.
                let labelHtml = `<h4>${esc(name)}</h4>`;
                if (isCollectionPage) {
                    const nm = String(name);
                    const m = /^(.*?)[\s]*[#\u2116]\s*(\d+)\s*$/.exec(nm);
                    const leadNum = /^\s*[#\u2116]\s*\d+/.test(nm);   // "#92 Takeda" - already leads with one
                    const onlyNum = /^\s*\d+\s*$/.test(nm);            // "02650" - the name IS the number
                    labelHtml = (m && !leadNum && !onlyNum && m[1].trim())
                        ? `<div class="nft-card-label"><strong class="nft-card-serial">#${esc(m[2])}</strong><span class="nft-card-name">${esc(m[1].trim())}</span></div>`
                        : `<div class="nft-card-label"><span class="nft-card-name">${esc(nm)}</span></div>`;
                }

                const card = document.createElement('div');
                card.className = `nft-card visible ${nft.offer_id ? 'is-listed' : ''} ${(isOwned || isOwnedServer) ? 'owned' : ''} ${showMediaIcon ? 'has-media' : ''} ${showMediaIcon && isAudio ? 'is-audio' : ''} ${showMediaIcon && isVideo ? 'is-video' : ''}`;
                card.dataset.nftId = nft.nftokenID;
                card.dataset.contentType = contentType;
                try {
                    card.dataset.nft = JSON.stringify({ name, image: fullImage, description, attributes });
                } catch (e) {
                    console.warn('Failed to serialize NFT data for', nft.nftokenID, e);
                    continue;
                }
                card.dataset.issuer = issuer;
                card.dataset.taxon = taxon;
                card.dataset.owner = owner;
                card.dataset.onlyXrp = onlyXrp ? 'true' : 'false';
                card.dataset.royaltyPercent = royaltyPercent;
                card.dataset.creatorWallet = creatorWallet;

                // Secondary market listing data (injected by nft_loader when an active sell offer exists)
                const hasListing  = Boolean(nft.offer_id);
                const offerAmount = nft.offer_amount ?? 0;
                // v708 (Step E): prefer the server-resolved ticker; fall back to the raw stored
                // value so the card still renders if an older payload lacks the display field.
                const offerCurrency = nft.offer_currency_display || nft.offer_currency || 'XRP';
                card.dataset.offerId      = nft.offer_id      || '';
                card.dataset.offerAmount  = nft.offer_amount  || '';
                card.dataset.offerCurrency = offerCurrency;

                // Format the price string — drop decimals if whole number
                // Sanitise currency for safe HTML insertion — allows only A-Z0-9 and period/plus
                // v707 (Step B): an unrecognised code must NEVER fall back to 'XRP' -- a token
                // price rendered as XRP is a misleading price, not a cosmetic glitch. Ledger wire
                // codes (40-char hex) and truncated codes fail the ticker test and now render as
                // the neutral 'Token'. Proper ticker resolution arrives with offer_currency_display.
                const safeCurrency = /^[A-Z0-9a-z.+$_-]{1,12}$/.test(offerCurrency) ? offerCurrency : 'Token';
                const priceStr = hasListing
                    ? (Number.isInteger(offerAmount) ? offerAmount : parseFloat(offerAmount.toFixed(2))) + '\u00a0' + safeCurrency
                    : '';

                const isWatched = watchedSet.has(nft.nftokenID);
                
                // Cards navigate to the NFT single page on click.
                // If the NFT has an active sell listing we show:
                //   • a gold price pill overlaid on the image (bottom-left)
                //   • a "Buy Now" link button beneath the title
                card.innerHTML = `
                    ${isOwnedServer ? '<div class="card-owned-tag">\u2605 OWNED</div>' : ''}
                    ${hasListing ? `<div class="card-price-tag">${priceStr}</div>` : ''}
                    <div class="nft-image-wrapper loading">
                        <img src="${image}" alt="${esc(name)}" class="nft-image" loading="lazy" data-raw-image="${fallbackImage}" onload="this.parentNode.classList.remove('loading')" onerror="window.handleImageError(this, '${nft.nftokenID}')">
                        <div class="nft-loading-spinner"></div>
                    </div>
                    <div class="nft-card-info">
                        ${labelHtml}
                    </div>
                `;
                frag.appendChild(card);
            } catch (err) {
                console.error('Error rendering NFT:', nft.nftokenID, err);
            }
        }
        targetGrid.appendChild(frag);
        const loading = targetGrid.querySelector('.nft-grid-loading');
        if (loading) loading.remove();
        setupButtonListeners();
        
        // Async: overlay ownership & watchlist badges AFTER cards are visible
        if (userAccount && nfts.length) {
            Promise.all([
                fetchOwnership(nfts.map(n => n.nftokenID)),
                getWatchlist()
            ]).then(([ownMap, wl]) => {
                nfts.forEach(nft => {
                    if (!nft?.nftokenID) return;
                    const card = targetGrid.querySelector(`.nft-card[data-nft-id="${nft.nftokenID}"]`);
                    if (!card) return;
                    if (ownMap[nft.nftokenID]) {
                        card.classList.add('owned');
                        if (!card.querySelector('.card-owned-tag')) {
                            card.insertAdjacentHTML('afterbegin', '<div class="card-owned-tag">\u2605 OWNED</div>');
                        }
                    }
                });
            }).catch(err => console.warn('Async ownership overlay:', err));
        }
    }

    // fetchOwnership
    async function fetchOwnership(nftIds) {
        if (!userAccount || !nftIds.length) return {};
        try {
            const userNfts = await loadUserNFTs(true);
            const ownedIds = new Set(userNfts.map(n => n.nftokenID));
            const map = {};
            nftIds.forEach(id => { map[id] = ownedIds.has(id); });
            return map;
        } catch (err) {
            console.error('Ownership fetch error:', err);
            return {};
        }
    }

    // getWatchlist
    async function getWatchlist() {
        if (!userAccount) return [];
        if (watchlistCache) return watchlistCache;
        try {
            const res = await fetchWithTimeout(`${xrplMarketplace.endpoints.watchlistHandler}?action=get&account=${encodeURIComponent(userAccount)}&nonce=${encodeURIComponent(xrplMarketplace.nonce)}`, {}, 10000);
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();
            watchlistCache = data.success && Array.isArray(data.watchlist) ? data.watchlist : [];
            return watchlistCache;
        } catch (err) {
            console.error('Watchlist fetch error:', err);
            return [];
        }
    }

    // handleWatchlistToggle
    async function handleWatchlistToggle(e) {
        if (!userAccount) {
            showToast('Please log in with Xaman to manage your watchlist.', 'warning');
            return;
        }
        const btn = e.target;
        const nftId = btn.dataset.nftId;
        const action = btn.dataset.action;
        btn.disabled = true;

        try {
            const body = new URLSearchParams({
                action: action === 'add' ? 'add' : 'remove',
                account: userAccount,
                nft_id: nftId,
                nonce: xrplMarketplace.nonce
            });
            const res = await fetchWithTimeout(xrplMarketplace.endpoints.watchlistHandler, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            }, 10000);
            const data = await res.json();
            if (data.success) {
                const isNowWatched = action === 'add';
                updateWatchlistUI(nftId, isNowWatched);
                watchlistCache = null; // Invalidate cache
                showToast(`NFT ${isNowWatched ? 'added to' : 'removed from'} watchlist.`, 'success');
            } else {
                throw new Error(data.error || 'Watchlist update failed');
            }
        } catch (err) {
            console.error('Watchlist toggle error:', err);
            showToast(`Failed to update watchlist: ${err.message}`, 'error');
        } finally {
            btn.disabled = false;
        }
    }

    // updatePagination
    function updatePagination() {
        const totalPages = Math.ceil(totalNfts / perPage);
        if (pageInfo) pageInfo.textContent = `Page ${currentPage} of ${totalPages || 1}`;
        if (prevPageButton) prevPageButton.disabled = currentPage === 1;
        if (nextPageButton) nextPageButton.disabled = currentPage >= totalPages || !hasMoreNfts;
    }

    // pollSigningStatus - polls XUMM via offer-handler poll_xumm_payload endpoint
    // expectedType can be: 'nft_offer', 'nft_offer_cancel', 'nft_offer_accept', etc.
    async function pollSigningStatus(payloadUuid, offerId, expectedType = null, isGroup = false, startTime = Date.now(), interval = window.config?.pollIntervalMs || 2500) {
        const signingStatus = document.getElementById('signing-status');
        const retryOfferBtn = document.getElementById('retry-offer');
        const closeQrPopupBtn = document.getElementById('close-qr-popup');
        const qrPopup = document.getElementById('qr-code-popup');

        const MAX_ATTEMPTS = 72; // 3 minutes at 2.5s intervals
        let attempts = 0;

        console.log('[Poll] Starting poll for UUID:', payloadUuid, 'expectedType:', expectedType);

        while (attempts < MAX_ATTEMPTS) {
            attempts++;
            try {
                // Poll via offer-handler's poll_xumm_payload endpoint (goes directly to XUMM API)
                const url = `${xrplMarketplace.endpoints.offerHandler}?action=poll_xumm_payload&uuid=${encodeURIComponent(payloadUuid)}&type=${encodeURIComponent(expectedType || '')}&nonce=${encodeURIComponent(xrplMarketplace.nonce)}`;
                const res = await fetchWithTimeout(url, { headers: { 'Accept': 'application/json' } }, 10000);
                const data = await res.json();

                console.log('[Poll] poll_xumm_payload response:', data);

                if (!data.success) {
                    console.log('[Poll] Error from server:', data.error);
                    // Keep polling unless it's a fatal error
                    if (data.error === 'Invalid nonce') {
                        if (signingStatus) signingStatus.textContent = '✗ Session expired';
                        if (closeQrPopupBtn) closeQrPopupBtn.style.display = 'block';
                        if (qrPopup) qrPopup.dataset.locked = 'false';
                        return false;
                    }
                }

                // Check status from response
                if (data.status === 'confirmed') {
                    console.log('[Poll] Transaction CONFIRMED! tx_hash:', data.tx_hash);
                    if (signingStatus) signingStatus.textContent = '✓ Transaction successful!';
                    if (qrPopup) qrPopup.dataset.locked = 'false';
                    
                    await new Promise(r => setTimeout(r, 1500));
                    
                    if (qrPopup) qrPopup.style.display = 'none';
                    document.body.classList.remove('imc-modal-open'); // v198
                    return true;
                }

                if (data.status === 'signed_pending') {
                    console.log('[Poll] Signed, awaiting on-chain confirmation...');
                    if (signingStatus) signingStatus.textContent = 'Confirming on blockchain...';
                    // Keep polling
                }

                if (data.status === 'failed') {
                    console.log('[Poll] Transaction FAILED:', data.dispatched_result);
                    if (signingStatus) signingStatus.textContent = `✗ Failed: ${data.dispatched_result || 'Transaction failed'}`;
                    if (closeQrPopupBtn) closeQrPopupBtn.style.display = 'block';
                    if (qrPopup) qrPopup.dataset.locked = 'false';
                    showToast(`Transaction failed: ${data.dispatched_result || 'Unknown error'}`, 'error', { position: 'center' });
                    return false;
                }

                if (data.status === 'rejected') {
                    console.log('[Poll] User REJECTED');
                    if (signingStatus) signingStatus.textContent = '✗ Transaction declined';
                    if (closeQrPopupBtn) closeQrPopupBtn.style.display = 'block';
                    if (qrPopup) qrPopup.dataset.locked = 'false';
                    showToast('Transaction was declined.', 'warning', { position: 'center' });
                    return false;
                }

                if (data.status === 'expired') {
                    console.log('[Poll] Payload EXPIRED');
                    if (signingStatus) signingStatus.textContent = '⏱ Transaction expired';
                    if (retryOfferBtn) retryOfferBtn.style.display = 'block';
                    if (closeQrPopupBtn) closeQrPopupBtn.style.display = 'block';
                    if (qrPopup) qrPopup.dataset.locked = 'false';
                    showToast('Transaction expired. Please try again.', 'warning', { position: 'center' });
                    return false;
                }

                // Still pending
                if (signingStatus) signingStatus.textContent = `Waiting for signature... (${attempts})`;

            } catch (err) {
                console.error('[Poll] Error:', err);
            }

            await new Promise(r => setTimeout(r, interval));
        }

        // Timeout
        if (signingStatus) signingStatus.textContent = '⏱ Signing timed out';
        if (retryOfferBtn) retryOfferBtn.style.display = 'block';
        if (closeQrPopupBtn) closeQrPopupBtn.style.display = 'block';
        if (qrPopup) qrPopup.dataset.locked = 'false';
        showToast('Signing timed out. Please try again.', 'warning', { position: 'center' });
        return false;
    }

    // setupButtonListeners
    function setupButtonListeners() {
        function setupGridHandler(grid) {
            if (!grid || gridHandlers.has(grid)) return;
            const handler = (e) => handleGridClick(e);
            grid.addEventListener('pointerup', handler);
            gridHandlers.set(grid, handler);
        }
        [nftGrid, recentDropsGrid].forEach(setupGridHandler);

        document.querySelectorAll('.offer-action').forEach(btn => {
            btn.removeEventListener('pointerup', handleOfferAction);
            btn.addEventListener('pointerup', handleOfferAction);
        });

        document.querySelectorAll('.watchlist-toggle').forEach(btn => {
            btn.removeEventListener('pointerup', handleWatchlistToggle);
            btn.addEventListener('pointerup', handleWatchlistToggle);
        });

        const controls = [
            { el: document.querySelector('.nft-popup-close'), handler: handlePopupClose },
            { el: document.querySelector('.offer-popup-close'), handler: handleOfferClose },
            { el: document.querySelector('.compare-popup-close'), handler: handleCompareClose },
            { el: document.getElementById('toggle-description'), handler: handleToggleDesc },
            { el: document.getElementById('toggle-attributes'), handler: handleToggleAttrs },
            { el: document.getElementById('nft-popup-make-offer'), handler: handlePopupMakeOffer },
            { el: document.getElementById('nft-popup-message-owner'), handler: handlePopupMessageOwner },
            { el: document.getElementById('nft-popup-explorer'), handler: handlePopupExplorer },
            { el: document.getElementById('nft-popup-compare'), handler: handlePopupCompare },
            { el: document.getElementById('clear-compare'), handler: handleClearCompare },
            { el: document.getElementById('view-all-drops'), handler: handleViewAllDrops },
            { el: document.getElementById('close-qr-popup'), handler: handleQrClose }
        ];

        controls.forEach(({ el, handler, event = 'pointerup' }) => {
            if (el) {
                el.removeEventListener(event, handler);
                el.addEventListener(event, handler);
            }
        });

        // Collection card buttons
        document.querySelectorAll('.view-collection-btn').forEach(btn => {
            btn.removeEventListener('pointerup', handleViewCollection);
            btn.addEventListener('pointerup', handleViewCollection);
        });

        // Make entire collection card clickable
        document.querySelectorAll('.collection-card').forEach(card => {
            card.removeEventListener('pointerup', handleCollectionCardClick);
            card.addEventListener('pointerup', handleCollectionCardClick);
        });

        ['nft-popup', 'offer-popup', 'compare-popup'].forEach(popupId => {
            const popup = document.getElementById(popupId);
            if (popup) {
                popup.removeEventListener('pointerup', handleOverlayClose);
                popup.addEventListener('pointerup', handleOverlayClose);
            }
        });
    }

    // handleGridClick
    async function handleGridClick(e) {
        e.stopPropagation();
        
        // GUARD: Never navigate when clicking retry/refresh buttons
        if (e.target.closest('.refresh-nft')) {
            return;
        }
        
        const btn = e.target.closest('button');
        const card = e.target.closest('.nft-card');

        if (btn) {
            e.preventDefault();
            const nftId = btn.dataset.nftId;
            const owner = btn.dataset.owner;

            if (btn.classList.contains('nft-view-more')) {
                btn.disabled = true;
                try {
                    const card = btn.closest('.nft-card');
                    if (window.innerWidth <= 768) {
                        if (card) openNFTPopup(card);
                    } else {
                        await openOfferPopup(nftId);
                    }
                } catch (err) {
                    console.error('Button click error:', err);
                    showToast(`Failed to open ${window.innerWidth <= 768 ? 'NFT details' : 'offer form'}: ${err.message}`, 'error');
                } finally {
                    btn.disabled = false;
                }
            }
            return;
        }

        // Card clicks navigate to NFT dedicated page
        if (card && card.dataset.nftId) {
            e.preventDefault();
            const nftId = card.dataset.nftId;
            // Navigate to NFT dedicated page
            window.location.href = `/nft/${nftId}/`;
        }
    }

    // openNFTPopup
    function openNFTPopup(card) {
        if (!card || !card.dataset.nftId || !card.dataset.nft) {
            console.error('Invalid card data:', card);
            showToast('Failed to load NFT details: Invalid data', 'error');
            return;
        }
        try {
            const data = JSON.parse(card.dataset.nft);
            const nftId = card.dataset.nftId;
            const popup = document.getElementById('nft-popup');
            if (!popup) {
                console.error('nft-popup element not found in DOM');
                showToast('NFT popup not found', 'error');
                return;
            }
            const popupTitle = document.getElementById('nft-popup-title');
            const popupImage = document.getElementById('nft-popup-image');
            const popupImageWrapper = popupImage?.closest('.nft-popup-image-wrapper');
            const popupDesc = document.getElementById('nft-popup-description');
            const popupAttrs = document.getElementById('nft-popup-attributes');
            const popupIssuer = document.getElementById('nft-popup-issuer');
            const popupOwner = document.getElementById('nft-popup-owner');
            const popupExplorer = document.getElementById('nft-popup-explorer');
            const popupMessageOwner = document.getElementById('nft-popup-message-owner');

            if (!popupTitle || !popupImage || !popupDesc || !popupAttrs || !popupIssuer || !popupOwner || !popupExplorer || !popupMessageOwner) {
                console.error('NFT popup elements missing');
                showToast('NFT popup is incomplete. Please refresh.', 'error', { position: 'center' });
                return;
            }

            popupTitle.textContent = data.name || 'Unnamed NFT';
            popupImage.src = '';
            if (popupImageWrapper) popupImageWrapper.classList.add('loading');
            popupImage.src = `${data.image}${data.image.includes('?') ? '&' : '?'}t=${Date.now()}`;
            popupImage.alt = data.name || 'NFT Image';
            popupImage.onload = () => popupImageWrapper?.classList.remove('loading');
            popupImage.onerror = () => popupImageWrapper?.classList.remove('loading');
            popupDesc.textContent = data.description || 'No description available';
            popupAttrs.innerHTML = data.attributes && data.attributes.length
                ? data.attributes.map(attr => `<li>${esc(attr.trait_type)}: ${esc(attr.value)}</li>`).join('')
                : '<li>No attributes</li>';
            popupIssuer.textContent = card.dataset.issuer || 'Unknown';
            popupOwner.textContent = card.dataset.owner || 'Unknown';
            popupExplorer.dataset.href = `https://bithomp.com/nft/${nftId}`;
            
            // Show/hide message owner based on login
            popupMessageOwner.style.display = userAccount && card.dataset.owner !== userAccount ? 'inline-block' : 'none';

            popup.dataset.nftId = nftId;
            popup.style.display = 'flex';
        } catch (err) {
            console.error('Error in openNFTPopup:', err);
            showToast(`Failed to open NFT details: ${err.message}`, 'error', { position: 'center' });
        }
    }

    // openOfferPopup
    async function openOfferPopup(nftId) {
        try {
            const card = document.querySelector(`.nft-card[data-nft-id="${nftId}"]`);
            if (!card) {
                console.error('NFT card not found for ID:', nftId);
                throw new Error('NFT card not found');
            }
            const data = JSON.parse(card.dataset.nft || '{}');
            const offerPopup = document.getElementById('offer-popup');
            if (!offerPopup) {
                console.error('offer-popup element not found');
                throw new Error('offer-popup not found');
            }
            const offerPopupTitle = document.getElementById('offer-popup-title');
            const offerPopupImage = document.getElementById('offer-popup-image');
            const offerImageWrapper = offerPopupImage?.closest('.nft-popup-image-wrapper');
            const offerIssuer = document.getElementById('offer-popup-issuer');
            const offerOwner = document.getElementById('offer-popup-owner');
            const offerNftId = document.getElementById('offer-nft-id');

            if (!offerPopupTitle || !offerPopupImage || !offerIssuer || !offerOwner || !offerNftId) {
                throw new Error('Offer popup elements missing');
            }

            offerPopupTitle.textContent = `Make an Offer for ${data.name || 'Unnamed NFT'}`;
            offerPopupImage.src = '';
            if (offerImageWrapper) offerImageWrapper.classList.add('loading');
            offerPopupImage.src = `${data.image}${data.image.includes('?') ? '&' : '?'}t=${Date.now()}`;
            offerPopupImage.alt = data.name || 'NFT Image';
            offerPopupImage.onload = () => offerImageWrapper?.classList.remove('loading');
            offerPopupImage.onerror = () => offerImageWrapper?.classList.remove('loading');
            offerIssuer.textContent = card.dataset.issuer || 'Unknown';
            offerIssuer.dataset.issuer = card.dataset.issuer || 'unknown';
            offerIssuer.dataset.taxon = card.dataset.taxon || '0';
            const issuer = offerIssuer.dataset.issuer;
            const taxon = +offerIssuer.dataset.taxon || 0;
            const fallback = collectionRoyaltyMap[`${issuer}:${taxon}`]?.royaltyPercent || 0;
            offerIssuer.dataset.royalty = fallback;
            offerOwner.textContent = card.dataset.owner || 'Unknown';
            offerNftId.value = nftId;

            initializeOfferPopup(nftId);

            offerPopup.style.display = 'flex';
        } catch (err) {
            console.error('Error in openOfferPopup:', err);
            showToast(`Failed to open offer form: ${err.message}`, 'error', { position: 'center' });
        }
    }

    // handlePopupClose
    function handlePopupClose(e) {
        e.preventDefault();
        const popup = document.getElementById('nft-popup');
        if (popup) {
            popup.style.display = 'none';
            const wrapper = document.getElementById('nft-popup-image')?.closest('.nft-popup-image-wrapper');
            if (wrapper) wrapper.classList.remove('loading');
        }
    }

    // handleOfferClose
    function handleOfferClose(e) {
        e.preventDefault();
        e.stopPropagation();
        const popup = document.getElementById('offer-popup');
        if (popup) {
            popup.style.display = 'none';
            const wrapper = document.getElementById('offer-popup-image')?.closest('.nft-popup-image-wrapper');
            if (wrapper) wrapper.classList.remove('loading');
        }
    }

    // handleCompareClose
    function handleCompareClose(e) {
        e.preventDefault();
        const popup = document.getElementById('compare-popup');
        if (popup) {
            popup.style.display = 'none';
            compareNfts = [];
            renderCompareNFT(null, 1);
            renderCompareNFT(null, 2);
        }
    }

    // handleToggleDesc
    function handleToggleDesc() {
        const popupDesc = document.getElementById('nft-popup-description');
        if (popupDesc) {
            popupDesc.style.display = popupDesc.style.display === 'none' ? 'block' : 'none';
            this.textContent = popupDesc.style.display === 'none' ? 'Show Description' : 'Hide Description';
            this.setAttribute('aria-expanded', popupDesc.style.display !== 'none');
        }
    }

    // handleToggleAttrs
    function handleToggleAttrs() {
        const popupAttrs = document.getElementById('nft-popup-attributes');
        if (popupAttrs) {
            popupAttrs.style.display = popupAttrs.style.display === 'none' ? 'block' : 'none';
            this.textContent = popupAttrs.style.display === 'none' ? 'Show Attributes' : 'Hide Attributes';
            this.setAttribute('aria-expanded', popupAttrs.style.display !== 'none');
        }
    }

    // handlePopupMakeOffer
    async function handlePopupMakeOffer() {
        const popup = document.getElementById('nft-popup');
        const nftId = popup?.dataset.nftId;
        if (!nftId) {
            showToast('NFT data not found.', 'error', { position: 'center' });
            return;
        }
        try {
            await openOfferPopup(nftId);
            popup.style.display = 'none';
        } catch (err) {
            console.error('Popup Make Offer error:', err);
            showToast(`Failed to open offer form: ${err.message}`, 'error', { position: 'center' });
        }
    }

    // handlePopupMessageOwner
    async function handlePopupMessageOwner() {
        const nftId = document.getElementById('nft-popup')?.dataset.nftId;
        const card = document.querySelector(`.nft-card[data-nft-id="${nftId}"]`);
        if (!card || !nftId) {
            showToast('NFT data not found.', 'error');
            return;
        }
        if (!userAccount) {
            showToast('Please log in with Xaman to message the owner.', 'error');
            return;
        }
        try {
            if (typeof window.startChat === 'function') {
                await window.startChat(card.dataset.owner, nftId);
                document.getElementById('nft-popup').style.display = 'none';
                showToast('Chat opened with owner.', 'success');
            } else {
                showToast('Messaging feature unavailable. Please try again later.', 'error');
            }
        } catch (err) {
            console.error('Error initiating chat:', err);
            showToast(`Failed to open chat: ${err.message}`, 'error');
        }
    }

    // handlePopupExplorer
    function handlePopupExplorer() {
        const button = document.getElementById('nft-popup-explorer');
        const href = button?.dataset.href;
        if (href && href !== '#') {
            const w = window.open(href, '_blank');
            if (w) w.opener = null;
        } else {
            showToast('Invalid Bithomp URL', 'error');
        }
    }

    // handlePopupCompare
    function handlePopupCompare() {
        const nftId = document.getElementById('nft-popup')?.dataset.nftId;
        if (!nftId) {
            showToast('NFT data not found.', 'error');
            return;
        }
        if (compareNfts.includes(nftId)) {
            showToast('NFT already selected for comparison.', 'warning');
            return;
        }
        if (compareNfts.length >= 2) {
            showToast('Comparison limited to two NFTs. Clear to add new.', 'warning');
            return;
        }
        compareNfts.push(nftId);
        renderCompareNFT(nftId, compareNfts.length);
        document.getElementById('nft-popup').style.display = 'none';
        document.getElementById('compare-popup').style.display = 'flex';
    }

    // handleClearCompare
    function handleClearCompare() {
        compareNfts = [];
        renderCompareNFT(null, 1);
        renderCompareNFT(null, 2);
        document.getElementById('compare-popup').style.display = 'none';
    }

    // handleViewAllDrops
    function handleViewAllDrops() {
        const el = viewAllDrops || document.getElementById('view-all-drops');
        if (!el) return;
        const slug = el.dataset.collectionSlug || '';
        window.location.href = slug ? `/collections/${slug}/` : '/recent-drops';
    }

    // renderCompareNFT
    function renderCompareNFT(nftId, slot) {
        const container = document.getElementById(`compare-nft-${slot}`);
        if (!container) return;

        if (!nftId) {
            container.innerHTML = '<p>Select an NFT to compare</p>';
            return;
        }

        const card = document.querySelector(`.nft-card[data-nft-id="${nftId}"]`);
        if (!card) {
            container.innerHTML = '<p>NFT not found</p>';
            return;
        }

        const data = JSON.parse(card.dataset.nft || '{}');
        container.innerHTML = `
            <img src="${safeImgSrc(data.image)}" alt="${esc(data.name)}" style="max-width:150px;">
            <h4>${esc(data.name)}</h4>
            <p>${esc(data.description || 'No description')}</p>
            <ul>
                ${(data.attributes || []).map(a => `<li>${esc(a.trait_type)}: ${esc(a.value)}</li>`).join('')}
            </ul>
        `;
    }

    // handleOverlayClose
    function handleOverlayClose(e) {
        if (e.target === e.currentTarget) {
            e.target.style.display = 'none';
        }
    }

    // fallbackBreakdown
    function fallbackBreakdown(currency = 'XRP', issuer = '', taxon = 0) {
        const royaltyPercent = collectionRoyaltyMap[`${issuer}:${taxon}`]?.royaltyPercent || 0;
        const issuerEl = document.getElementById('offer-popup-issuer');
        if (issuerEl) {
            issuerEl.dataset.royalty = royaltyPercent;
        }
        updateOfferTotals();
    }

    // updateOfferTotals
    async function updateOfferTotals() {
        const amountInput = document.getElementById('offer-amount');
        const currency = document.getElementById('offer-currency')?.value || 'XRP';
        const amount = parseFloat(amountInput?.value) || 0;
        const disc = await computeUserDiscount() / 100;
        const feePercent = window.config.platformFeePercent * (1 - disc);
        const fee = amount * (feePercent / 100);
        const issuerEl = document.getElementById('offer-popup-issuer');
        const royaltyPercent = issuerEl ? (parseFloat(issuerEl.dataset.royalty || '0')) : 0;
        const royalty = amount * (royaltyPercent / 100);
        const netAmount = amount - royalty;
        const total = amount + fee;

        const breakdownNet = document.getElementById('breakdown-net');
        const breakdownFee = document.getElementById('breakdown-fee');
        const breakdownRoyalty = document.getElementById('breakdown-royalty');
        const breakdownRoyaltyPercent = document.getElementById('breakdown-royalty-percent');
        const breakdownTotal = document.getElementById('breakdown-total');

        if (!breakdownNet || !breakdownFee || !breakdownRoyalty || !breakdownRoyaltyPercent || !breakdownTotal) {
            return;
        }

        const feeStr = fmt(fee, currency);
        const royaltyStr = royalty > 0 ? fmt(royalty, currency) : 'None';
        const netStr = fmt(netAmount, currency);
        const totalStr = fmt(total, currency);
        breakdownNet.textContent = `${netStr} (est. seller proceeds)`;
        breakdownFee.textContent = `${feeStr} (${feePercent.toFixed(1)}% platform fee collected on finalization, paid by buyer)`;
        breakdownRoyalty.textContent = royaltyStr;
        breakdownRoyaltyPercent.textContent = royalty > 0 ? `(${(royaltyPercent).toFixed(1)}% sent automatically to creator on paid transfers only)` : '(0% sent automatically to creator on paid transfers only)';
        breakdownTotal.textContent = `${totalStr} (your total)`;
    }

    // fetchRoyalty
    async function fetchRoyalty() {
        const nftId = document.getElementById('offer-nft-id')?.value;
        if (!nftId) {
            fallbackBreakdown();
            return;
        }

        try {
            const body = new URLSearchParams({
                action: 'get_nft_name',
                nft_id: nftId,
                nonce: xrplMarketplace.nonce
            });
            const res = await fetchWithTimeout(xrplMarketplace.endpoints.offerHandler, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: body
            });
            const data = await res.json();

            if (!data.success) throw new Error(data.error || 'Failed to fetch royalty');

            const resp = data.data || {};
            const royaltyPercent = resp.royalty_percent;
            const issuer = resp.issuer;
            const taxon = resp.taxon;
            if (resp.royalty_warning) showToast(resp.royalty_warning, 'warning');
            if (resp.trustline_warning) showToast(resp.trustline_warning, 'warning');

            const issuerEl = document.getElementById('offer-popup-issuer');
            if (issuerEl) {
                issuerEl.dataset.royalty = royaltyPercent;
                issuerEl.dataset.issuer = issuer;
                issuerEl.dataset.taxon = taxon;
            }

            updateOfferTotals();
        } catch (err) {
            console.error('Royalty fetch error:', err);
            const issuerEl = document.getElementById('offer-popup-issuer');
            const issuer = issuerEl?.dataset.issuer || 'unknown';
            const taxon = +(issuerEl?.dataset.taxon || 0);
            const currency = document.getElementById('offer-currency')?.value || 'XRP';
            fallbackBreakdown(currency, issuer, taxon);
        }
    }

    // handleOfferSubmit
    async function handleOfferSubmit(e) {
        e.preventDefault();
        e.stopPropagation();
        if (!userAccount) {
            showToast('Please log in with Xaman to submit an offer.', 'error', { position: 'center' });
            return;
        }

        const submitBtn = e.target.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;

        const amountError = document.getElementById('offer-amount-error');
        const formError = document.getElementById('offer-form-error');
        if (amountError) amountError.style.display = 'none';
        if (formError) formError.style.display = 'none';

        const nftId = document.getElementById('offer-nft-id')?.value;
        if (!nftId || !/^[0-9A-Fa-f]{64}$/.test(nftId)) {
            if (formError) {
                formError.textContent = 'Invalid NFT ID.';
                formError.style.display = 'block';
            }
            showToast('Invalid NFT ID.', 'error', { position: 'center' });
            if (submitBtn) submitBtn.disabled = false;
            return;
        }

        const raw = (document.getElementById('offer-amount')?.value || '').trim();
        if (Number(raw) <= 0) {
            if (formError) {
                formError.textContent = 'Amount must be greater than 0';
                formError.style.display = 'block';
            }
            showToast('Amount must be greater than 0', 'error', { position: 'center' });
            if (submitBtn) submitBtn.disabled = false;
            return;
        }

        try {
            await submitOffer(nftId);
        } catch (err) {
            console.error('submitOffer failed:', err);
            showToast('Offer submission failed: ' + err.message, 'error', { position: 'center' });
        } finally {
            if (submitBtn) submitBtn.disabled = false;
        }
    }

    // submitOffer
    async function submitOffer(nftId) {
        const amountInput = document.getElementById('offer-amount');
        const offerCurrency = document.getElementById('offer-currency');
        const currency = offerCurrency?.value || 'XRP';
        const qrPopup = document.getElementById('qr-code-popup');
        const signingStatus = document.getElementById('signing-status');
        const retryOfferBtn = document.getElementById('retry-offer');
        const closeQrPopupBtn = document.getElementById('close-qr-popup');
        const formError = document.getElementById('offer-form-error');
        
        // v86: Get issuer from selected option
        const selectedOption = offerCurrency?.selectedOptions[0];
        const issuer = selectedOption?.dataset.issuer || '';
        
        // v86: Check trustline before proceeding
        if (!_currentTrustlineOk) {
            if (formError) {
                formError.textContent = `You need a trustline for ${currency} to make this offer.`;
                formError.style.display = 'block';
            }
            throw new Error(`Missing trustline for ${currency}`);
        }

        const raw = (amountInput?.value || '').trim();
        let amountStr = raw;
        if (currency === 'XRP' && !/^\d+(\.\d{1,6})?$/.test(raw)) {
            if (formError) {
                formError.textContent = 'XRP supports up to 6 decimals';
                formError.style.display = 'block';
            }
            throw new Error('XRP supports up to 6 decimals');
        }
        if (!/^\d+(\.\d{1,16})?$/.test(raw)) {
            if (formError) {
                formError.textContent = 'Invalid amount format';
                formError.style.display = 'block';
            }
            throw new Error('Invalid amount format');
        }

        const card = document.querySelector(`.nft-card[data-nft-id="${nftId}"]`);
        const onlyXrp = card?.dataset.onlyXrp === 'true';
        if (onlyXrp && currency !== 'XRP') {
            if (formError) {
                formError.textContent = 'This NFT only accepts XRP offers.';
                formError.style.display = 'block';
            }
            throw new Error('This NFT only accepts XRP offers.');
        }

        // Balance check (also verifies trustline for IOUs)
        try {
            const balanceUrl = `${xrplMarketplace.endpoints.offerHandler}?action=check_balance&account=${encodeURIComponent(userAccount)}&currency=${currency}${issuer ? '&issuer=' + encodeURIComponent(issuer) : ''}&amount=${raw}&nonce=${encodeURIComponent(xrplMarketplace.nonce)}`;
            const balanceRes = await fetchWithTimeout(balanceUrl);
            const balanceData = await balanceRes.json();
            const ok = (balanceData && (balanceData.ok ?? balanceData.data?.ok)) === true;
            if (!ok) {
                if (formError) {
                    formError.textContent = balanceData.error || 'Insufficient funds or missing trustline.';
                    formError.style.display = 'block';
                }
                if (balanceData.error === 'No trustline' || balanceData.error === 'Missing trustline') {
                    showToast(`Set up ${currency} trustline in Xaman to proceed.`, 'warning', { position: 'center' });
                }
                throw new Error(balanceData.error || 'Insufficient funds or missing trustline');
            }
        } catch (err) {
            console.error('Balance check error:', err);
            if (formError) {
                formError.textContent = 'Failed to verify funds. Please try again.';
                formError.style.display = 'block';
            }
            throw err;
        }

        const body = new URLSearchParams({
            action: 'create_offer',
            account: userAccount,
            target_nft_id: nftId,
            amount: amountStr,
            currency: currency,
            nonce: xrplMarketplace.nonce
        });
        
        // v86: Add issuer for IOU tokens
        if (issuer) {
            body.append('issuer', issuer);
        }

        const handlerUrl = xrplMarketplace.endpoints.offerHandler;

        try {
            const res = await fetchWithTimeout(handlerUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body }, 15000);

            const text = await res.text();
            const data = text ? JSON.parse(text) : { success: false, error: 'Empty server response' };

            if (!res.ok) {
                throw new Error(`HTTP ${res.status}: ${data.error || text}`);
            }

            if (!data.success) {
                throw new Error(data.error || 'Unknown server error');
            }

            // FIXED: Backend returns flat response, not nested data.data
            const payload = {
                payload_uuid: data.payload_uuid,
                qr: data.qr,
                deeplink: data.deeplink,
                offer_id: data.offer_id || '',
                trustline_warning: data.trustline_warning,
                royalty_warning: data.royalty_warning
            };

            if (payload.trustline_warning) {
                showToast(payload.trustline_warning, 'warning', { position: 'center' });
            }
            if (payload.royalty_warning) {
                showToast(payload.royalty_warning, 'warning', { position: 'center' });
            }

            if (!payload.payload_uuid || !payload.qr || !payload.deeplink) {
                throw new Error('Invalid server response: missing payload_uuid, qr, or deeplink');
            }
            
            qrPopup.dataset.locked = 'true';
            document.getElementById('qr-code-image').src = payload.qr || ''; // v198: was fallback-nft.svg
            document.getElementById('qr-offer-id').textContent = payload.offer_id || 'Pending…';
            document.getElementById('qr-deeplink').href = payload.deeplink || '#';
            signingStatus.textContent = 'Waiting for signature...';
            retryOfferBtn.style.display = 'none';
            closeQrPopupBtn.style.display = 'none';
            qrPopup.style.display = 'flex';

            await pollSigningStatus(payload.payload_uuid, payload.offer_id, 'nft_offer');

            const offerPopup = document.getElementById('offer-popup');
            if (offerPopup) {
                offerPopup.style.display = 'none';
            }
        } catch (err) {
            console.error('Offer submission failed:', err);
            if (formError) {
                formError.textContent = `Offer submission failed: ${err.message || 'Unknown error'}`;
                formError.style.display = 'block';
            }
            showToast(`Offer submission failed: ${err.message || 'Unknown error'}`, 'error', { position: 'center' });
            qrPopup.dataset.locked = 'false';
            document.getElementById('qr-code-image').src = ''; // v198: was fallback-nft.svg
            signingStatus.textContent = 'Offer submission failed: ' + err.message;
            retryOfferBtn.style.display = 'block';
            closeQrPopupBtn.style.display = 'block';
            qrPopup.style.display = 'flex';
            throw err;
        }
    }

    // initializeOfferPopup
    async function initializeOfferPopup(nftId) {
        const offerNftId = document.getElementById('offer-nft-id');
        const offerAmount = document.getElementById('offer-amount');
        const offerCurrency = document.getElementById('offer-currency');
        const offerForm = document.getElementById('offer-form');

        if (!offerNftId || !offerAmount || !offerCurrency || !offerForm) {
            console.error('Offer popup elements missing');
            showToast('Offer form is incomplete. Please refresh and try again.', 'error', { position: 'center' });
            return;
        }

        offerNftId.value = nftId;
        offerAmount.value = '';
        
        // v86: Load tokens from Token Manager API
        if (!_offerTokensLoaded && window.imcTrustline) {
            try {
                const tokens = await window.imcTrustline.getTokens('offers');
                if (tokens && tokens.length > 0) {
                    offerCurrency.innerHTML = '';
                    tokens.forEach(t => {
                        const opt = document.createElement('option');
                        opt.value = t.ticker;
                        opt.dataset.issuer = t.issuer || '';
                        opt.dataset.trustlineUrl = t.trustline_url || '';
                        opt.textContent = `${t.icon || '🪙'} ${t.ticker}`;
                        offerCurrency.appendChild(opt);
                    });
                    _offerTokensLoaded = true;
                }
            } catch (err) {
                console.error('Failed to load tokens:', err);
            }
        }
        
        offerCurrency.value = 'XRP';
        _currentTrustlineOk = true;

        const card = document.querySelector(`.nft-card[data-nft-id="${nftId}"]`);
        const onlyXrp = card?.dataset.onlyXrp === 'true';
        offerCurrency.disabled = !!onlyXrp;
        offerCurrency.title = onlyXrp ? 'This NFT only accepts XRP' : '';

        offerAmount.removeEventListener('input', fetchRoyalty);
        offerCurrency.removeEventListener('change', handleCurrencyChange);
        offerForm.removeEventListener('submit', handleOfferSubmit);

        offerAmount.addEventListener('input', debounce(fetchRoyalty, 300));
        offerCurrency.addEventListener('change', handleCurrencyChange);
        offerForm.addEventListener('submit', handleOfferSubmit);

        _royaltyWarnedSession = false;
        fetchRoyalty();
    }
    
    // v86: Handle currency change with trustline check
    async function handleCurrencyChange() {
        fetchRoyalty();
        await checkOfferTrustline();
    }
    
    // v86: Check trustline for selected currency
    async function checkOfferTrustline() {
        const offerCurrency = document.getElementById('offer-currency');
        const currency = offerCurrency?.value || 'XRP';
        const submitBtn = offerCurrency?.closest('form')?.querySelector('button[type="submit"]');
        const warningEl = document.getElementById('offer-trustline-warning') || document.getElementById('trustline-warning');
        
        if (currency === 'XRP') {
            _currentTrustlineOk = true;
            if (warningEl) warningEl.style.display = 'none';
            if (submitBtn) submitBtn.disabled = false;
            return;
        }
        
        if (!window.imcTrustline || !userAccount) {
            _currentTrustlineOk = true;
            return;
        }
        
        const selectedOption = offerCurrency.selectedOptions[0];
        const result = await window.imcTrustline.checkTrustline(userAccount, currency);
        _currentTrustlineOk = result.has_trustline;
        
        if (!result.has_trustline) {
            const trustlineUrl = selectedOption?.dataset.trustlineUrl || result.trustline_url || '';
            if (warningEl) {
                const textEl = warningEl.querySelector('#trustline-warning-text') || warningEl;
                if (textEl.tagName === 'SPAN') {
                    textEl.textContent = `You need a trustline for ${currency} to make offers.`;
                }
                const linkEl = warningEl.querySelector('#set-trustline-link');
                if (linkEl) {
                    linkEl.href = trustlineUrl;
                    linkEl.style.display = trustlineUrl ? 'block' : 'none';
                }
                warningEl.style.display = 'block';
            }
            if (submitBtn) submitBtn.disabled = true;
            showToast(`Set up ${currency} trustline to make offers with this token.`, 'warning', { position: 'center' });
        } else {
            if (warningEl) warningEl.style.display = 'none';
            if (submitBtn) submitBtn.disabled = false;
        }
    }

    // Initialize
    loadNFTs(currentPage);
    loadRecentDrops();
    loadBrowseCollections();

    // Global listeners
    myHubBtn?.addEventListener('pointerup', () => {
        loadMyHubData();
        myHubPopup.style.display = 'flex';
    });

    frequencyFountainBtn?.addEventListener('pointerup', () => {
        window.location.href = '/profile';
    });

    tasksBtn?.addEventListener('pointerup', () => {
        window.location.href = '/tasks';
    });

    document.querySelector('.popup-close')?.addEventListener('pointerup', () => {
        myHubPopup.style.display = 'none';
    });

    filterSelect?.addEventListener('change', () => {
        filterState = filterSelect.value;
        currentPage = 1;
        loadNFTs(1, false);
    });

    sortSelect?.addEventListener('change', () => {
        // CP-A: '__mine__' is an owned-only FILTER that now lives in the sort list so it
        // renders on every collection (the old title-filter home only appeared on IMC
        // collections with 2+ titles). It never becomes a sort value - the live sort is
        // preserved underneath, and it composes with the title filter rather than
        // clearing it (the loader threads &owner and &title independently).
        if (sortSelect.value === '__mine__') {
            if (!userAccount) {
                showToast('Connect your Xaman wallet to view your NFTs.', 'warning');
                sortSelect.value = sortState;   // restore the sort that is actually live
                mineState = false;
            } else {
                mineState = true;
            }
        } else {
            mineState = false;
            sortState = sortSelect.value;
        }
        currentPage = 1;
        loadNFTs(1, false);
    });

    // v504 Phase A2: Title filter (IMC collections) — mirrors the sort listener exactly.
    titleSelect?.addEventListener('change', () => {
        // CP-A: '__mine__' moved to the sort select; this is now purely a title filter.
        titleState = (titleSelect.value === 'all') ? '' : titleSelect.value;
        currentPage = 1;
        loadNFTs(1, false);
    });

    searchButton?.addEventListener('pointerup', async () => {
        const q = searchInput.value.trim();
        if (!q) return;
        showToast(`Searching for: ${q}`, 'info');
        try {
            const res = await fetchWithTimeout(`${xrplMarketplace.endpoints.filterHandler}?search=${encodeURIComponent(q)}&limit=${perPage}&offset=0&sort=${sortState}&t=${Date.now()}&nonce=${encodeURIComponent(xrplMarketplace.nonce)}`, {}, 20000);
            if (!res.ok) throw new Error(`Status ${res.status}`);
            const data = await res.json();
            if (data.success) {
                currentPage = 1;
                totalNfts = data.total || data.nfts.length || 0;
                perPage = Math.min(perPage, totalNfts || perPage);
                await renderNFTs(data.nfts.slice(0, perPage), false);
                updatePagination();
                setupButtonListeners();
            } else {
                showToast('No results found.', 'warning');
            }
        } catch (err) {
            console.error('Search error:', err);
            showToast(`Search failed: ${err.message}`, 'error');
        }
    });

    searchInput?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') searchButton.click();
    });

    prevPageButton?.addEventListener('pointerup', () => {
        if (currentPage > 1) loadNFTs(currentPage - 1, false);
    });

    nextPageButton?.addEventListener('pointerup', () => {
        loadNFTs(currentPage + 1, false);
    });

    // ============================================================
    // HORIZONTAL SLIDER CONTROLS
    // ============================================================
    function setupSlider(sliderId, prevBtnClass, nextBtnClass) {
        const slider = document.getElementById(sliderId);
        const prevBtn = document.querySelector(`.${prevBtnClass}`);
        const nextBtn = document.querySelector(`.${nextBtnClass}`);
        
        if (!slider) return;
        
        const scrollAmount = 300; // pixels to scroll
        
        function updateButtons() {
            if (prevBtn) {
                prevBtn.disabled = slider.scrollLeft <= 10;
            }
            if (nextBtn) {
                nextBtn.disabled = slider.scrollLeft >= slider.scrollWidth - slider.clientWidth - 10;
            }
        }
        
        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                slider.scrollBy({ left: -scrollAmount, behavior: 'smooth' });
            });
        }
        
        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                slider.scrollBy({ left: scrollAmount, behavior: 'smooth' });
            });
        }
        
        slider.addEventListener('scroll', updateButtons);
        updateButtons();
        
        // Update on resize
        window.addEventListener('resize', updateButtons);
    }
    
    // Initialize sliders
    setupSlider('imu-collections-slider', 'slider-prev', 'slider-next');
    setupSlider('recent-mints-slider', 'slider-prev-mints', 'slider-next-mints');

    // ============================================================
    // v4.0: STATS SECTION FUNCTIONS
    // ============================================================
    
    let statsData = [];
    let statsExpanded = false;

    /**
     * v255: Load platform top collections from imc-stats-handler (own DB, no external APIs)
     */
    async function loadStats() {
        const tbody = document.getElementById('stats-table-body');
        const expandBtn = document.getElementById('stats-expand-btn');
        if (!tbody) return;

        tbody.innerHTML = `
            <tr class="th-loading-row">
                <td colspan="5">
                    <div class="nft-loading-spinner"></div>
                    <span>Loading stats…</span>
                </td>
            </tr>`;

        try {
            const handlerUrl = xrplMarketplace.endpoints?.imcStatsHandler ||
                '/wp-content/themes/astra/xrpl-nft-marketplace/backend/imc-stats-handler.php';
            const url = `${handlerUrl}?action=top_collections&limit=10&period=all&nonce=${encodeURIComponent(xrplMarketplace.nonce)}`;
            const response = await fetchWithTimeout(url, {}, 20000);
            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const data = await response.json();
            if (!data.success || !data.collections) throw new Error(data.error || 'Failed');

            statsData = data.collections;
            renderStats(statsData);

            if (expandBtn && statsData.length > 5) {
                expandBtn.style.display = 'block';
                expandBtn.textContent = statsExpanded ? 'Show Top 5 ▲' : 'Show Top 10 ▼';
            } else if (expandBtn) {
                expandBtn.style.display = 'none';
            }
        } catch (error) {
            console.error('Stats load error:', error);
            tbody.innerHTML = `
                <tr class="th-loading-row">
                    <td colspan="5" style="color:var(--error);">
                        Failed to load stats.
                        <button onclick="loadStats()" class="retry-link">Retry</button>
                    </td>
                </tr>`;
        }
    }

    /**
     * v255: Render platform stats — mints / XRP vol / buyers
     */
    function renderStats(collections) {
        const tbody = document.getElementById('stats-table-body');
        if (!tbody) return;

        const html = collections.map((col, index) => {
            const isHidden = !statsExpanded && index >= 5;
            const rankClass = col.rank <= 3 ? `th-rank th-rank-${col.rank}` : 'th-rank';
            return `
                <tr class="${isHidden ? 'hidden' : ''}" style="cursor:pointer"
                    onclick="window.location.href='${imc_col_url(col)}'">
                    <td class="th-col-rank"><span class="${rankClass}">${col.rank}</span></td>
                    <td class="th-col-collection">
                        <div class="th-collection-cell">
                            <img src="${esc(col.image)}" alt="${esc(col.name)}"
                                 onerror="this.src='/wp-content/uploads/fallback-nft.svg'">
                            <div>
                                <span class="th-collection-name">${esc(col.name)}</span>
                                <div style="font-size:.74rem;color:var(--text-muted);margin-top:.1rem">${col.type_icon} ${esc(col.artist_display)}</div>
                            </div>
                        </div>
                    </td>
                    <td class="th-col-volume th-volume">${col.total_mints.toLocaleString()}</td>
                    <td class="th-col-floor th-floor">${esc(col.xrp_vol_fmt)}</td>
                    <td class="th-col-sales">${col.unique_buyers.toLocaleString()}</td>
                </tr>`;
        }).join('');

        tbody.innerHTML = html;
    }

    /**
     * Toggle stats expansion (5 vs 10)
     */
    function toggleStatsExpand() {
        statsExpanded = !statsExpanded;
        const tbody = document.getElementById('stats-table-body');
        const expandBtn = document.getElementById('stats-expand-btn');
        if (tbody) {
            tbody.querySelectorAll('tr').forEach((row, i) => {
                if (i >= 5) row.classList.toggle('hidden', !statsExpanded);
            });
        }
        if (expandBtn) expandBtn.textContent = statsExpanded ? 'Show Top 5 ▲' : 'Show Top 10 ▼';
    }

    // ============================================================
    // v4.0: RECENT MINTS (Platform mints from VPS)
    // ============================================================

    // ============================================================
    // v409: GENRE CAROUSELS — Music Access + Art Access
    // ============================================================

    /**
     * Load Music Access NFTs carousel (type=music only).
     * Music videos belong to the Video Access row; they used to appear in both.
     */
    async function loadMusicAccessCarousel() {
        const slider = document.getElementById('music-access-slider');
        const section = document.getElementById('music-carousel-section');
        if (!slider || !section) return;

        try {
            const listingsUrl = xrplMarketplace.endpoints?.listingsHandler ||
                '/wp-content/themes/astra/xrpl-nft-marketplace/backend/listings-handler.php';
            const cacheBuster = Date.now();

            // Music Access is audio-first: type=music only. Music videos live in the Video
            // Access row (musicvideo + film) and no longer appear in both rows.
            const musicRes = await fetchWithTimeout(
                `${listingsUrl}?action=get_marketplace&type=music&limit=30&sort=newest&include_sold_out=1&include_paused=1&_=${cacheBuster}`, {}, 15000
            );

            const musicData = await musicRes.json();

            const allListings = (musicData.success && musicData.data?.listings)
                ? musicData.data.listings
                : [];

            if (allListings.length === 0) {
                section.style.display = 'none';
                return;
            }

            let collections = groupListingsByCollection(allListings, { perListing: true, limit: 0 });

            // v23: Exclude scheduled future drops — they appear in the Coming Soon row only
            collections = collections.filter(col => !col.is_scheduled);

            // v409/v23: Sort active/open first, sold-out last.
            // Uses identical isSoldOut logic as renderMintCollectionCard:
            // sold-out = not open edition AND (all_sold_out flag OR zero available editions)
            collections.sort((a, b) => {
                const aOut = (!a.has_open_edition && (a.all_sold_out || a.available_editions <= 0)) ? 1 : 0;
                const bOut = (!b.has_open_edition && (b.all_sold_out || b.available_editions <= 0)) ? 1 : 0;
                if (aOut !== bOut) return aOut - bOut;
                return new Date(b.published_at || b.created_at) - new Date(a.published_at || a.created_at);
            });

            slider.innerHTML = collections.slice(0, 25).map(col => renderMintCollectionCard(col)).join('');
            section.style.display = '';
            setupSlider('music-access-slider', 'slider-prev-music', 'slider-next-music');
            // v478: Kick OE countdown ticker — covers OE cards that rendered after init's setupOpenMintCountdowns() call
            setupOpenMintCountdowns();
            console.log(`[v23] Loaded ${collections.length} Music Access collections`);

        } catch (error) {
            console.error('Music carousel error:', error);
            section.style.display = 'none';
        }
    }

    /**
     * v467: Load Album Access NFTs carousel
     * Filters listings-handler get_marketplace with type=album. The backend
     * whitelist at listings-handler.php:842 already accepts 'album' so no
     * backend change is needed. Mirrors loadArtAccessCarousel exactly —
     * same fetch shape, same grouping, same sort, same skeleton handling,
     * same failure-path hide. Only the selectors, filter type, and slider
     * control classes differ.
     */
    async function loadAlbumAccessCarousel() {
        const slider = document.getElementById('album-access-slider');
        const section = document.getElementById('album-carousel-section');
        if (!slider || !section) return;

        try {
            const listingsUrl = xrplMarketplace.endpoints?.listingsHandler ||
                '/wp-content/themes/astra/xrpl-nft-marketplace/backend/listings-handler.php';
            const cacheBuster = Date.now();

            const response = await fetchWithTimeout(
                `${listingsUrl}?action=get_marketplace&type=album&limit=30&sort=newest&include_sold_out=1&include_paused=1&_=${cacheBuster}`, {}, 15000
            );

            const data = await response.json();

            if (!data.success || !data.data?.listings || data.data.listings.length === 0) {
                section.style.display = 'none';
                return;
            }

            let collections = groupListingsByCollection(data.data.listings, { perListing: true, limit: 0 });

            // v23: Exclude scheduled future drops — they appear in the Coming Soon row only
            collections = collections.filter(col => !col.is_scheduled);

            // v409/v23: Sort active/open first, sold-out last (identical to other carousels)
            collections.sort((a, b) => {
                const aOut = (!a.has_open_edition && (a.all_sold_out || a.available_editions <= 0)) ? 1 : 0;
                const bOut = (!b.has_open_edition && (b.all_sold_out || b.available_editions <= 0)) ? 1 : 0;
                if (aOut !== bOut) return aOut - bOut;
                return new Date(b.published_at || b.created_at) - new Date(a.published_at || a.created_at);
            });

            slider.innerHTML = collections.slice(0, 25).map(col => renderMintCollectionCard(col)).join('');
            section.style.display = '';
            setupSlider('album-access-slider', 'slider-prev-album', 'slider-next-album');
            // v478: Kick OE countdown ticker — covers OE cards that rendered after init's setupOpenMintCountdowns() call
            setupOpenMintCountdowns();
            console.log(`[v467] Loaded ${collections.length} Album Access collections`);

        } catch (error) {
            console.error('Album carousel error:', error);
            section.style.display = 'none';
        }
    }

    /**
     * Load Art Access NFTs carousel
     */
    async function loadArtAccessCarousel() {
        const slider = document.getElementById('art-access-slider');
        const section = document.getElementById('art-carousel-section');
        if (!slider || !section) return;

        try {
            const listingsUrl = xrplMarketplace.endpoints?.listingsHandler ||
                '/wp-content/themes/astra/xrpl-nft-marketplace/backend/listings-handler.php';
            const cacheBuster = Date.now();

            const response = await fetchWithTimeout(
                `${listingsUrl}?action=get_marketplace&type=art&limit=30&sort=newest&include_sold_out=1&include_paused=1&_=${cacheBuster}`, {}, 15000
            );

            const data = await response.json();

            if (!data.success || !data.data?.listings || data.data.listings.length === 0) {
                section.style.display = 'none';
                return;
            }

            let collections = groupListingsByCollection(data.data.listings, { perListing: true, limit: 0 });

            // v23: Exclude scheduled future drops — they appear in the Coming Soon row only
            collections = collections.filter(col => !col.is_scheduled);

            // v409/v23: Sort active/open first, sold-out last.
            // Uses identical isSoldOut logic as renderMintCollectionCard:
            // sold-out = not open edition AND (all_sold_out flag OR zero available editions)
            collections.sort((a, b) => {
                const aOut = (!a.has_open_edition && (a.all_sold_out || a.available_editions <= 0)) ? 1 : 0;
                const bOut = (!b.has_open_edition && (b.all_sold_out || b.available_editions <= 0)) ? 1 : 0;
                if (aOut !== bOut) return aOut - bOut;
                return new Date(b.published_at || b.created_at) - new Date(a.published_at || a.created_at);
            });

            slider.innerHTML = collections.slice(0, 25).map(col => renderMintCollectionCard(col)).join('');
            section.style.display = '';
            setupSlider('art-access-slider', 'slider-prev-art', 'slider-next-art');
            // v478: Kick OE countdown ticker — covers OE cards that rendered after init's setupOpenMintCountdowns() call
            setupOpenMintCountdowns();
            console.log(`[v23] Loaded ${collections.length} Art Access collections`);

        } catch (error) {
            console.error('Art carousel error:', error);
            section.style.display = 'none';
        }
    }

    /**
     * B2: Comics & Books Access carousel (eBook + AudioBook combined).
     * Mirrors loadVideoAccessCarousel: two parallel type fetches merged client
     * side, each individually fault-tolerant. Hidden until books exist.
     */
    async function loadBooksAccessCarousel() {
        const slider = document.getElementById('books-access-slider');
        const section = document.getElementById('books-carousel-section');
        if (!slider || !section) return;

        try {
            const listingsUrl = xrplMarketplace.endpoints?.listingsHandler ||
                '/wp-content/themes/astra/xrpl-nft-marketplace/backend/listings-handler.php';
            const cacheBuster = Date.now();

            // Two parallel type fetches — each resolves to [] on any failure
            const fetchType = async (type) => {
                try {
                    const resp = await fetchWithTimeout(
                        `${listingsUrl}?action=get_marketplace&type=${type}&limit=30&sort=newest&include_sold_out=1&include_paused=1&_=${cacheBuster}`, {}, 15000
                    );
                    const json = await resp.json();
                    return (json.success && json.data?.listings) ? json.data.listings : [];
                } catch (e) {
                    console.error(`Books carousel ${type} fetch error:`, e);
                    return [];
                }
            };

            const [ebookListings, audiobookListings] = await Promise.all([
                fetchType('ebook'),
                fetchType('audiobook')
            ]);

            const allListings = ebookListings.concat(audiobookListings);

            if (allListings.length === 0) {
                section.style.display = 'none';
                return;
            }

            let collections = groupListingsByCollection(allListings, { perListing: true, limit: 0 });

            // Exclude scheduled future drops — they appear in the Coming Soon row only
            collections = collections.filter(col => !col.is_scheduled);

            if (collections.length === 0) {
                section.style.display = 'none';
                return;
            }

            // Sort active/open first, sold-out last — identical isSoldOut logic
            // as renderMintCollectionCard, matching the other Access carousels.
            collections.sort((a, b) => {
                const aOut = (!a.has_open_edition && (a.all_sold_out || a.available_editions <= 0)) ? 1 : 0;
                const bOut = (!b.has_open_edition && (b.all_sold_out || b.available_editions <= 0)) ? 1 : 0;
                if (aOut !== bOut) return aOut - bOut;
                return new Date(b.published_at || b.created_at) - new Date(a.published_at || a.created_at);
            });

            slider.innerHTML = collections.slice(0, 25).map(col => renderMintCollectionCard(col)).join('');
            section.style.display = '';
            setupSlider('books-access-slider', 'slider-prev-books', 'slider-next-books');
            // Kick OE countdown ticker — covers OE cards rendered after init's call
            setupOpenMintCountdowns();
            console.log(`[B2] Loaded ${collections.length} Comics & Books collections (${ebookListings.length} ebook + ${audiobookListings.length} audiobook)`);

        } catch (error) {
            console.error('Books carousel error:', error);
            section.style.display = 'none';
        }
    }
    /**
     * v502: Load Video Access carousel (Music Video + Film combined)
     * get_marketplace accepts a single `type` per request, so this fires two
     * parallel fetches (type=musicvideo, type=film) and merges the listings
     * client-side before grouping — backend untouched. Each fetch is
     * individually fault-tolerant: if one type fails, the other still renders.
     * Section hidden by default — revealed only when video listings exist.
     */
    async function loadVideoAccessCarousel() {
        const slider = document.getElementById('video-access-slider');
        const section = document.getElementById('video-carousel-section');
        if (!slider || !section) return;

        try {
            const listingsUrl = xrplMarketplace.endpoints?.listingsHandler ||
                '/wp-content/themes/astra/xrpl-nft-marketplace/backend/listings-handler.php';
            const cacheBuster = Date.now();

            // Two parallel type fetches — each resolves to [] on any failure
            const fetchType = async (type) => {
                try {
                    const resp = await fetchWithTimeout(
                        `${listingsUrl}?action=get_marketplace&type=${type}&limit=30&sort=newest&include_sold_out=1&include_paused=1&_=${cacheBuster}`, {}, 15000
                    );
                    const json = await resp.json();
                    return (json.success && json.data?.listings) ? json.data.listings : [];
                } catch (e) {
                    console.error(`Video carousel ${type} fetch error:`, e);
                    return [];
                }
            };

            const [mvListings, filmListings] = await Promise.all([
                fetchType('musicvideo'),
                fetchType('film')
            ]);

            const allListings = mvListings.concat(filmListings);

            if (allListings.length === 0) {
                section.style.display = 'none';
                return;
            }

            let collections = groupListingsByCollection(allListings, { perListing: true, limit: 0 });

            // Exclude scheduled future drops — they appear in the Coming Soon row only
            collections = collections.filter(col => !col.is_scheduled);

            if (collections.length === 0) {
                section.style.display = 'none';
                return;
            }

            // Sort active/open first, sold-out last — identical isSoldOut logic
            // as renderMintCollectionCard, matching the other Access carousels.
            collections.sort((a, b) => {
                const aOut = (!a.has_open_edition && (a.all_sold_out || a.available_editions <= 0)) ? 1 : 0;
                const bOut = (!b.has_open_edition && (b.all_sold_out || b.available_editions <= 0)) ? 1 : 0;
                if (aOut !== bOut) return aOut - bOut;
                return new Date(b.published_at || b.created_at) - new Date(a.published_at || a.created_at);
            });

            slider.innerHTML = collections.slice(0, 25).map(col => renderMintCollectionCard(col)).join('');
            section.style.display = '';
            setupSlider('video-access-slider', 'slider-prev-video', 'slider-next-video');
            // Kick OE countdown ticker — covers OE cards rendered after init's call
            setupOpenMintCountdowns();
            console.log(`[v502] Loaded ${collections.length} Video Access collections (${mvListings.length} musicvideo + ${filmListings.length} film listings)`);

        } catch (error) {
            console.error('Video carousel error:', error);
            section.style.display = 'none';
        }
    }

    /**
     * v23: Load Scheduled / Coming Soon carousel
     * Shows ONLY listings with launch_type='scheduled' AND launch_at > now().
     * Section hidden by default — revealed only when scheduled drops exist.
     */
    async function loadScheduledMintsCarousel() {
        const track = document.getElementById('featured-drops-track');
        const section = document.getElementById('featured-drops-section');
        const navBtns = document.querySelectorAll('.fd-nav');
        if (!track || !section) return;
        try {
            const listingsUrl = xrplMarketplace.endpoints?.listingsHandler ||
                '/wp-content/themes/astra/xrpl-nft-marketplace/backend/listings-handler.php';
            const cacheBuster = Date.now();
            // Fetch active listings — scheduled ones have status=active with launch_at in future
            const response = await fetchWithTimeout(
                `${listingsUrl}?action=get_marketplace&limit=50&sort=newest&_=${cacheBuster}`, {}, 15000
            );
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();
            if (!data.success || !data.data?.listings) {
                section.style.display = 'none';
                return;
            }
            let collections = groupListingsByCollection(data.data.listings, { perListing: true, limit: 0 });
            // Keep ONLY scheduled future drops for this block
            collections = collections.filter(col => col.is_scheduled);
            if (collections.length === 0) {
                section.style.display = 'none';
                return;
            }
            // Soonest launch first so the most imminent drop leads
            collections.sort((a, b) => new Date(a.launch_at) - new Date(b.launch_at));
            const drops = collections.slice(0, 10);
            track.innerHTML = drops.map(col => renderFeaturedDropCard(col)).join('');
            section.style.display = '';
            // Arrows only earn their place when there is more than one drop.
            // They flank the card, so this must toggle the BUTTONS, never the viewport.
            navBtns.forEach(b => { b.style.display = drops.length > 1 ? '' : 'none'; });
            setupFeaturedDropsNav();
            setupFeaturedDropExtras();
            setupScheduledCountdowns();
            console.log(`[B3] Loaded ${drops.length} featured scheduled drops`);
        } catch (error) {
            console.error('Featured drops error:', error);
            section.style.display = 'none';
        }
    }

    /**
     * B3: wide featured card for a scheduled drop. Cover, title, artist, live
     * countdown, edition type, price and a mint link. Uses only fields already
     * present on a grouped collection — no extra query.
     */
    function renderFeaturedDropCard(col) {
        const cover = col.listing_cover_ipfs || col.cover_ipfs || '';
        const coverUrl = cover
            ? (cover.startsWith('http') ? cover
                : `https://metadata.imcollectibles.io/img.php?url=${encodeURIComponent(cover.startsWith('ipfs://') ? cover : 'ipfs://' + cover)}`)
            : '/wp-content/uploads/fallback-nft.svg';
        const title = esc(col.nft_name || col.collection_name || 'Untitled');
        const artist = esc(col.artist_name || truncateAddr(col.artist_account || ''));
        const editions = col.has_open_edition
            ? 'Open Edition'
            : `${parseInt(col.total_editions) || 0} edition${(parseInt(col.total_editions) || 0) === 1 ? '' : 's'}`;
        const price = (col.min_price !== undefined && isFinite(col.min_price) && col.min_price > 0)
            ? `${col.min_price} XRP` : '';
        // FIX: use the shared slug helper so the card lands on the pretty
        // collection URL (/collections/{slug}/) like every other surface,
        // not a query-string form.
        const href = imc_col_url(col);
        const launch = col.launch_at || '';
        // B5: type chip - tells you what you are waiting for at a glance.
        const typeLabels = { music: 'Music', musicvideo: 'Music Video', album: 'Album',
                             art: 'Art', film: 'Film', audiobook: 'AudioBook', ebook: 'eBook' };
        const typeLabel = typeLabels[(col.nft_type || '').toLowerCase()] || '';
        // B5: books can carry a public back cover - both faces are watermarked.
        const backRaw = col.back_cover_ipfs || '';
        const backUrl = backRaw
            ? (backRaw.startsWith('http') ? backRaw
                : `https://metadata.imcollectibles.io/img.php?url=${encodeURIComponent(backRaw.startsWith('ipfs://') ? backRaw : 'ipfs://' + backRaw)}`)
            : '';
        // B5: audio types get the public preview clip; silent types never do.
        const audioTypes = ['music', 'album', 'audiobook', 'musicvideo'];
        const previewRaw = col.media_ipfs || '';
        const previewUrl = (previewRaw && audioTypes.includes((col.nft_type || '').toLowerCase()))
            ? `https://<your-pinata-gateway>/ipfs/${String(previewRaw).replace(/^ipfs:\/\//, '')}`
            : '';
        return `
            <article class="featured-drop-card">
                <div class="fd-cover">
                    <img class="fd-front" src="${coverUrl}" alt="${title}" loading="lazy"
                         onerror="this.onerror=null;this.src='/wp-content/uploads/fallback-nft.svg';">
                    ${backUrl ? `<img class="fd-back" src="${backUrl}" alt="${title} back cover" loading="lazy" style="display:none;">
                    <button type="button" class="fd-flip" aria-label="Flip cover">Back</button>` : ''}
                </div>
                <div class="fd-main">
                    <div class="fd-toprow">
                        <span class="fd-eyebrow">Dropping Soon</span>
                        <span class="fd-chip fd-chip-edition">${editions}</span>
                        ${typeLabel ? `<span class="fd-chip fd-chip-type">${typeLabel}</span>` : ''}
                    </div>
                    <h3 class="fd-title">${title}</h3>
                    <p class="fd-artist">by ${artist}</p>
                    ${previewUrl ? `
                    <div class="fd-player" data-src="${previewUrl}">
                        <button type="button" class="fd-play" aria-label="Play preview">&#9654;</button>
                        <div class="fd-track"><div class="fd-track-fill"></div></div>
                        <span class="fd-time">0:00</span>
                        <span class="fd-preview-tag">Preview</span>
                    </div>` : ''}
                </div>
                <div class="fd-side">
                    <div class="fd-countdown" data-launch="${esc(launch)}">
                        <span class="fd-countdown-value">Calculating…</span>
                    </div>
                    <a class="fd-cta" href="${href}">View drop</a>
                    ${price ? `<span class="fd-price">${price}</span>` : ''}
                </div>
            </article>`;
    }

    /**
     * B5: cover flip + preview player for featured drop cards.
     * Delegated, so it survives re-renders. One audio element for the whole
     * block: starting one preview stops any other.
     */
    let _fdAudio = null;
    let _fdPlayingEl = null;
    function fdFmtTime(s) {
        if (!isFinite(s) || s < 0) s = 0;
        const m = Math.floor(s / 60);
        const r = Math.floor(s % 60);
        return m + ':' + (r < 10 ? '0' : '') + r;
    }
    function fdResetPlayer(el) {
        if (!el) return;
        const btn = el.querySelector('.fd-play');
        const fill = el.querySelector('.fd-track-fill');
        const time = el.querySelector('.fd-time');
        if (btn)  btn.innerHTML = '&#9654;';
        if (fill) fill.style.width = '0%';
        if (time) time.textContent = '0:00';
    }
    function setupFeaturedDropExtras() {
        const section = document.getElementById('featured-drops-section');
        if (!section || section.dataset.fdBound === '1') return;
        section.dataset.fdBound = '1';   // delegate once

        section.addEventListener('click', function (e) {
            // ---- cover flip ----
            const flip = e.target.closest && e.target.closest('.fd-flip');
            if (flip) {
                e.preventDefault();
                const wrap = flip.closest('.fd-cover');
                if (!wrap) return;
                const front = wrap.querySelector('.fd-front');
                const back  = wrap.querySelector('.fd-back');
                if (!front || !back) return;
                const showBack = (back.style.display === 'none');
                back.style.display  = showBack ? '' : 'none';
                front.style.display = showBack ? 'none' : '';
                flip.textContent = showBack ? 'Front' : 'Back';
                return;
            }
            // ---- preview player ----
            const play = e.target.closest && e.target.closest('.fd-play');
            if (!play) return;
            e.preventDefault();
            const el = play.closest('.fd-player');
            const src = el && el.dataset.src;
            if (!src) return;
            if (!_fdAudio) {
                _fdAudio = new Audio();
                _fdAudio.preload = 'none';
                _fdAudio.addEventListener('timeupdate', function () {
                    if (!_fdPlayingEl) return;
                    const fill = _fdPlayingEl.querySelector('.fd-track-fill');
                    const time = _fdPlayingEl.querySelector('.fd-time');
                    const dur = _fdAudio.duration;
                    if (fill && isFinite(dur) && dur > 0) {
                        fill.style.width = Math.min(100, (_fdAudio.currentTime / dur) * 100) + '%';
                    }
                    if (time) time.textContent = fdFmtTime(_fdAudio.currentTime);
                });
                _fdAudio.addEventListener('ended', function () { fdResetPlayer(_fdPlayingEl); _fdPlayingEl = null; });
                _fdAudio.addEventListener('error', function () { fdResetPlayer(_fdPlayingEl); _fdPlayingEl = null; });
            }
            const isThis = (_fdPlayingEl === el) && !_fdAudio.paused;
            if (isThis) {
                _fdAudio.pause();
                play.innerHTML = '&#9654;';
                return;
            }
            // starting a preview stops whichever was playing
            if (_fdPlayingEl && _fdPlayingEl !== el) fdResetPlayer(_fdPlayingEl);
            if (_fdAudio.src !== src) { _fdAudio.src = src; }
            _fdPlayingEl = el;
            _fdAudio.play().then(function () {
                play.innerHTML = '&#10073;&#10073;';
            }).catch(function () {
                fdResetPlayer(el); _fdPlayingEl = null;
            });
        });
    }

    /**
     * B3: prev/next stepping one featured card at a time.
     */
    function setupFeaturedDropsNav() {
        const track = document.getElementById('featured-drops-track');
        if (!track) return;
        const step = () => track.clientWidth || 1;
        document.querySelectorAll('.slider-prev-drops').forEach(btn => {
            btn.onclick = () => track.scrollBy({ left: -step(), behavior: 'smooth' });
        });
        document.querySelectorAll('.slider-next-drops').forEach(btn => {
            btn.onclick = () => track.scrollBy({ left: step(), behavior: 'smooth' });
        });
    }

    /**
     * B3: countdown ticker for scheduled drops. Mirrors setupOpenMintCountdowns
     * (60s cadence, idempotent) and reuses its UTC normalisation.
     */
    let _fdTimer = null;
    function setupScheduledCountdowns() {
        function updateAll() {
            const els = document.querySelectorAll('.fd-countdown[data-launch]');
            if (els.length === 0) return;
            const now = Date.now();
            els.forEach(el => {
                const raw = el.dataset.launch;
                if (!raw) return;
                const normalised = raw.includes('Z') ? raw : raw.replace(' ', 'T') + 'Z';
                const ms = new Date(normalised).getTime() - now;
                const out = el.querySelector('.fd-countdown-value');
                if (!out) return;
                if (isNaN(ms)) { out.textContent = ''; return; }
                if (ms <= 0) { out.textContent = 'Live now'; return; }
                const totalMins = Math.floor(ms / 60000);
                const days = Math.floor(totalMins / 1440);
                const hours = Math.floor((totalMins % 1440) / 60);
                const mins = totalMins % 60;
                out.textContent = days > 0 ? `Mints in ${days}d ${hours}h`
                    : hours > 0 ? `Mints in ${hours}h ${mins}m`
                    : `Mints in ${mins}m`;
            });
        }
        updateAll();
        if (_fdTimer) return;   // idempotent — one ticker only
        _fdTimer = setInterval(updateAll, 60000);
    }


    /**
     * v475: Open Mints carousel
     * Mirrors loadScheduledMintsCarousel — filters to active Open Editions with a
     * future open_edition_ends_at; sorts by soonest-closing first.
     * Section is hidden when there are no eligible listings.
     */
    /**
     * v568-uxr: Fixed Collections carousel — fixed-size (non-OE) listings only.
     * Per-listing cards, newest first, sold-out pushed to the back. Mirrors the
     * Open Mints loader structure; sits between Open Mints and Music Access.
     */
    async function loadFixedCollectionsCarousel() {
        const slider = document.getElementById('fixed-collections-slider');
        const section = document.getElementById('fixed-collections-section');
        if (!slider || !section) return;

        try {
            const listingsUrl = xrplMarketplace.endpoints?.listingsHandler ||
                '/wp-content/themes/astra/xrpl-nft-marketplace/backend/listings-handler.php';
            const cacheBuster = Date.now();

            const response = await fetchWithTimeout(
                `${listingsUrl}?action=get_marketplace&limit=50&sort=newest&include_sold_out=1&include_paused=1&_=${cacheBuster}`, {}, 15000
            );

            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();

            if (!data.success || !data.data?.listings) {
                section.style.display = 'none';
                return;
            }

            let listings = groupListingsByCollection(data.data.listings, { perListing: true, limit: 0 });

            // Fixed-size only: exclude open editions and scheduled future drops
            listings = listings.filter(col => !col.has_open_edition && !col.is_scheduled);

            if (listings.length === 0) {
                section.style.display = 'none';
                return;
            }

            // Newest first, sold-out pushed to the back (same isSoldOut logic as the card)
            listings.sort((a, b) => {
                const aOut = (a.all_sold_out || a.available_editions <= 0) ? 1 : 0;
                const bOut = (b.all_sold_out || b.available_editions <= 0) ? 1 : 0;
                if (aOut !== bOut) return aOut - bOut;
                return new Date(b.published_at || b.created_at) - new Date(a.published_at || a.created_at);
            });

            slider.innerHTML = listings.slice(0, 25).map(col => renderMintCollectionCard(col)).join('');
            section.style.display = '';
            setupSlider('fixed-collections-slider', 'slider-prev-fixed', 'slider-next-fixed');
        } catch (error) {
            console.error('Fixed collections carousel error:', error);
            section.style.display = 'none';
        }
    }

    async function loadOpenMintsCarousel() {
        const slider = document.getElementById('open-mints-slider');
        const section = document.getElementById('open-mints-carousel-section');
        if (!slider || !section) return;

        try {
            const listingsUrl = xrplMarketplace.endpoints?.listingsHandler ||
                '/wp-content/themes/astra/xrpl-nft-marketplace/backend/listings-handler.php';
            const cacheBuster = Date.now();

            const response = await fetchWithTimeout(
                `${listingsUrl}?action=get_marketplace&limit=50&sort=newest&_=${cacheBuster}`, {}, 15000
            );

            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();

            if (!data.success || !data.data?.listings) {
                section.style.display = 'none';
                return;
            }

            let collections = groupListingsByCollection(data.data.listings, { perListing: true, limit: 0 });

            // Keep only active open editions with a known, future end timestamp
            const now = new Date();
            collections = collections.filter(col => {
                if (!col.has_open_edition || !col.open_edition_ends_at) return false;
                const raw = col.open_edition_ends_at;
                const endsUtc = raw.includes('Z') ? raw : raw.replace(' ', 'T') + 'Z';
                return new Date(endsUtc) > now;
            });

            if (collections.length === 0) {
                section.style.display = 'none';
                return;
            }

            // Sort by soonest-closing first so users see the most urgent deadlines
            collections.sort((a, b) => new Date(a.open_edition_ends_at) - new Date(b.open_edition_ends_at));

            slider.innerHTML = collections.slice(0, 25).map(col => renderMintCollectionCard(col)).join('');
            section.style.display = '';
            setupSlider('open-mints-slider', 'slider-prev-open', 'slider-next-open');

            // Kick countdown tickers (idempotent — won't double-up if already running)
            setupOpenMintCountdowns();

            console.log(`[v475] Loaded ${collections.length} open mints`);

        } catch (error) {
            console.error('Open mints carousel error:', error);
            section.style.display = 'none';
        }
    }

    /**
     * v475: Open Mint countdown ticker
     * Single global setInterval scans all .mint-open-badge[data-ends] elements
     * every 60s and updates their inner .mint-open-badge-countdown text.
     * 60s tick matches the days/hours display granularity — sub-minute precision
     * isn't visually useful here. Idempotent: multiple calls are safe.
     * The interval is started on first call regardless of badge count so it
     * picks up cards that render asynchronously after the initial call.
     */
    let _openMintCountdownInterval = null;

    function setupOpenMintCountdowns() {
        function updateAll() {
            const badges = document.querySelectorAll('.mint-open-badge[data-ends]');
            if (badges.length === 0) return; // nothing to update this tick — keep interval alive in case cards render later

            const now = Date.now();
            badges.forEach(badge => {
                const endsUtc = badge.dataset.ends;
                if (!endsUtc) return;
                const normalised = endsUtc.includes('Z') ? endsUtc : endsUtc.replace(' ', 'T') + 'Z';
                const endsMs = new Date(normalised).getTime();
                const countdownEl = badge.querySelector('.mint-open-badge-countdown');
                if (!countdownEl) return;

                const diff = endsMs - now;
                if (diff <= 0) {
                    countdownEl.textContent = 'Ending soon';
                    return;
                }

                const totalMins = Math.floor(diff / 60000);
                const days = Math.floor(totalMins / 1440);
                const hours = Math.floor((totalMins % 1440) / 60);
                const mins = totalMins % 60;

                let text;
                if (days > 0) {
                    text = `Mint Closes in ${days}D ${hours}H`;
                } else if (hours > 0) {
                    text = `Mint Closes in ${hours}H ${mins}M`;
                } else {
                    text = `Mint Closes in ${mins}M`;
                }
                countdownEl.textContent = text;
            });
        }

        updateAll();
        if (!_openMintCountdownInterval) {
            _openMintCountdownInterval = setInterval(updateAll, 60000);
        }
    }

/**
     * Load recent mints from our platform - Shows COLLECTION CARDS
     * Grouped by collection, linking to collection page with mint status
     */
    // v137: Recent Mints type filter
    let mintsFilterType = 'all';
    
    async function loadRecentMintsV4() {
        const slider = document.getElementById('recent-mints-slider');
        if (!slider) return;
        
        // Show skeleton loading
        slider.innerHTML = Array.from({length: 5}, () =>
            '<div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:60%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:40%;height:12px;margin-top:0.5rem"></div></div></div>'
        ).join('');
        
        try {
            // Load active listings from listings-handler
            // v63 FIX: Include sold_out listings to show "SOLD OUT" badge
            const listingsUrl = xrplMarketplace.endpoints?.listingsHandler || 
                xrplMarketplace.endpoints?.listings ||
                '/wp-content/themes/astra/xrpl-nft-marketplace/backend/listings-handler.php';
            
            // v67: Add cache-busting timestamp to ensure fresh data on paused listings
            // v140: All filter options are exact nft_type values
            const cacheBuster = Date.now();
            let fetchUrl = `${listingsUrl}?action=get_marketplace&limit=30&sort=newest&include_sold_out=1&include_paused=1&_=${cacheBuster}`;
            if (mintsFilterType !== 'all') {
                fetchUrl += `&type=${encodeURIComponent(mintsFilterType)}`;
            }
            
            const response = await fetchWithTimeout(fetchUrl, {}, 15000);
            
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            
            const data = await response.json();
            
            if (!data.success || !data.data?.listings || data.data.listings.length === 0) {
                // No listings - try legacy fallback
                console.log('No mint-on-demand listings, trying legacy...');
                await loadRecentMintsLegacy();
                return;
            }
            
            // v66: Debug logging for paused listings
            const pausedListings = data.data.listings.filter(l => l.status === 'paused');
            console.log(`Received ${data.data.listings.length} listings (${pausedListings.length} paused)`);
            
            // Group listings by collection
            let collections = groupListingsByCollection(data.data.listings, { perListing: true, limit: 0 });

            // v23: Exclude scheduled future drops — they appear in the Coming Soon row only
            collections = collections.filter(col => !col.is_scheduled);

            // v23: Sort active/open editions first, sold-out last.
            // Uses identical isSoldOut logic as renderMintCollectionCard to ensure
            // consistency: available_editions <= 0 also counts as sold-out for sorting.
            collections.sort((a, b) => {
                const aOut = (!a.has_open_edition && (a.all_sold_out || a.available_editions <= 0)) ? 1 : 0;
                const bOut = (!b.has_open_edition && (b.all_sold_out || b.available_editions <= 0)) ? 1 : 0;
                if (aOut !== bOut) return aOut - bOut;
                return new Date(b.published_at || b.created_at) - new Date(a.published_at || a.created_at);
            });
            
            if (collections.length === 0) {
                slider.innerHTML = `
                    <div class="empty-mints-placeholder">
                        <span class="empty-icon">🚀</span>
                        <p>No collections yet - be the first to create NFTs on IMCollectibles!</p>
                        <a href="/mint/" class="btn btn-primary">Start Minting</a>
                    </div>
                `;
                return;
            }
            
            // Render collection cards
            slider.innerHTML = collections.slice(0, 25).map(col => renderMintCollectionCard(col)).join('');
            
            // v66: Debug logging for paused collections
            const pausedCollections = collections.filter(c => c.all_paused || c.has_paused);
            console.log(`Loaded ${collections.length} mint collections (${pausedCollections.length} with paused status)`);
            
            // Re-setup slider after loading content
            setupSlider('recent-mints-slider', 'slider-prev-mints', 'slider-next-mints');
            // v478: Kick OE countdown ticker — covers OE cards that rendered after init's setupOpenMintCountdowns() call
            setupOpenMintCountdowns();
        } catch (error) {
            console.error('Recent mints error:', error);
            // Fallback to legacy behavior
            await loadRecentMintsLegacy();
        }
    }

    /**
     * Group listings by collection (artist + collection_name)
     */
    function groupListingsByCollection(listings, opts = {}) {
        // v568-uxr: per-listing mode keys each listing on its own row so every
        // listing renders an individual card; default (collection) mode is unchanged.
        const perListing = opts.perListing === true;
        const sliceLimit = (opts.limit !== undefined) ? opts.limit : 12;
        const collectionMap = new Map();
        
        listings.forEach((listing, _idx) => {
            // Create unique key for collection (per-listing: unique per row)
            const collectionKey = perListing
                ? `L${_idx}`
                : (listing.collection_name 
                    ? `${listing.artist_account}_${listing.collection_name}`
                    : `${listing.artist_account}_singles`);
            
            if (!collectionMap.has(collectionKey)) {
                collectionMap.set(collectionKey, {
                    artist_account: listing.artist_account,
                    artist_name: listing.artist_name || truncateAddr(listing.artist_account),
                    collection_name: listing.collection_name || 'Singles',
                    collection_taxon: listing.collection_taxon || 0,
                    cover_ipfs: listing.cover_ipfs,
                    // B5: featured drop cards use the public preview clip and the
                    // optional back cover. Per-listing only; grouped mode takes the first.
                    media_ipfs: listing.media_ipfs || null,
                    back_cover_ipfs: listing.back_cover_ipfs || null,
                    // v568-uxr2: per-listing display fields (null in grouped mode → renderer falls back)
                    nft_name: perListing ? listing.nft_name : null,
                    listing_cover_ipfs: perListing ? listing.listing_cover_ipfs : null,
                    nft_type: listing.nft_type,
                    // v630 Step6: AI flags (per-listing in perListing mode; grouped mode upgraded below)
                    ai_generated: parseInt(listing.ai_generated) || 0,
                    ai_mode: listing.ai_mode || null,
                    ai_platform: listing.ai_platform || null,
                    listings: [],
                    total_editions: 0,
                    minted_count: 0,
                    available_editions: 0,
                    min_price: Infinity,
                    max_price: 0,
                    // v85: Track dynamic pricing
                    min_price_usd: Infinity,
                    max_price_usd: 0,
                    has_dynamic: false,
                    // v1021 FIX: get_marketplace returns published_at, NOT created_at.
                      // Every sort below read created_at -> undefined -> new Date(undefined)
                      // is Invalid Date -> the comparator returned NaN on every pair, and a
                      // NaN comparator does not sort: Array.sort leaves the arbitrary order
                      // V8 happens to produce. That is why the carousel looked FROZEN.
                      created_at: listing.published_at || listing.created_at,
                    // v63 FIX: Track if collection has any sold_out listings
                    // v65 FIX: Also track paused status
                    has_sold_out: false,
                    all_sold_out: true,
                    has_paused: false,
                    all_paused: true,
                    // v233: Track upcoming scheduled launch
                    is_scheduled: false,
                    launch_at: null,
                    launch_timezone: null,
                    // v393: Track open edition status
                    has_open_edition: false,
                    // v475: Track earliest active OE end time (for countdown badge)
                    open_edition_ends_at: null
                });
            }
            
            const col = collectionMap.get(collectionKey);
            col.listings.push(listing);
            // v630 Step6: if any listing in a grouped collection is AI, surface the flag
            if ((parseInt(listing.ai_generated) || 0) === 1 && !col.ai_generated) {
                col.ai_generated = 1;
                col.ai_mode = listing.ai_mode || col.ai_mode;
                col.ai_platform = listing.ai_platform || col.ai_platform;
            }
            col.total_editions += parseInt(listing.total_editions) || 0;
            col.minted_count += parseInt(listing.minted_count) || 0;
            
            // Calculate available
            const available = parseInt(listing.available_editions) || 
                             (parseInt(listing.total_editions) || 0) - (parseInt(listing.minted_count) || 0);
            col.available_editions += Math.max(0, available);
            
            // v393: Detect open editions from API data (active only — closed OEs display as sold out)
            if ((listing.edition_type === 'open' || listing.is_open_edition) && !listing.open_edition_closed_at) col.has_open_edition = true;

            // v475: Track earliest active OE end time across listings in this collection.
            // Mirrors the launch_at tracker pattern below — captures the soonest-closing
            // OE so the card's countdown reflects the next deadline the buyer faces.
            if ((listing.edition_type === 'open' || listing.is_open_edition)
                && !listing.open_edition_closed_at
                && listing.open_edition_ends_at) {
                if (!col.open_edition_ends_at
                    || new Date(listing.open_edition_ends_at) < new Date(col.open_edition_ends_at)) {
                    col.open_edition_ends_at = listing.open_edition_ends_at;
                }
            }
            
            // v65 FIX: Track sold_out status from listing status field
            // v65 FIX: Also track paused status
            if (listing.status === 'sold_out') {
                col.has_sold_out = true;
            } else if (listing.status === 'paused') {
                col.has_paused = true;
                col.all_sold_out = false;
            } else if (listing.status === 'active') {
                col.all_sold_out = false;
                col.all_paused = false;
            }
            
            // v233: Track upcoming scheduled launch for the collection
            if (listing.launch_type === 'scheduled' && listing.launch_at) {
                const launchTime = new Date(listing.launch_at.includes('Z') ? listing.launch_at : listing.launch_at + 'Z');
                if (launchTime > new Date()) {
                    col.is_scheduled = true;
                    col.launch_at = listing.launch_at;
                    col.launch_timezone = listing.launch_timezone || 'UTC';
                }
            }
            
            // Track price range (only for active listings)
            // v85: Track both XRP and USD prices
            if (listing.status === 'active') {
                const pricingMode = listing.pricing_mode || 'static';
                const priceUsd = parseFloat(listing.price_usd) || 0;
                const priceXrp = parseFloat(listing.price_xrp) || 0;
                
                if (pricingMode === 'dynamic' && priceUsd > 0) {
                    col.has_dynamic = true;
                    col.min_price_usd = Math.min(col.min_price_usd, priceUsd);
                    col.max_price_usd = Math.max(col.max_price_usd, priceUsd);
                } else if (priceXrp > 0) {
                    col.min_price = Math.min(col.min_price, priceXrp);
                    col.max_price = Math.max(col.max_price, priceXrp);
                }
            }
            
            // Use most recent cover if this listing has one
            if (listing.cover_ipfs) {
                col.cover_ipfs = listing.cover_ipfs;
            }
        });
        
        // Convert to array and sort by most recent
        // v568-uxr: slice is conditional — per-listing callers pass limit:0 and
        // apply their own per-carousel sort + 25-cap downstream.
        const _arr = Array.from(collectionMap.values())
            .sort((a, b) => new Date(b.published_at || b.created_at) - new Date(a.published_at || a.created_at));
        return sliceLimit > 0 ? _arr.slice(0, sliceLimit) : _arr;
    }

    /**
     * Render a collection card with mint status
     */
    function renderMintCollectionCard(collection) {
        // v568-uxr2: per-listing cards show the listing's own name + cover; grouped cards
        // fall back to collection identity (nft_name/listing_cover_ipfs are null when grouped).
        const displayTitle = collection.nft_name || collection.collection_name;
        const displayCoverCid = collection.listing_cover_ipfs || collection.cover_ipfs;
        // Build cover URL
        let coverUrl = '/wp-content/uploads/fallback-nft.svg';
        if (displayCoverCid) {
            // v704: normalize bare IPFS CIDs to ipfs:// form before the proxy. img.php only
            // resolves url=ipfs://CID (or a full http URL); a BARE CID (e.g. "Qm...") is used
            // as-is, matches no gateway, 404s, and the <img> onerror hides the whole card.
            // Some album covers were stored bare (handleAlbumMint stripped the prefix) — this
            // makes them resolve. Guarded so already-prefixed / http(s) values are untouched.
            const normCoverCid = (!displayCoverCid.startsWith('ipfs://') && !displayCoverCid.startsWith('http'))
                ? 'ipfs://' + displayCoverCid
                : displayCoverCid;
            // v574: route card covers through the VPS image proxy for a 400px
            // thumbnail + disk cache + immutable (1y) headers + multi-gateway
            // fallback. encodeURIComponent preserves both ipfs:// and https://
            // cover forms. Full-res detail/popup views are unchanged.
            coverUrl = `https://metadata.imcollectibles.io/img.php?url=${encodeURIComponent(normCoverCid)}&thumb=1`;
        }
        
        // v63 FIX: Check sold_out status from listing status OR available_editions
        // v65 FIX: Also check paused status
        // v233: Check scheduled future launch
        // v393: Open editions are never sold out while active
        const isSoldOut = !collection.has_open_edition && (collection.all_sold_out || collection.available_editions <= 0);
        const isPaused = collection.all_paused && !isSoldOut;
        const isScheduled = collection.is_scheduled && !isSoldOut && !isPaused;
        
        // v233: Build scheduled launch time string
        let scheduledTimeStr = '';
        if (isScheduled && collection.launch_at) {
            const launchDate = new Date(collection.launch_at.includes('Z') ? collection.launch_at : collection.launch_at + 'Z');
            const now = new Date();
            const diffMs = launchDate - now;
            const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));
            const diffHours = Math.floor((diffMs % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const diffMins = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));
            if (diffDays > 0) {
                scheduledTimeStr = diffDays + 'D ' + diffHours + 'H';
            } else if (diffHours > 0) {
                scheduledTimeStr = diffHours + 'H ' + diffMins + 'M';
            } else {
                scheduledTimeStr = diffMins + 'M';
            }
        }
        // v393: OE progress is undefined; fixed editions use normal percentage
        const mintProgress = (!collection.has_open_edition && collection.total_editions > 0)
            ? Math.round((collection.minted_count / collection.total_editions) * 100)
            : 0;
        
        // Price display - v85: Support dynamic pricing (USD)
        let priceDisplay = '';
        if (collection.has_dynamic && collection.min_price_usd !== Infinity && collection.min_price_usd > 0) {
            // Dynamic pricing - show USD
            if (collection.min_price_usd === collection.max_price_usd) {
                priceDisplay = `$${collection.min_price_usd.toFixed(2)} USD`;
            } else {
                priceDisplay = `$${collection.min_price_usd.toFixed(2)}-$${collection.max_price_usd.toFixed(2)} USD`;
            }
        } else if (collection.min_price !== Infinity && collection.min_price > 0) {
            // Static pricing - show XRP
            if (collection.min_price === collection.max_price) {
                priceDisplay = `${formatXrpCompact(collection.min_price)} XRP`;
            } else {
                priceDisplay = `${formatXrpCompact(collection.min_price)}-${formatXrpCompact(collection.max_price)} XRP`;
            }
        }
        
        // Build collection URL - goes to collection page with issuer/taxon
        const collectionUrl = imc_col_url({ slug: collection.slug || '', name: collection.collection_name, taxon: collection.collection_taxon });
        
        // Type icon — v468: album takes 💿 disc to match Album Access carousel h2
        const typeIcon = collection.nft_type === 'musicvideo' ? '🎬' : 
                        collection.nft_type === 'art' ? '🖼️' : 
                        collection.nft_type === 'film' ? '🎥' : 
                        collection.nft_type === 'album' ? '💿' : 
                        collection.nft_type === 'audiobook' ? '🎧' : 
                        collection.nft_type === 'ebook' ? '📖' : '🎵';
        
        // v65: Determine card class and badge
        let cardClass = 'collection-card mint-collection-card';
        let badgeHtml = '';
        if (isSoldOut) {
            cardClass += ' sold-out';
            badgeHtml = '<span class="mint-sold-out-badge">SOLD OUT</span>';
        } else if (isPaused) {
            cardClass += ' paused';
            badgeHtml = '<span class="mint-paused-badge">PAUSED</span>';
        } else if (isScheduled) {
            cardClass += ' scheduled';
            badgeHtml = `<span class="mint-scheduled-badge">🚀 Mint Starts in ${scheduledTimeStr}</span>`;
        } else if (collection.has_open_edition) {
            // v475: Wide-strip OPEN MINT badge with live countdown — sits beside the
            // type-icon at top-left (does NOT overlap it). The countdown text is
            // updated by setupOpenMintCountdowns() every 60s after render.
            if (collection.open_edition_ends_at) {
                const endsAttr = String(collection.open_edition_ends_at).replace(/"/g, '');
                badgeHtml = `<span class="mint-open-badge" data-ends="${endsAttr}"><span class="mint-open-badge-countdown">Mint Closes in --</span></span>`;
            } else {
                // Defensive fallback — listings-handler enforces ends_at on OE creation,
                // so this branch should never fire for production data.
                badgeHtml = '<span class="mint-open-badge">OPEN MINT</span>';
            }
        }
        
        // v393: OE counter shows "X minted (???)" instead of "X/0"
        const mintedStr = collection.has_open_edition
            ? `${collection.minted_count} minted`
            : `${collection.minted_count}/${collection.total_editions}`;
        
        // v393: Don't show "999999 left" for open editions
        const showAvailable = !isSoldOut && !isPaused && !collection.has_open_edition;
        
        return `
            <div class="${cardClass}" 
                 onclick="window.location.href='${collectionUrl}'">
                <div class="nft-image-wrapper">
                    <img src="${esc(coverUrl)}" 
                         alt="${esc(displayTitle)}" 
                         loading="lazy"
                         onerror="var _c=this.closest('.collection-card,.th-featured-card,.mint-collection-card');if(_c){_c.classList.add('imc-card-hidden');_c.style.setProperty('display','none','important');}">
                    ${badgeHtml}
                    ${collection.ai_generated ? `<span class="imc-ai-badge" data-mode="${esc(collection.ai_mode || '')}" data-platform="${esc(collection.ai_platform || '')}" role="button" tabindex="0" aria-label="AI generated — tap for details">🤖 AI</span>` : ''}
                </div>
                <div class="mint-card-info">
                    <h3>${esc(displayTitle)}</h3>
                    <p class="mint-card-artist">${esc(collection.artist_name)}</p>
                    <div class="mint-progress-bar-container">
                        ${(!collection.has_open_edition) ? `
                        <div class="mint-progress-bar-bg">
                            <div class="mint-progress-bar-fill" style="width: ${mintProgress}%"></div>
                        </div>` : ''}
                        <span class="mint-progress-text">${mintedStr}</span>
                    </div>
                    <div class="mint-card-footer">
                        ${priceDisplay ? `<span class="mint-card-price">${priceDisplay}</span>` : ''}
                        ${showAvailable ? `<span class="mint-available-badge">${collection.available_editions} left</span>` : ''}
                        ${collection.has_open_edition && !isSoldOut ? `<span class="mint-available-badge">Open Edition</span>` : ''}
                        ${isPaused ? `<span class="mint-paused-text">Minting paused</span>` : ''}
                    </div>
                </div>
            </div>
        `;
    }

    // v631 Step6: AI badge hover tooltip — shared by all trading-hub carousel cards.
    // Cards navigate on `pointerup` (+ inline onclick), so we intercept those on the
    // badge in the capture phase to prevent navigation, and reveal info on hover.
    (function imcAiBadgeInit(){
        if (window.__imcAiBadgeInit) return;
        window.__imcAiBadgeInit = true;
        if (!document.getElementById('imc-ai-badge-css')) {
            const st = document.createElement('style');
            st.id = 'imc-ai-badge-css';
            st.textContent =
                '.imc-ai-badge{position:absolute;top:8px;right:8px;z-index:4;display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:800;line-height:1;cursor:help;background:linear-gradient(180deg,#7b5cff,#4a2fd0);color:#fff;box-shadow:0 1px 4px rgba(0,0,0,.4);}' +
                '.imc-ai-badge:hover{filter:brightness(1.08);}' +
                '.imc-ai-tip{position:absolute;z-index:99999;max-width:240px;background:#15151f;border:1px solid #2b2b3d;border-radius:12px;padding:12px 14px;color:#fff;box-shadow:0 10px 30px rgba(0,0,0,.5);font-size:.85rem;pointer-events:none;}' +
                '.imc-ai-tip h4{margin:0 0 4px;font-size:.92rem;color:#b9a6ff;}' +
                '.imc-ai-tip p{margin:0;color:#cfd2dc;line-height:1.4;}' +
                '.imc-ai-tip .tool{margin-top:6px;font-size:.78rem;color:#9aa0aa;}' +
                '.imc-ai-tip .tool b{color:#d6ba66;font-weight:700;}';
            document.head.appendChild(st);
        }
        function hide(){ const t=document.getElementById('imc-ai-tip'); if(t) t.remove(); }
        function show(badge){
            if (document.getElementById('imc-ai-tip')) return;
            const full = (badge.dataset.mode === 'full');
            const platform = badge.dataset.platform || '';
            const tip = document.createElement('div');
            tip.className='imc-ai-tip'; tip.id='imc-ai-tip';
            const h=document.createElement('h4'); h.textContent = full ? '100% AI-Generated' : 'AI-Assisted';
            const pp=document.createElement('p'); pp.textContent = full
                ? 'This artwork was generated entirely using AI.'
                : 'This artwork was created using AI together with human elements.';
            tip.appendChild(h); tip.appendChild(pp);
            if (platform){ const tl=document.createElement('div'); tl.className='tool'; tl.textContent='Tool: '; const bb=document.createElement('b'); bb.textContent=platform; tl.appendChild(bb); tip.appendChild(tl); }
            document.body.appendChild(tip);
            const r=badge.getBoundingClientRect(); const tw=tip.offsetWidth||240;
            tip.style.top=(window.scrollY + r.bottom + 8)+'px';
            tip.style.left=(window.scrollX + Math.max(8, r.right - tw))+'px';
        }
        // Desktop hover
        document.addEventListener('mouseover', function(e){ const b=e.target.closest && e.target.closest('.imc-ai-badge'); if (b) show(b); });
        document.addEventListener('mouseout',  function(e){ const b=e.target.closest && e.target.closest('.imc-ai-badge'); if (b) hide(); });
        // Block card navigation on the badge; toggle tip on touch
        function block(e){
            const b=e.target.closest && e.target.closest('.imc-ai-badge');
            if (!b) return;
            e.stopPropagation(); e.preventDefault();
            if (e.type === 'pointerup'){ if (document.getElementById('imc-ai-tip')) hide(); else show(b); }
        }
        document.addEventListener('pointerdown', block, true);
        document.addEventListener('pointerup',   block, true);
        document.addEventListener('click',       block, true);
        // Dismiss touch tip when tapping elsewhere or scrolling
        document.addEventListener('pointerup', function(e){ if (e.target.closest && e.target.closest('.imc-ai-badge')) return; hide(); });
        window.addEventListener('scroll', hide, { passive:true });
    })();

    /**
     * Format XRP amount compactly
     */
    function formatXrpCompact(amount) {
        const num = parseFloat(amount);
        if (isNaN(num)) return '0';
        if (num >= 1000) return Math.round(num).toLocaleString();
        if (num >= 1) return num.toFixed(1);
        return num.toFixed(2);
    }

    /**
     * Truncate address helper (if not already defined)
     */
    function truncateAddr(address) {
        if (!address || address.length < 10) return address || '';
        return `${address.slice(0, 6)}...${address.slice(-4)}`;
    }

    /**
     * Fallback: Load individual mints (legacy behavior)
     */
    async function loadRecentMintsLegacy() {
        const slider = document.getElementById('recent-mints-slider');
        if (!slider) return;
        
        try {
            const proxyUrl = xrplMarketplace.endpoints?.myNftsHandler || 
                '/wp-content/themes/astra/xrpl-nft-marketplace/backend/my-nfts-handler.php';
            
            const url = `${proxyUrl}?action=get_recent_mints&limit=10&nonce=${encodeURIComponent(xrplMarketplace.nonce)}`;
            const response = await fetchWithTimeout(url, {}, 10000);
            
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            
            const data = await response.json();
            
            if (!data.success || !data.nfts || data.nfts.length === 0) {
                slider.innerHTML = `
                    <div class="empty-mints-placeholder">
                        <span class="empty-icon">🚀</span>
                        <p>No mints yet - be the first to create NFTs on IMCollectibles!</p>
                        <a href="/mint/" class="btn btn-primary">Start Minting</a>
                    </div>
                `;
                return;
            }
            
            // Render individual NFT cards (original behavior)
            slider.innerHTML = data.nfts.map(nft => `
                <div class="collection-card mint-card" 
                     onclick="window.location.href='/nft/${encodeURIComponent(nft.nftokenID)}/'">
                    <div class="nft-image-wrapper">
                        <img src="${esc(nft.image || '/wp-content/uploads/fallback-nft.svg')}" 
                             alt="${esc(nft.name)}" 
                             loading="lazy"
                             onerror="var _c=this.closest('.collection-card,.th-featured-card,.mint-collection-card');if(_c){_c.classList.add('imc-card-hidden');_c.style.setProperty('display','none','important');}">
                    </div>
                    <h3>${esc(nft.name || 'Untitled')}</h3>
                    <p>${esc(nft.collection_name || 'New Mint')}</p>
                </div>
            `).join('');
            
            setupSlider('recent-mints-slider', 'slider-prev-mints', 'slider-next-mints');
            // v478: Kick OE countdown ticker — same reason as the V4 path; legacy fallback also renders cards
            setupOpenMintCountdowns();
            
        } catch (error) {
            console.error('Legacy mints fallback error:', error);
            slider.innerHTML = `
                <div class="empty-mints-placeholder">
                    <span class="empty-icon">🚀</span>
                    <p>No mints yet - be the first to create NFTs on IMCollectibles!</p>
                    <a href="/mint/" class="btn btn-primary">Start Minting</a>
                </div>
            `;
        }
    }

    // ============================================================
    // v4.0: INITIALIZATION
    // ============================================================

    // Only initialize v4.0 features if the stats section exists
    if (document.querySelector('.th-stats-section')) {
        console.log('Trading Hub v4.0: Initializing...');
        
        // Expand button handler
        const expandBtn = document.getElementById('stats-expand-btn');
        if (expandBtn) {
            expandBtn.addEventListener('click', toggleStatsExpand);
        }

        // v255: Load platform stats (single all-time view in widget; full stats page has period filter)
        loadStats();
        
        // v23: Load scheduled / upcoming drops row (hidden if none exist)
        loadScheduledMintsCarousel();

        // v475: Load open mints row — sits between Recent Mints and Coming Soon
        // visually, but kicked off here in parallel with the others (async fetches).
        loadOpenMintsCarousel();

        // v568-uxr: Fixed Collections row (fixed-size / non-OE listings)
        loadFixedCollectionsCarousel();

        // Load recent mints (platform mints)
        loadRecentMintsV4();
        
        // v409: Load genre-specific carousels
        loadMusicAccessCarousel();
        // v467: Album Access carousel — ordered after Music, before Art to
        // match the visual section order in trading-hub.php and keep the
        // audio-oriented rows grouped in the hub layout.
        loadAlbumAccessCarousel();
        loadArtAccessCarousel();
        // v502: Video Access carousel (Music Video + Film) — after Art to
        // match the visual section order in trading-hub.php.
        loadVideoAccessCarousel();
        // B2: Comics & Books Access row (eBook + AudioBook)
        loadBooksAccessCarousel();

        // v475: Kick the open-mint countdown ticker so any OE cards appearing
        // in the other carousels (Recent / Music / Album / Art) also get live
        // countdowns, not just the dedicated Open Mints carousel.
        setupOpenMintCountdowns();
        
        console.log('Trading Hub v4.0: Initialized successfully');
    }
});

function handleQrClose(e) {
    e.preventDefault();
    const qr = document.getElementById('qr-code-popup');
    if (qr) {
        qr.style.display = 'none';
        qr.dataset.locked = 'false';
        document.getElementById('signing-status').textContent = '';
        document.getElementById('retry-offer').style.display = 'none';
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   CP-C3-A4 · HOLDERS TAB
   ══════════════════════════════════════════════════════════════════════════
   TWO-STAGE BY NECESSITY, NOT PREFERENCE.
   Holders come from the VPS (metadata.imcollectibles.io, SQLite). Display
   names live in WordPress (wp_imc_artist_profiles, MySQL). The VPS cannot
   reach the WP database, so names arrive in a second call.
   ⚠ Stage 2 must NEVER block stage 1: addresses render immediately and names
   swap in when they land. A slow or failed name lookup degrades to short
   addresses, which are still correct and still linkable.

   ENDPOINT IS THE ROOT, NOT /api/. R-C3a removed the dead `metadataApi` key
   precisely so no new loader would reach for the legacy five-collection store.

   MINTER WALLETS ARE SHOWN AS ORDINARY HOLDERS. Ruled 08 Sep: it is a true
   stat, and a profile set on that wallet explains itself. There is no
   exception logic here and none should be added.
   ══════════════════════════════════════════════════════════════════════════ */
(function () {
    var IMC_HOLDERS_API = 'https://metadata.imcollectibles.io/';
    // A4f: ONE fetch of 100, split 1-50 left / 51-100 right, then STOP.
    // Everything beyond is the CSV. This removes all pagination state -
    // no offset tracking, no Load more, no Show all, no exhausted flag.
    var LIST_CAP = 100;
    var COL_SIZE = 50;

    var hState = {
        issuer: '', taxon: null,
        rows: [], offset: 0, total: 0, distinct: 0,
        loading: false, exhausted: false, chartMode: false, chart: null,
        names: {}   // account -> {name, slug}
    };

    function hEsc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    // Mirrors imc_wallet_short() in imc-artist-profiles.php (6 + ... + 4) so the
    // fallback form matches the rest of the site.
    function hShort(a) {
        a = String(a || '');
        return a.length <= 12 ? a : a.slice(0, 6) + '...' + a.slice(-4);
    }
    function hNum(n) { return Number(n || 0).toLocaleString(); }

    function hCollectionKey() {
        var root = document.getElementById('nft-collection-page');
        if (!root) return null;
        var i = root.dataset.issuer || '';
        var t = root.dataset.taxon;
        if (!i || t === undefined || t === null || t === '') return null;
        return { issuer: i, taxon: parseInt(t, 10) };
    }

    function hRowHtml(r, rank) {
        var meta = hState.names[r.account] || null;
        var label, cls;
        if (meta && meta.name) { label = hEsc(meta.name); cls = "imc-holder-named"; }
        else { label = hEsc(hShort(r.account)); cls = "imc-holder-addr"; }
        var href = "/user/" + encodeURIComponent((meta && meta.slug) ? meta.slug : r.account) + "/";
        return '<div class="imc-holder-row">'
             +   '<span class="imc-holder-rank">' + rank + '</span>'
             +   '<span class="imc-holder-name">'
             +     '<a href="' + href + '" class="' + cls + '" title="' + hEsc(r.account) + '">'
             +       label
             +     '</a>'
             +   '</span>'
             +   '<span class="imc-holder-held">' + hNum(r.held) + '</span>'
             +   '<span class="imc-holder-pct">' + Number(r.pct || 0).toFixed(2) + '%</span>'
             + '</div>';
    }

    function hRenderRows() {
        var a = document.getElementById("imc-holders-rows-a");
        var b = document.getElementById("imc-holders-rows-b");
        var colB = document.getElementById("imc-holders-colb");
        if (!a) return;
        if (!hState.rows.length) {
            a.innerHTML = '<div class="imc-tab-empty">No holders found for this collection.</div>';
            if (b) b.innerHTML = "";
            if (colB) colB.hidden = true;
            return;
        }
        // A4g: BALANCE the columns. A fixed 50 left every short collection
        // one-sided - a short holder list stacked left with an empty half beside
        // them. Split down the middle instead, capped at 50 per column.
        // Under 8 holders a split reads as two stray fragments, so stay single.
        var n = hState.rows.length;
        var cut = n < 8 ? n : Math.min(COL_SIZE, Math.ceil(n / 2));
        var left = "", right = "";
        for (var k = 0; k < n; k++) {
            var html = hRowHtml(hState.rows[k], k + 1);
            if (k < cut) { left += html; } else { right += html; }
        }
        a.innerHTML = left;
        if (b) { b.innerHTML = right; }
        // A short collection keeps one column rather than leaving a headed
        // empty half sitting next to it.
        if (colB) { colB.hidden = (cut >= n); }
        var foot = document.getElementById("imc-holders-foot");
        if (foot) {
            if (hState.distinct > hState.rows.length) {
                foot.innerHTML = "Showing the top <strong>" + hNum(hState.rows.length)
                    + "</strong> of <strong>" + hNum(hState.distinct)
                    + "</strong> holders \u2014 download the CSV for the full list.";
            } else {
                foot.innerHTML = "All <strong>" + hNum(hState.distinct) + "</strong> holders shown.";
            }
        }
    }
    /* A4e: the four summary numbers now live in their own gold-bordered cards
       down the left, not in a run-on sentence above the table. */
    function hSet(id, v) { var e = document.getElementById(id); if (e) e.textContent = v; }
    function hRenderSummary(d) {
        hSet("imc-stat-holders", hNum(d.distinct_holders));
        hSet("imc-stat-nfts",    hNum(d.total_nfts));
        hSet("imc-stat-top10",   Number(d.top10_share_pct || 0).toFixed(1) + "%");
        hSet("imc-stat-single",  hNum(d.single_holders));
        var m = document.getElementById("imc-holders-meta");
        if (m) {
            // D15: every metric carries its source and its age. "live from the
            // ledger" is a stronger claim than this page has ever made, and it
            // is only honest if the source is stated.
            // D15: state the source AND what it means. "From our index" and
            // "Live from the ledger" can legitimately differ by a holder or two
            // - the index moves continuously, the snapshot is a fixed point.
            // Saying which is which turns a puzzling mismatch into information.
            var src = d.source === "ledger" ? "<strong>Live from the XRP Ledger</strong>"
                    : d.source === "cache"  ? "<strong>Today\u2019s ledger snapshot</strong>"
                    : "From our index \u00b7 verify for a live ledger count";
            var when = "";
            if (d.fetched_at) {
                var dt = new Date(d.fetched_at);
                if (!isNaN(dt)) { when = "<br>" + dt.toLocaleString(); }
            }
            // A4i: src is HTML WE build (it carries <strong>), so it must NOT
            // be escaped - A4g added the markup and left hEsc() in place, which
            // rendered "<strong>Live from the XRP Ledger</strong>" as visible
            // text. `when` and the ledger number are numeric/date strings we
            // format ourselves, so nothing here is user input.
            m.innerHTML = src + when
                + (d.ledger_index ? "<br>Ledger " + hNum(d.ledger_index) : "")
                + (d.truncated ? "<br><em>Partial \u2014 large collection</em>" : "");
        }
    }
    /* A4c: OWNER DISTRIBUTION.
       Bucketed server-side over ALL holders - the client only ever holds one
       page, so it cannot compute this itself. Bars are scaled to the LARGEST
       bucket, not to 100%, or a collection where 95% hold one item renders as
       a single full bar and four invisible ones. */
    function hRenderDist(dist) {
        var box = document.getElementById('imc-holders-dist');
        if (!box) return;
        if (!dist || !dist.length) { box.innerHTML = ''; return; }
        var max = 0;
        dist.forEach(function (b) { if (b.holders > max) max = b.holders; });
        if (!max) { box.innerHTML = ''; return; }
        var html = '';
        dist.forEach(function (b) {
            var w = Math.round((b.holders / max) * 100);
            var lbl = b.label === '1' ? '1 item' : b.label + ' items';
            html += '<div class="imc-dist-row">'
                 +   '<span class="imc-dist-label">' + hEsc(lbl) + '</span>'
                 +   '<span class="imc-dist-track"><span class="imc-dist-fill" style="width:' + w + '%"></span></span>'
                 +   '<span class="imc-dist-val">' + hNum(b.holders)
                 +     ' <span>' + Number(b.pct || 0).toFixed(0) + '%</span></span>'
                 + '</div>';
        });
        box.innerHTML = html;
    }

    /* Stage 2. Never awaited by stage 1 - it repaints when it returns. */
    function hFetchNames(accounts) {
        var want = accounts.filter(function (a) { return !(a in hState.names); });
        if (!want.length) return;
        var body = new URLSearchParams();
        body.append('action', 'imc_holder_names');
        want.slice(0, 200).forEach(function (a) { body.append('accounts[]', a); });
        fetch('/wp-admin/admin-ajax.php', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                var got = (j && j.names) || {};
                // Cache misses too, so we never re-ask for a nameless wallet.
                want.forEach(function (a) { hState.names[a] = got[a] || null; });
                hRenderRows();
            })
            .catch(function () { /* addresses already render - nothing to undo */ });
    }

    /* A4g: `verify` walks the XRP Ledger live instead of reading our index.
       The server side has existed since A-2 - three guards, is_burned filtered,
       a 25s wall-clock budget - but nothing in the UI ever triggered it, so the
       one number that is genuinely ledger-authoritative was unreachable. That is
       what this flag fixes. */
    function hLoad(verify) {
        if (hState.loading) return;
        var key = hCollectionKey();
        if (!key) return;
        hState.loading = true;
        var vbtn = document.getElementById("imc-holders-verify");
        if (verify && vbtn) { vbtn.textContent = "Verifying\u2026"; }
        var url = IMC_HOLDERS_API + "?action=holders"
                + "&issuer=" + encodeURIComponent(key.issuer)
                + "&taxon=" + encodeURIComponent(key.taxon)
                + "&limit=" + LIST_CAP + "&offset=0"
                + (verify ? "&mode=verify" : "");
        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (d) {
                hState.loading = false;
                if (vbtn) {
                    vbtn.textContent = "Verify live";
                    vbtn.classList.toggle("is-verified", d && (d.source === "ledger" || d.source === "cache"));
                }
                if (!d || !d.success) {
                    var bx = document.getElementById("imc-holders-rows-a");
                    if (bx) { bx.innerHTML = '<div class="imc-tab-empty">Holders are unavailable right now.</div>'; }
                    return;
                }
                hState.distinct = d.distinct_holders || 0;
                hState.total    = d.total_nfts || 0;
                hState.rows     = d.holders || [];
                hRenderSummary(d);
                hRenderDist(d.distribution);
                hRenderRows();
                hFetchNames(hState.rows.map(function (x) { return x.account; }));
            })
            .catch(function () {
                hState.loading = false;
                if (vbtn) { vbtn.textContent = "Verify live"; }
                var bx = document.getElementById("imc-holders-rows-a");
                if (bx) { bx.innerHTML = '<div class="imc-tab-empty">Holders are unavailable right now.</div>'; }
            });
    }
    /* CP-C3-H · HOLDERS CHART VIEW
       Two charts, both from fields holders_history has returned since A-4 and
       neither of which was ever drawn:
         1. holders + SUPPLY on one axis - both counts, so they share a scale
            honestly, and the comparison is the point. Holders rising while
            supply is flat means ownership is SPREADING; both rising together is
            just minting. One line alone cannot tell you which.
         2. top10_share_pct on its OWN chart - a percentage cannot share an axis
            with a count, and a dual axis invites exactly the misreading the
            average-price line caused on Activity.
       ⚠ No server change: every field was already in the payload. */
    var hAn = { win: { growth: 3650, conc: 3650 }, cache: {}, charts: {} };

    function hKill(k) { if (hAn.charts[k]) { hAn.charts[k].destroy(); hAn.charts[k] = null; } }
    function hSetTxt(id, t) { var e = document.getElementById(id); if (e) e.textContent = t; }
    function hWrapHide(id, hide) { var e = document.getElementById(id); if (e) e.hidden = !!hide; }

    function hAxes(pct) {
        return {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: true, labels: { color: "#a8a8a8", boxWidth: 10, font: { size: 11 } } } },
            interaction: { intersect: false, mode: "index" },
            scales: {
                x: { ticks: { color: "#6f6f6f", maxRotation: 0, autoSkipPadding: 26 },
                     grid: { display: false }, border: { color: "rgba(255,255,255,0.08)" } },
                // ⚠ min:0 always. A single reading otherwise renders -1..1 ticks
                // and the chart looks broken rather than young.
                y: { min: 0, max: pct ? 100 : undefined,
                     ticks: { color: "#6f6f6f",
                              callback: pct ? function (v) { return v + "%"; } : undefined },
                     grid: { color: "rgba(255,255,255,0.04)" }, border: { display: false } }
            }
        };
    }

    function hDrawGrowth(pts) {
        if (!pts.length) {
            hWrapHide("imc-holders-chartwrap", true); hKill("growth");
            hSetTxt("imc-holders-chartnote", "Daily holder readings begin once this collection is snapshotted.");
            hSetTxt("imc-holders-range", "");
            return;
        }
        hWrapHide("imc-holders-chartwrap", false);
        hSetTxt("imc-holders-range", pts.length === 1 ? pts[0].date
                : pts[0].date + " \u2192 " + pts[pts.length - 1].date);
        hKill("growth");
        if (typeof Chart === "undefined") return;
        hAn.charts.growth = new Chart(document.getElementById("imc-holders-chart").getContext("2d"), {
            type: "line",
            data: { labels: pts.map(function (p) { return p.date; }), datasets: [
                { label: "Holders", data: pts.map(function (p) { return p.distinct_holders; }),
                  borderColor: "#4da3ff", backgroundColor: "rgba(77,163,255,0.12)",
                  borderWidth: 2, tension: 0.3, fill: true,
                  pointRadius: pts.length === 1 ? 5 : 0,
                  pointBackgroundColor: "#4da3ff", pointHoverRadius: 5 },
                { label: "Supply", data: pts.map(function (p) { return p.total_nfts; }),
                  borderColor: "#d6ba66", backgroundColor: "rgba(214,186,102,0.06)",
                  borderWidth: 2, borderDash: [4, 3], tension: 0.3, fill: false,
                  pointRadius: pts.length === 1 ? 5 : 0,
                  pointBackgroundColor: "#d6ba66", pointHoverRadius: 5 }
            ] },
            options: hAxes(false)
        });
        hSetTxt("imc-holders-chartnote", pts.length === 1
            ? "First daily reading \u2014 recorded from 8 September 2026."
            : pts.length + " daily readings \u00b7 holders against total supply.");
    }

    function hDrawConc(pts) {
        var cp = pts.filter(function (p) { return p.top10_share_pct !== null && p.top10_share_pct !== undefined; });
        if (!cp.length) {
            hWrapHide("imc-conc-chartwrap", true); hKill("conc");
            hSetTxt("imc-conc-note", "Concentration history begins building today.");
            hSetTxt("imc-conc-range", "");
            return;
        }
        hWrapHide("imc-conc-chartwrap", false);
        hSetTxt("imc-conc-range", cp.length === 1 ? cp[0].date
                : cp[0].date + " \u2192 " + cp[cp.length - 1].date);
        hKill("conc");
        if (typeof Chart === "undefined") return;
        hAn.charts.conc = new Chart(document.getElementById("imc-conc-chart").getContext("2d"), {
            type: "line",
            data: { labels: cp.map(function (p) { return p.date; }),
                datasets: [{ label: "Top 10 share",
                    data: cp.map(function (p) { return p.top10_share_pct; }),
                    borderColor: "#d6ba66", backgroundColor: "rgba(214,186,102,0.10)",
                    borderWidth: 2, tension: 0.3, fill: true,
                    pointRadius: cp.length === 1 ? 5 : 0,
                    pointBackgroundColor: "#d6ba66", pointHoverRadius: 5 }] },
            options: hAxes(true)
        });
        hSetTxt("imc-conc-note", cp.length === 1
            ? "First daily reading \u2014 the share held by the ten largest holders."
            : cp.length + " daily readings \u00b7 rising means the largest holders are accumulating.");
    }

    function hFetchWindow(days) {
        if (hAn.cache[days]) { return Promise.resolve(hAn.cache[days]); }
        var key = hCollectionKey();
        if (!key) return Promise.resolve(null);
        return fetch(IMC_HOLDERS_API + "?action=holders_history"
              + "&issuer=" + encodeURIComponent(key.issuer)
              + "&taxon=" + encodeURIComponent(key.taxon) + "&days=" + days)
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d && d.success) { hAn.cache[days] = d; return d; } return null; })
            .catch(function () { return null; });
    }

    function hMarkThin(which, n) {
        var row = document.querySelector('.imc-act-windows[data-hchart="' + which + '"]');
        if (!row) return;
        [].forEach.call(row.querySelectorAll(".imc-act-win"), function (b) {
            b.classList.toggle("is-thin", n < 2 && b.dataset.days !== "3650");
        });
    }

    function hRefresh(which) {
        hFetchWindow(hAn.win[which]).then(function (d) {
            if (!d) return;
            var pts = d.points || [];
            if (which === "growth") { hDrawGrowth(pts); }
            if (which === "conc")   { hDrawConc(pts); }
            hMarkThin(which, pts.length);
        });
    }

    function hLoadChart() {
        hRefresh("growth"); hRefresh("conc");
        var rows = document.querySelectorAll(".imc-act-windows[data-hchart]");
        [].forEach.call(rows, function (row) {
            if (row.dataset.bound) return;
            row.dataset.bound = "1";
            row.addEventListener("click", function (e) {
                var b = e.target.closest ? e.target.closest(".imc-act-win") : null;
                if (!b) return;
                var which = row.dataset.hchart;
                hAn.win[which] = parseInt(b.dataset.days, 10);
                [].forEach.call(row.querySelectorAll(".imc-act-win"), function (x) {
                    x.classList.toggle("is-on", x === b);
                });
                hRefresh(which);
            });
        });
    }

    /* A4e: DOWNLOAD FULL LIST AS CSV.
       Walks every page rather than exporting what happens to be on screen - a
       CSV of only the first page of holders would be quietly wrong. The endpoint
       caps limit at 500, so this loops until it has them all, then builds a
       Blob client-side. No new endpoint, no server round-trip beyond the pages
       we would fetch anyway. */
    function hCsvEscape(v) {
        v = String(v == null ? "" : v);
        return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
    }
    function hDownloadCsv() {
        var key = hCollectionKey();
        var btn = document.getElementById("imc-holders-csv");
        if (!key || hState.csvBusy) return;
        hState.csvBusy = true;
        var origLabel = btn ? btn.textContent : "";
        if (btn) { btn.textContent = "Preparing\u2026"; }
        var all = [];
        function page(offset) {
            return fetch(IMC_HOLDERS_API + "?action=holders"
                    + "&issuer=" + encodeURIComponent(key.issuer)
                    + "&taxon=" + encodeURIComponent(key.taxon)
                    + "&limit=500&offset=" + offset)
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || !d.success) { throw new Error("failed"); }
                    var batch = d.holders || [];
                    all = all.concat(batch);
                    if (batch.length === 500 && all.length < (d.distinct_holders || 0)) {
                        return page(offset + 500);
                    }
                    return d;
                });
        }
        page(0).then(function (d) {
            var lines = ["owner,held,share_pct"];
            all.forEach(function (h) {
                var meta = hState.names[h.account];
                lines.push([hCsvEscape(h.account), h.held,
                            Number(h.pct || 0).toFixed(4)].join(","));
            });
            var blob = new Blob([lines.join("\n")], { type: "text/csv;charset=utf-8;" });
            var url = URL.createObjectURL(blob);
            var a = document.createElement("a");
            a.href = url;
            a.download = "holders-" + key.issuer + "-" + key.taxon + ".csv";
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
            setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
            if (btn) { btn.textContent = origLabel || "Download CSV"; }
            hState.csvBusy = false;
        }).catch(function () {
            if (btn) { btn.textContent = "Download failed"; 
                setTimeout(function () { btn.textContent = origLabel || "Download CSV"; }, 2500); }
            hState.csvBusy = false;
        });
    }

    function hToggleView() {
        var btn = document.getElementById('imc-holders-toggle');
        var list = document.getElementById('imc-holders-listview');
        var chart = document.getElementById('imc-holders-chartview');
        if (!btn || !list || !chart) return;
        hState.chartMode = !hState.chartMode;
        list.hidden = hState.chartMode;
        chart.hidden = !hState.chartMode;
        btn.setAttribute('aria-pressed', hState.chartMode ? 'true' : 'false');
        btn.textContent = hState.chartMode ? 'Show list' : 'Change View';
        // Distribution is already rendered from the holders payload; only the
        // growth series needs its own fetch, and only once.
        if (hState.chartMode && !hState.chartLoaded) { hState.chartLoaded = true; hLoadChart(); }
    }

    window.imcLoadHolders = function () {
        hLoad(false);
        // A4f: Load more / Show all are gone with the pagination they drove.
        // The list is a fixed top-100; the CSV is the route to the rest.
        var ver = document.getElementById('imc-holders-verify');
        if (ver && !ver.dataset.bound) { ver.dataset.bound = '1';
            ver.addEventListener('click', function () { hLoad(true); }); }
        var tog = document.getElementById('imc-holders-toggle');
        if (tog && !tog.dataset.bound) { tog.dataset.bound = '1';
            tog.addEventListener('click', hToggleView); }
        var csv = document.getElementById('imc-holders-csv');
        if (csv && !csv.dataset.bound) { csv.dataset.bound = '1';
            csv.addEventListener('click', hDownloadCsv); }
    };
})();

/* ══════════════════════════════════════════════════════════════════════════
   CP-C3-B4 · ACTIVITY TAB
   ══════════════════════════════════════════════════════════════════════════
   SALES AND IMC MINTS ONLY, by ruling.

   `events=sale,mint` is filtered SERVER-SIDE in SQL (B-1). Filtering a fetched
   page client-side would return 50 rows of which a handful survive - store-wide
   the clear majority of nft_sales rows are transfers.

   ⚠ TRANSFERS ARE EXCLUDED BECAUSE WE CANNOT LABEL THEM HONESTLY. A zero-amount
   move is indistinguishable from another marketplace delivering its own mint.
   Calling it a "transfer" would be a claim about intent we cannot support, so
   the footer states the omission and its reason instead.

   Names resolve through the SAME two-stage path as Holders (A-4): addresses
   render immediately, names swap in when admin-ajax returns. ⚠ Activity has TWO
   accounts per row, so a 50-row page is up to 100 accounts - inside the resolver's
   200 cap, but a 100-row page would exceed it. PAGE stays at 50.
   ══════════════════════════════════════════════════════════════════════════ */
(function () {
    var IMC_ACT_API = 'https://metadata.imcollectibles.io/';
    var ACT_PAGE = 50;

    var aState = { rows: [], offset: 0, loading: false, done: false, names: {}, mints: {} };

    function aEsc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    // Mirrors imc_wallet_short() (6 + ... + 4) so the fallback matches the site.
    function aShort(a) {
        a = String(a || '');
        return a.length <= 12 ? a : a.slice(0, 6) + '...' + a.slice(-4);
    }
    function aNum(n) { return Number(n || 0).toLocaleString(); }

    /* No relative-time helper existed anywhere in the theme, so here is one.
       Deliberately coarse - "3 days ago" is more readable than "3d 4h 12m". */
    function aAgo(iso) {
        if (!iso) return '';
        var t = Date.parse(String(iso).replace(' ', 'T') + (/[Zz+]/.test(iso) ? '' : 'Z'));
        if (isNaN(t)) return '';
        var s = Math.max(0, (Date.now() - t) / 1000);
        if (s < 60)     return 'just now';
        if (s < 3600)   return Math.floor(s / 60) + 'm ago';
        if (s < 86400)  return Math.floor(s / 3600) + 'h ago';
        if (s < 604800) return Math.floor(s / 86400) + 'd ago';
        var d = new Date(t);
        return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
    }

    function aCollectionKey() {
        var root = document.getElementById('nft-collection-page');
        if (!root) return null;
        var i = root.dataset.issuer || '';
        var t = root.dataset.taxon;
        if (!i || t === undefined || t === null || t === '') return null;
        return { issuer: i, taxon: parseInt(t, 10) };
    }

    function aParty(acct) {
        if (!acct) return '<span class="imc-act-addr">&mdash;</span>';
        var meta = aState.names[acct] || null;
        var label, cls;
        if (meta && meta.name) { label = aEsc(meta.name); cls = 'imc-act-named'; }
        else { label = aEsc(aShort(acct)); cls = 'imc-act-addr'; }
        var href = '/user/' + encodeURIComponent((meta && meta.slug) ? meta.slug : acct) + '/';
        return '<a href="' + href + '" class="' + cls + '" title="' + aEsc(acct) + '">' + label + '</a>';
    }

    function aPrice(r) {
        // A MINT carries no price in nft_sales - IMC deliveries are zero-amount
        // by design (verified across the store). The price lives in
        // wp_imc_purchases, resolved in stage two alongside the names.
        if (r.event === 'mint') {
            var m = aState.mints[(r.nft_id || '').toUpperCase()];
            if (!m) { return '<span class="imc-act-nil">&mdash;</span>'; }
            if (m.free) { return '<span class="imc-act-free">FREE</span>'; }
            // ⚠ A token-priced mint whose amount lives in accepted_currencies:
            // vanishingly rare store-wide. Show a dash rather than guess - labelling a
            // PAID mint FREE is the one error a buyer would notice.
            if (m.price === null || m.price === undefined) {
                return '<span class="imc-act-nil">&mdash;</span>';
            }
            var mdp = Number(m.price) < 1 ? 4 : 2;
            return Number(m.price).toLocaleString(undefined, {
                minimumFractionDigits: mdp, maximumFractionDigits: mdp
            }) + '<small>' + aEsc(m.currency || 'XRP') + '</small>';
        }
        if (r.price_xrp > 0) {
            // ⚠ NOT aNum(toFixed(...)): toFixed returns a STRING, Number()
            // parses it back and toLocaleString drops the trailing zeros, so
            // 15.00 rendered as '15'. Fix the digits in the formatter itself.
            var dp = Number(r.price_xrp) < 1 ? 4 : 2;
            return Number(r.price_xrp).toLocaleString(undefined, {
                minimumFractionDigits: dp, maximumFractionDigits: dp
            }) + '<small>XRP</small>';
        }
        // ⚠ D14: a token price is shown in ITS OWN currency, never converted.
        if (r.amount_value !== null && r.amount_value !== '' && r.amount_currency) {
            return aEsc(String(r.amount_value)) + '<small>' + aEsc(r.amount_currency) + '</small>';
        }
        return '<span class="imc-act-nil">&mdash;</span>';
    }

    function aRenderRows() {
        var box = document.getElementById('imc-activity-rows');
        if (!box) return;
        if (!aState.rows.length) {
            box.innerHTML = '<div class="imc-tab-empty">No sales or mints recorded for this collection yet.</div>';
            return;
        }
        var html = '';
        for (var k = 0; k < aState.rows.length; k++) {
            var r = aState.rows[k];
            var isMint = (r.event === 'mint');
            var img = r.nft_image
                ? '<img class="imc-act-thumb" src="' + aEsc(r.nft_image) + '" alt="" loading="lazy">'
                : '<span class="imc-act-thumb"></span>';
            html += '<div class="imc-act-row">'
                 +   '<span class="imc-act-item">' + img
                 +     '<span class="imc-act-name" title="' + aEsc(r.nft_name) + '">' + aEsc(r.nft_name) + '</span>'
                 +   '</span>'
                 +   '<span class="imc-act-price">' + aPrice(r) + '</span>'
                 +   '<span class="imc-act-event"><span class="imc-act-badge '
                 +     (isMint ? 'is-mint">Mint' : 'is-sale">Sale') + '</span></span>'
                 +   '<span class="imc-act-party">' + aParty(r.seller) + '</span>'
                 +   '<span class="imc-act-party">' + aParty(r.buyer) + '</span>'
                 +   '<span class="imc-act-time" title="' + aEsc(r.sold_at) + '">' + aEsc(aAgo(r.sold_at)) + '</span>'
                 +   '<span class="imc-act-exp">'
                 +     (r.tx_hash
                        ? '<a href="https://bithomp.com/explorer/' + aEsc(r.tx_hash)
                          + '" target="_blank" rel="noopener" title="View on Bithomp">View &#8599;</a>'
                        : '')
                 +   '</span>'
                 + '</div>';
        }
        box.innerHTML = html;
    }

    /* Stage 2 - never awaited by stage 1. Two accounts per row. */
    /* Stage 2 resolves BOTH names and mint prices in ONE request. Activity
       already fired one call per page for names; asking for prices separately
       would double the round trips for the same render. */
    function aFetchNames(rows) {
        var want = [], wantIds = [];
        rows.forEach(function (r) {
            [r.seller, r.buyer].forEach(function (a) {
                if (a && !(a in aState.names) && want.indexOf(a) === -1) want.push(a);
            });
            // Only mints need a price lookup - a sale already carries one.
            if (r.event === 'mint' && r.nft_id) {
                var id = r.nft_id.toUpperCase();
                if (!(id in aState.mints) && wantIds.indexOf(id) === -1) wantIds.push(id);
            }
        });
        if (!want.length && !wantIds.length) return;
        var body = new URLSearchParams();
        body.append('action', 'imc_holder_names');
        want.slice(0, 200).forEach(function (a) { body.append('accounts[]', a); });
        wantIds.slice(0, 200).forEach(function (n) { body.append('nft_ids[]', n); });
        fetch('/wp-admin/admin-ajax.php', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                var got = (j && j.names) || {};
                var gotM = (j && j.mints) || {};
                want.forEach(function (a) { aState.names[a] = got[a] || null; });
                // Cache misses too, so a mint with no purchase row is never
                // re-requested on the next page.
                wantIds.forEach(function (n) { aState.mints[n] = gotM[n] || null; });
                aRenderRows();
            })
            .catch(function () { /* addresses already render */ });
    }

    function aLoad() {
        if (aState.loading || aState.done) return;
        var key = aCollectionKey();
        if (!key) return;
        aState.loading = true;
        var more = document.getElementById('imc-activity-more');
        if (more) { more.textContent = 'Loading\u2026'; }

        fetch(IMC_ACT_API + '?action=recent_sales'
              + '&issuer=' + encodeURIComponent(key.issuer)
              + '&taxon=' + encodeURIComponent(key.taxon)
              + '&events=sale,mint&limit=' + ACT_PAGE + '&offset=' + aState.offset)
            .then(function (r) { return r.json(); })
            .then(function (d) {
                aState.loading = false;
                if (more) { more.textContent = 'Load more'; }
                if (!d || !d.success) {
                    var b = document.getElementById('imc-activity-rows');
                    if (b && !aState.rows.length) {
                        b.innerHTML = '<div class="imc-tab-empty">Activity is unavailable right now.</div>';
                    }
                    return;
                }
                var batch = d.sales || [];
                aState.rows = aState.rows.concat(batch);
                aState.offset += batch.length;
                if (batch.length < ACT_PAGE) { aState.done = true; }

                aRenderRows();
                aFetchNames(batch);

                var cnt = document.getElementById('imc-activity-count');
                if (cnt) { cnt.textContent = aNum(aState.rows.length) + ' shown'; }
                if (more) { more.hidden = aState.done; }
                var foot = document.getElementById('imc-activity-foot');
                if (foot && aState.done) {
                    foot.innerHTML = 'Sales and IMC mints only. '
                        + 'Transfers are not shown \u2014 they cannot be reliably told apart '
                        + 'from mints delivered on other platforms.';
                }
            })
            .catch(function () {
                aState.loading = false;
                if (more) { more.textContent = 'Load more'; }
                var b = document.getElementById('imc-activity-rows');
                if (b && !aState.rows.length) {
                    b.innerHTML = '<div class="imc-tab-empty">Activity is unavailable right now.</div>';
                }
            });
    }



    /* ══════════════════════════════════════════════════════════════════════
       CP-C3-G · THREE CHARTS, WINDOWED
       ══════════════════════════════════════════════════════════════════════
       1. SALE PRICES  scatter, one dot per sale, opacity = density
       2. FLOOR        line, from the daily ledger snapshot
       3. LISTINGS     line, from the same snapshot

       ★ WINDOW, NOT GRAIN. A scatter has no grain - every dot is one sale.
       Floor and listings are one reading per day by construction, so "daily"
       is already their only grain. Weekly would mean averaging daily floors,
       which is a different and lossier statistic. Grain can be added later
       without touching the API.

       ★ WHY A SCATTER AND NOT AN AVERAGE. A thin sale history can be
       6.5, 7, 5, 5, 15 - they average to 7.7, a price at which NOTHING traded.
       A line between two daily averages invents a trend that does not exist.
       Every dot is a real transaction; overlapping dots darken, so clustering
       reads without binning and it degrades gracefully to five points.

       ⚠ D14: one currency per plot. sale_points carries `cur` per point and
       the endpoint returns a `currencies` map, so the selector offers only
       what THIS collection actually traded in.
       ══════════════════════════════════════════════════════════════════════ */
    var aAn = {
        mode: 'list',
        cur: 'XRP',
        win: { scatter: 90, floor: 3650, listings: 3650 },
        cache: {},                 // days -> payload
        charts: {},                // key -> Chart
        statsLoaded: false
    };

    function aAxes(yLabel) {
        return {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false },
                       tooltip: { callbacks: {} } },
            interaction: { intersect: false, mode: 'nearest' },
            scales: {
                x: { type: 'category',
                     ticks: { color: '#6f6f6f', maxRotation: 0, autoSkipPadding: 30 },
                     grid: { display: false }, border: { color: 'rgba(255,255,255,0.08)' } },
                // ⚠ min:0 on every axis. Without it a single zero reading renders
                // -1.0 .. 1.0 ticks and the chart looks broken rather than empty.
                y: { min: 0, ticks: { color: '#6f6f6f' },
                     grid: { color: 'rgba(255,255,255,0.04)' }, border: { display: false } }
            }
        };
    }

    function aKill(k) { if (aAn.charts[k]) { aAn.charts[k].destroy(); aAn.charts[k] = null; } }

    function aNote(id, txt) { var e = document.getElementById(id); if (e) e.textContent = txt; }
    function aWrapHide(canvasId, hide) {
        var c = document.getElementById(canvasId);
        if (c && c.parentNode) { c.parentNode.hidden = !!hide; }
    }

    /* ---- 1 · SALE PRICE SCATTER ---- */
    function aDrawScatter(d) {
        var pts = (d.sale_points || []).filter(function (p) { return p.cur === aAn.cur; });
        var other = (d.sale_points || []).length - pts.length;
        if (!pts.length) {
            aWrapHide('imc-act-scatter', true); aKill('scatter');
            aNote('imc-act-scatternote', 'No ' + aAn.cur + ' sales in this window.');
            return;
        }
        aWrapHide('imc-act-scatter', false);
        var labels = pts.map(function (p) { return String(p.t).slice(0, 10); });
        aKill('scatter');
        if (typeof Chart === 'undefined') return;
        var cv = document.getElementById('imc-act-scatter');
        var opts = aAxes();
        // Tooltip shows the exact timestamp and price - the dot is a real trade.
        opts.plugins.tooltip.callbacks.title = function (items) {
            return String(pts[items[0].dataIndex].t);
        };
        opts.plugins.tooltip.callbacks.label = function (item) {
            return item.parsed.y + ' ' + aAn.cur;
        };
        aAn.charts.scatter = new Chart(cv.getContext('2d'), {
            type: 'scatter',
            data: { labels: labels, datasets: [{
                data: pts.map(function (p, i) { return { x: i, y: p.v }; }),
                // ★ 0.55 alpha IS the heat map - two sales at the same price on
                // the same day overlap into a darker dot, no binning needed.
                backgroundColor: 'rgba(77,163,255,0.55)',
                borderColor: 'rgba(77,163,255,0.9)', borderWidth: 1,
                pointRadius: 4, pointHoverRadius: 7
            }] },
            options: opts
        });
        var note = pts.length + ' ' + aAn.cur + ' sale' + (pts.length === 1 ? '' : 's')
                 + ' \u00b7 each dot is one trade';
        if (other > 0) { note += ' \u00b7 ' + other + ' sale' + (other === 1 ? '' : 's') + ' in other currencies not shown'; }
        if (d.sale_points_truncated) { note += ' \u00b7 showing the most recent 2,000'; }
        aNote('imc-act-scatternote', note);
    }

    /* ---- 2 · FLOOR ---- */
    function aDrawFloor(d) {
        var pts = (d.points || []).filter(function (p) { return p.floor_xrp !== null; });
        var rng = document.getElementById('imc-act-floorrange');
        if (!pts.length) {
            aWrapHide('imc-act-floorchart', true); aKill('floor');
            aNote('imc-act-floornote', 'Floor history begins building today.');
            if (rng) rng.textContent = '';
            return;
        }
        aWrapHide('imc-act-floorchart', false);
        if (rng) { rng.textContent = pts.length === 1 ? pts[0].date
                                   : pts[0].date + ' \u2192 ' + pts[pts.length - 1].date; }
        aKill('floor');
        if (typeof Chart === 'undefined') return;
        aAn.charts.floor = new Chart(document.getElementById('imc-act-floorchart').getContext('2d'), {
            type: 'line',
            data: { labels: pts.map(function (p) { return p.date; }),
                    datasets: [{ data: pts.map(function (p) { return p.floor_xrp; }),
                        borderColor: '#d6ba66', backgroundColor: 'rgba(214,186,102,0.10)',
                        borderWidth: 2, tension: 0.3, fill: true,
                        pointRadius: pts.length === 1 ? 5 : 0,
                        pointBackgroundColor: '#d6ba66', pointHoverRadius: 5 }] },
            options: aAxes()
        });
        aNote('imc-act-floornote', pts.length === 1
            ? 'First daily reading \u2014 recorded from 8 September 2026.'
            : pts.length + ' daily readings \u00b7 lowest active listing, from the ledger snapshot.');
    }

    /* ---- 3 · LISTINGS ---- */
    function aDrawListings(d) {
        var pts = (d.points || []).filter(function (p) { return p.listed_count !== null; });
        var rng = document.getElementById('imc-act-listrange');
        // ⚠ A series of nothing but zeros is not a chart. One zero dot on a
        // -1..1 axis reads as broken; the sentence reads as true.
        var anyNonZero = pts.some(function (p) { return p.listed_count > 0; });
        if (!pts.length || !anyNonZero) {
            aWrapHide('imc-act-listchart', true); aKill('listings');
            aNote('imc-act-listnote', pts.length
                ? 'Nothing listed for sale \u2014 the chart appears when there is.'
                : 'Listing history begins building today.');
            if (rng) rng.textContent = '';
            return;
        }
        aWrapHide('imc-act-listchart', false);
        if (rng) { rng.textContent = pts.length === 1 ? pts[0].date
                                   : pts[0].date + ' \u2192 ' + pts[pts.length - 1].date; }
        aKill('listings');
        if (typeof Chart === 'undefined') return;
        aAn.charts.listings = new Chart(document.getElementById('imc-act-listchart').getContext('2d'), {
            type: 'line',
            data: { labels: pts.map(function (p) { return p.date; }),
                    datasets: [{ data: pts.map(function (p) { return p.listed_count; }),
                        borderColor: '#4da3ff', backgroundColor: 'rgba(77,163,255,0.12)',
                        borderWidth: 2, tension: 0.3, fill: true,
                        pointRadius: pts.length === 1 ? 5 : 0,
                        pointBackgroundColor: '#4da3ff', pointHoverRadius: 5 }] },
            options: aAxes()
        });
        aNote('imc-act-listnote', pts.length === 1
            ? 'First daily reading \u2014 recorded from 8 September 2026.'
            : pts.length + ' daily readings \u00b7 how much of the collection is for sale.');
    }

    /* Currency selector, built from what this collection ACTUALLY traded in. */
    function aBuildCurrencies(d) {
        var sel = document.getElementById('imc-act-cursel');
        if (!sel) return;
        var curs = Object.keys(d.currencies || {});
        if (curs.length < 2) { sel.hidden = true; return; }
        sel.hidden = false;
        if (sel.dataset.built === curs.join(',')) return;
        sel.dataset.built = curs.join(',');
        sel.innerHTML = curs.map(function (c) {
            return '<option value="' + aEsc(c) + '">' + aEsc(c) + ' (' + d.currencies[c] + ')</option>';
        }).join('');
        if (curs.indexOf(aAn.cur) === -1) { aAn.cur = curs[0]; }
        sel.value = aAn.cur;
        if (!sel.dataset.bound) {
            sel.dataset.bound = '1';
            sel.addEventListener('change', function () {
                aAn.cur = sel.value;
                var d2 = aAn.cache[aAn.win.scatter];
                if (d2) aDrawScatter(d2);
            });
        }
    }

    /* Dim - never hide - windows the series cannot fill. */
    function aMarkThin(chart, points) {
        var row = document.querySelector('.imc-act-windows[data-chart="' + chart + '"]');
        if (!row) return;
        [].forEach.call(row.querySelectorAll('.imc-act-win'), function (b) {
            b.classList.toggle('is-thin', points < 2 && b.dataset.days !== '3650');
        });
    }

    function aFetchWindow(days) {
        if (aAn.cache[days]) { return Promise.resolve(aAn.cache[days]); }
        var key = aCollectionKey();
        if (!key) return Promise.resolve(null);
        return fetch(IMC_ACT_API + '?action=holders_history'
              + '&issuer=' + encodeURIComponent(key.issuer)
              + '&taxon=' + encodeURIComponent(key.taxon) + '&days=' + days)
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d && d.success) { aAn.cache[days] = d; return d; } return null; })
            .catch(function () { return null; });
    }

    function aRefresh(which) {
        var days = aAn.win[which];
        aFetchWindow(days).then(function (d) {
            if (!d) return;
            if (which === 'scatter')  { aBuildCurrencies(d); aDrawScatter(d);
                                        aMarkThin('scatter', (d.sale_points || []).length); }
            if (which === 'floor')    { aDrawFloor(d);
                                        aMarkThin('floor', (d.points || []).length); }
            if (which === 'listings') { aDrawListings(d);
                                        aMarkThin('listings', (d.points || []).length); }
        });
    }

    function aLoadAnalytics() {
        var key = aCollectionKey();
        if (!key) return;
        if (!aAn.statsLoaded) {
            aAn.statsLoaded = true;
            fetch(IMC_ACT_API + '?action=collection&issuer=' + encodeURIComponent(key.issuer)
                  + '&taxon=' + encodeURIComponent(key.taxon))
                .then(function (r) { return r.json(); })
                .then(function (d) { if (d && d.collection) { aRenderStats(d.collection); } })
                .catch(function () {});
        }
        aRefresh('scatter'); aRefresh('floor'); aRefresh('listings');
        var rows = document.querySelectorAll('.imc-act-windows');
        [].forEach.call(rows, function (row) {
            if (row.dataset.bound) return;
            row.dataset.bound = '1';
            row.addEventListener('click', function (e) {
                var b = e.target.closest ? e.target.closest('.imc-act-win') : null;
                if (!b) return;
                var which = row.dataset.chart;
                aAn.win[which] = parseInt(b.dataset.days, 10);
                [].forEach.call(row.querySelectorAll('.imc-act-win'), function (x) {
                    x.classList.toggle('is-on', x === b);
                });
                aRefresh(which);
            });
        });
    }

    function aToggleView() {
        var btn = document.getElementById('imc-activity-toggle');
        var list = document.getElementById('imc-activity-listview');
        var an = document.getElementById('imc-activity-analytics');
        if (!btn || !list || !an) return;
        aAn.mode = (aAn.mode === 'list') ? 'analytics' : 'list';
        list.hidden = (aAn.mode === 'analytics');
        an.hidden = (aAn.mode === 'list');
        btn.setAttribute('aria-pressed', aAn.mode === 'analytics' ? 'true' : 'false');
        btn.textContent = (aAn.mode === 'analytics') ? 'Show list' : 'Change View';
        if (aAn.mode === 'analytics') { aLoadAnalytics(); }
    }

    window.imcLoadActivity = function () {
        aLoad();
        var more = document.getElementById('imc-activity-more');
        if (more && !more.dataset.bound) {
            more.dataset.bound = '1';
            more.addEventListener('click', function () { aLoad(); });
        }
        var tog = document.getElementById('imc-activity-toggle');
        if (tog && !tog.dataset.bound) {
            tog.dataset.bound = '1';
            tog.addEventListener('click', aToggleView);
        }
    };
})();