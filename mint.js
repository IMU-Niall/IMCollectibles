/**
 * File: mint.js (XLS-24d Compliant)
 * Path: /wp-content/themes/astra/xrpl-nft-marketplace/frontend/mint.js
 * 
 * Mint wizard functionality for IMCollectibles Marketplace
 * Generates music.v1 and musicvideo.v1 compliant metadata
 * 
 * Features:
 * - PRO-compliant writer credits with roles, ownership, IPI, PRO
 * - Master recording ownership tracking
 * - Sample and AI disclosure (required)
 * - Full XLS-24d metadata generation
 * 
 * @version 3.0.0 - XLS-24d Compliant + Custom Traits
 */

(function() {
    'use strict';

    // v179: HTML escape utility for safe innerHTML usage
    // Use esc() around any user-supplied or API-sourced text in template literals
    function esc(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // v473: Convert a wall-clock datetime in a named IANA timezone to a UTC
    // "YYYY-MM-DD HH:MM:SS" string for backend storage. Brings the initial-create
    // write path (handleMint / handleAlbumMint) in line with the dashboard's
    // Edit Schedule write path (page-creator-dashboard.php:3357), which already
    // stores UTC via toISOString(). Without this, mint.js stored a NAIVE local
    // time string while every read path (PHP DateTime+UTC, JS new Date(...+'Z'))
    // assumed UTC — resulting in the launch time appearing shifted by the tz
    // offset on the public collection page and creator dashboard.
    //
    // Algorithm: short-circuit when tz is UTC (no work needed). Otherwise treat
    // the input as a fake-UTC moment, observe what wall clock that produces in
    // the target tz, derive the offset, and subtract it to get the real UTC.
    // No external dependencies; uses Intl.DateTimeFormat which is available in
    // every browser we support.
    function localTzToUTC(dateStr, timeStr, tz) {
        if (!dateStr || !timeStr) return '';
        if (!tz || tz === 'UTC') {
            // No conversion needed — the wall clock IS the UTC clock.
            return `${dateStr} ${timeStr}:00`;
        }
        try {
            const fakeUtc = new Date(`${dateStr}T${timeStr}:00Z`);
            if (isNaN(fakeUtc.getTime())) return `${dateStr} ${timeStr}:00`;
            const parts = {};
            new Intl.DateTimeFormat('en-US', {
                timeZone: tz, hour12: false,
                year: 'numeric', month: '2-digit', day: '2-digit',
                hour: '2-digit', minute: '2-digit', second: '2-digit'
            }).formatToParts(fakeUtc).forEach(p => {
                if (p.type !== 'literal') parts[p.type] = p.value;
            });
            // Intl quirk: hour can be '24' for midnight in some locales — normalize.
            if (parts.hour === '24') parts.hour = '00';
            const tzShown = `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}:${parts.second}Z`;
            const diffMs = new Date(tzShown).getTime() - fakeUtc.getTime();
            const realUtc = new Date(fakeUtc.getTime() - diffMs);
            return realUtc.toISOString().slice(0, 19).replace('T', ' ');
        } catch (e) {
            // If anything goes sideways (unknown tz, etc.), fall back to naive
            // string. Backend will still accept it; behavior is no worse than v472.
            console.warn('localTzToUTC failed, falling back to naive string:', e);
            return `${dateStr} ${timeStr}:00`;
        }
    }

    // Configuration from PHP
    const CONFIG = window.MINT_CONFIG || {};
    
    // Legacy wallet removed — all non-IMC minters use unified switch flow
    
    // VPS Media Processor
    const VPS_MEDIA = CONFIG.vpsMedia || {
        endpoint: 'https://metadata.imcollectibles.io/media-processor.php',
        apiKey: '',
    };
    
    // State
    const state = {
        currentStep: 0,
        totalSteps: 9, // 0-8
        collection: {
            isNew: true,
            existingId: null,
            existingTaxon: null,
            name: '',
            description: '',
            coverImage: null,
            coverIpfs: null,
            taxon: null
        },
        contentType: null, // 'music', 'musicVideo', 'art', or 'film'
        
        // File state - 3-file architecture
        primaryFile: null,   // Master audio/video (stored OFF-IPFS)
        previewFile: null,   // Preview audio ≤30s (stored on IPFS)
        audioFile: null,     // For music videos (separate audio)
        coverFile: null,     // Cover art (stored on IPFS)
        
        // IPFS CIDs (public files only)
        previewIpfs: null,   // Preview audio IPFS CID
        audioIpfs: null,     // Music video audio IPFS CID
        coverIpfs: null,     // Cover art IPFS CID
        tierCardCoverFile: null,  // v699: optional tiered listing-card cover (display-only)
        tierCardCoverIpfs: null,  // v699: pinned CID for the above
        
        // Master storage
        masterContentHash: null,  // SHA256 hash of master file
        masterStoragePath: null,
        coverContentHash: null,   // v377: SHA256 hash of original cover (Music/MV/Film watermarked covers)
        
        // v43: Art auto-watermark — when true, VPS generates the public preview
        artAutoWatermark: false,
        
        // Legacy compatibility (primaryIpfs now maps to previewIpfs)
        get primaryIpfs() { return this.previewIpfs; },
        set primaryIpfs(val) { this.previewIpfs = val; },
        
        // Upload state
        primaryUpload: { id: null, status: 'idle', progress: 0, step: '', result: null },
        previewUpload: { id: null, status: 'idle', progress: 0, step: '', result: null },
        audioUpload: { id: null, status: 'idle', progress: 0, step: '', result: null },
        coverUpload: { id: null, status: 'idle', progress: 0, step: '', result: null },
        
        // Rarity tiers state
        tiers: {
            enabled: false,
            items: [],   // [{id, name, editions, coverFile, coverIpfs, coverContentHash, masterContentHash, masterPoolRef, useDefaultMaster, watermarkable, traits:[{trait_type,value}]}]
            nextId: 0
        },

        // v24: Album Access NFT state
        album: {
            type: 'ep',   // 'ep' or 'album' — set by EP/Album toggle in Step 2
            tracks: []    // [{id, title, file, previewFile, previewIpfs, masterContentHash, coverIpfs, duration}]
        },

        detectedMeta: {},
        previewMeta: {},  // Preview audio detected properties
        formData: {},
        licensed: false, // Whether music is licensed/copyrighted/registered
        
        // Dynamic list counters
        counters: {
            primaryArtists: 1,
            featuredArtists: 0,
            writers: 1,
            producers: 0,
            engineers: 0,
            musicians: 0,
            publishers: 1,
            samples: 1,
            cast: 0,
            customTraits: 0,
            masterOwners: 1,
            filmCopyrightOwners: 1,
            compCopyrightOwners: 1,
            soundCopyrightOwners: 1,
            filmDirectors: 1,
            filmProducers: 1,
            filmWriters: 1,
            filmCast: 1
        },
        
        // Authorization state for mint-on-demand
        auth: {
            isChecking: false,
            isAuthorized: false,
            status: null,        // 'authorized', 'has_other_minter', 'not_authorized'
            currentMinter: null,
            pollInterval: null,
            pendingUuid: null
        }
    };
    
    // File size limits
    const FILE_LIMITS = {
        music: 500 * 1024 * 1024,      // 500MB
        musicVideo: 2 * 1024 * 1024 * 1024, // 2GB
        art: 500 * 1024 * 1024,        // 500MB (PSD, TIFF, etc.)
        film: 10 * 1024 * 1024 * 1024, // 10GB (feature films)
        album: 500 * 1024 * 1024,      // 500MB per track (same as music)
        audiobook: 1024 * 1024 * 1024, // 1GB (matches the VPS audiobook limit)
        ebook: 500 * 1024 * 1024,      // 500MB (matches the VPS ebook limit; a book PDF)
        cover: 50 * 1024 * 1024,       // 50MB
        preview: 100 * 1024 * 1024,    // 100MB (matches VPS limit)
    };

    const MAX_ALBUM_TRACKS = 20; // v24: Maximum tracks per album/EP listing
    const MAX_CHAPTERS = 100;    // M1-e2b: maximum chapters per AudioBook
    
    const CHUNK_SIZE = 2 * 1024 * 1024; // v229: 2MB chunks (was 5MB — at 10 KB/s, 5MB needs 500s which exceeds 300s timeout)

    // v43: Art file extensions that can be auto-watermarked by VPS (GD + FFmpeg)
    // PSD and RAW require ImageMagick (not installed) — artist must upload manual preview
    const WATERMARKABLE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif'];
    function isWatermarkable(filename) {
        const ext = (filename || '').split('.').pop()?.toLowerCase();
        return WATERMARKABLE_EXTS.includes(ext);
    }

    // DOM Elements cache
    const elements = {};

    // Initialize
    document.addEventListener('DOMContentLoaded', init);

    function init() {
        cacheElements();
        bindEvents();
        bindDynamicListEvents();
        updateFieldVisibility();
        
        // Check authorization before enabling wizard (if auth section exists)
        if (CONFIG.account && elements.authSection) {
            checkAuthorizationStatus();
        } else {
            // No auth section or no account - proceed normally (backward compatibility)
            updateNavigation();
            updateProgressBar();
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // v75: Pricing Mode & Multi-Currency System
    // ═══════════════════════════════════════════════════════════════════════════
    
    // State for pricing
    state.pricingMode = 'static'; // 'static' or 'dynamic'
    state.staticTokens = []; // [{currency, issuer, price, icon}]
    // PP-3: progressive pricing config. UI-selected via the Progressive mode card, but
    // state.pricingMode stays 'static' underneath (the server model + every static gate
    // in this file keep working unchanged). Drafted via its own key (v591 pattern).
    state.progressive = { enabled: false, scope: 'together', increments: {}, caps: {} };
    state.unlockables = { pool: [], ticket: null, ticketExp: 0 };
    state.dynamicTokens = []; // [{currency, issuer, discount_pct, icon}]
    state.mintLimitEnabled = false;
    state.mintLimitPerWallet = 0;
    state.pendingToken = null; // v76: Track token being added
    state.supportedTokens = []; // v86: Tokens from Token Manager

    // OE-v1: Open Edition state (spec field names)
    state.editionType        = 'fixed';  // 'fixed' | 'open'
    state.oeEndsAt           = null;     // ISO UTC string — sent as open_edition_ends_at
    state.oeDurationDays     = null;     // number — sent as open_edition_duration_days

    // Shared master media pool for tiered collections (music/mv/film).
    // Each item: {id, label, file, contentHash, previewFile, previewIpfs}
    // Art tiers each get their own pool entry (1:1 pool:tier).
    // Replaces per-tier masterFile — pool masters are uploaded once,
    // referenced by multiple tiers via tier.masterPoolRef.
    state.masterPool = [];

    // M1-e1b: AudioBook (single). Sub-type + optional back cover.
    state.audiobook = { format: null, chapters: [] };
    // M1-e2c: book pages - images or one PDF. Holder-only, never watermarked.
    // M2-b: one unified page list. Each entry is a source (image = 1 page, pdf = N).
    state.book = { pages: [] };
    state.backCoverFile = null;
    state.backCoverIpfs = null;
    state.backCoverContentHash = null;
    
    // v86: Load tokens from Token Manager API
    async function loadSupportedTokens() {
        try {
            const resp = await fetch('/wp-admin/admin-ajax.php?action=imc_get_tokens&context=mint');
            const data = await resp.json();
            
            if (data.success && data.data.tokens) {
                state.supportedTokens = data.data.tokens;
                populateTokenSelectors();
            }
        } catch (err) {
            console.error('Failed to load tokens:', err);
            // Use fallback defaults
            state.supportedTokens = [
                { ticker: 'XRP', name: 'XRP', icon: '💧', is_native: true },
                { ticker: 'XFT', name: 'XFT Token', icon: '🎵', issuer: 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt' }
            ];
            populateTokenSelectors();
        }
    }
    
    function populateTokenSelectors() {
        const staticSelector = document.getElementById('static-token-selector');
        if (staticSelector) {
            // Build options excluding XRP (always enabled separately)
            const nonXrpTokens = state.supportedTokens.filter(t => !t.is_native);
            let html = '<option value="">-- Select token --</option>';
            nonXrpTokens.forEach(t => {
                html += `<option value="${t.ticker}" data-issuer="${t.issuer || ''}" data-icon="${t.icon}" data-trustline="${t.trustline_url || ''}">${t.icon} ${t.ticker} - ${t.name}</option>`;
            });
            html += '<option value="custom">+ Custom...</option>';
            staticSelector.innerHTML = html;
        }
    }
    
    function initPricingSystem() {
        // Pricing mode toggle
        document.querySelectorAll('.pricing-mode-toggle .mode-option').forEach(label => {
            const radio = label.querySelector('input[type="radio"]');
            radio?.addEventListener('change', () => {
                switchPricingMode(radio.value);
            });
            label.addEventListener('click', () => {
                if (radio) {
                    radio.checked = true;
                    switchPricingMode(radio.value);
                }
            });
        });
        
        // v76: Static mode token selector - shows inline price input
        const staticSelector = document.getElementById('static-token-selector');
        staticSelector?.addEventListener('change', (e) => {
            const inlineInput = document.getElementById('inline-price-input');
            const customFields = document.getElementById('custom-token-inline');
            const addBtn = document.getElementById('add-static-token-btn');
            const suffix = document.getElementById('new-token-suffix');
            
            if (e.target.value === 'custom') {
                // Show custom token fields
                customFields.style.display = 'flex';
                inlineInput.style.display = 'flex';
                suffix.textContent = 'TOKEN';
                addBtn.disabled = false;
                state.pendingToken = { custom: true };
            } else if (e.target.value) {
                // Show inline price input for selected token
                const selected = e.target.options[e.target.selectedIndex];
                customFields.style.display = 'none';
                inlineInput.style.display = 'flex';
                suffix.textContent = selected.value;
                addBtn.disabled = false;
                state.pendingToken = {
                    currency: selected.value,
                    issuer: selected.dataset.issuer,
                    icon: selected.dataset.icon || '🪙'
                };
            } else {
                // Nothing selected
                inlineInput.style.display = 'none';
                customFields.style.display = 'none';
                addBtn.disabled = true;
                state.pendingToken = null;
            }
        });
        
        // Static mode: Add token button
        document.getElementById('add-static-token-btn')?.addEventListener('click', addStaticToken);
        ppDynWire();      // PP-5: the dynamic-mode inline toggle
        ppHydrateDraft(); // PP-3: restore a drafted progressive config (v591 pattern)
        
        // v76: Dynamic mode token selector - shows inline discount input
        const dynamicSelector = document.getElementById('dynamic-token-selector');
        dynamicSelector?.addEventListener('change', (e) => {
            const inlineInput = document.getElementById('inline-discount-input');
            const addBtn = document.getElementById('add-dynamic-token-btn');
            const discountInput = document.getElementById('new-token-discount');
            
            if (e.target.value) {
                const selected = e.target.options[e.target.selectedIndex];
                inlineInput.style.display = 'flex';
                addBtn.disabled = false;
                // v79: Default to 0% - let creator define discount
                discountInput.value = 0;
                state.pendingToken = {
                    currency: selected.value,
                    issuer: selected.dataset.issuer,
                    icon: selected.dataset.icon || '🪙'
                };
            } else {
                inlineInput.style.display = 'none';
                addBtn.disabled = true;
                state.pendingToken = null;
            }
        });
        
        // Dynamic mode: Add token button
        document.getElementById('add-dynamic-token-btn')?.addEventListener('click', addDynamicToken);
        
        // USD price change for dynamic mode
        document.getElementById('dynamic-usd-price')?.addEventListener('input', updateDynamicPreview);
        
        // Mint limit toggle
        document.getElementById('mint-limit-enabled')?.addEventListener('change', (e) => {
            const config = document.getElementById('mint-limit-config');
            config.style.display = e.target.checked ? 'block' : 'none';
            state.mintLimitEnabled = e.target.checked;
        });
        document.getElementById('mint-limit-per-wallet')?.addEventListener('input', (e) => {
            state.mintLimitPerWallet = parseInt(e.target.value) || 0;
        });
        
        // Sync XRP display and revenue calculator with main price
        const priceInput = document.getElementById('nft-price');
        const editionsInput = document.getElementById('nft-editions');
        
        const updateRevenue = () => {
            const price = parseFloat(priceInput?.value || 0);
            const editions = parseInt(editionsInput?.value || 1);
            
            document.getElementById('calc-price').textContent = `${price.toFixed(2)} XRP`;
            document.getElementById('calc-editions').textContent = editions;
            document.getElementById('calc-total').textContent = `${(price * editions).toFixed(2)} XRP`;
            updateDynamicPreview();
        };
        
        priceInput?.addEventListener('input', updateRevenue);
        editionsInput?.addEventListener('input', updateRevenue);
        editionsInput?.addEventListener('input', updateFeeEstimate);
    }
    
    // ═══ PP-3: PROGRESSIVE PRICING (creator) ═══════════════════════════════
    function ppCurrencies() {
        const list = [{ currency: 'XRP', label: 'XRP' }];
        (state.staticTokens || []).forEach(t => { if (t.enabled !== false) list.push({ currency: t.currency, label: t.currency }); });
        return list;
    }
    function ppRenderRows() {
        const host = document.getElementById('pp-increment-rows');
        if (!host) return;
        const curs = ppCurrencies();
        // prune config entries for removed currencies
        Object.keys(state.progressive.increments).forEach(k => { if (!curs.some(c => c.currency === k)) delete state.progressive.increments[k]; });
        Object.keys(state.progressive.caps).forEach(k => { if (!curs.some(c => c.currency === k)) delete state.progressive.caps[k]; });
        host.innerHTML = curs.map(c => `
            <div class="pp-row" style="display:flex;gap:10px;align-items:center;margin-bottom:8px;flex-wrap:wrap;">
                <span style="min-width:64px;font-weight:600;">${c.label}</span>
                <input type="number" min="0" step="any" class="pp-inc" data-cur="${c.currency}" placeholder="Increment per mint"
                    value="${state.progressive.increments[c.currency] ?? ''}" style="flex:1;min-width:130px;padding:8px 10px;border-radius:7px;background:rgba(255,255,255,.06);color:#fff;border:1px solid rgba(255,255,255,.18);">
                <input type="number" min="0" step="any" class="pp-cap" data-cur="${c.currency}" placeholder="Price cap (optional)"
                    value="${state.progressive.caps[c.currency] ?? ''}" style="flex:1;min-width:130px;padding:8px 10px;border-radius:7px;background:rgba(255,255,255,.06);color:#fff;border:1px solid rgba(255,255,255,.18);">
            </div>`).join('')
            + '<div style="font-size:.78rem;opacity:.65;">Optional — the price stops climbing at the cap.</div>';
        host.querySelectorAll('.pp-inc, .pp-cap').forEach(inp => {
            inp.addEventListener('input', () => {
                const cur = inp.dataset.cur, v = parseFloat(inp.value);
                const bag = inp.classList.contains('pp-inc') ? state.progressive.increments : state.progressive.caps;
                if (v > 0) bag[cur] = v; else delete bag[cur];
                ppUpdatePreview(); ppSaveDraft();
            });
        });
        const sc = document.getElementById('pp-scope-cards');
        if (sc) {
            sc.style.display = (curs.length >= 2) ? 'flex' : 'none';
            sc.querySelectorAll('.pp-scope-card').forEach(card => {
                card.classList.toggle('selected', card.dataset.scope === state.progressive.scope);
                card.style.borderColor = (card.dataset.scope === state.progressive.scope) ? 'rgba(124,92,255,.8)' : 'rgba(255,255,255,.18)';
                card.onclick = () => { state.progressive.scope = card.dataset.scope; ppRenderRows(); ppSaveDraft(); };
            });
        }
    }
    // PP-5: the dynamic-mode inline toggle (single USD increment; scope together by construction).
    function ppDynWire() {
        const t = document.getElementById('pp-dyn-toggle');
        if (!t || t.dataset.ppWired) return;
        t.dataset.ppWired = '1';
        const fields = document.getElementById('pp-dyn-fields');
        const sync = () => {
            if (fields) fields.style.display = t.checked ? 'block' : 'none';
            const iv = parseFloat(document.getElementById('pp-dyn-inc')?.value);
            const cv = parseFloat(document.getElementById('pp-dyn-cap')?.value);
            if (t.checked && iv > 0) state.progressive.increments['USD'] = iv; else delete state.progressive.increments['USD'];
            if (t.checked && cv > 0 && iv > 0) state.progressive.caps['USD'] = cv; else delete state.progressive.caps['USD'];
            ppDynPreview(); ppSaveDraft();
        };
        t.addEventListener('change', sync);
        document.getElementById('pp-dyn-inc')?.addEventListener('input', sync);
        document.getElementById('pp-dyn-cap')?.addEventListener('input', sync);
        document.getElementById('dynamic-usd-price')?.addEventListener('input', ppDynPreview);
    }
    function ppDynPreview() {
        const el = document.getElementById('pp-dyn-preview');
        if (!el) return;
        const t = document.getElementById('pp-dyn-toggle');
        const base = parseFloat(document.getElementById('dynamic-usd-price')?.value) || 0;
        const inc = parseFloat(state.progressive.increments['USD']) || 0;
        const cap = parseFloat(state.progressive.caps['USD']) || null;
        if (!t?.checked || !(inc > 0) || !(base > 0)) { el.style.display = 'none'; return; }
        const unit = k => { let p = base + inc * k; if (cap && p > cap) p = cap; return p; };
        const m10 = unit(9);
        el.style.display = 'block';
        el.innerHTML = `Mint #1: <strong>$${base.toFixed(2)}</strong> → Mint #10: <strong>$${m10.toFixed(2)}</strong>` + ((cap && m10 >= cap) ? ' · <strong>capped</strong>' : '');
    }
    // PP-6 P2: art auto-watermarks every common format server-side (v43 + the VPS
    // pipeline), so the public cover upload defaults HIDDEN for art -- shown only when
    // the v43 detect finds a non-watermarkable master (PSD/RAW, no ImageMagick on the
    // VPS). Every other content type keeps its cover upload untouched (it is the
    // cover's SOURCE there -- v377 watermarks it after upload).
    function ppArtCoverDefault() {
        const zone = document.getElementById('cover-upload-zone');
        const cdz  = document.getElementById('cover-dropzone');
        const cpv  = document.getElementById('cover-preview');
        const wmN  = document.getElementById('art-watermark-notice');
        const wmM  = document.getElementById('art-watermark-manual-notice');
        if (!zone) return;
        // HIDDEN-ANCESTOR FIX (25 Aug): never hide the CONTAINER -- #cover-upload-zone
        // also wraps #art-master-single-zone (the art master upload) AND
        // #tier-builder-section (the rarity boxes), so display:none here killed both
        // and dead-ended the v43 PSD/RAW manual reveal (it shows #cover-dropzone under
        // a hidden ancestor). Hide ONLY the cover machinery -- the exact scoping the
        // v43 detect already uses ("Target only the cover upload elements, NOT the
        // parent section"). The container is force-restored every call so any session
        // stuck hidden by the old code self-heals on the next visibility pass.
        zone.style.display = '';
        if (state.contentType === 'art') {
            if (state.artAutoWatermark !== false) { // default + watermarkable masters
                if (cdz) cdz.style.display = 'none';
                if (cpv) cpv.style.display = 'none';
                if (wmN) wmN.style.display = 'block';
                if (wmM) wmM.style.display = 'none';
            }
            // artAutoWatermark === false: the v43 detect's else-branch owns the manual
            // reveal (PSD/RAW) -- leave its state untouched here.
        } else {
            if (cdz) cdz.style.display = '';
            if (wmN) wmN.style.display = 'none';
            if (wmM) wmM.style.display = 'none';
        }
    }
    function ppUpdatePreview() {
        const el = document.getElementById('pp-preview');
        if (!el) return;
        const basePrice = parseFloat(document.getElementById('nft-price')?.value) || 0;
        const inc = parseFloat(state.progressive.increments['XRP']) || 0;
        const cap = parseFloat(state.progressive.caps['XRP']) || null;
        if (!state.progressive.enabled || !(inc > 0) || !(basePrice > 0)) { el.style.display = 'none'; return; }
        const unit = k => { let p = basePrice + inc * k; if (cap && p > cap) p = cap; return p; };
        const m10 = unit(9);
        el.style.display = 'block';
        el.innerHTML = `Mint #1: <strong>${basePrice} XRP</strong> → Mint #10: <strong>${m10} XRP</strong>`
            + ((cap && m10 >= cap) ? ' · <strong>capped</strong>' : '');
    }
    // v591-pattern draft (own key; saveDraft()/loadDraft() untouched). Hydrated at init
    // ONLY when a main draft exists; self-clears otherwise so a fresh wizard starts clean.
    function ppSaveDraft() {
        try { localStorage.setItem('imc_pp_draft_v1', JSON.stringify(state.progressive)); } catch (e) {}
    }
    function ppHydrateDraft() {
        try {
            if (!localStorage.getItem(DRAFT_STORAGE_KEY)) { localStorage.removeItem('imc_pp_draft_v1'); return; }
            const d = JSON.parse(localStorage.getItem('imc_pp_draft_v1') || 'null');
            if (!d || !d.enabled) return;
            state.progressive = { enabled: true, scope: (d.scope === 'individual') ? 'individual' : 'together',
                                  increments: d.increments || {}, caps: d.caps || {} };
            // PP-5: a USD-increment draft belongs to the dynamic toggle, not the static card.
            if (d.increments && parseFloat(d.increments['USD']) > 0) {
                const t = document.getElementById('pp-dyn-toggle');
                if (t) { t.checked = true; }
                const fi = document.getElementById('pp-dyn-inc'); if (fi) fi.value = d.increments['USD'];
                const fc = document.getElementById('pp-dyn-cap'); if (fc && d.caps && d.caps['USD']) fc.value = d.caps['USD'];
                const ff = document.getElementById('pp-dyn-fields'); if (ff) ff.style.display = 'block';
                ppDynPreview();
                return;
            }
            switchPricingMode('progressive');
        } catch (e) {}
    }

    function switchPricingMode(mode) {
        // PP-3: 'progressive' is a PRESENTATION-layer mode. Underneath, state.pricingMode
        // stays 'static' so every static gate in this file (trustline submit gate, discount
        // visibility, collectPricingData) keeps working unchanged -- the wire submits
        // pricing_mode=static + the progressive config, exactly what PP-0/PP-1 expect.
        // PP-4 (R8 final ruling): progressive is listing-level ALWAYS -- tiered listings
        // included ("the price climbs while rarity stays random"). Tiers are rarity pools
        // assigned at mint time, after payment; the ladder lives on the listing.
        const ppSelected = (mode === 'progressive');
        if (ppSelected) mode = 'static';
        state.progressive.enabled = ppSelected;
        state.pricingMode = mode;
        
        // Update UI -- the Progressive card highlights when selected; Static otherwise.
        const ppHighlight = state.progressive.enabled ? 'progressive' : mode;
        document.querySelectorAll('.pricing-mode-toggle .mode-option').forEach(label => {
            label.classList.toggle('selected', label.dataset.mode === ppHighlight);
        });
        const ppPanel = document.getElementById('progressive-pricing-panel');
        if (ppPanel) ppPanel.style.display = state.progressive.enabled ? 'block' : 'none';
        if (state.progressive.enabled) { ppRenderRows(); ppUpdatePreview(); }
        ppSaveDraft();
        
        const staticSection = document.getElementById('static-pricing-section');
        const dynamicSection = document.getElementById('dynamic-pricing-section');
        
        if (mode === 'dynamic') {
            staticSection.style.display = 'none';
            dynamicSection.style.display = 'block';
            updateDynamicPreview();
        } else {
            // v660: 'static' and 'pwyw' both use the static price inputs; for PWYW those
            // amounts are per-token FLOORS (minimums), surfaced via the pwyw note below.
            staticSection.style.display = 'block';
            dynamicSection.style.display = 'none';
        }
        const pwywNote = document.getElementById('pwyw-note');
        if (pwywNote) pwywNote.style.display = (mode === 'pwyw') ? 'block' : 'none';
        // v664: FREE MINT -- show the static section (for its layout) but hide every price
        // control, since a free listing carries no prices at all.
        const freeNote = document.getElementById('free-note');
        if (freeNote) freeNote.style.display = (mode === 'free') ? 'block' : 'none';
        // Selectors match page-mint.php: the XRP price box, the add-token UI, and the
        // added-tokens list -- i.e. every price control in the static section.
        if (staticSection) {
            staticSection.querySelectorAll('.token-price-box, .add-token-section, #static-tokens-list')
                .forEach(el => { el.style.display = (mode === 'free') ? 'none' : ''; });
        }
        
        // Update revenue display for dynamic mode
        const calcPrice = document.getElementById('calc-price');
        if (mode === 'dynamic') {
            const usdPrice = parseFloat(document.getElementById('dynamic-usd-price')?.value) || 0;
            calcPrice.textContent = `$${usdPrice.toFixed(2)} USD`;
        } else {
            const xrpPrice = parseFloat(document.getElementById('nft-price')?.value) || 0;
            calcPrice.textContent = `${xrpPrice.toFixed(2)} XRP`;
        }

        // v612: refresh the Review & Mint preview card to reflect the new pricing mode.
        if (typeof updatePreview === 'function') updatePreview();
    }
    
    // v76: No more prompt() - uses inline input
    function addStaticToken() {
        if (!state.pendingToken) return;
        
        const priceInput = document.getElementById('new-token-price');
        const price = parseFloat(priceInput?.value) || 0;
        
        if (price <= 0) {
            showToast('Please enter a valid price', 'error');
            return;
        }
        
        let tokenData;
        
        if (state.pendingToken.custom) {
            // Custom token
            const code = document.getElementById('static-custom-code')?.value?.trim().toUpperCase();
            const issuer = document.getElementById('static-custom-issuer')?.value?.trim();
            
            if (!code || !issuer) {
                showToast('Please fill in currency code and issuer', 'error');
                return;
            }
            
            if (!/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/.test(issuer)) {
                showToast('Invalid issuer address format', 'error');
                return;
            }
            
            if (state.staticTokens.some(t => t.currency === code)) {
                showToast('Token already added', 'error');
                return;
            }
            
            tokenData = { currency: code, issuer, price, icon: '🪙', enabled: true };
            
            // Clear custom fields
            document.getElementById('static-custom-code').value = '';
            document.getElementById('static-custom-issuer').value = '';
        } else {
            // Known token
            const { currency, issuer, icon } = state.pendingToken;
            
            if (state.staticTokens.some(t => t.currency === currency)) {
                showToast('Token already added', 'error');
                return;
            }
            
            tokenData = { currency, issuer, price, icon, enabled: true };
        }
        
        state.staticTokens.push(tokenData);
        renderStaticTokensList();
        if (state.progressive.enabled) { ppRenderRows(); ppUpdatePreview(); }
        // v711 (Step I): verify the ARTIST can actually receive this token. Fire-and-forget --
        // it must never delay or block the add itself; the verdict lands on the token and the
        // list re-renders when it returns. The hard gate happens at submit.
        imcCheckArtistTrustline(tokenData).then(renderStaticTokensList);
        
        // Reset UI
        document.getElementById('static-token-selector').selectedIndex = 0;
        document.getElementById('inline-price-input').style.display = 'none';
        document.getElementById('custom-token-inline').style.display = 'none';
        document.getElementById('add-static-token-btn').disabled = true;
        priceInput.value = '';
        state.pendingToken = null;
        
        showToast(`${tokenData.currency} added`, 'success');
    }
    
    function addCustomStaticToken() {
        // Redirect to addStaticToken which handles custom tokens now
        addStaticToken();
    }
    
    function renderStaticTokensList() {
        const list = document.getElementById('static-tokens-list');
        if (!list) return;
        
        list.innerHTML = state.staticTokens.map((t, idx) => `
            <div class="token-price-box added-token" data-idx="${idx}">
                <div class="token-info">
                    <span class="token-icon">${t.icon}</span>
                    <span class="token-name">${t.currency}</span>
                </div>
                <div class="token-price-input">
                    <input type="number" value="${t.price}" min="0" step="0.000001" 
                           onchange="window.updateStaticTokenPrice(${idx}, this.value)">
                    <span>${t.currency}</span>
                </div>
                <button type="button" class="btn-remove" onclick="window.removeStaticToken(${idx})">×</button>
            </div>
        `).join('');
        // v711 (Step I): append a warning for any token this artist cannot receive.
        // Rendered AFTER the rows so the existing template is untouched.
        state.staticTokens.forEach((t, idx) => {
            if (t.trustlineOk !== false) return;
            const warn = document.createElement('div');
            warn.className = 'imc-artist-trustline-warning';
            warn.style.cssText = 'margin:6px 0 10px;padding:10px 12px;border:1px solid rgba(212,175,55,.55);'
                + 'border-radius:8px;background:rgba(212,175,55,.08);font-size:.85rem;line-height:1.45;';
            warn.innerHTML = '<strong>Trustline needed for ' + t.currency + '</strong><br>'
                + 'You cannot receive ' + t.currency + ' yet, so buyers paying in it would fail. '
                + 'Set the trustline before publishing.';
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = 'Set ' + t.currency + ' Trustline';
            btn.style.cssText = 'margin-top:8px;padding:6px 14px;border-radius:6px;cursor:pointer;'
                + 'border:1px solid rgba(212,175,55,.7);background:rgba(212,175,55,.15);color:#d4af37;font-weight:600;';
            btn.addEventListener('click', function () {
                if (!window.imcTrustline) return;
                window.imcTrustline.openTokenActions(t.currency, t.issuer, { account: imcArtistAccount() });
            });
            warn.appendChild(btn);
            list.appendChild(warn);
        });
    }

    // ── v711 (Step I): artist-side trustline helpers ──────────────────────────
    // Buyers pay the artist DIRECTLY, so a token the artist cannot receive makes every
    // purchase fail on-chain. These verify it before the listing can be published.
    function imcArtistAccount() {
        if (window.xrplAccount) return window.xrplAccount;
        if (CONFIG && CONFIG.account) return CONFIG.account;
        const m = document.cookie.match(/(?:^|;\s*)xrpl_account=([^;]+)/);
        return m ? decodeURIComponent(m[1]) : '';
    }

    // Resolves to true when the artist CAN receive the token, false only on a definitive
    // "no trustline" answer. Any infrastructure problem (checker absent, no account,
    // network error) resolves TRUE -- an artist must never be blocked by our outage.
    async function imcCheckArtistTrustline(t) {
        if (!t || !t.currency || t.currency === 'XRP') { return true; }
        if (!window.imcTrustline || !window.imcTrustline.checkTrustline) { t.trustlineOk = true; return true; }
        const account = imcArtistAccount();
        if (!account) { t.trustlineOk = true; return true; }
        try {
            if (window.imcTrustline.clearCache) { window.imcTrustline.clearCache(); }
            const r = await window.imcTrustline.checkTrustline(account, t.currency, t.issuer || '');
            t.trustlineOk = !!(r && r.has_trustline);
        } catch (e) {
            t.trustlineOk = true; // fail open on a network/server error
        }
        return t.trustlineOk;
    }

    // The SUBMIT GATE. Only static pricing can carry tokens -- free returns [] and dynamic is
    // XRP-only (collectPricingData), and state.dynamicTokens is never submitted -- so those
    // modes are structurally exempt and can never be blocked here.
    async function imcVerifyArtistTrustlines() {
        if ((state.pricingMode || 'static') !== 'static') return true;
        if (!Array.isArray(state.staticTokens) || !state.staticTokens.length) return true;
        const missing = [];
        for (const t of state.staticTokens) {
            if (t.enabled === false) continue;
            const ok = await imcCheckArtistTrustline(t);
            if (!ok) missing.push(t);
        }
        renderStaticTokensList();
        if (state.progressive.enabled) { ppRenderRows(); ppUpdatePreview(); }
        if (!missing.length) return true;
        const names = missing.map(t => t.currency).join(', ');
        showToast('Set a trustline for ' + names + ' before publishing, or remove the token', 'error');
        if (window.imcTrustline && window.imcTrustline.openTokenActions) {
            window.imcTrustline.openTokenActions(missing[0].currency, missing[0].issuer, { account: imcArtistAccount() });
        }
        return false;
    }

    window.updateStaticTokenPrice = (idx, value) => {
        if (state.staticTokens[idx]) {
            state.staticTokens[idx].price = parseFloat(value) || 0;
        }
    };
    
    window.removeStaticToken = (idx) => {
        state.staticTokens.splice(idx, 1);
        renderStaticTokensList();
        if (state.progressive.enabled) { ppRenderRows(); ppUpdatePreview(); }
    };
    
    // v76: Uses inline discount input instead of selecting from dropdown
    function addDynamicToken() {
        if (!state.pendingToken) return;
        
        const discountInput = document.getElementById('new-token-discount');
        const discount = parseInt(discountInput?.value) || 0;
        
        const { currency, issuer, icon } = state.pendingToken;
        
        // Check if already added
        if (state.dynamicTokens.some(t => t.currency === currency)) {
            showToast('Token already added', 'error');
            return;
        }
        
        state.dynamicTokens.push({ currency, issuer, icon, discount_pct: discount, enabled: true });
        renderDynamicTokensList();
        updateDynamicPreview();
        
        // Reset UI
        document.getElementById('dynamic-token-selector').selectedIndex = 0;
        document.getElementById('inline-discount-input').style.display = 'none';
        document.getElementById('add-dynamic-token-btn').disabled = true;
        discountInput.value = '10';
        state.pendingToken = null;
        
        showToast(`${currency} added with ${discount}% discount`, 'success');
    }
    
    function renderDynamicTokensList() {
        const list = document.getElementById('dynamic-tokens-list');
        if (!list) return;
        
        list.innerHTML = state.dynamicTokens.map((t, idx) => `
            <div class="dynamic-token-row added-token" data-idx="${idx}">
                <div class="token-info">
                    <input type="checkbox" checked onchange="window.toggleDynamicToken(${idx}, this.checked)">
                    <span class="token-icon">${t.icon}</span>
                    <span class="token-name">${t.currency}</span>
                </div>
                <div class="token-discount">
                    <label>Discount:</label>
                    <input type="number" value="${t.discount_pct}" min="0" max="50" step="1" 
                           onchange="window.updateDynamicDiscount(${idx}, this.value)">
                    <span>%</span>
                </div>
                <button type="button" class="btn-remove" onclick="window.removeDynamicToken(${idx})">×</button>
            </div>
        `).join('');
    }
    
    window.toggleDynamicToken = (idx, enabled) => {
        if (state.dynamicTokens[idx]) {
            state.dynamicTokens[idx].enabled = enabled;
            updateDynamicPreview();
        }
    };
    
    window.updateDynamicDiscount = (idx, value) => {
        if (state.dynamicTokens[idx]) {
            state.dynamicTokens[idx].discount_pct = parseInt(value) || 0;
            updateDynamicPreview();
        }
    };
    
    window.removeDynamicToken = (idx) => {
        state.dynamicTokens.splice(idx, 1);
        renderDynamicTokensList();
        updateDynamicPreview();
    };
    
    // v77: Cache for price oracle to avoid excessive API calls
    let priceOracleCache = { prices: null, timestamp: 0 };
    const PRICE_CACHE_TTL = 30000; // 30 seconds
    
    async function fetchLivePrices() {
        const now = Date.now();
        if (priceOracleCache.prices && (now - priceOracleCache.timestamp) < PRICE_CACHE_TTL) {
            return priceOracleCache.prices;
        }
        
        try {
            // Call the price oracle REST API
            const response = await fetch('/wp-json/imc-price/v1/prices');
            if (!response.ok) throw new Error('Price API unavailable');
            
            const data = await response.json();
            const prices = {};
            
            // API returns { prices: { XRP: { usd: 1.50, ... }, XFT: { usd: 0.0005 } }, fetched_at: ... }
            if (data.prices && typeof data.prices === 'object') {
                Object.entries(data.prices).forEach(([ticker, info]) => {
                    if (info && info.usd !== undefined) {
                        prices[ticker] = parseFloat(info.usd);
                    }
                });
            }
            
            // Cache the result
            if (Object.keys(prices).length > 0) {
                priceOracleCache = { prices, timestamp: now };
                console.log('Price oracle updated:', prices);
            }
            
            return prices;
        } catch (err) {
            console.warn('Price oracle fetch failed, using cached or fallback:', err);
            // Return cached prices if available, otherwise fallback
            if (priceOracleCache.prices) {
                return priceOracleCache.prices;
            }
            // v78: Return null to indicate we couldn't fetch prices
            // Let the caller decide what to do
            console.error('Price oracle unavailable and no cache');
            return null;
        }
    }
    
    async function updateDynamicPreview() {
        const usdInput = document.getElementById('dynamic-usd-price');
        const usdPrice = parseFloat(usdInput?.value) || 0;
        
        // v193: Live enforcement of $5,000 USD cap
        if (usdPrice > 5000) {
            const xrpCalc = document.getElementById('dynamic-xrp-calc');
            if (xrpCalc) xrpCalc.innerHTML = '<span style="color:#ef4444;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> Maximum price is $5,000 USD</span>';
            if (usdInput) usdInput.style.borderColor = '#ef4444';
            return;
        } else if (usdInput) {
            usdInput.style.borderColor = '';
        }
        
        if (usdPrice <= 0) return;
        
        const xrpCalc = document.getElementById('dynamic-xrp-calc');
        
        // v84: Show calculating state
        if (xrpCalc) xrpCalc.innerHTML = '<span style="opacity:0.7;">⏳ Fetching live rate...</span>';
        
        // Fetch live XRP price from oracle
        const rates = await fetchLivePrices();
        
        if (!rates || !rates['XRP']) {
            if (xrpCalc) xrpCalc.innerHTML = '<svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> Unable to fetch XRP price';
            return;
        }
        
        // v84: Dynamic mode = XRP only
        const xrpRate = rates['XRP'];
        const xrpAmount = (usdPrice / xrpRate).toFixed(4);
        
        // Update XRP preview
        if (xrpCalc) {
            xrpCalc.innerHTML = `≈ <strong>${xrpAmount}</strong> XRP <span style="opacity:0.6;">(@$${xrpRate.toFixed(4)})</span>`;
        }
        
        // Update revenue calculator for dynamic mode
        const calcPrice = document.getElementById('calc-price');
        const calcTotal = document.getElementById('calc-total');
        const editions = parseInt(document.getElementById('nft-editions')?.value) || 1;
        
        if (state.pricingMode === 'dynamic' && calcPrice) {
            calcPrice.textContent = `$${usdPrice.toFixed(2)} USD (~${xrpAmount} XRP)`;
        }
        if (state.pricingMode === 'dynamic' && calcTotal) {
            calcTotal.textContent = `$${(usdPrice * editions).toFixed(2)} USD`;
        }
    }
    
    // v86: Load tokens first, then init pricing system
    function initCurrencySelection() {
        loadSupportedTokens().then(() => {
            initPricingSystem();
            wirePreviewLiveRefresh(); // v612: live preview refresh on step-9 control edits
        });
    }
    
    // v86: Check trustline using Token Manager API
    async function checkCurrencyTrustline(tokenId) {
        if (!CONFIG.account) return { has_trustline: false };
        
        // Use trustline checker utility if available
        if (window.imcTrustline) {
            return await window.imcTrustline.checkTrustline(CONFIG.account, tokenId);
        }
        
        // Fallback: find issuer from loaded tokens or defaults
        let issuer = null;
        const token = state.supportedTokens?.find(t => t.ticker === tokenId.toUpperCase());
        if (token) {
            issuer = token.issuer;
        } else {
            const knownIssuers = {
                'XFT': 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt',
                'RLUSD': 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De',
                'SOLO': 'rsoLo2S1kiGeCcn6hCUXVrCpGMWLrRrLZz',
                'SCHMECKLES': 'rBzpCpREWWTRtwU2kHgMbBNFYPNJuHLJP1',
                'XMEME': 'rNvHB3JNM9xgSByfJAjYaYUDp9t24SWLAP'
            };
            issuer = knownIssuers[tokenId.toUpperCase()];
        }
        
        if (!issuer) return { has_trustline: false };
        
        try {
            const response = await fetch(`/wp-admin/admin-ajax.php?action=imc_check_trustline&account=${CONFIG.account}&ticker=${tokenId.toUpperCase()}`);
            const data = await response.json();
            return data.success ? data.data : { has_trustline: false };
        } catch (err) {
            return { has_trustline: false };
        }
    }
    
    // Legacy addCustomCurrency - kept for compatibility
    function addCustomCurrency() {
        // Redirect to new static token system
        addCustomStaticToken();
    }


    // ═══════════════════════════════════════════════════════════════════════════
    // v71: Allowlist UI Initialization
    // ═══════════════════════════════════════════════════════════════════════════
    
    function initAllowlistUI() {
        const enableCheckbox = document.getElementById('enable-allowlist');
        const configSection = document.getElementById('allowlist-config');
        const typeSelect = document.getElementById('allowlist-type');
        const walletsTextarea = document.getElementById('allowlist-wallets');
        const csvInput = document.getElementById('allowlist-csv');
        
        // Toggle config visibility
        enableCheckbox?.addEventListener('change', () => {
            if (configSection) {
                configSection.style.display = enableCheckbox.checked ? 'block' : 'none';
            }
        });
        
        // Type-specific config visibility
        // v729 (A3): also toggles the discount-mode groups and relabels the max-mint
        // field for Holder Discount, where it acts as the DEFAULT amount for any
        // wallet row without its own — so quantities can never be silently absent.
        function updateAllowlistTypeUI() {
            const type = typeSelect.value;
            document.getElementById('allowlist-early-config').style.display = 
                type === 'early_access' ? 'block' : 'none';
            document.getElementById('allowlist-discount-config').style.display = 
                (type === 'discount' || type === 'discount_limited') ? 'block' : 'none';
            // v381: Show CSV quantity hint only for types that use per-wallet quantities
            const csvHint = document.getElementById('allowlist-csv-hint');
            if (csvHint) csvHint.style.display = (type === 'discount_limited' || type === 'limit_override') ? 'block' : 'none';
            const mmLabel = document.getElementById('allowlist-max-mint-label');
            const mmHint = document.getElementById('allowlist-max-mint-hint');
            if (mmLabel && mmHint) {
                if (type === 'discount_limited') {
                    mmLabel.textContent = 'Default Amount Per Wallet';
                    mmHint.textContent = 'Used for any wallet without its own amount (rWallet...,amount)';
                } else {
                    mmLabel.textContent = 'Per-Wallet Mint Limit';
                    mmHint.textContent = 'Maximum editions each allowlisted wallet can mint';
                }
            }
            updateDiscountModeUI();
        }
        function updateDiscountModeUI() {
            const modeSel = document.getElementById('allowlist-discount-mode');
            const pctGroup = document.getElementById('allowlist-discount-percent-group');
            const priceGroup = document.getElementById('allowlist-discount-price-group');
            if (!modeSel || !pctGroup || !priceGroup) return;
            const mode = modeSel.value;
            pctGroup.style.display = mode === 'percent' ? 'block' : 'none';
            priceGroup.style.display = mode === 'price' ? 'block' : 'none';
            updateAllowlistMatrix();
        }

        // v731 (A4b): per-token pricing rows, built LIVE from the current token config
        // (state.staticTokens + the XRP price field). Rebuilt on every type/mode change so
        // the matrix always mirrors what the listing will actually accept. Hidden when the
        // feature flag is off, on free mode (free is free in every currency), and when no
        // tokens are configured.
        window.updateAllowlistMatrix = function() {
            const wrap = document.getElementById('allowlist-matrix-wrap');
            if (!wrap || wrap.dataset.enabled !== '1') return;
            const type = document.getElementById('allowlist-type')?.value;
            const mode = document.getElementById('allowlist-discount-mode')?.value || 'percent';
            const isDiscount = (type === 'discount' || type === 'discount_limited');
            const tokens = (state.staticTokens || []).filter(t => t.currency);
            // v732: never hide silently — discount lists always show the section, with an
            // explicit XRP line and an XRP-only explainer when no tokens are configured.
            const show = isDiscount && mode !== 'free' && state.pricingMode === 'static';
            wrap.style.display = show ? 'block' : 'none';
            if (!show) return;
            const rows = document.getElementById('allowlist-matrix-rows');
            if (!rows) return;
            const xrpLine = '<div style="display:flex;gap:8px;align-items:center;margin-top:6px;opacity:0.75;font-size:0.85em;">XRP → base discount above</div>';
            if (tokens.length === 0) {
                rows.innerHTML = xrpLine + '<div style="opacity:0.75;font-size:0.85em;margin-top:4px;">Only XRP is priced so far — add token pricing above to unlock per-token rules.</div>';
                return;
            }
            const existing = {};
            rows.querySelectorAll('.al-matrix-row').forEach(r => {
                existing[r.dataset.currency] = {
                    on: r.querySelector('.al-mx-on')?.checked,
                    mode: r.querySelector('.al-mx-mode')?.value,
                    value: r.querySelector('.al-mx-value')?.value
                };
            });
            rows.innerHTML = xrpLine + tokens.map(t => {
                const prev = existing[t.currency] || {};
                return `
                <div class="al-matrix-row" data-currency="${t.currency}" data-issuer="${t.issuer || ''}" data-price="${t.price || 0}" style="display:flex;gap:8px;align-items:center;margin-top:6px;">
                    <label style="display:flex;align-items:center;gap:4px;min-width:90px;"><input type="checkbox" class="al-mx-on" ${prev.on ? 'checked' : ''} onchange="updateAllowlistMatrixPreview(this)"> ${t.currency}</label>
                    <select class="al-mx-mode form-select" style="width:auto;" onchange="updateAllowlistMatrixPreview(this)">
                        <option value="percent" ${prev.mode !== 'price' ? 'selected' : ''}>% off</option>
                        <option value="price" ${prev.mode === 'price' ? 'selected' : ''}>fixed price</option>
                    </select>
                    <input type="number" class="al-mx-value" style="width:130px;min-height:38px;padding:6px 8px;box-sizing:border-box;" min="0.000001" step="0.000001" placeholder="value" value="${prev.value || ''}" oninput="updateAllowlistMatrixPreview(this)">
                    <span class="al-mx-preview field-hint" style="margin:0;"></span>
                </div>`;
            }).join('');
            rows.querySelectorAll('.al-mx-on').forEach(el => updateAllowlistMatrixPreview(el));
        };

        window.updateAllowlistMatrixPreview = function(el) {
            const row = el.closest('.al-matrix-row');
            if (!row) return;
            const on = row.querySelector('.al-mx-on')?.checked;
            const mode = row.querySelector('.al-mx-mode')?.value;
            const v = parseFloat(row.querySelector('.al-mx-value')?.value);
            const price = parseFloat(row.dataset.price) || 0;
            const out = row.querySelector('.al-mx-preview');
            if (!out) return;
            if (!on || !Number.isFinite(v)) { out.textContent = ''; return; }
            if (mode === 'percent') {
                out.textContent = (v >= 1 && v <= 99 && price > 0) ? `→ pays ${(price * (1 - v / 100)).toFixed(6).replace(/\.?0+$/, '')} ${row.dataset.currency}` : (v < 1 || v > 99 ? '1-99% only (use Free claim for free)' : '');
            } else {
                out.textContent = v > 0 ? `→ pays ${v} ${row.dataset.currency}` : 'must be > 0 (use Free claim for free)';
            }
        };
        typeSelect?.addEventListener('change', updateAllowlistTypeUI);
        document.getElementById('allowlist-discount-mode')?.addEventListener('change', updateDiscountModeUI);
        
        // Wallet address validation and counting
        walletsTextarea?.addEventListener('input', () => updateAllowlistPreview());
        
        // CSV upload
        csvInput?.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (!file) return;
            
            const reader = new FileReader();
            reader.onload = (event) => {
                const content = event.target.result;
                // v381: Smart column detection — scan ALL columns for r-address
                const lines = content.split(/[\r\n]+/).filter(l => l.trim());
                const walletRegex = /^r[1-9A-HJ-NP-Za-km-z]{25,34}$/;
                const wallets = [];
                
                for (const line of lines) {
                    const parts = line.split(',').map(p => p.trim().replace(/^["']|["']$/g, ''));
                    // v729 (A3): the reader previously kept ONLY the wallet, silently
                    // discarding the quantity column the UI hint promised (B3). Now the
                    // nearest positive number to the wallet column rides along as
                    // "wallet,amount" — the same expand-outward rule as the server.
                    let walletIdx = -1;
                    for (let i = 0; i < parts.length; i++) {
                        if (walletRegex.test(parts[i])) { walletIdx = i; break; }
                    }
                    if (walletIdx === -1) continue;
                    let qty = null;
                    const maxDist = Math.max(walletIdx, parts.length - 1 - walletIdx);
                    for (let d = 1; d <= maxDist && qty === null; d++) {
                        for (const ci of [walletIdx - d, walletIdx + d]) {
                            if (ci >= 0 && ci < parts.length && /^[0-9]+$/.test(parts[ci]) && parseInt(parts[ci], 10) > 0) {
                                qty = Math.min(99999, parseInt(parts[ci], 10));
                                break;
                            }
                        }
                    }
                    wallets.push(qty !== null ? parts[walletIdx] + ',' + qty : parts[walletIdx]);
                }
                
                if (wallets.length > 0) {
                    const existing = walletsTextarea.value.trim();
                    walletsTextarea.value = existing 
                        ? existing + '\n' + wallets.join('\n')
                        : wallets.join('\n');
                    updateAllowlistPreview();
                } else {
                    imcToast('No valid wallet addresses found in CSV');
                }
            };
            reader.readAsText(file);
        });
    }
    
    function updateAllowlistPreview() {
        const walletsText = document.getElementById('allowlist-wallets')?.value || '';
        const preview = document.getElementById('allowlist-preview');
        const countEl = document.getElementById('allowlist-count');
        
        const wallets = walletsText
            .split(/[\n,]+/)
            .map(w => w.trim())
            .filter(w => /^r[1-9A-HJ-NP-Za-km-z]{25,34}$/.test(w));
        
        // v729 (A3): also count per-wallet amounts so creators can verify them at a glance
        let withAmounts = 0;
        for (const line of walletsText.split(/[\r\n]+/)) {
            const parts = line.split(',').map(p => p.trim());
            if (parts.some(p => /^r[1-9A-HJ-NP-Za-km-z]{25,34}$/.test(p))
                && parts.some(p => /^[0-9]+$/.test(p) && parseInt(p, 10) > 0)) withAmounts++;
        }
        if (preview && countEl) {
            countEl.textContent = withAmounts > 0
                ? wallets.length + ' (' + withAmounts + ' with amounts)'
                : wallets.length;
            preview.style.display = wallets.length > 0 ? 'block' : 'none';
        }
    }

    function cacheElements() {
        elements.wizard = document.getElementById('mint-wizard');
        elements.walletCheck = document.getElementById('wallet-check');
        elements.connectBtn = document.getElementById('connect-wallet-btn');
        elements.prevBtn = document.getElementById('wizard-prev');
        elements.nextBtn = document.getElementById('wizard-next');
        elements.mintBtn = document.getElementById('wizard-mint');
        elements.loadingOverlay = document.getElementById('mint-loading-overlay');
        elements.loadingText = document.getElementById('mint-loading-text');
        elements.successModal = document.getElementById('mint-success-modal');
        elements.steps = document.querySelectorAll('.wizard-step');
        elements.progressSteps = document.querySelectorAll('.progress-step');
        elements.contentTypeCards = document.querySelectorAll('.content-type-card');
        elements.contentGroupCards = document.querySelectorAll('.content-group-card');   // M3
        
        // File inputs
        elements.primaryInput = document.getElementById('primary-file-input');
        elements.previewInput = document.getElementById('preview-file-input');
        elements.audioInput = document.getElementById('audio-file-input');
        elements.coverInput = document.getElementById('cover-file-input');
        elements.backCoverInput = document.getElementById('back-cover-file-input');
        var _bcRemove = document.getElementById('back-cover-remove');
        if (_bcRemove) {
            _bcRemove.addEventListener('click', function () {
                state.backCoverFile = null;
                state.backCoverIpfs = null;
                state.backCoverContentHash = null;
                if (elements.backCoverInput) elements.backCoverInput.value = '';
                var dz = document.getElementById('back-cover-dropzone');
                var pv = document.getElementById('back-cover-preview');
                if (dz) dz.style.display = '';
                if (pv) pv.style.display = 'none';
            });
        }
        bindAudiobookSubtype();
        bindBookPages();
        if (elements.backCoverInput) {
            elements.backCoverInput.addEventListener('change', function (e) {
                var f = e.target.files && e.target.files[0];
                if (!f) return;
                if (!f.type || !f.type.startsWith('image/')) { imcToast('Back cover must be an image.'); return; }
                if (f.size > FILE_LIMITS.cover) { imcToast('Back cover is too large.'); return; }
                state.backCoverFile = f;
                state.backCoverIpfs = null;
                state.backCoverContentHash = null;
                var nm = document.getElementById('back-cover-filename');
                if (nm) nm.textContent = f.name;
                var sz = document.getElementById('back-cover-filesize');
                if (sz) sz.textContent = (f.size / 1024).toFixed(1) + ' KB';
                var dz = document.getElementById('back-cover-dropzone');
                var pv = document.getElementById('back-cover-preview');
                if (dz) dz.style.display = 'none';
                if (pv) pv.style.display = '';
            });
        }
        
        // Step 0 elements (Collection - now mandatory)
        elements.collectionTabs = document.querySelectorAll('.collection-tab');
        elements.collectionTabContents = document.querySelectorAll('.collection-tab-content');
        elements.backToCollectionBtn = document.getElementById('back-to-collection');
        elements.collectionCoverInput = document.getElementById('collection-cover-input');
        elements.collectionCoverDropzone = document.getElementById('collection-cover-dropzone');
        elements.tierCardCoverInput = document.getElementById('tier-card-cover-input');
        elements.tierCardCoverDropzone = document.getElementById('tier-card-cover-dropzone');
        
        // Rights toggles
        elements.selfPublished = document.getElementById('self-published');
        elements.publishersContainer = document.getElementById('publishers-container');
        elements.worldwideDistribution = document.getElementById('worldwide-distribution');
        elements.territoryExclusions = document.getElementById('territory-exclusions');
        
        // Authorization elements (for mint-on-demand)
        elements.authSection = document.getElementById('auth-minter-section');
        elements.authChecking = document.getElementById('auth-checking');
        elements.authSuccess = document.getElementById('auth-success');
        elements.authConflict = document.getElementById('auth-conflict');
        elements.authRequired = document.getElementById('auth-required');
        elements.authOtherMinter = document.getElementById('other-minter-address');
        elements.authBtnAuthorize = document.getElementById('btn-authorize');
        elements.authBtnUpdate = document.getElementById('btn-update-auth');
        elements.conflictTitle = document.getElementById('conflict-title');
        elements.conflictMessage = document.getElementById('conflict-message');
        elements.switchWarning = document.getElementById('switch-warning');
        elements.authModal = document.getElementById('auth-xumm-modal');
        elements.authModalClose = document.getElementById('auth-modal-close');
        elements.authQrCode = document.getElementById('auth-qr-code');
        elements.authDeepLink = document.getElementById('auth-deep-link');
        elements.authPollStatus = document.getElementById('auth-poll-status');
    }

    function bindEvents() {
        // Collection tabs (Step 0 — now the first step)
        elements.collectionTabs?.forEach(tab => {
            tab.addEventListener('click', () => switchCollectionTab(tab.dataset.tab));
        });
        
        // Navigation
        elements.prevBtn?.addEventListener('click', prevStep);
        elements.nextBtn?.addEventListener('click', nextStep);
        elements.mintBtn?.addEventListener('click', handleMint);
        
        // Save as Draft button
        document.getElementById('wizard-save-draft')?.addEventListener('click', saveDraft);
        
        // M3: group cards reveal their members. A group NEVER sets state.contentType -
        // only a leaf type card can - except a single-member group (Art, Film), where the
        // group card carries data-single-type and selects it outright.
        elements.contentGroupCards?.forEach(card => {
            card.addEventListener('click', () => {
                if (card.dataset.comingSoon === 'true') return;
                var gkey = card.dataset.group;
                if (!gkey) return;
                openContentGroup(gkey);
                var single = card.dataset.singleType;
                if (single) { selectContentType(single); }
                else if (state.contentType) {
                    // switching groups clears a leaf chosen in the previous one
                    var owner = groupForType(state.contentType);
                    if (owner !== gkey) { clearContentType(); }
                }
            });
        });

        // Content type selection
        elements.contentTypeCards?.forEach(card => {
            card.addEventListener('click', () => {
                // v24-CS: Coming Soon cards are non-clickable for regular users.
                // CSS pointer-events:none is the primary block; this is the JS belt.
                if (card.dataset.comingSoon === 'true') return;
                selectContentType(card.dataset.type);
            });
        });
        
        // Licensed/Registered toggle (Music)
        document.querySelectorAll('input[name="music_licensed"]').forEach(radio => {
            radio.addEventListener('change', (e) => {
                state.licensed = e.target.value === 'yes';
                updateFieldVisibility(); // C-α: canonical lane engine
            });
        });
        
        // Licensed/Registered toggle (Film)
        document.querySelectorAll('input[name="film_licensed"]').forEach(radio => {
            radio.addEventListener('change', (e) => {
                state.licensed = e.target.value === 'yes';
                updateFieldVisibility(); // C-α: canonical lane engine
            });
        });

        // v24: EP/Album type toggle — updates state.album.type and fee estimate
        document.querySelectorAll('input[name="album_type"]').forEach(radio => {
            radio.addEventListener('change', (e) => {
                state.album.type = e.target.value; // 'ep' or 'album'
                updateFeeEstimate();
            });
        });

        // v24: Licensed toggle (Album) — shows Step 6 (Rights) only (NodeList indices 6+7)
        document.querySelectorAll('input[name="album_licensed"]').forEach(radio => {
            radio.addEventListener('change', (e) => {
                state.licensed = e.target.value === 'yes';
                updateFieldVisibility(); // C-α: canonical lane engine
            });
        });
        
        // Explicit content flag card — toggle checkbox on card click
        const explicitCard = document.querySelector('.explicit-flag');
        if (explicitCard) {
            explicitCard.addEventListener('click', (e) => {
                // Don't double-toggle if clicking the actual checkbox area
                if (e.target.tagName === 'INPUT') return;
                const cb = document.getElementById('nft-explicit');
                if (cb) {
                    cb.checked = !cb.checked;
                    // Update visual checkmark
                    const mark = explicitCard.querySelector('.flag-checkmark');
                    if (mark) mark.textContent = cb.checked ? '✓' : '';
                }
            });
            explicitCard.style.cursor = 'pointer';
        }
        
        // File uploads
        elements.primaryInput?.addEventListener('change', (e) => handleFileSelect(e, 'primary'));
        elements.previewInput?.addEventListener('change', (e) => handleFileSelect(e, 'preview'));
        elements.audioInput?.addEventListener('change', (e) => handleFileSelect(e, 'audio'));
        elements.coverInput?.addEventListener('change', (e) => handleFileSelect(e, 'cover'));
        
        // Remove buttons
        document.getElementById('primary-remove')?.addEventListener('click', () => removeFile('primary'));
        document.getElementById('preview-remove')?.addEventListener('click', () => removeFile('preview'));
        document.getElementById('audio-remove')?.addEventListener('click', () => removeFile('audio'));
        document.getElementById('cover-remove')?.addEventListener('click', () => removeFile('cover'));
        
        // v144: Art-only master artwork upload (single cover mode)
        const artMasterInput = document.getElementById('art-master-file-input');
        artMasterInput?.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (!file) return;
            if (file.size > FILE_LIMITS.art) {
                imcToast(`File too large. Maximum: ${formatFileSize(FILE_LIMITS.art)}`);
                e.target.value = '';
                return;
            }
            // Art single mode: master artwork goes into primaryFile (same as audio/video master)
            state.primaryFile = file;
            state.masterContentHash = null;
            const nameEl = document.getElementById('art-master-filename');
            const sizeEl = document.getElementById('art-master-filesize');
            if (nameEl) nameEl.textContent = file.name;
            if (sizeEl) sizeEl.textContent = formatFileSize(file.size);
            document.getElementById('art-master-dropzone').style.display = 'none';
            document.getElementById('art-master-preview').style.display = 'flex';

            // D4 (STRONG GUIDANCE, NOT A GATE — product ruling): dimension advisory only.
            // Pixel art is a genre, not a defect; nothing here ever blocks a select.
            try {
                const _dimUrl = URL.createObjectURL(file);
                const _dimImg = new Image();
                _dimImg.onload = () => {
                    URL.revokeObjectURL(_dimUrl);
                    let dimEl = document.getElementById('art-dim-note');
                    if (!dimEl && nameEl && nameEl.parentElement) {
                        dimEl = document.createElement('div');
                        dimEl.id = 'art-dim-note';
                        dimEl.className = 'imc-limit-note';
                        nameEl.parentElement.appendChild(dimEl);
                    }
                    if (!dimEl) return;
                    dimEl.textContent = (Math.min(_dimImg.width, _dimImg.height) < 1000)
                        ? `Heads up: this image is ${_dimImg.width}×${_dimImg.height}. For the sharpest marketplace display we recommend 1000×1000 or larger — smaller works (like pixel art) are absolutely still welcome.`
                        : '';
                };
                _dimImg.onerror = () => URL.revokeObjectURL(_dimUrl); // PSD/RAW etc: silent — advisory only
                _dimImg.src = _dimUrl;
            } catch (_e) { /* advisory only — never blocks */ }

            // v43: Detect if watermarkable — hide cover upload, preview auto-generated
            // (PP-6 P2: the zone now DEFAULTS hidden for art — see ppArtCoverDefault() —
            // so this detect's else-branch is the sole REVEAL, for PSD/RAW masters only.)
            state.artAutoWatermark = isWatermarkable(file.name);
            // Target only the cover upload elements, NOT the parent section (which also wraps master upload)
            const coverDropzone = document.getElementById('cover-dropzone');
            const coverPreview = document.getElementById('cover-preview');
            const wmNotice = document.getElementById('art-watermark-notice');
            const wmManualNotice = document.getElementById('art-watermark-manual-notice');
            if (state.artAutoWatermark) {
                if (coverDropzone) coverDropzone.style.display = 'none';
                if (coverPreview) coverPreview.style.display = 'none';
                if (wmNotice) wmNotice.style.display = 'block';
                if (wmManualNotice) wmManualNotice.style.display = 'none';
                // Clear any previously selected cover — VPS will generate it
                state.coverFile = null;
                state.coverIpfs = null;
            } else {
                if (coverDropzone) coverDropzone.style.display = '';
                if (wmNotice) wmNotice.style.display = 'none';
                if (wmManualNotice) wmManualNotice.style.display = 'block';
            }
        });
        // ── U3: unlockable vault handlers ─────────────────────────────────
        const ulDrop = document.getElementById('ul-dropzone');
        const ulInput = document.getElementById('ul-file-input');
        if (ulDrop && ulInput) {
            ulDrop.addEventListener('click', () => ulInput.click());
            ulInput.addEventListener('change', async (e) => {
                const files = Array.from(e.target.files || []);
                ulInput.value = '';
                for (const f of files) { await ulAddFile(f); }
            });
        }
        document.getElementById('art-master-remove')?.addEventListener('click', () => {
            state.primaryFile = null;
            state.masterContentHash = null;
            state.artAutoWatermark = false;
            document.getElementById('art-master-dropzone').style.display = 'flex';
            document.getElementById('art-master-preview').style.display = 'none';
            if (artMasterInput) artMasterInput.value = '';
            // v43: Restore cover upload and clear notices
            const coverDropzone = document.getElementById('cover-dropzone');
            if (coverDropzone) coverDropzone.style.display = '';
            const wmNotice = document.getElementById('art-watermark-notice');
            const wmManualNotice = document.getElementById('art-watermark-manual-notice');
            if (wmNotice) wmNotice.style.display = 'none';
            if (wmManualNotice) wmManualNotice.style.display = 'none';
        });
        
        // Collection cover
        elements.collectionCoverDropzone?.addEventListener('click', () => {
            elements.collectionCoverInput?.click();
        });
        elements.collectionCoverInput?.addEventListener('change', handleCollectionCoverUpload);

        // v699: tiered listing-card cover
        elements.tierCardCoverDropzone?.addEventListener('click', () => {
            elements.tierCardCoverInput?.click();
        });
        elements.tierCardCoverInput?.addEventListener('change', handleTierCardCoverUpload);
        
        // Collection continue buttons (Step 0 dedicated buttons)
        document.getElementById('collection-continue-btn')?.addEventListener('click', handleCollectionContinue);
        document.getElementById('existing-continue-btn')?.addEventListener('click', handleExistingContinue);
        
        // Real-time taxon validation on blur
        document.getElementById('collection-taxon')?.addEventListener('blur', validateTaxonAvailability);
        
        // Self-published toggle
        elements.selfPublished?.addEventListener('change', (e) => {
            if (elements.publishersContainer) {
                elements.publishersContainer.style.display = e.target.checked ? 'none' : 'block';
            }
        });
        
        // Worldwide distribution toggle
        elements.worldwideDistribution?.addEventListener('change', (e) => {
            if (elements.territoryExclusions) {
                elements.territoryExclusions.style.display = e.target.checked ? 'none' : 'block';
            }
        });
        
        // Samples disclosure toggle
        document.querySelectorAll('input[name="contains_samples"]').forEach(radio => {
            radio.addEventListener('change', (e) => {
                const details = document.getElementById('samples-details');
                if (details) {
                    details.style.display = e.target.value === 'true' ? 'block' : 'none';
                }
            });
        });
        
        // AI disclosure toggle
        document.querySelectorAll('input[name="ai_used"]').forEach(radio => {
            radio.addEventListener('change', (e) => {
                const details = document.getElementById('ai-details');
                const isAI = e.target.value === 'true';
                if (details) {
                    details.style.display = isAI ? 'block' : 'none';
                }
                // v178: Make AI fields mandatory when "Yes" selected
                const platformInput = document.querySelector('input[name="ai_platform"]');
                const dateInput = document.querySelector('input[name="ai_creation_date"]');
                if (platformInput) platformInput.required = isAI;
                if (dateInput) dateInput.required = isAI;
                // At least one AI element must be checked — validated on submit
            });
        });
        
        // v178: Full Song checkbox unticks all human elements
        document.addEventListener('change', (e) => {
            if (e.target.name === 'ai_elements[]' && e.target.value === 'full_song') {
                if (e.target.checked) {
                    // Full Song selected → untick ALL human elements
                    document.querySelectorAll('input[name="human_elements[]"]').forEach(cb => {
                        cb.checked = false;
                    });
                    // Also check all other AI elements
                    document.querySelectorAll('input[name="ai_elements[]"]').forEach(cb => {
                        cb.checked = true;
                    });
                }
            }
            // If any human element is checked, untick Full Song
            if (e.target.name === 'human_elements[]' && e.target.checked) {
                const fullSong = document.querySelector('input[name="ai_elements[]"][value="full_song"]');
                if (fullSong) fullSong.checked = false;
            }
        });
        
        // Video AI disclosure toggle
        document.querySelectorAll('input[name="video_ai_used"]').forEach(radio => {
            radio.addEventListener('change', (e) => {
                const details = document.getElementById('video-ai-details');
                if (details) {
                    details.style.display = e.target.value === 'true' ? 'block' : 'none';
                }
            });
        });
        
        // Writer ownership calculation
        document.getElementById('writers-list')?.addEventListener('input', (e) => {
            if (e.target.name?.includes('writer_ownership')) {
                calculateWriterOwnership();
            }
        });
        
        // Wallet connection
        elements.connectBtn?.addEventListener('click', handleWalletConnect);
        
        // Authorization events (for mint-on-demand)
        elements.authBtnAuthorize?.addEventListener('click', requestAuthorization);
        elements.authBtnUpdate?.addEventListener('click', requestAuthorization); // Same flow for update
        elements.authModalClose?.addEventListener('click', closeAuthModal);
        elements.authModal?.addEventListener('click', (e) => {
            if (e.target === elements.authModal) closeAuthModal();
        });
        
        // v71: Currency selection events
        initCurrencySelection();
        
        // v71: Allowlist events
        initAllowlistUI();
    }

    function bindDynamicListEvents() {
        // Add buttons for dynamic lists
        document.getElementById('add-primary-artist-btn')?.addEventListener('click', () => addPrimaryArtist());
        document.getElementById('add-featured-artist-btn')?.addEventListener('click', () => addFeaturedArtist());
        document.getElementById('add-writer-btn')?.addEventListener('click', () => addWriter());
        document.getElementById('add-producer-btn')?.addEventListener('click', () => addProducer());
        document.getElementById('add-engineer-btn')?.addEventListener('click', () => addEngineer());
        document.getElementById('add-musician-btn')?.addEventListener('click', () => addMusician());
        document.getElementById('add-publisher-btn')?.addEventListener('click', () => addPublisher());
        document.getElementById('add-master-owner-btn')?.addEventListener('click', () => addMasterOwner());
        document.getElementById('add-film-copyright-owner-btn')?.addEventListener('click', () => addFilmCopyrightOwner());
        document.getElementById('add-comp-copyright-btn')?.addEventListener('click', () => addCompCopyrightOwner());
        document.getElementById('add-sound-copyright-btn')?.addEventListener('click', () => addSoundCopyrightOwner());
        document.getElementById('add-sample-btn')?.addEventListener('click', () => addSample());
        
        // Live ownership calculation for all percentage sections
        document.addEventListener('input', (e) => {
            if (e.target.classList.contains('master-owner-pct')) calculateMasterOwnership();
            if (e.target.classList.contains('film-copyright-pct')) calculateFilmCopyrightOwnership();
            if (e.target.classList.contains('comp-copyright-pct')) calculateCompCopyrightOwnership();
            if (e.target.classList.contains('sound-copyright-pct')) calculateSoundCopyrightOwnership();
            if (e.target.name?.startsWith('publisher_ownership_')) calculatePublisherOwnership();
        });
        document.getElementById('add-cast-btn')?.addEventListener('click', () => addCast());
        document.getElementById('add-film-director-btn')?.addEventListener('click', () => addFilmCredit('directors', 'film_director', 'Director'));
        document.getElementById('add-film-producer-btn')?.addEventListener('click', () => addFilmCredit('producers', 'film_producer', 'Producer'));
        document.getElementById('add-film-writer-btn')?.addEventListener('click', () => addFilmCredit('writers', 'film_writer', 'Writer'));
        document.getElementById('add-film-cast-btn')?.addEventListener('click', () => addFilmCredit('cast', 'film_cast', 'Actor/actress'));
        document.getElementById('add-trait-btn')?.addEventListener('click', () => addCustomTrait());
        
        // Live update trait preview as traits are edited
        document.getElementById('custom-traits-list')?.addEventListener('input', () => updateTraitPreview());
        
        // Launch scheduling
        initLaunchScheduling();
        
        // Rarity tier builder
        initTierBuilder();
    }

    // ===== Step Navigation =====
    
    // ===== Launch Scheduling =====
    function initLaunchScheduling() {
        const radios = document.querySelectorAll('input[name="launch_type"]');
        const scheduledFields = document.getElementById('scheduled-fields');
        const launchDate = document.getElementById('launch-date');
        const launchTime = document.getElementById('launch-time');
        const launchTz = document.getElementById('launch-timezone');
        const preview = document.getElementById('schedule-preview');
        const previewText = document.getElementById('schedule-preview-text');
        
        if (!radios.length) return;
        
        // Set min date to today
        if (launchDate) {
            launchDate.min = new Date().toISOString().split('T')[0];
        }
        
        radios.forEach(radio => {
            radio.addEventListener('change', () => {
                const isScheduled = radio.value === 'scheduled' && radio.checked;
                // Toggle card active states
                document.querySelectorAll('.launch-option-card').forEach(c => c.classList.remove('active'));
                radio.closest('.launch-option')?.querySelector('.launch-option-card')?.classList.add('active');
                // Show/hide scheduled fields
                if (scheduledFields) scheduledFields.style.display = isScheduled ? 'block' : 'none';
            });
        });
        
        // Update schedule preview
        const updatePreviewText = () => {
            if (!launchDate?.value || !launchTime?.value) {
                if (preview) preview.style.display = 'none';
                return;
            }
            const tz = launchTz?.selectedOptions[0]?.text || 'UTC';
            const d = new Date(launchDate.value + 'T' + launchTime.value);
            const formatted = d.toLocaleDateString('en-GB', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
            const time = d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
            if (previewText) previewText.textContent = `Listing goes live: ${formatted} at ${time} (${tz})`;
            if (preview) preview.style.display = 'flex';
        };
        
        launchDate?.addEventListener('change', updatePreviewText);
        launchTime?.addEventListener('change', updatePreviewText);
        launchTz?.addEventListener('change', updatePreviewText);
    }
    
    // ===== Shared Master Pool (music/mv/film tiered listings) =====
    //
    // Artists upload 1-N master media files once here. Each tier card then
    // uses a dropdown to assign which pool master it uses. The same file
    // can be assigned to multiple tiers without re-uploading or extra fees.
    // Art tiers use per-tier masters (unchanged from previous behaviour).

    function renderPoolZones() {
        const container = document.getElementById('pool-master-container');
        if (!container) return;
        container.innerHTML = '';
        const isArt = state.contentType === 'art';

        state.masterPool.forEach((item, idx) => {
            const zone = document.createElement('div');
            zone.className = 'pool-master-zone';
            zone.id = `pool-zone-${item.id}`;

            // ── Tier assignment badges (music/mv/film only) ──────────────
            // Shows numbered pills for each existing tier. Clicking toggles
            // tier.masterPoolRef. Art is 1:1 so no badge strip needed.
            let tierBadgesHtml = '';
            if (!isArt && state.tiers.items.length > 0) {
                const maxBadges = 20;
                if (state.tiers.items.length <= maxBadges) {
                    const badges = state.tiers.items.map(tier => {
                        const active = tier.masterPoolRef === item.id;
                        return `<button type="button"
                            class="pool-tier-badge ${active ? 'active' : ''}"
                            data-pool="${item.id}" data-tier="${tier.id}"
                            title="${escHtmlAttr(tier.name || `Tier ${tier.id}`)}"
                        >${state.tiers.items.indexOf(tier) + 1}</button>`;
                    }).join('');
                    tierBadgesHtml = `
                        <div class="pool-tier-assign">
                            <span class="pool-tier-assign-label">Assign to tiers:</span>
                            <div class="pool-tier-badges">${badges}</div>
                            <button type="button" class="pool-tier-all-btn" data-pool="${item.id}">All</button>
                            <button type="button" class="pool-tier-none-btn" data-pool="${item.id}">None</button>
                        </div>`;
                } else {
                    const assignedCount = state.tiers.items.filter(t => t.masterPoolRef === item.id).length;
                    tierBadgesHtml = `
                        <div class="pool-tier-assign">
                            <span class="pool-tier-assign-label">Assigned to <strong>${assignedCount}</strong> of ${state.tiers.items.length} tiers</span>
                            <button type="button" class="pool-tier-all-btn" data-pool="${item.id}">All</button>
                            <button type="button" class="pool-tier-none-btn" data-pool="${item.id}">None</button>
                        </div>`;
                }
            }

            zone.innerHTML = `
                <div class="pool-master-row">
                    <div class="pool-master-label">${escHtml(item.label)}</div>
                    <button type="button" class="pool-master-delete-btn" data-pool="${item.id}" title="Remove">✕</button>
                </div>
                <div class="pool-master-files">
                    <div class="pool-file-col">
                        <div class="pool-file-heading"><svg class="imc-ic" aria-hidden="true"><use href="#ic-lock"></use></svg> Master ${isArt ? 'Artwork' : 'File'} (Off-Chain)</div>
                        ${item.file
                            ? `<div class="pool-master-info">
                                   <span class="pool-file-name">${escHtml(item.file.name)}</span>
                                   <span class="pool-master-size">${formatFileSize(item.file.size)}</span>
                                   ${item.contentHash ? '<span class="pool-master-stored"><svg class="imc-ic" aria-hidden="true"><use href="#ic-check-circle"></use></svg> Stored</span>' : ''}
                                   <button type="button" class="pool-file-remove-btn" data-pool="${item.id}" data-field="master">✕</button>
                               </div>`
                            : `<div class="pool-master-drop" id="pool-drop-${item.id}">
                                   <span class="pool-drop-label">Choose ${isArt ? 'master artwork' : 'master audio/video'}</span>
                                   <button type="button" class="pool-choose-btn">Choose File</button>
                                   <input type="file" accept="${isArt ? 'image/*,.psd,.tiff,.tif,.bmp,.raw' : 'audio/*,video/*'}" data-pool="${item.id}" data-field="master" hidden>
                               </div>`
                        }
                    </div>
                    ${!isArt ? `
                    <div class="pool-file-col">
                        <div class="pool-file-heading">▶️ Preview Clip (IPFS, optional)</div>
                        ${item.previewFile
                            ? `<div class="pool-master-info">
                                   <span class="pool-file-name">${escHtml(item.previewFile.name)}</span>
                                   <span class="pool-master-size">${formatFileSize(item.previewFile.size)}</span>
                                   ${item.previewIpfs ? '<span class="pool-master-stored"><svg class="imc-ic" aria-hidden="true"><use href="#ic-check-circle"></use></svg> Pinned</span>' : ''}
                                   <button type="button" class="pool-file-remove-btn" data-pool="${item.id}" data-field="preview">✕</button>
                               </div>`
                            : `<div class="pool-preview-drop" id="pool-preview-drop-${item.id}">
                                   <span class="pool-drop-label">Choose preview clip (≤30s — public on IPFS)</span>
                                   <span class="pool-preview-hint">Optional — tiers inherit listing preview if absent</span>
                                   <button type="button" class="pool-choose-btn">Choose File</button>
                                   <input type="file" accept="audio/*,video/*" data-pool="${item.id}" data-field="preview" hidden>
                               </div>`
                        }
                    </div>` : ''}
                </div>
                ${tierBadgesHtml}
            `;
            container.appendChild(zone);

            // Wire delete button
            zone.querySelector('.pool-master-delete-btn')?.addEventListener('click', (e) => {
                removePoolMaster(parseInt(e.target.dataset.pool));
            });

            // Wire master file input
            const masterDrop = zone.querySelector(`#pool-drop-${item.id}`);
            const masterInput = masterDrop?.querySelector('input[type="file"]');
            if (masterDrop && masterInput) {
                // Click anywhere on the drop zone (or its button) triggers the hidden input
                masterDrop.addEventListener('click', () => masterInput.click());
                masterInput.addEventListener('change', (e) => {
                    if (e.target.files[0]) handlePoolMasterFile(item.id, e.target.files[0], 'master');
                });
            }

            // Wire preview file input (music/mv/film only)
            const previewDrop = zone.querySelector(`#pool-preview-drop-${item.id}`);
            const previewInput = previewDrop?.querySelector('input[type="file"]');
            if (previewDrop && previewInput) {
                previewDrop.addEventListener('click', () => previewInput.click());
                previewInput.addEventListener('change', (e) => {
                    if (e.target.files[0]) handlePoolMasterFile(item.id, e.target.files[0], 'preview');
                });
            }

            // Wire file-remove buttons
            zone.querySelectorAll('.pool-file-remove-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const poolId = parseInt(e.target.dataset.pool);
                    const field  = e.target.dataset.field;
                    const pi = state.masterPool.find(p => p.id === poolId);
                    if (!pi) return;
                    if (field === 'master') { pi.file = null; pi.contentHash = null; }
                    if (field === 'preview') { pi.previewFile = null; pi.previewIpfs = null; }
                    renderPoolZones();
                });
            });

            // Wire tier assignment badges
            zone.querySelectorAll('.pool-tier-badge').forEach(btn => {
                btn.addEventListener('click', () => {
                    const poolId = parseInt(btn.dataset.pool);
                    const tierId = parseInt(btn.dataset.tier);
                    const tier = state.tiers.items.find(t => t.id === tierId);
                    if (!tier) return;
                    if (tier.masterPoolRef === poolId) {
                        // Deselect
                        tier.masterPoolRef = null;
                        tier.useDefaultMaster = true;
                    } else {
                        tier.masterPoolRef = poolId;
                        tier.useDefaultMaster = false;
                    }
                    refreshTierPoolDropdowns();
                    renderPoolZones(); // Re-render to update badge states
                });
            });

            // Wire All button
            zone.querySelector('.pool-tier-all-btn')?.addEventListener('click', (e) => {
                const poolId = parseInt(e.target.dataset.pool);
                state.tiers.items.forEach(tier => {
                    tier.masterPoolRef = poolId;
                    tier.useDefaultMaster = false;
                });
                refreshTierPoolDropdowns();
                renderPoolZones();
            });

            // Wire None button
            zone.querySelector('.pool-tier-none-btn')?.addEventListener('click', (e) => {
                const poolId = parseInt(e.target.dataset.pool);
                state.tiers.items.forEach(tier => {
                    if (tier.masterPoolRef === poolId) {
                        tier.masterPoolRef = null;
                        tier.useDefaultMaster = true;
                    }
                });
                refreshTierPoolDropdowns();
                renderPoolZones();
            });
        });

        // Update all tier card pool dropdowns with current pool items
        refreshTierPoolDropdowns();
    }

    function addPoolMaster() {
        const isArt = state.contentType === 'art';
        const maxPool = isArt ? 1000 : 10;
        if (state.masterPool.length >= maxPool) {
            imcToast(`Maximum ${maxPool} pool masters for this content type.`);
            return;
        }
        const id = (state.masterPool.length > 0
            ? Math.max(...state.masterPool.map(p => p.id)) + 1 : 1);
        const label = `Media ${id}`;
        state.masterPool.push({ id, label, file: null, contentHash: null, previewFile: null, previewIpfs: null });
        renderPoolZones();
    }

    function removePoolMaster(poolId) {
        const idx = state.masterPool.findIndex(p => p.id === poolId);
        if (idx === -1) return;
        // Clear any tier references to this pool item
        state.tiers.items.forEach(tier => {
            if (tier.masterPoolRef === poolId) {
                tier.masterPoolRef = null;
                tier.useDefaultMaster = true;
                tier.masterContentHash = null;
            }
        });
        state.masterPool.splice(idx, 1);
        renderPoolZones();
    }

    function handlePoolMasterFile(poolId, file, field = 'master') {
        const isArt = state.contentType === 'art';
        const poolItem = state.masterPool.find(p => p.id === poolId);
        if (!poolItem) return;

        if (field === 'master') {
            const validAudio = file.type.startsWith('audio/') || file.type.startsWith('video/');
            if (!isArt && !validAudio) {
                imcToast('Master file must be an audio or video file.');
                return;
            }
            if (isArt) {
                const ext = file.name.split('.').pop()?.toLowerCase();
                const artExts = ['png','jpg','jpeg','tiff','tif','psd','bmp','webp','gif','raw'];
                if (!ext || !artExts.includes(ext)) {
                    imcToast('Master artwork must be an image file (PNG, JPG, TIFF, PSD, BMP, WebP, GIF, RAW)');
                    return;
                }
            }
            poolItem.file = file;
            poolItem.contentHash = null;
        } else if (field === 'preview') {
            const validAudio = file.type.startsWith('audio/') || file.type.startsWith('video/');
            if (!validAudio) {
                imcToast('Preview clip must be an audio or video file.');
                return;
            }
            poolItem.previewFile = file;
            poolItem.previewIpfs = null;
        }

        renderPoolZones();
    }

    function refreshTierPoolDropdowns() {
        // Rebuild all pool dropdowns in existing tier cards
        state.tiers.items.forEach(tier => {
            const select = document.querySelector(`#tier-card-${tier.id} .tier-pool-select`);
            if (!select) return;
            const currentVal = tier.masterPoolRef ? String(tier.masterPoolRef) : '';
            const defaultOpt = `<option value="">Use listing default</option>`;
            const poolOpts = state.masterPool.map(p =>
                `<option value="${p.id}">${escHtmlAttr(p.label)}</option>`
            ).join('');
            select.innerHTML = defaultOpt + poolOpts;
            select.value = currentVal;
        });
    }

    // ===== Bulk Cover Folder Upload =====
    //
    // Artist drops a folder of numbered images (1.png, 2.jpg, etc.).
    // System matches file number to tier number, auto-creates tiers,
    // and assigns each file as the tier's cover.
    // For art: files map to pool masters (watermark auto-generates cover).
    // Pre-confirmation table shown before any tiers are created.

    function handleBulkCoverFolder(files) {
        if (state.editionType === 'open') {
            imcToast('Bulk upload is not available for Open Edition listings.');
            return;
        }
        const isArt = state.contentType === 'art';
        const maxTiers = isArt ? 1000 : 1000;

        // Parse numbered files: accept "N.ext" or "N anything.ext"
        const validExts = ['png','jpg','jpeg','gif','webp','bmp','tiff','tif'];
        const parsed = [];
        const skipped = [];
        for (const file of files) {
            const base = file.name.replace(/\.[^.]+$/, '');
            const ext = file.name.split('.').pop()?.toLowerCase();
            const num = parseInt(base.match(/^(\d+)/)?.[1]);
            if (!isNaN(num) && num > 0 && validExts.includes(ext)) {
                if (!file.type.startsWith('image/') && !['tiff','tif','bmp'].includes(ext)) {
                    skipped.push(file.name);
                    continue;
                }
                parsed.push({ num, file });
            } else {
                skipped.push(file.name);
            }
        }

        if (parsed.length === 0) {
            imcToast('No valid numbered image files found.\nFiles must be named 1.png, 2.jpg, etc.');
            return;
        }

        // Sort by number, deduplicate (keep last if duplicate numbers)
        parsed.sort((a, b) => a.num - b.num);
        const byNum = {};
        parsed.forEach(p => { byNum[p.num] = p.file; });
        const sortedNums = Object.keys(byNum).map(Number).sort((a,b) => a-b);

        if (sortedNums.length > maxTiers) {
            imcToast(`Folder contains ${sortedNums.length} files but maximum tiers is ${maxTiers}. Only the first ${maxTiers} will be used.`);
            sortedNums.splice(maxTiers);
        }

        // Detect gaps
        const maxNum = sortedNums[sortedNums.length - 1];
        const gaps = [];
        for (let i = 1; i <= maxNum; i++) {
            if (!byNum[i]) gaps.push(i);
        }

        // Build pre-confirmation table
        showBulkCoverConfirm(sortedNums, byNum, gaps, skipped, isArt);
    }

    function showBulkCoverConfirm(nums, byNum, gaps, skipped, isArt) {
        // Remove any existing confirm panel
        document.getElementById('bulk-cover-confirm')?.remove();

        const panel = document.createElement('div');
        panel.id = 'bulk-cover-confirm';
        panel.className = 'bulk-confirm-panel';

        const gapWarning = gaps.length > 0
            ? `<div class="bulk-gap-warning"><svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> ${gaps.length} gap(s) detected — tier(s) ${gaps.slice(0,5).join(', ')}${gaps.length>5?'…':''} will need manual ${isArt ? 'master' : 'cover'} upload.</div>`
            : '';
        const skipInfo = skipped.length > 0
            ? `<div class="bulk-skip-info">ℹ️ ${skipped.length} file(s) skipped (not numbered images).</div>`
            : '';

        // Build preview rows (show first 10, summarise rest)
        const previewCount = Math.min(nums.length, 10);
        const rows = nums.slice(0, previewCount).map(num => {
            const file = byNum[num];
            const thumbId = `bulk-thumb-${num}`;
            // Generate thumbnail asynchronously after insertion
            return `<tr>
                <td>${num}</td>
                <td><img id="${thumbId}" src="" style="width:40px;height:40px;object-fit:cover;border-radius:4px;background:#1a1a24;"></td>
                <td>${escHtml(file.name)}</td>
                <td>${formatFileSize(file.size)}</td>
            </tr>`;
        }).join('');
        const moreRow = nums.length > previewCount
            ? `<tr><td colspan="4" style="color:#888;font-size:0.8rem;">… and ${nums.length - previewCount} more</td></tr>`
            : '';

        panel.innerHTML = `
            <div class="bulk-confirm-header">
                <strong><svg class="imc-ic" aria-hidden="true"><use href="#ic-folder"></use></svg> Bulk ${isArt ? 'Master' : 'Cover'} Upload — Review Before Applying</strong>
                <button type="button" id="bulk-cover-cancel" style="float:right;">✕ Cancel</button>
            </div>
            ${gapWarning}${skipInfo}
            <p>${nums.length} tiers will be created from ${nums.length} numbered files.</p>
            <table class="bulk-confirm-table">
                <thead><tr><th>#</th><th>Preview</th><th>Filename</th><th>Size</th></tr></thead>
                <tbody>${rows}${moreRow}</tbody>
            </table>
            <div style="margin-top:12px;display:flex;gap:10px;">
                <button type="button" id="bulk-cover-confirm-btn" class="btn-primary">
                    ✅ Create ${nums.length} Tiers
                </button>
                <button type="button" id="bulk-cover-cancel-btn" class="btn-secondary">Cancel</button>
            </div>
        `;

        // Insert before tier cards container
        const container = document.getElementById('tier-cards-container');
        container?.parentNode?.insertBefore(panel, container);

        // Load thumbnails asynchronously
        nums.slice(0, previewCount).forEach(num => {
            const file = byNum[num];
            const img = document.getElementById(`bulk-thumb-${num}`);
            if (img && file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = e => { img.src = e.target.result; };
                reader.readAsDataURL(file);
            }
        });

        // Wire buttons
        const doCancel = () => panel.remove();
        document.getElementById('bulk-cover-cancel')?.addEventListener('click', doCancel);
        document.getElementById('bulk-cover-cancel-btn')?.addEventListener('click', doCancel);
        document.getElementById('bulk-cover-confirm-btn')?.addEventListener('click', () => {
            panel.remove();
            applyBulkCoverFolder(nums, byNum, gaps, isArt);
        });
    }

    function applyBulkCoverFolder(nums, byNum, gaps, isArt) {
        const maxTiers = isArt ? 1000 : 1000;
        // v599: enable tiers if needed, then drop the pristine auto-seeded presets
        // (Legendary/Rare/Common) BEFORE sizing, so the upload defines tiers from position 1
        // with no placeholders in front. Replaces the old removeTierCard loop, which could not
        // empty the list (min-2-tiers guard) and only ran when this upload was what enabled tiers.
        if (!state.tiers.enabled) {
            const tiersRadio = document.querySelector('input[name="tier_mode"][value="tiers"]');
            if (tiersRadio) {
                tiersRadio.checked = true;
                tiersRadio.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
        // v591 Slice 5: place covers by numbered position so a missing/failed image
        // leaves its tier box EMPTY in place (no compaction). maxNum = highest numbered
        // file; one tier is created per position 1..maxNum.
        const maxNum = nums.length > 0 ? nums[nums.length - 1] : 0;
        if (maxNum > 0) imcClearSeededPresetTiers();
        const existingCount = state.tiers.items.length;
        const toCreate = Math.min(maxNum, maxTiers - existingCount);

        if (toCreate <= 0) {
            imcToast(`Already at maximum ${maxTiers} tiers.`);
            return;
        }

        // Use DocumentFragment for bulk DOM insertion (single reflow)
        const container = document.getElementById('tier-cards-container');
        const frag = document.createDocumentFragment();
        const tempContainer = document.createElement('div');

        for (let pos = 1; pos <= toCreate; pos++) {
            const file = byNum[pos];   // undefined => gap (failed/missing image): leave empty
            const name = file
                ? (file.name.replace(/\.[^.]+$/, '').replace(/^\d+[\s_-]*/, '').trim() || `Tier ${pos}`)
                : `Tier ${pos}`;

            // addTierCard with skipSummary=true — batch DOM update
            addTierCard(name, 1, true);

            const tier = state.tiers.items[state.tiers.items.length - 1];
            if (!tier) continue;

            // Gap: tier box created but left EMPTY in position, awaiting manual upload.
            if (!file) continue;

            if (isArt) {
                // v702 Phase 4: converge bulk art onto the SAME per-tier path as the manual
                // Master Artwork box — set tier.masterFile via handleTierMasterFile (validates,
                // updates the box UI: name, thumbnail, watermark notice). Uploaded per-tier at
                // submit (the artTierMode masterFile loop). Retires the old art→pool bridge so
                // art has ONE master model with two entry points (manual + bulk). No pool for art.
                handleTierMasterFile(tier.id, file);
            } else {
                // Music/MV/Film: file goes to tier coverFile
                handleTierCoverFile(tier.id, file);
            }
        }

        // Single summary update after all tiers created
        updateTierSummary();
        syncEditionsFromTiers();
        renderPoolZones();
        scheduleMetadataSave(); // v591: persist bulk-applied cover structure to draft

        // Show gap warnings if any — these tier boxes were left EMPTY in position
        if (gaps.length > 0) {
            const gapMsg = document.createElement('div');
            gapMsg.className = 'bulk-gap-result-warning';
            gapMsg.innerHTML = `<svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> ${gaps.length} tier box(es) left empty for missing ${isArt ? 'artwork' : 'images'}: tier${gaps.length>1?'s':''} ${gaps.slice(0,5).join(', ')}${gaps.length>5?`… (${gaps.length} total)`:''}. Upload these before publishing.`;
            container?.parentNode?.insertBefore(gapMsg, container);
            setTimeout(() => gapMsg.remove(), 10000);
        }

        console.log(`[IMC] Bulk folder: created ${toCreate} tiers from ${nums.length} files.`);
    }

    // ===== Bulk Traits CSV Upload =====
    //
    // CSV format: tier_number, [tier_name], [editions], [trait columns...]
    // tier_number is required — used to match rows to existing tier cards.
    // Other columns become trait_type (column header) + value (cell).
    // Shows a preview table before applying.

    // v591 Slice 3: Lazy SheetJS loader for Excel trait uploads. Loaded only on the
    // first .xlsx/.xls selection, from a CSP-allowed CDN (cdnjs). Cached after first load;
    // resets on failure so a later attempt can retry.
    let _sheetJSPromise = null;
    // M1-e2c: PDF.js, self-hosted (Apache-2.0, Mozilla). Lazy-loaded only when a
    // creator picks a PDF, so a normal /mint/ visit never pays the 1.5MB.
    let _pdfJsPromise = null;
    function loadPdfJs() {
        if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
        if (_pdfJsPromise) return _pdfJsPromise;
        const src = (CONFIG.endpoints && CONFIG.endpoints.pdfjs) || '';
        const worker = (CONFIG.endpoints && CONFIG.endpoints.pdfjsWorker) || '';
        if (!src) return Promise.reject(new Error('PDF support is unavailable'));
        _pdfJsPromise = new Promise(function (resolve, reject) {
            const s = document.createElement('script');
            s.src = src; s.async = true;
            s.onload = function () {
                if (window.pdfjsLib) {
                    if (worker) window.pdfjsLib.GlobalWorkerOptions.workerSrc = worker;
                    resolve(window.pdfjsLib);
                } else { reject(new Error('PDF.js missing after load')); }
            };
            s.onerror = function () { _pdfJsPromise = null; reject(new Error('PDF.js failed to load')); };
            document.head.appendChild(s);
        });
        return _pdfJsPromise;
    }

    async function readPdfPageCount(file) {
        const lib = await loadPdfJs();
        const buf = await file.arrayBuffer();
        const doc = await lib.getDocument({ data: buf }).promise;
        const n = doc.numPages;
        try { doc.destroy(); } catch (e) {}
        return n;
    }

    function loadSheetJS() {
        if (window.XLSX && window.XLSX.utils) return Promise.resolve(window.XLSX);
        if (_sheetJSPromise) return _sheetJSPromise;
        _sheetJSPromise = new Promise(function(resolve, reject) {
            const s = document.createElement('script');
            s.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
            s.async = true;
            s.onload = function() {
                if (window.XLSX && window.XLSX.utils) resolve(window.XLSX);
                else reject(new Error('SheetJS missing after load'));
            };
            s.onerror = function() { _sheetJSPromise = null; reject(new Error('SheetJS failed to load')); };
            document.head.appendChild(s);
        });
        return _sheetJSPromise;
    }

    function handleBulkTraitsCSV(file) {
        const fname = (file.name || '').toLowerCase();
        const isExcel = /\.(xlsx|xls)$/i.test(fname);
        if (!isExcel && !/\.(csv|txt)$/i.test(fname)) {
            imcToast('Please upload a CSV (.csv, .txt) or Excel (.xlsx, .xls) file.');
            return;
        }
        if (isExcel) {
            // v591: Excel support. Lazy-load SheetJS, convert the FIRST sheet to CSV, then
            // reuse the exact same quote-aware CSV parser — no CSV round-trip for the artist.
            loadSheetJS().then(function(XLSX) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    try {
                        const wb = XLSX.read(new Uint8Array(e.target.result), { type: 'array' });
                        const ws = wb.Sheets[wb.SheetNames[0]];
                        if (!ws) { imcToast('That Excel file has no readable sheet.'); return; }
                        parseBulkCSV(XLSX.utils.sheet_to_csv(ws));
                    } catch (err) {
                        imcToast('Excel parse error: ' + err.message);
                    }
                };
                reader.onerror = function() { imcToast('Could not read the Excel file.'); };
                reader.readAsArrayBuffer(file);
            }).catch(function() {
                imcToast('Could not load the Excel reader (network/CDN). Save the file as .csv and try again.');
            });
            return;
        }
        const reader = new FileReader();
        reader.onload = e => {
            try {
                parseBulkCSV(e.target.result);
            } catch (err) {
                imcToast('CSV parse error: ' + err.message);
            }
        };
        reader.readAsText(file);
    }

    function parseBulkCSV(text) {
        // v591: Quote-aware tokenizer. Builds the full cell matrix in a single pass so
        // that quoted fields containing commas, embedded newlines, or escaped quotes ("")
        // parse correctly across CRLF/LF endings. The previous parser split on newlines
        // BEFORE honoring quotes, so one line break inside a quoted header/cell (common in
        // Excel exports) severed the row and silently dropped every column after it.
        // Output shape and all downstream logic are unchanged.
        function parseCSVMatrix(src) {
            const matrix = [];
            let row = [], cur = '', inQ = false;
            for (let i = 0; i < src.length; i++) {
                const ch = src[i], nx = src[i + 1];
                if (inQ) {
                    if (ch === '"' && nx === '"') { cur += '"'; i++; }   // escaped quote
                    else if (ch === '"') { inQ = false; }                // closing quote
                    else { cur += ch; }
                } else {
                    if (ch === '"') { inQ = true; }
                    else if (ch === ',') { row.push(cur.trim()); cur = ''; }
                    else if (ch === '\r') { /* swallow; row break handled on \n */ }
                    else if (ch === '\n') { row.push(cur.trim()); matrix.push(row); row = []; cur = ''; }
                    else { cur += ch; }
                }
            }
            // Flush trailing field/row (file may not end with a newline)
            if (cur !== '' || row.length > 0) { row.push(cur.trim()); matrix.push(row); }
            // Drop fully-blank rows (mirrors previous lines.filter(l => l.trim()))
            return matrix.filter(r => r.some(c => c !== ''));
        }

        const matrix = parseCSVMatrix(text);
        if (matrix.length < 2) {
            imcToast('CSV must have a header row and at least one data row.');
            return;
        }

        const headers = matrix[0].map(h => h.toLowerCase().trim());
        // v591 Slice 4: '#' is an accepted alias for the tier box number. tier_number
        // takes precedence when both are present; '#' is never treated as a trait.
        const tierNumIdx = headers.indexOf('tier_number') !== -1
            ? headers.indexOf('tier_number')
            : headers.indexOf('#');
        if (tierNumIdx === -1) {
            imcToast('CSV must have a "tier_number" (or "#") column for the tier box number.');
            return;
        }
        const tierNameIdx  = headers.indexOf('tier_name');
        const editionsIdx  = headers.indexOf('editions');
        const masterFileIdx = headers.indexOf('master_file'); // v481: pool master assignment

        // Trait columns: everything that isn't a reserved control column.
        // v591 Slice 4: '#' added so the tier-box-number alias never becomes a trait.
        const RESERVED_COLS = ['tier_number', '#', 'tier_name', 'editions', 'master_file'];
        const traitCols = headers
            .map((h, i) => ({ name: h, idx: i }))
            .filter(c => !RESERVED_COLS.includes(c.name));

        // Parse data rows
        const rows = [];
        for (let i = 1; i < matrix.length; i++) {
            const cells = matrix[i];
            const tierNum = parseInt(cells[tierNumIdx]);
            if (isNaN(tierNum) || tierNum < 1) continue;
            // v591 Slice 4: tolerant master_file — accept "Media 3", "Master 3", or "3".
            const mfMatch = masterFileIdx >= 0 ? String(cells[masterFileIdx] || '').match(/\d+/) : null;
            rows.push({
                tierNum,
                tierName: tierNameIdx >= 0 ? (cells[tierNameIdx] || '') : '',
                editions: editionsIdx >= 0 ? parseInt(cells[editionsIdx]) || 1 : 1,
                masterFile: mfMatch ? parseInt(mfMatch[0], 10) : 0,
                traits: traitCols.map(c => ({
                    trait_type: c.name,
                    value: String(cells[c.idx] || '').trim()
                })).filter(t => t.value !== '')
            });
        }

        if (rows.length === 0) {
            imcToast('No valid data rows found in CSV.');
            return;
        }

        showBulkTraitsPreview(rows, traitCols);
    }

    function showBulkTraitsPreview(rows, traitCols) {
        document.getElementById('bulk-traits-confirm')?.remove();

        const panel = document.createElement('div');
        panel.id = 'bulk-traits-confirm';
        panel.className = 'bulk-confirm-panel';

        // Check which rows match existing tiers and which will be auto-created
        const isArt = state.contentType === 'art';
        const maxTiers = isArt ? 1000 : 1000;
        const matched = rows.filter(r => state.tiers.items[r.tierNum - 1]);
        const toCreate = rows.filter(r => !state.tiers.items[r.tierNum - 1] && r.tierNum <= maxTiers);
        const outOfRange = rows.filter(r => !state.tiers.items[r.tierNum - 1] && r.tierNum > maxTiers);

        const createNotice = toCreate.length > 0
            ? `<div style="background:rgba(74,222,128,0.07);border:1px solid rgba(74,222,128,0.25);border-radius:6px;padding:0.4rem 0.8rem;color:#4ade80;font-size:0.78rem;margin-bottom:0.5rem;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-sparkle"></use></svg> ${toCreate.length} new tier(s) will be auto-created (tier ${toCreate.map(r=>r.tierNum).join(', ')})</div>`
            : '';
        const outOfRangeWarn = outOfRange.length > 0
            ? `<div class="bulk-gap-warning"><svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> ${outOfRange.length} row(s) exceed the ${maxTiers}-tier limit and will be skipped.</div>`
            : '';

        const previewRows = matched.slice(0, 10).map(r => {
            const tier = state.tiers.items[r.tierNum - 1];
            const traitSummary = r.traits.map(t => `${escHtml(t.trait_type)}: ${escHtml(t.value)}`).join(' / ');
            let masterInfo = '';
            if (r.masterFile > 0) {
                const poolItem = state.masterPool[r.masterFile - 1];
                masterInfo = poolItem
                    ? `<span style="color:#d4af37;font-size:0.75rem;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-music"></use></svg> ${escHtml(poolItem.label)}</span>`
                    : `<span style="color:#f87171;font-size:0.75rem;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> Pool ${r.masterFile} not found</span>`;
            }
            return `<tr>
                <td>${r.tierNum}</td>
                <td>${escHtml(r.tierName || tier?.name || '')}</td>
                <td>${r.editions || '(unchanged)'}</td>
                <td>${masterInfo || '—'}</td>
                <td style="font-size:0.78rem;color:#aaa;">${traitSummary || '—'}</td>
            </tr>`;
        }).join('');
        const moreRow = matched.length > 10
            ? `<tr><td colspan="5" style="color:#888;font-size:0.8rem;">… and ${matched.length - 10} more</td></tr>`
            : '';

        panel.innerHTML = `
            <div class="bulk-confirm-header">
                <strong><svg class="imc-ic" aria-hidden="true"><use href="#ic-clipboard"></use></svg> Bulk Traits CSV — Review Before Applying</strong>
            </div>
            ${outOfRangeWarn}
            ${createNotice}
            <p>${matched.length + toCreate.length} tier(s) will be updated${toCreate.length > 0 ? ` (${toCreate.length} new)` : ''}.</p>
            <table class="bulk-confirm-table">
                <thead><tr><th>Tier #</th><th>Name</th><th>Editions</th><th>Master</th><th>Traits</th></tr></thead>
                <tbody>${previewRows}${moreRow}</tbody>
            </table>
            <div style="margin-top:12px;display:flex;gap:10px;">
                <button type="button" id="bulk-traits-confirm-btn" class="btn-primary">
                    ✅ Apply to ${matched.length + toCreate.length} Tier(s)
                </button>
                <button type="button" id="bulk-traits-cancel-btn" class="btn-secondary">Cancel</button>
            </div>
        `;

        const container = document.getElementById('tier-cards-container');
        container?.parentNode?.insertBefore(panel, container);

        document.getElementById('bulk-traits-cancel-btn')?.addEventListener('click', () => panel.remove());
        document.getElementById('bulk-traits-confirm-btn')?.addEventListener('click', () => {
            panel.remove();
            applyBulkTraitsCSV(rows); // Pass all rows — auto-create handles tier creation
        });
    }

    function applyBulkTraitsCSV(rows) {
        const isArt = state.contentType === 'art';
        const maxTiers = isArt ? 1000 : 1000;

        // Sort rows ascending by tier number for sequential auto-creation
        rows.sort((a, b) => a.tierNum - b.tierNum);

        // Enable tiers if not already
        if (!state.tiers.enabled && rows.length > 0) {
            const tiersRadio = document.querySelector('input[name="tier_mode"][value="tiers"]');
            if (tiersRadio) {
                tiersRadio.checked = true;
                tiersRadio.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
        // v599: drop pristine auto-seeded presets so CSV rows map from tier 1 cleanly
        // (no placeholder names/editions left behind in uncovered positions).
        if (rows.length > 0) imcClearSeededPresetTiers();

        let tiersCreated = 0;
        rows.forEach(row => {
            // Auto-create any missing tiers up to the required tier number
            while (state.tiers.items.length < row.tierNum && state.tiers.items.length < maxTiers) {
                addTierCard('', 1, true); // skipSummary=true — batch DOM update
                tiersCreated++;
            }

            const tier = state.tiers.items[row.tierNum - 1];
            if (!tier) return; // Beyond max tier limit

            // Update tier name if provided in CSV
            if (row.tierName) {
                tier.name = row.tierName;
                const nameInput = document.querySelector(`#tier-card-${tier.id} .tier-name-input`);
                if (nameInput) nameInput.value = row.tierName;
            }

            // Update editions if provided (fallback 1 — never 0)
            if (row.editions > 0) {
                tier.editions = Math.max(1, row.editions);
                const edInput = document.querySelector(`#tier-card-${tier.id} .tier-editions-input`);
                if (edInput) edInput.value = tier.editions;
            }

            // v481: Assign pool master from master_file column
            if (row.masterFile > 0) {
                const poolItem = state.masterPool[row.masterFile - 1];
                if (poolItem) {
                    tier.masterPoolRef = poolItem.id;
                    tier.useDefaultMaster = false;
                    // Update tier card pool select dropdown
                    const poolSel = document.querySelector(`#tier-card-${tier.id} .tier-pool-select`);
                    if (poolSel) {
                        poolSel.value = String(poolItem.id);
                        const nameEl = document.getElementById(`tier-pool-name-${tier.id}`);
                        if (nameEl) nameEl.textContent = `✅ ${poolItem.label}`;
                    }
                }
            }

            // Apply traits: clear existing, add from CSV
            if (row.traits.length > 0) {
                tier.traits = [];
                const list = document.getElementById(`tier-traits-list-${tier.id}`);
                if (list) list.innerHTML = '';

                row.traits.forEach(trait => {
                    addTierTrait(tier.id);
                    const traitIdx = tier.traits.length - 1;
                    if (tier.traits[traitIdx]) {
                        tier.traits[traitIdx].trait_type = trait.trait_type;
                        tier.traits[traitIdx].value = trait.value;
                    }
                    const traitRow = document.getElementById(`tier-trait-${tier.id}-${traitIdx}`);
                    if (traitRow) {
                        const nameInp = traitRow.querySelector('[data-field="trait_type"]');
                        const valInp  = traitRow.querySelector('[data-field="value"]');
                        if (nameInp) nameInp.value = trait.trait_type;
                        if (valInp)  valInp.value  = trait.value;
                    }
                });
            }
        });

        updateTierSummary();
        syncEditionsFromTiers();
        refreshTierPoolDropdowns();
        renderPoolZones(); // Refresh badge states if masters were assigned
        scheduleMetadataSave(); // v591: persist bulk-applied traits to draft
        console.log(`[IMC] Bulk CSV: applied to ${rows.length} tiers, ${tiersCreated} auto-created.`);
    }

    // ===== Rarity Tier Builder =====
    
    const TIER_COLORS = ['#d4af37','#c0c0c0','#cd7f32','#14b8a6','#a855f7','#f97316','#3b82f6','#84cc16','#f43f5e','#06b6d4'];
    const TIER_PRESETS = [
        { name: 'Legendary', editions: 1 },
        { name: 'Rare', editions: 10 },
        { name: 'Common', editions: 89 }
    ];

    // v599: pristine-preset detection + direct clear. The builder seeds Legendary/Rare/Common
    // (TIER_PRESETS) when tiers mode is first enabled. A bulk upload (cover folder or traits
    // CSV) should DEFINE the tier set, not stack behind those placeholders. These helpers drop
    // the seed ONLY while it is untouched (exact preset names/editions, no cover, master, or
    // traits) — never user-edited or real tiers. Direct clear is required because removeTierCard()
    // enforces a min-2-tiers guard and cannot empty the list.
    function imcTiersArePristinePresets() {
        const items = state.tiers.items;
        if (!Array.isArray(items) || items.length !== TIER_PRESETS.length) return false;
        for (let i = 0; i < items.length; i++) {
            const t = items[i], p = TIER_PRESETS[i];
            if (!t || t.name !== p.name || Number(t.editions) !== Number(p.editions)) return false;
            if (t.coverFile || t.coverIpfs || t.coverContentHash || t.masterContentHash || t.masterPoolRef) return false;
            if (Array.isArray(t.traits) && t.traits.length > 0) return false;
        }
        return true;
    }
    function imcClearSeededPresetTiers() {
        if (!imcTiersArePristinePresets()) return;
        state.tiers.items.forEach(function(t) {
            const card = document.getElementById('tier-card-' + t.id);
            if (card) card.remove();
        });
        state.tiers.items = [];
    }
    
    function initTierBuilder() {
        const radios = document.querySelectorAll('input[name="tier_mode"]');
        const singleSection = document.getElementById('single-cover-section');
        const tierSection = document.getElementById('tier-builder-section');
        const addBtn = document.getElementById('add-tier-btn');
        
        if (!radios.length || !singleSection || !tierSection) return;
        
        // Toggle between single cover and tier builder
        radios.forEach(radio => {
            radio.addEventListener('change', () => {
                const isTiers = radio.value === 'tiers' && radio.checked;
                state.tiers.enabled = isTiers;
                
                // Toggle card active states
                document.querySelectorAll('.tier-toggle-card').forEach(c => c.classList.remove('active'));
                radio.closest('.tier-toggle-option')?.querySelector('.tier-toggle-card')?.classList.add('active');
                
                singleSection.style.display = isTiers ? 'none' : 'block';
                tierSection.style.display = isTiers ? 'block' : 'none';

                // v699: reveal the optional listing-card cover only in Rarity Tiers mode
                const tccRow = document.getElementById('tier-card-cover-row');
                if (tccRow) tccRow.style.display = isTiers ? 'block' : 'none';

                // Toggle full-width class for CSS grid breakout
                const coverZone = document.getElementById('cover-upload-zone');
                if (coverZone) {
                    coverZone.classList.toggle('tier-mode-active', isTiers);
                }

                // v481: Refresh upload zone visibility — primary/preview hide in media tier mode
                updateUploadLabels();

                // v481b fix: Show/hide pool-master-section on tier toggle.
                // updatePoolSectionVisibility() is local to initTierBuilder and only
                // runs once at init — so we inline the same logic here to respond to
                // every toggle (and content type is already set at this point).
                const poolSec = document.getElementById('pool-master-section');
                if (poolSec) {
                    const showPool = isTiers && ['music', 'musicVideo', 'film'].includes(state.contentType);
                    poolSec.style.display = showPool ? 'block' : 'none';
                    // Render pool zones so tier badge strip reflects current tiers
                    if (showPool) renderPoolZones();
                }

                // Seed with default tiers if empty
                if (isTiers && state.tiers.items.length === 0) {
                    TIER_PRESETS.forEach(p => addTierCard(p.name, p.editions));
                }
                
                syncEditionsFromTiers();
            });
        });
        
        // Add tier button
        addBtn?.addEventListener('click', () => {
            // v365: Art supports up to 1000 tiers; v454: music/musicvideo/film raised from 100 to 250, then to 500 (all non-Art types)
            const maxTiers = state.contentType === 'art' ? 1000 : 1000;
            if (state.tiers.items.length >= maxTiers) {
                imcToast('Maximum ' + maxTiers + ' tiers per listing');
                return;
            }
            addTierCard('', 1);
        });

        // Pool master section: show for music/mv/film, hidden for art/album
        // (art pool zones are rendered per-tier; album has no tier masters)
        const updatePoolSectionVisibility = () => {
            const poolSection = document.getElementById('pool-master-section');
            if (!poolSection) return;
            const show = ['music', 'musicVideo', 'film', 'musicvideo'].includes(state.contentType);
            poolSection.style.display = show ? 'block' : 'none';
        };
        updatePoolSectionVisibility();

        // Add pool master button
        document.getElementById('add-pool-master-btn')?.addEventListener('click', addPoolMaster);

        // Bulk cover folder input
        document.getElementById('bulk-cover-folder-input')?.addEventListener('change', (e) => {
            if (e.target.files && e.target.files.length > 0) {
                handleBulkCoverFolder(e.target.files);
                e.target.value = ''; // Reset so same folder can be re-selected
            }
        });

        // Bulk traits CSV input
        document.getElementById('bulk-traits-csv-input')?.addEventListener('change', (e) => {
            if (e.target.files && e.target.files[0]) {
                handleBulkTraitsCSV(e.target.files[0]);
                e.target.value = '';
            }
        });

        // Traits CSV template download (wired to both the bulk panel link and tier-builder-intro link)
        const handleTemplateDownload = (e) => {
            e.preventDefault();
            const csv = 'tier_number,tier_name,editions,master_file,Background,Body,Eyes,Rarity\n1,Legendary,5,1,Gold,Dragon,Flame,1\n2,Rare,20,1,Blue,Phoenix,Glowing,2\n3,Common,100,2,Gray,Default,Normal,3\n';
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url; a.download = 'imc-traits-template.csv'; a.click();
            URL.revokeObjectURL(url);
        };
        document.getElementById('bulk-traits-template-link')?.addEventListener('click', handleTemplateDownload);
        document.getElementById('bulk-traits-template-link-intro')?.addEventListener('click', handleTemplateDownload);
    }
    
    function addTierCard(name = '', editions = 1, skipSummary = false) {
        const tierId = state.tiers.nextId++;
        const container = document.getElementById('tier-cards-container');
        if (!container) return;
        
        // v143: Content-type aware tier card layout (must be before tierData)
        const isArt = state.contentType === 'art';
        const isAlbum = state.contentType === 'album'; // v461: Album tiers = cover art only
        const isMusic = state.contentType === 'music';
        const isMusicVideo = state.contentType === 'musicVideo';
        const isFilm = state.contentType === 'film';
        const masterLabel = isFilm ? 'film' : isMusicVideo ? 'video' : 'audio';
        const masterIcon = isFilm ? '<svg class="imc-ic" aria-hidden="true"><use href="#ic-video"></use></svg>' : isMusicVideo ? '<svg class="imc-ic" aria-hidden="true"><use href="#ic-clapper"></use></svg>' : '<svg class="imc-ic" aria-hidden="true"><use href="#ic-music"></use></svg>';
        const masterAccept = (isMusicVideo || isFilm) ? 'audio/*,video/*' : 'audio/*';
        
        // Create tier data object
        const tierData = {
            id: tierId,
            name: name,
            editions: editions,
            coverFile: null,
            coverIpfs: null,
            coverContentHash: null, // v377: SHA256 of original cover (for watermarked Music/MV/Film tiers)
            masterContentHash: null,
            masterPoolRef: null,    // ID of masterPool item (null = use listing default master)
            useDefaultMaster: isArt ? false : true,  // v144: Art always needs unique master per tier; album always shares tracks
            watermarkable: false,  // v43: Set true when art master is a watermarkable format
            traits: []
        };
        state.tiers.items.push(tierData);
        
        // Build DOM
        const card = document.createElement('div');
        card.className = 'tier-card';
        card.id = `tier-card-${tierId}`;
        
        // Common header
        let cardHtml = `
            <div class="tier-card-header">
                <div class="tier-card-number">${state.tiers.items.length}</div>
                <div class="tier-card-fields">
                    <div class="form-group">
                        <label>Tier Name</label>
                        <input type="text" class="tier-name-input" data-tier="${tierId}" 
                               placeholder="e.g., Legendary, Rare, Common" value="${escHtmlAttr(name)}">
                    </div>
                    <div class="form-group">
                        <label>Editions</label>
                        <input type="number" class="tier-editions-input" data-tier="${tierId}" 
                               min="1" max="10000" value="${editions}" placeholder="1">
                    </div>
                </div>
                <button type="button" class="tier-remove-btn" data-tier="${tierId}" title="Remove tier">✕</button>
            </div>`;
        
        if (isArt) {
            // ── ART FLOW: Master Artwork (VPS) dominant, then Public Preview (IPFS) compact ──
            // v43: Master uses cover-zone styling (large/dashed) since it's the primary upload
            cardHtml += `
            <div class="tier-cover-zone">
                <label>Master Artwork <span style="color:#d4af37;">*</span> <span style="color:#888;font-size:0.75rem;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-lock"></use></svg> Stored securely off-chain</span></label>
                <div class="tier-cover-dropzone" id="tier-master-drop-${tierId}">
                    <span class="tier-cover-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-palette"></use></svg> </span>
                    <span class="tier-cover-text"><strong>Choose full quality</strong> master artwork for this tier</span>
                    <input type="file" accept="image/*,.psd,.tiff,.tif,.bmp,.raw" data-tier="${tierId}" data-type="master">
                </div>
                <div class="tier-master-preview" id="tier-master-preview-${tierId}" style="display:none;align-items:center;gap:10px;">
                    <img id="tier-master-img-${tierId}" src="" alt="Master preview" style="width:56px;height:56px;object-fit:cover;border-radius:6px;border:1px solid rgba(212,175,55,0.3);">
                    <span id="tier-master-name-${tierId}" style="flex:1;"></span>
                    <span class="preview-badge master-badge" style="font-size:0.7rem;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-lock"></use></svg> Master</span>
                    <button type="button" class="tier-master-remove" data-tier="${tierId}" data-type="master">✕</button>
                </div>
            </div>

            <div class="tier-master-zone" id="tier-cover-zone-${tierId}">
                <label>Public Preview <span style="color:#d4af37;">*</span></label>
                <div class="tier-master-dropzone" id="tier-cover-drop-${tierId}">
                    <svg class="imc-ic" aria-hidden="true"><use href="#ic-image"></use></svg> <span><strong>Choose public preview</strong> — low-res or watermarked (IPFS)</span>
                    <input type="file" accept="image/*" data-tier="${tierId}" data-type="cover">
                </div>
                <div class="tier-cover-preview" id="tier-cover-preview-${tierId}" style="display:none;">
                    <img id="tier-cover-img-${tierId}" src="" alt="Tier preview">
                    <span class="tier-cover-info" id="tier-cover-name-${tierId}"></span>
                    <button type="button" class="tier-cover-remove" data-tier="${tierId}" data-type="cover">✕</button>
                </div>
                <div id="tier-wm-notice-${tierId}" style="display:none;padding:8px;color:#d4af37;font-size:0.85rem;">
                    ✨ Watermarked preview will be auto-generated from master file
                </div>
            </div>`;
            // No media/master override toggle for art — each tier always has its own master
        } else {
            // ── MUSIC / MUSICVIDEO / FILM / ALBUM FLOW ──
            // Cover Art (IPFS, public-facing)
            cardHtml += `
            <div class="tier-cover-zone">
                <label>Cover Art <span style="color:#d4af37;">*</span></label>
                <div class="tier-cover-dropzone" id="tier-cover-drop-${tierId}">
                    <span class="tier-cover-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-image"></use></svg> </span>
                    <span class="tier-cover-text"><strong>Choose cover</strong> for this tier (PNG, JPG, GIF, WebP)</span>
                    <input type="file" accept="image/*" data-tier="${tierId}" data-type="cover">
                </div>
                <div class="tier-cover-preview" id="tier-cover-preview-${tierId}" style="display:none;">
                    <img id="tier-cover-img-${tierId}" src="" alt="Tier cover">
                    <span class="tier-cover-info" id="tier-cover-name-${tierId}"></span>
                    <button type="button" class="tier-cover-remove" data-tier="${tierId}" data-type="cover">✕</button>
                </div>
            </div>`;
            
            // v461: Album tiers = cover art only — no master override (tracks are shared)
            if (!isAlbum) {
                // Build pool dropdown options from current state.masterPool
                const poolOptions = state.masterPool.length > 0
                    ? state.masterPool.map(p =>
                        `<option value="${p.id}">${escHtmlAttr(p.label || `Media ${p.id}`)}</option>`
                      ).join('')
                    : '<option value="" disabled>No media uploaded yet — add above</option>';

                cardHtml += `
            <!-- Pool master selector: artist picks which pool master this tier uses -->
            <div class="tier-pool-select-wrap">
                <label>Master ${masterLabel} <span style="color:#d4af37;">*</span>
                    <span style="color:#888;font-size:0.75rem;">Select from uploaded media above</span>
                </label>
                <select class="tier-pool-select" data-tier="${tierId}">
                    <option value="">Use listing default ${masterLabel}</option>
                    ${poolOptions}
                </select>
                <div class="tier-pool-selected-name" id="tier-pool-name-${tierId}" style="font-size:0.8rem;color:#d4af37;margin-top:4px;"></div>
            </div>`;
            }
        }
        
        // Tier traits (all content types)
        cardHtml += `
            <div class="tier-traits-section">
                <div class="tier-traits-toggle" data-tier="${tierId}">
                    <span class="toggle-arrow" id="tier-traits-arrow-${tierId}">▸</span>
                    <span>Tier-specific traits</span>
                </div>
                <div class="tier-traits-body" id="tier-traits-body-${tierId}">
                    <div class="tier-traits-list" id="tier-traits-list-${tierId}"></div>
                    <button type="button" class="tier-add-trait-btn" data-tier="${tierId}">+ Add Trait</button>
                </div>
            </div>`;
        
        card.innerHTML = cardHtml;
        
        container.appendChild(card);
        
        // Wire events for this card
        wireTierCardEvents(card, tierId);

        // Skip summary recalculation during bulk creation (caller invokes once at end)
        if (!skipSummary) {
            updateTierSummary();
        }
    }
    
    function wireTierCardEvents(card, tierId) {
        // Name/editions inputs
        card.querySelector('.tier-name-input')?.addEventListener('input', (e) => {
            const tier = state.tiers.items.find(t => t.id === tierId);
            if (tier) tier.name = e.target.value;
            updateTierSummary();
        });
        card.querySelector('.tier-editions-input')?.addEventListener('input', (e) => {
            const tier = state.tiers.items.find(t => t.id === tierId);
            if (tier) tier.editions = Math.max(1, parseInt(e.target.value) || 1);
            updateTierSummary();
            syncEditionsFromTiers();
        });
        
        // Remove tier
        card.querySelector('.tier-remove-btn')?.addEventListener('click', () => removeTierCard(tierId));
        
        // Cover upload
        const coverDrop = card.querySelector(`#tier-cover-drop-${tierId}`);
        const coverInput = coverDrop?.querySelector('input[type="file"]');
        coverDrop?.addEventListener('click', (e) => {
            if (e.target.tagName !== 'INPUT') coverInput?.click();
        });
        coverInput?.addEventListener('change', (e) => {
            if (e.target.files[0]) handleTierCoverFile(tierId, e.target.files[0]);
        });
        card.querySelector(`.tier-cover-remove`)?.addEventListener('click', () => removeTierCover(tierId));

        // v702: Per-tier master ARTWORK upload — ART tiers only. This box (#tier-master-drop)
        // exists solely in the art render branch; music/mv/film/album have no such element, so
        // this binding is inherently art-scoped and cannot affect other flows. Mirrors the
        // cover-drop wiring above. handleTierMasterFile sets tier.masterFile (uploaded at submit).
        if (state.contentType === 'art') {
            const masterDrop = card.querySelector(`#tier-master-drop-${tierId}`);
            const masterInput = masterDrop?.querySelector('input[type="file"]');
            masterDrop?.addEventListener('click', (e) => {
                if (e.target.tagName !== 'INPUT') masterInput?.click();
            });
            masterInput?.addEventListener('change', (e) => {
                if (e.target.files[0]) handleTierMasterFile(tierId, e.target.files[0]);
            });
            card.querySelector(`.tier-master-remove`)?.addEventListener('click', () => removeTierMaster(tierId));
        }
        
        // Pool master selector (music/mv/film only — not on art or album cards)
        const poolSelect = card.querySelector('.tier-pool-select');
        if (poolSelect) {
            poolSelect.addEventListener('change', () => {
                const tier = state.tiers.items.find(t => t.id === tierId);
                if (!tier) return;
                const val = poolSelect.value;
                tier.masterPoolRef = val ? parseInt(val) : null;
                tier.useDefaultMaster = (tier.masterPoolRef === null);
                // Show selected pool item name as confirmation
                const nameEl = document.getElementById(`tier-pool-name-${tierId}`);
                if (nameEl) {
                    if (tier.masterPoolRef !== null) {
                        const poolItem = state.masterPool.find(p => p.id === tier.masterPoolRef);
                        nameEl.textContent = poolItem ? `✅ ${poolItem.label}` : '';
                    } else {
                        nameEl.textContent = '';
                    }
                }
            });
        }
        
        // C1 fix: Tier traits toggle (expand/collapse)
        card.querySelector('.tier-traits-toggle')?.addEventListener('click', () => {
            const body = document.getElementById(`tier-traits-body-${tierId}`);
            const arrow = document.getElementById(`tier-traits-arrow-${tierId}`);
            if (body) body.classList.toggle('visible');
            if (arrow) arrow.classList.toggle('open');
        });
        
        // C2 fix: Tier add trait button
        card.querySelector('.tier-add-trait-btn')?.addEventListener('click', () => addTierTrait(tierId));
        
        document.querySelectorAll('.tier-card').forEach((card, idx) => {
            const num = card.querySelector('.tier-card-number');
            if (num) num.textContent = idx + 1;
        });
        
        updateTierSummary();
        syncEditionsFromTiers();
    }
    
    function handleTierCoverFile(tierId, file) {
        if (!file.type.startsWith('image/')) {
            imcToast('Cover must be an image file');
            return;
        }
        if (file.size > 50 * 1024 * 1024) {
            imcToast('Cover image must be under 50MB');
            return;
        }
        
        const tier = state.tiers.items.find(t => t.id === tierId);
        if (!tier) return;
        tier.coverFile = file;
        tier.coverIpfs = null; // Reset CID — will upload during handleMint
        
        // Show preview
        const reader = new FileReader();
        reader.onload = (e) => {
            const img = document.getElementById(`tier-cover-img-${tierId}`);
            const nameEl = document.getElementById(`tier-cover-name-${tierId}`);
            if (img) img.src = e.target.result;
            if (nameEl) nameEl.textContent = file.name;
            
            const dropzone = document.getElementById(`tier-cover-drop-${tierId}`);
            const preview = document.getElementById(`tier-cover-preview-${tierId}`);
            if (dropzone) dropzone.style.display = 'none';
            if (preview) preview.style.display = 'flex';
        };
        reader.readAsDataURL(file);
    }
    
    function removeTierCover(tierId) {
        const tier = state.tiers.items.find(t => t.id === tierId);
        if (tier) { tier.coverFile = null; tier.coverIpfs = null; }
        
        const dropzone = document.getElementById(`tier-cover-drop-${tierId}`);
        const preview = document.getElementById(`tier-cover-preview-${tierId}`);
        if (dropzone) dropzone.style.display = 'flex';
        if (preview) preview.style.display = 'none';
        
        // Reset file input
        const input = dropzone?.querySelector('input[type="file"]');
        if (input) input.value = '';
    }
    
    // v143: Handle master file upload for a tier (music/mv/film override)
    function handleTierMasterFile(tierId, file) {
        const isArt = state.contentType === 'art';
        if (!isArt && !file.type.startsWith('audio/') && !file.type.startsWith('video/')) {
            imcToast('Master file must be an audio or video file');
            return;
        }
        if (isArt) {
            // v226: Validate by extension — PSD/RAW files have inconsistent MIME types across browsers
            const ext = file.name.split('.').pop()?.toLowerCase();
            const artExts = ['png', 'jpg', 'jpeg', 'tiff', 'tif', 'psd', 'bmp', 'webp', 'gif', 'raw'];
            if (!ext || !artExts.includes(ext)) {
                imcToast('Master artwork must be an image file (PNG, JPG, TIFF, PSD, BMP, WebP, GIF, RAW)');
                return;
            }
        }
        
        const tier = state.tiers.items.find(t => t.id === tierId);
        if (!tier) return;
        tier.masterFile = file;
        tier.masterContentHash = null;
        
        const nameEl = document.getElementById(`tier-master-name-${tierId}`);
        if (nameEl) nameEl.textContent = file.name;
        
        const dropzone = document.getElementById(`tier-master-drop-${tierId}`);
        const preview = document.getElementById(`tier-master-preview-${tierId}`);
        if (dropzone) dropzone.style.display = 'none';
        if (preview) preview.style.display = 'flex';

        // v43: Show thumbnail preview for art master files (browser-renderable types only)
        if (isArt) {
            const thumbImg = document.getElementById(`tier-master-img-${tierId}`);
            if (thumbImg && file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = (e) => { thumbImg.src = e.target.result; };
                reader.readAsDataURL(file);
            } else if (thumbImg) {
                // PSD/RAW: browser can't render — show placeholder
                thumbImg.style.display = 'none';
            }
        }

        // v43: Art tier watermark detection — hide cover upload when auto-watermark available
        if (isArt) {
            tier.watermarkable = isWatermarkable(file.name);
            const coverDrop = document.getElementById(`tier-cover-drop-${tierId}`);
            const coverPreview = document.getElementById(`tier-cover-preview-${tierId}`);
            const wmNotice = document.getElementById(`tier-wm-notice-${tierId}`);
            if (tier.watermarkable) {
                if (coverDrop) coverDrop.style.display = 'none';
                if (coverPreview) coverPreview.style.display = 'none';
                if (wmNotice) wmNotice.style.display = 'block';
                tier.coverFile = null;
                tier.coverIpfs = null;
            } else {
                if (coverDrop && !tier.coverFile && !tier.coverIpfs) coverDrop.style.display = '';
                if (wmNotice) wmNotice.style.display = 'none';
            }
        }
    }
    
    function removeTierMaster(tierId) {
        const tier = state.tiers.items.find(t => t.id === tierId);
        if (tier) {
            tier.masterFile = null;
            tier.masterContentHash = null;
            // v43: Reset watermark state
            tier.watermarkable = false;
        }
        
        const dropzone = document.getElementById(`tier-master-drop-${tierId}`);
        const preview = document.getElementById(`tier-master-preview-${tierId}`);
        if (dropzone) dropzone.style.display = 'flex';
        if (preview) preview.style.display = 'none';
        
        const input = dropzone?.querySelector('input[type="file"]');
        if (input) input.value = '';

        // v43: Reset thumbnail and restore tier cover upload zone
        if (state.contentType === 'art') {
            const thumbImg = document.getElementById(`tier-master-img-${tierId}`);
            if (thumbImg) { thumbImg.src = ''; thumbImg.style.display = ''; }
            const coverDrop = document.getElementById(`tier-cover-drop-${tierId}`);
            const wmNotice = document.getElementById(`tier-wm-notice-${tierId}`);
            if (coverDrop) coverDrop.style.display = '';
            if (wmNotice) wmNotice.style.display = 'none';
        }
    }
    
    // C3 fix: Remove entire tier card from state and DOM
    function removeTierCard(tierId) {
        const idx = state.tiers.items.findIndex(t => t.id === tierId);
        if (idx === -1) return;
        
        // Enforce minimum 2 tiers when enabled
        if (state.tiers.items.length <= 2) {
            imcToast('Rarity tiers require at least 2 tiers. Remove all tiers by disabling the toggle instead.');
            return;
        }
        
        // Remove from state
        state.tiers.items.splice(idx, 1);
        
        // Remove DOM card
        const card = document.getElementById(`tier-card-${tierId}`);
        if (card) card.remove();
        
        // Re-number remaining cards
        document.querySelectorAll('.tier-card').forEach((card, i) => {
            const num = card.querySelector('.tier-card-number');
            if (num) num.textContent = i + 1;
        });
        
        updateTierSummary();
        syncEditionsFromTiers();
    }
    
    function addTierTrait(tierId) {
        const tier = state.tiers.items.find(t => t.id === tierId);
        if (!tier) return;
        
        const traitIdx = tier.traits.length;
        tier.traits.push({ trait_type: '', value: '' });
        
        const list = document.getElementById(`tier-traits-list-${tierId}`);
        if (!list) return;
        
        const row = document.createElement('div');
        row.className = 'tier-trait-row';
        row.id = `tier-trait-${tierId}-${traitIdx}`;
        row.innerHTML = `
            <input type="text" placeholder="Trait name" data-tier="${tierId}" data-trait="${traitIdx}" data-field="trait_type">
            <input type="text" placeholder="Value" data-tier="${tierId}" data-trait="${traitIdx}" data-field="value">
            <button type="button" class="tier-trait-remove" data-tier="${tierId}" data-trait="${traitIdx}">✕</button>
        `;
        
        // Wire events
        row.querySelectorAll('input').forEach(input => {
            input.addEventListener('input', (e) => {
                const tIdx = parseInt(e.target.dataset.trait);
                const field = e.target.dataset.field;
                if (tier.traits[tIdx]) tier.traits[tIdx][field] = e.target.value;
            });
        });
        row.querySelector('.tier-trait-remove')?.addEventListener('click', () => {
            tier.traits.splice(traitIdx, 1);
            row.remove();
        });
        
        list.appendChild(row);
    }
    
    function updateTierSummary() {
        const summary = document.getElementById('tier-summary');
        const bar = document.getElementById('tier-distribution-bar');
        const legend = document.getElementById('tier-distribution-legend');
        const countEl = document.getElementById('tier-total-editions');
        const validationEl = document.getElementById('tier-validation');
        
        if (!summary || state.tiers.items.length === 0) {
            if (summary) summary.style.display = 'none';
            return;
        }
        
        summary.style.display = 'block';
        
        const totalEditions = state.tiers.items.reduce((sum, t) => sum + (t.editions || 0), 0);
        if (countEl) countEl.textContent = `${totalEditions.toLocaleString()} total editions`;
        
        // Distribution bar
        if (bar) {
            bar.innerHTML = '';
            state.tiers.items.forEach((tier, idx) => {
                const pct = totalEditions > 0 ? (tier.editions / totalEditions * 100) : 0;
                const seg = document.createElement('div');
                seg.className = 'tier-bar-segment';
                seg.style.width = `${pct}%`;
                seg.style.backgroundColor = TIER_COLORS[idx % TIER_COLORS.length];
                seg.title = `${tier.name || 'Tier ' + (idx+1)}: ${pct.toFixed(1)}%`;
                bar.appendChild(seg);
            });
        }
        
        // Legend
        if (legend) {
            legend.innerHTML = state.tiers.items.map((tier, idx) => {
                const pct = totalEditions > 0 ? (tier.editions / totalEditions * 100) : 0;
                const color = TIER_COLORS[idx % TIER_COLORS.length];
                const name = tier.name || `Tier ${idx + 1}`;
                return `<span class="tier-legend-item">
                    <span class="tier-legend-dot" style="background:${color}"></span>
                    ${escHtml(name)}: ${tier.editions} (${pct.toFixed(1)}%)
                </span>`;
            }).join('');
        }
        
        // Validation
        if (validationEl) {
            // v43: Watermarkable art tiers don't need manual covers — VPS auto-generates
            const allHaveCovers = state.tiers.items.every(t => t.coverFile || t.coverIpfs || t.watermarkable);
            const allHaveNames = state.tiers.items.every(t => t.name?.trim());
            
            if (totalEditions < 2) {
                validationEl.className = 'tier-validation invalid';
                validationEl.textContent = '⚠ Total editions must be at least 2 for tiers';
                validationEl.style.display = 'block';
            } else if (!allHaveNames) {
                validationEl.className = 'tier-validation info';
                validationEl.textContent = 'ℹ Give each tier a name (e.g., Legendary, Rare, Common)';
                validationEl.style.display = 'block';
            } else if (!allHaveCovers) {
                validationEl.className = 'tier-validation info';
                validationEl.textContent = 'ℹ Each tier needs its own cover artwork';
                validationEl.style.display = 'block';
            } else {
                validationEl.className = 'tier-validation valid';
                validationEl.textContent = `✅ ${state.tiers.items.length} tiers · ${totalEditions} editions · All covers uploaded`;
                validationEl.style.display = 'block';
            }
        }
    
        // U3-r1: keep the vault rows' per-tier dropdowns in step with tier
        // add/remove/rename. Focus-guarded so a re-render never steals the
        // caret from a label being typed in the vault list.
        if (typeof ulRenderPool === 'function' && state.unlockables && state.unlockables.pool.length > 0) {
            const ulList = document.getElementById('ul-pool-list');
            if (!ulList || !ulList.contains(document.activeElement)) { ulRenderPool(); }
        }
    }
    
    function syncEditionsFromTiers() {
        const editionsInput = document.getElementById('nft-editions');
        const notice = document.querySelector('.editions-tier-notice');
        
        if (state.tiers.enabled && state.tiers.items.length > 0) {
            const total = state.tiers.items.reduce((sum, t) => sum + (t.editions || 0), 0);
            if (editionsInput) {
                editionsInput.value = total;
                editionsInput.readOnly = true;
                editionsInput.style.opacity = '0.6';
            }
            // Show notice
            if (notice) {
                notice.style.display = 'block';
                notice.textContent = `${total} editions across ${state.tiers.items.length} rarity tiers (set in Step 2)`;
            }
            // Trigger fee recalculation
            editionsInput?.dispatchEvent(new Event('input'));
        } else {
            if (editionsInput) {
                editionsInput.readOnly = false;
                editionsInput.style.opacity = '1';
            }
            if (notice) notice.style.display = 'none';
        }
    }
    
    function escHtmlAttr(str) {
        return (str || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    function escHtml(str) {
        return (str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    
    function switchCollectionTab(tabName) {
        state.collection.isNew = (tabName === 'new');
        elements.collectionTabs?.forEach(tab => {
            tab.classList.toggle('active', tab.dataset.tab === tabName);
        });
        elements.collectionTabContents?.forEach(content => {
            content.classList.toggle('active', content.dataset.tabContent === tabName);
        });
        
        // Load existing collections when switching to that tab
        if (tabName === 'existing') {
            loadExistingCollections();
        }
    }

    // M1-e2c: wire the pages panel (source toggle, inputs, CSV, PDF).
    // M2-b: one picker, no source toggle. Images and PDFs arrive through the same inputs.
    function bindBookPages() {
        const fi = document.getElementById('bp-files-input');
        if (fi) fi.addEventListener('change', function (e) { addBookPages(e.target.files); e.target.value = ''; });
        const fo = document.getElementById('bp-folder-input');
        if (fo) fo.addEventListener('change', function (e) { addBookPages(e.target.files); e.target.value = ''; });
        const cs = document.getElementById('bp-csv-input');
        if (cs) cs.addEventListener('change', function (e) { if (e.target.files[0]) handleBookPagesCSV(e.target.files[0]); e.target.value = ''; });
        const tpl = document.getElementById('bp-csv-template');
        if (tpl) tpl.addEventListener('click', function (e) {
            e.preventDefault();
            const blob = new Blob(['page,filename\n1,page-001.jpg\n2,page-002.jpg\n3,chapter-one.pdf\n'], { type: 'text/csv' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob); a.download = 'book-pages-template.csv'; a.click();
        });
    }
    function bindAudiobookSubtype() {
        document.querySelectorAll('input[name="audiobook_format"]').forEach(function (r) {
            r.addEventListener('change', function () {
                if (!r.checked) return;
                state.audiobook.format = r.value;
                updateFieldVisibility();
                updateUploadLabels();
                setTimeout(function () { goToStep(1); }, 250);
            });
        });
    }

    /* M3 helpers ---------------------------------------------------------- */
    var IMC_TYPE_GROUPS = {
        music: ['music', 'musicVideo', 'album'],
        books: ['ebook', 'audiobook'],
        art:   ['art'],
        film:  ['film']
    };
    function groupForType(type) {
        for (var g in IMC_TYPE_GROUPS) {
            if (IMC_TYPE_GROUPS[g].indexOf(type) !== -1) return g;
        }
        return null;
    }
    // Reveal one group's panel, mark its card, hide the rest.
    function openContentGroup(gkey) {
        document.querySelectorAll('.content-subtype-panel').forEach(function (p) {
            p.style.display = (p.dataset.group === gkey) ? 'block' : 'none';
        });
        elements.contentGroupCards?.forEach(function (c) {
            c.classList.toggle('selected', c.dataset.group === gkey);
        });
    }
    // Chose a group but not yet a member: nothing may remain selected downstream.
    function clearContentType() {
        state.contentType = null;
        elements.contentTypeCards?.forEach(function (c) { c.classList.remove('selected'); });
        var _ab = document.getElementById('audiobook-subtype-panel');
        if (_ab) { _ab.style.display = 'none'; }
        state.audiobook.format = null;
    }

    function selectContentType(type) {
        // M1-e1b: the AudioBook sub-type panel is only shown for audiobook.
        var _abPanel = document.getElementById('audiobook-subtype-panel');
        if (_abPanel) {
            _abPanel.style.display = (type === 'audiobook') ? 'block' : 'none';
            if (type !== 'audiobook') {
                state.audiobook.format = null;
                var _abS = document.getElementById('ab-format-single');
                if (_abS) _abS.checked = false;
            }
        }
        // M3: keep the group layer in step with the leaf (covers clicks, draft resume
        // and any programmatic call).
        var _g = groupForType(type);
        if (_g) { openContentGroup(_g); }
        state.artAutoWatermark = null; // PP-6 P2: reset the detect on every type change
        state.contentType = type;
        elements.contentTypeCards?.forEach(card => {
            card.classList.toggle('selected', card.dataset.type === type);
        });
        
        // v485: updateFieldVisibility() before updateUploadLabels() — matches restoreDraft() order.
        // Previously reversed: updateUploadLabels() hid audio-upload-zone for musicVideo, then
        // updateFieldVisibility() re-showed it (zone still had class "musicVideo"). Now visibility
        // runs first (sets display:none on audio zone), then labels run (confirm hide). Clean.
        updateFieldVisibility();
        updateUploadLabels();
        updateContentPlaceholders();
        
        // Seed default "Access Pass" trait if traits list is empty
        const traitsList = document.getElementById('custom-traits-list');
        if (traitsList && traitsList.children.length === 0) {
            addCustomTrait('Access Pass', '');
        }
        
        // M1-e1d: AudioBook pauses on step 0 until a sub-type is chosen (the panel is
        // right below the cards); every other type keeps the original auto-advance.
        if (type === 'audiobook' && !state.audiobook.format) {
            return;
        }
        setTimeout(() => goToStep(1), 300);
    }

    function updateUploadLabels() {
        // 8d-i: visible limits under each upload zone (per-type; the server's own numbers).
        (function () {
            var ct = state.contentType;
            var masterTxt = ct === 'art'  ? 'Images + PSD / TIFF / BMP / RAW — up to 500MB · Recommended: 1000×1000+'
                          : ct === 'musicVideo' ? 'Video — up to 2GB'
                          : ct === 'film' ? 'Video — up to 10GB'
                          : ct === 'audiobook' ? 'Audio — up to 1GB'
                          : 'Audio — up to 500MB';
            var previewTxt = ct === 'film' ? 'Video clip — up to 100MB · max 120s'
                           : ct === 'audiobook' ? 'Audio — up to 100MB · max 60s'
                           : 'Audio — up to 100MB · max 30s';
            var coverTxt = 'JPG · PNG · WebP · GIF — up to 50MB · animated GIFs publish up to 15MB after processing';
            // M2-b2: books use cover language, and the public preview is the watermarked cover.
            if (ct === 'ebook' || ct === 'audiobook') {
                var _cvLabel = document.getElementById('cover-upload-label');
                if (_cvLabel) _cvLabel.textContent = 'Upload Front Cover';
                var _cvHint = document.getElementById('cover-upload-hint');
                if (_cvHint) _cvHint.textContent = 'PNG, JPG, WebP — watermarked for the public preview';
            }
            var notes = { 'primary-file-input': masterTxt, 'art-master-file-input': masterTxt,
                          'preview-file-input': previewTxt, 'audio-file-input': 'Audio — up to 500MB',
                          'cover-file-input': coverTxt, 'collection-cover-input': 'JPG · PNG · WebP · GIF — up to 50MB' };
            Object.keys(notes).forEach(function (id) {
                var inp = document.getElementById(id);
                if (!inp) return;
                var zone = inp.closest('.upload-zone, .file-upload-zone, .upload-area, .dropzone') || inp.parentElement;
                if (!zone) return;
                var note = zone.querySelector(':scope > .imc-limit-note');
                if (!note) { note = document.createElement('div'); note.className = 'imc-limit-note'; zone.appendChild(note); }
                note.textContent = notes[id];
            });
        })();
        // ── Phase A (v827): dynamic per-type hero identity ──
        // Hook point chosen deliberately: updateUploadLabels() is called by BOTH
        // selectContentType() and the restoreDraft() path (v485 order), so the
        // title/subtitle always match state.contentType, including on draft resume.
        (function applyTypeIdentity() {
            const names = { music: 'Music', musicVideo: 'Music Video', art: 'Art', film: 'Film', album: 'Album', audiobook: 'AudioBook', ebook: 'eBook' };
            const subs = {
                music: 'Mint your track as a protected Access NFT — public preview on IPFS, master stored securely off-chain, streaming for holders across the IMU ecosystem.',
                musicVideo: 'Mint your official video as a protected Access NFT — public preview on IPFS, full video unlocked for holders across the IMU ecosystem.',
                art: 'Mint your artwork as a protected Access NFT — watermarked public preview, secure off-chain master, full resolution unlocked for holders.',
                film: 'Mint your film as a protected Access NFT — trailer preview on IPFS, the full feature unlocked for holders across the IMU ecosystem.',
                album: 'Mint your album as a single Access NFT — one token unlocks every track with sequential playback across the IMU ecosystem.'
            };
            const t = state.contentType;
            const h = document.getElementById('mint-hero-title');
            const s = document.getElementById('mint-hero-subtitle');
            if (h && names[t]) h.textContent = 'Create ' + names[t] + ' NFT';
            var bt = document.getElementById('msb-title');
            if (bt && names[t]) bt.textContent = 'Create ' + names[t] + ' NFT';
            if (s && subs[t]) s.textContent = subs[t];
            if (names[t]) document.title = 'Create ' + names[t] + ' NFT | IMCollectibles';
        })();
        const primaryLabel = document.getElementById('primary-upload-label');
        const primaryHint = document.getElementById('primary-upload-hint');
        const primaryInput = document.getElementById('primary-file-input');
        const audioZone = document.getElementById('audio-upload-zone');
        const coverLabel = document.querySelector('#cover-dropzone h4');
        const coverHint = document.querySelector('#cover-dropzone p');
        const previewLabel = document.getElementById('preview-upload-label');
        const previewHint = document.getElementById('preview-upload-hint');
        
        // v144: Reset zone visibility for type switching
        const primaryZone = document.getElementById('primary-upload-zone');
        const artMasterZone = document.getElementById('art-master-single-zone');
        const tierToggleLabel = document.querySelector('.tier-toggle-label');
        const tierBuilderIntro = document.querySelector('.tier-builder-intro p');
        // v24: Hide primaryZone for art AND album (album uses per-track upload zones)
        // v481: Also hide for music/mv/film when tiers enabled — pool section replaces it
        const isMediaTierMode = state.tiers.enabled && ['music','musicVideo','film'].includes(state.contentType);
        // M1-e2d: ONE writer for the master zone. A chaptered AudioBook has no
        // listing-level master (its chapters are the masters), so it hides here too.
        // updateFieldVisibility must NOT also write this element - it runs first and
        // was being overwritten, which left "Upload AudioBook Audio" on screen.
        const isAbChaptered = state.contentType === 'audiobook' && state.audiobook && state.audiobook.format === 'chaptered';
        // M2-b: an eBook's pages ARE its masters - no listing-level master file.
        if (primaryZone) primaryZone.style.display = (state.contentType === 'art' || state.contentType === 'album' || state.contentType === 'ebook' || isMediaTierMode || isAbChaptered) ? 'none' : '';
        // Hide listing-level preview zone in media tier mode (pool items each have own preview)
        const previewZone = document.getElementById('preview-upload-zone');
        // M2-b2: ONE writer for the preview zone. An eBook has no audio, so no preview
        // clip - its public preview is the watermarked cover. updateFieldVisibility must
        // NOT also write this element: it runs first and was being overwritten.
        if (previewZone) previewZone.style.display = (isMediaTierMode || state.contentType === 'art' || state.contentType === 'ebook') ? 'none' : '';
        if (artMasterZone) artMasterZone.style.display = (state.contentType === 'art') ? 'block' : 'none';
        
        if (state.contentType === 'audiobook') {
            if (primaryLabel) primaryLabel.textContent = 'Upload AudioBook Audio';
            if (primaryHint) primaryHint.textContent = 'MP3, WAV, FLAC, M4A, M4B (Max 1GB) - stored securely off-chain';
            if (primaryInput) primaryInput.accept = 'audio/*';
            if (audioZone) audioZone.style.display = 'none';
            if (coverLabel) coverLabel.textContent = 'Upload Front Cover';
            if (coverHint) coverHint.textContent = 'PNG, JPG, WebP - watermarked for the public preview';
            if (previewLabel) previewLabel.textContent = 'Upload Preview Audio';
            if (previewHint) previewHint.textContent = 'MP3, WAV, FLAC (Max 60 seconds) - public sample on IPFS';
        } else if (state.contentType === 'music') {
            if (primaryLabel) primaryLabel.textContent = 'Upload Master Audio';
            if (primaryHint) primaryHint.textContent = 'MP3, WAV, FLAC, M4A (Max 500MB) — Stored securely off-chain';
            if (primaryInput) primaryInput.accept = 'audio/*';
            if (audioZone) audioZone.style.display = 'none';
            if (coverLabel) coverLabel.textContent = 'Upload Cover Art';
            if (coverHint) coverHint.textContent = 'PNG, JPG, GIF, WebP (Min 1000x1000 recommended)';
            if (previewLabel) previewLabel.textContent = 'Upload Preview Audio';
            if (previewHint) previewHint.textContent = 'MP3, WAV, FLAC (Max 30 seconds) — Public preview on IPFS';
            if (tierToggleLabel) tierToggleLabel.textContent = 'Cover Artwork Mode:';
            if (tierBuilderIntro) tierBuilderIntro.textContent = 'Create different cover artworks (and optionally different audio/video) for each rarity tier. Buyers get a random tier when they mint — mystery box style!';
        } else if (state.contentType === 'musicVideo') {
            if (primaryLabel) primaryLabel.textContent = 'Upload Master Video';
            if (primaryHint) primaryHint.textContent = 'MP4, MOV, WebM (Max 2GB) — Stored securely off-chain';
            if (primaryInput) primaryInput.accept = 'video/*';
            if (audioZone) audioZone.style.display = 'none'; // v204: Removed stray audio upload — audio is part of the video
            if (coverLabel) coverLabel.textContent = 'Upload Cover Art';
            if (coverHint) coverHint.textContent = 'PNG, JPG, GIF, WebP (Min 1000x1000 recommended)';
            if (previewLabel) previewLabel.textContent = 'Upload Preview Clip';
            if (previewHint) previewHint.textContent = 'MP4, MP3, WAV, FLAC (Max 30 seconds) — Public preview on IPFS';
            const previewInput = document.getElementById('preview-file-input');
            if (previewInput) previewInput.accept = 'video/*,audio/*';
            if (tierToggleLabel) tierToggleLabel.textContent = 'Cover Artwork Mode:';
            if (tierBuilderIntro) tierBuilderIntro.textContent = 'Create different cover artworks (and optionally different audio/video) for each rarity tier. Buyers get a random tier when they mint — mystery box style!';
        } else if (state.contentType === 'art') {
            // v144: Art — primary zone hidden, dedicated art master zone shown instead
            if (audioZone) audioZone.style.display = 'none';
            if (coverLabel) coverLabel.textContent = 'Upload Public Preview';
            if (coverHint) coverHint.textContent = 'Low-resolution or watermarked version — shown publicly on IPFS and marketplaces (Min 1000x1000)';
            if (tierToggleLabel) tierToggleLabel.textContent = 'Artwork Mode:';
            if (tierBuilderIntro) tierBuilderIntro.textContent = 'Create different artworks for each rarity tier. Each tier needs a public preview (shown on marketplace) and a master artwork (stored securely off-chain). Buyers get a random tier — mystery box style!';
        } else if (state.contentType === 'film') {
            if (primaryLabel) primaryLabel.textContent = 'Upload Master Film';
            if (primaryHint) primaryHint.textContent = 'MP4, MOV, MKV (Max 10GB) — Stored securely off-chain';
            if (primaryInput) primaryInput.accept = 'video/*';
            if (audioZone) audioZone.style.display = 'none';
            if (coverLabel) coverLabel.textContent = 'Upload Poster / Cover Art';
            if (coverHint) coverHint.textContent = 'Film poster or key art — PNG, JPG, WebP (Min 1000x1000 recommended)';
            if (previewLabel) previewLabel.textContent = 'Upload Preview Clip / Trailer';
            if (previewHint) previewHint.textContent = 'MP4, MOV, WebM (Max 120 seconds) — Public preview on IPFS';
            const previewInput = document.getElementById('preview-file-input');
            if (previewInput) previewInput.accept = 'video/*,audio/*';
            if (tierToggleLabel) tierToggleLabel.textContent = 'Cover Artwork Mode:';
            if (tierBuilderIntro) tierBuilderIntro.textContent = 'Create different cover artworks (and optionally different audio/video) for each rarity tier. Buyers get a random tier when they mint — mystery box style!';
        } else if (state.contentType === 'album') {
            // v24: Album — primary zone hidden (per-track uploads used instead), show cover labels
            if (audioZone) audioZone.style.display = 'none';
            if (coverLabel) coverLabel.textContent = 'Upload Album Cover Art';
            if (coverHint) coverHint.textContent = 'PNG, JPG, GIF, WebP (Min 1000x1000 recommended) — Displayed on all marketplaces';
        }
    }

    /**
     * Update placeholder text and hints based on selected content type.
     * Called when the user selects Music / Music Video / Art / Film on Step 1.
     */
    function updateContentPlaceholders() {
        const titleEl = document.getElementById('nft-title');
        const descEl = document.getElementById('nft-description');
        const titleHint = document.getElementById('nft-title-hint');
        const descHint = document.getElementById('nft-description-hint');
        const collHint = document.getElementById('collection-name-hint');
        const collInput = document.getElementById('collection-name');

        const placeholders = {
            music: {
                title: "e.g. 'Song Title' Access Pass",
                titleHint: 'This is the title shown on XRPL marketplaces. Suggestion: <em>"Your Song Title" Access Pass</em>',
                desc: "e.g. This 'Song Title' Access NFT provides you exclusive access to the full quality music on IMUTV, IMUP3, and IMC! Grab a piece of history, and own a collectible with genuine exclusivity!",
                collPlaceholder: "e.g., My Album 2026, Singles Collection, Summer EP",
                collHint: 'Tip: For single releases, use something like "Singles 2026" or your artist name'
            },
            musicVideo: {
                title: "e.g. 'Video Title' Access Pass",
                titleHint: 'This is the title shown on XRPL marketplaces. Suggestion: <em>"Your Video Title" Access Pass</em>',
                desc: "e.g. This 'Video Title' Access NFT provides you exclusive access to the full quality music video on IMUTV, IMUP3, and IMC! Own a piece of music video history!",
                collPlaceholder: "e.g., Music Video Collection, Visual EP, Live Sessions",
                collHint: 'Tip: Group videos by project, or use "Music Videos 2026" for singles'
            },
            art: {
                title: "e.g. 'Artwork Title' Access Pass",
                titleHint: 'This is the title shown on XRPL marketplaces. Suggestion: <em>"Your Artwork Title" Access Pass</em>',
                desc: "e.g. This 'Artwork Title' Access NFT provides you exclusive access to the full resolution artwork on IMCollectibles! Own the original, high-quality digital piece.",
                collPlaceholder: "e.g., Abstract Series, Portraits 2026, Digital Gallery",
                collHint: 'Tip: Use a series name like "Abstract Collection" or your studio name'
            },
            film: {
                title: "e.g. 'Film Title' Access Pass",
                titleHint: 'This is the title shown on XRPL marketplaces. Suggestion: <em>"Your Film Title" Access Pass</em>',
                desc: "e.g. This 'Film Title' Access NFT grants you exclusive streaming access to the full film on IMUTV and IMCollectibles! Own a piece of independent cinema.",
                collPlaceholder: "e.g., Film Trilogy, Documentary Series, Shorts Collection",
                collHint: 'Tip: Use the film title, series name, or "Films by [Director]"'
            },
            album: {
                title: "e.g. 'Album Title' Access Pass",
                titleHint: 'This is the title shown on XRPL marketplaces. Suggestion: <em>"Your Album Title" Access Pass</em>',
                desc: "e.g. This 'Album Title' Album Access NFT provides you exclusive access to all tracks in full quality on IMUTV, IMUP3, and IMCollectibles! Own a piece of music history.",
                collPlaceholder: "e.g., My Album 2026, Debut EP, Summer Collection",
                collHint: 'Tip: Use the album or EP title as your collection name'
            }
        };

        const p = placeholders[state.contentType] || placeholders.music;

        if (titleEl) titleEl.placeholder = p.title;
        if (descEl) descEl.placeholder = p.desc;
        if (titleHint) titleHint.innerHTML = p.titleHint;
        if (collInput) collInput.placeholder = p.collPlaceholder;
        if (collHint) collHint.innerHTML = p.collHint;
    }

    function goToStep(stepNum) {
        state.currentStep = stepNum;
        // U0: keep the nav-bar fee chip current as the creator progresses.
        if (state.contentType) { try { updateFeeEstimate(); } catch (e) {} }
        if (window.imcClearFieldErrors) window.imcClearFieldErrors();
        // ── B-b: sticky-bar step text + book target (mirrors displayed numbering) ──
        try {
            var _ps = document.querySelector('.progress-step[data-step="' + stepNum + '"]');
            var _bs = document.getElementById('msb-step');
            if (_ps && _bs) {
                var _n = _ps.querySelector('.step-number');
                var _l = _ps.querySelector('.step-label');
                _bs.textContent = (_n ? ('Step ' + _n.textContent.trim() + ' \u2014 ') : '') + (_l ? _l.textContent.trim() : '');
            }
            var _bk = document.getElementById('msb-book');
            if (_bk) _bk.dataset.step = String(stepNum);
        } catch (e) {}
        updateStepDisplay();
        updateNavigation();
        updateProgressBar();
        
        // OE-v1 fix: wire edition-type + OE-duration handlers when these controls
        // first become visible (step 8), and re-sync the radio/visual to true state
        // on every entry — covers forward flow, prev/next, and draft resume.
        if (stepNum === 5) {
            initOEToggle();
            applyOEEditionType(state.editionType);
            updateFeeEstimate();
        }
        
        // Update preview on final step
        if (stepNum === 6) {
            updatePreview();
            generateMetadataPreview();
            updateChecklist();
            updateFeeEstimate();
            initOEToggle(); // OE-v1
            
            // AI Disclosure now lives on Step 2 — no Step 9 toggle needed
            
            // M2 fix (v248: extended for tier coverFile): Refresh NFT preview image from cover/tier state
            const previewContainer = document.getElementById('preview-image');
            if (previewContainer) {
                if (state.tiers.enabled && state.tiers.items.length > 0) {
                    // Tiered mint: use first tier cover. coverIpfs is only set during handleMint,
                    // so fall back to local coverFile if we're still at the review step.
                    const firstTier = state.tiers.items[0];
                    if (firstTier.coverIpfs) {
                        previewContainer.innerHTML = `<img src="${firstTier.coverIpfs}" alt="Preview">`;
                    } else if (firstTier.coverFile) {
                        const reader = new FileReader();
                        reader.onload = (e) => { previewContainer.innerHTML = `<img src="${e.target.result}" alt="Preview">`; };
                        reader.readAsDataURL(firstTier.coverFile);
                    // v43: For watermarkable art tiers, use master file as preview (watermark applied on submit)
                    } else if (firstTier.watermarkable && firstTier.masterFile) {
                        const reader = new FileReader();
                        reader.onload = (e) => { previewContainer.innerHTML = `<img src="${e.target.result}" alt="Preview">`; };
                        reader.readAsDataURL(firstTier.masterFile);
                    }
                } else if (state.coverIpfs) {
                    previewContainer.innerHTML = `<img src="${state.coverIpfs}" alt="Preview">`;
                } else if (state.coverFile) {
                    const reader = new FileReader();
                    reader.onload = (e) => { previewContainer.innerHTML = `<img src="${e.target.result}" alt="Preview">`; };
                    reader.readAsDataURL(state.coverFile);
                // v43: For watermarkable art, use master file as preview (watermark applied on submit)
                } else if (state.contentType === 'art' && state.artAutoWatermark && state.primaryFile) {
                    const reader = new FileReader();
                    reader.onload = (e) => { previewContainer.innerHTML = `<img src="${e.target.result}" alt="Preview">`; };
                    reader.readAsDataURL(state.primaryFile);
                }
            }
        }
    }

    function nextStep() {
        if (!validateCurrentStep()) return;
        let nextStepNum;
        if (state.currentStep === 0) {
            // Type: card selection auto-advances; Next is hidden here
            if (state.contentType) { goToStep(1); }
            return;
        } else if (state.currentStep === 1) {
            // Collection: dedicated buttons; fallback mirrors them
            if (state.collection.isNew) { handleCollectionContinue(); } else { handleExistingContinue(); }
            return;
        } else if (state.currentStep === 2) {
            nextStepNum = (state.licensed && ['music','musicVideo','film','album'].includes(state.contentType)) ? 3 : 4;
        } else if (state.currentStep === 3) {
            nextStepNum = 4;
        } else if (state.currentStep < 6) {
            nextStepNum = state.currentStep + 1;
        } else {
            return;
        }
        goToStep(nextStepNum);
    }

    function prevStep() {
        let prevStepNum;
        if (state.currentStep === 4) {
            prevStepNum = (state.licensed && ['music','musicVideo','film','album'].includes(state.contentType)) ? 3 : 2;
        } else if (state.currentStep === 3) {
            prevStepNum = 2;
        } else if (state.currentStep >= 1) {
            prevStepNum = state.currentStep - 1;
        } else {
            return;
        }
        goToStep(prevStepNum);
    }
    
    /**
     * Validate collection setup (Step 0)
     * For new collections: validates name + taxon range
     * For existing: validates selection exists
     */
    function validateCollection() {
        if (state.collection.isNew) {
            const name = document.getElementById('collection-name')?.value?.trim();
            if (!name) {
                return imcFieldError('#collection-name', 'Please enter a collection name');
            }
            
            const taxonInput = document.getElementById('collection-taxon')?.value?.trim();
            if (!taxonInput || taxonInput === '') {
                return imcFieldError('#collection-taxon', 'Please enter a collection taxon number');
            }
            
            const taxonNum = parseInt(taxonInput, 10);
            if (isNaN(taxonNum) || taxonNum < 0 || taxonNum > 4294967295) {
                return imcFieldError('#collection-taxon', 'Taxon must be a whole number from 0 to 4,294,967,295');
            }
            
            state.collection.name = name;
            state.collection.description = document.getElementById('collection-description')?.value?.trim() || '';
            state.collection.taxon = taxonNum;
        } else if (!state.collection.existingId) {
            imcToast('Please select an existing collection');
            return false;
        }
        return true;
    }

    function updateStepDisplay() {
        elements.steps?.forEach(step => {
            const sNum = parseFloat(step.dataset.step);
            step.classList.toggle('active', sNum === state.currentStep);
        });
        elements.wizard?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function updateNavigation() {
        const showPrev = state.currentStep >= 1; // Can go back from step 1+ (back to collection)
        const showNext = state.currentStep >= 2 && state.currentStep < 6; // hidden on Type(0)+Collection(1); Mint replaces it on 6
        const showMint = state.currentStep === 6;
        const showDraft = state.currentStep >= 2 && state.currentStep < 6;
        
        // Hide Next on steps where card selection auto-advances (Step 1 = content type)
        // AND on Step 0 (has dedicated collection buttons)
        const hideNextOnCardStep = state.currentStep === 0 || state.currentStep === 1;
        
        if (elements.prevBtn) elements.prevBtn.style.display = showPrev ? 'flex' : 'none';
        if (elements.nextBtn) elements.nextBtn.style.display = (showNext && !hideNextOnCardStep) ? 'flex' : 'none';
        if (elements.mintBtn) elements.mintBtn.style.display = showMint ? 'flex' : 'none';
        
        const draftBtn = document.getElementById('wizard-save-draft');
        if (draftBtn) draftBtn.style.display = showDraft ? 'flex' : 'none';
    }

    function updateProgressBar() {
        const currentMapped = state.currentStep;
        
        elements.progressSteps?.forEach(step => {
            const sNum = parseInt(step.dataset.step);
            step.classList.toggle('active', sNum <= currentMapped);
            step.classList.toggle('completed', sNum < currentMapped);
        });
    }

    function updateProgressStepNumbers() {
        // Renumber visible progress steps sequentially
        let visibleCount = 0;
        document.querySelectorAll('#wizard-progress .progress-step').forEach(step => {
            if (step.style.display !== 'none') {
                visibleCount++;
                const numEl = step.querySelector('.step-number');
                if (numEl) numEl.textContent = visibleCount;
            }
        });
    }

    function updateFieldVisibility() {
        // Show/hide content-type specific fields
        document.querySelectorAll('.content-field').forEach(field => {
            const types = field.classList;
            const shouldShow = (state.contentType === 'music' && types.contains('music')) ||
                             (state.contentType === 'musicVideo' && types.contains('musicVideo')) ||
                             (state.contentType === 'art' && types.contains('art')) ||
                             (state.contentType === 'film' && types.contains('film')) ||
                             (state.contentType === 'album' && types.contains('album')) ||
                (state.contentType === 'audiobook' && types.contains('audiobook')) ||
                (state.contentType === 'ebook' && types.contains('ebook')); // M2-b2: was missing — hid the eBook back cover
            field.style.display = shouldShow ? 'block' : 'none';
        });
        
        // Show/hide music-only and film-only sections
        document.querySelectorAll('.music-only-section').forEach(el => {
            el.style.display = (state.contentType === 'music' || state.contentType === 'musicVideo') ? 'block' : 'none';
        });
        document.querySelectorAll('.film-only-section').forEach(el => {
            el.style.display = state.contentType === 'film' ? 'block' : 'none';
        });
        // M1-e1c: Book Details block - shown only on the audiobook path.
        document.querySelectorAll('.audiobook-only-section').forEach(el => {
            el.style.display = state.contentType === 'audiobook' ? 'block' : 'none';
        });
        // M1-e2b: chaptered AudioBooks use per-chapter files instead of one master.
        const _abChaptered = state.contentType === 'audiobook' && state.audiobook.format === 'chaptered';
        document.querySelectorAll('.audiobook-chaptered-only').forEach(el => {
            el.style.display = _abChaptered ? 'block' : 'none';
        });
        // M2-b: pages belong to eBook only. AudioBook never shows them (M1-e2d ruling).
        const _isEbook = state.contentType === 'ebook';
        const _bpSection = document.getElementById('book-pages-section');
        if (_bpSection) _bpSection.style.display = _isEbook ? 'block' : 'none';
        document.querySelectorAll('.ebook-only-section').forEach(el => {
            el.style.display = _isEbook ? 'block' : 'none';
        });
        
        // Show/hide step content based on content type
        document.querySelectorAll('.music-step-content').forEach(el => {
            el.style.display = (state.contentType === 'film') ? 'none' : 'block';
        });
        document.querySelectorAll('.film-step-content').forEach(el => {
            el.style.display = (state.contentType === 'film') ? 'block' : 'none';
        });
        
        // Update step headings for film
        const h3 = document.getElementById('step3-heading');
        const d3 = document.getElementById('step3-description');
        const h4 = document.getElementById('step4-heading');
        const d4 = document.getElementById('step4-description');
        if (state.contentType === 'film') {
            if (h3) h3.textContent = 'Film Details & Credits';
            if (d3) d3.textContent = 'Enter descriptive metadata for your film. Fields marked * are required for registered/licensed films.';
            if (h4) h4.textContent = 'Administrative & Rights';
            if (d4) d4.textContent = 'Enter production, copyright, and distribution rights information.';
        } else {
            if (h3) h3.textContent = 'Track Information';
            if (d3) d3.textContent = 'Enter detailed track metadata. This information is used for PRO reporting and DSP integration.';
            if (h4) h4.textContent = 'Artists & Credits';
            if (d4) d4.textContent = 'Add all contributors. Writer information is critical for PRO royalty reporting.';
        }
        
        // C-alpha lane engine: ONE Industry step (data-step 3); tabs per lane.
        var lanes = { music: [0,1,2,3,4], musicVideo: [0,1,2,3,4], film: [0,1], album: [3] };
        var laneTabs = (state.licensed && lanes[state.contentType]) ? lanes[state.contentType] : null;
        window.__imcLaneTabs = laneTabs;
        document.querySelectorAll('.licensed-step').forEach(function (el) {
            el.style.display = laneTabs ? '' : 'none';
        });
        var lbl3 = document.getElementById('step-label-3');
        if (lbl3) {
            var laneNames = { music: 'Music Info', musicVideo: 'Music Video Info', film: 'Film Info', album: 'Album Rights' };
            lbl3.textContent = laneNames[state.contentType] || 'Industry Info';
        }
        if (window.applyIndustryTabs) window.applyIndustryTabs(laneTabs, state.contentType);
        if (state.contentType === 'album') {
            document.querySelectorAll('.album-only-section').forEach(function (el) { el.style.display = ''; });
        } else {
            document.querySelectorAll('.album-only-section').forEach(function (el) { el.style.display = 'none'; });
        }
        updateProgressStepNumbers();

        // v43: Rebuild AI element checkboxes for the selected content type
        updateAiElementGrids();

        // v43 + PP-6 P2: art cover visibility, BOTH layers (the outer cover-upload-zone
        // was never part of the v43 detect, so it stayed visible for art -- the residue).
        // ART: the public cover upload DEFAULTS HIDDEN (auto-watermark covers every common
        // format via the VPS); the v43 master-select detect's else-branch is the sole
        // reveal (PSD/RAW -- no ImageMagick). NON-ART: everything restores (the cover
        // upload is the cover's SOURCE for music/MV/film/album; v377 watermarks it after).
        ppArtCoverDefault();
        if (state.contentType !== 'art') {
            state.artAutoWatermark = false;
            const coverDropzone = document.getElementById('cover-dropzone');
            if (coverDropzone) coverDropzone.style.display = '';
        }
    }

    /**
     * v43: Rebuild AI-Created and Human-Created element checkbox grids
     * based on content type. Music uses music terms, art uses art terms, etc.
     * v376: Also update AI Platform placeholder to show relevant examples per content type.
     */
    function updateAiElementGrids() {
        const typeMap = CONFIG?.aiElementsByType;
        if (!typeMap) return; // Backwards compatible — old config without aiElementsByType
        
        const elements = typeMap[state.contentType] || typeMap['music'] || {};
        const aiGrid = document.getElementById('ai-created-grid');
        const humanGrid = document.getElementById('human-created-grid');
        if (!aiGrid || !humanGrid) return;

        // Determine which key is the "full" option (excluded from human-created)
        const fullKeys = ['full_song', 'full_artwork', 'full_film'];

        // Rebuild AI-Created grid
        aiGrid.innerHTML = Object.entries(elements).map(([value, label]) =>
            `<label class="checkbox-label">
                <input type="checkbox" name="ai_elements[]" value="${value}">
                <span>${label}</span>
            </label>`
        ).join('');

        // Rebuild Human-Created grid (exclude the "full" option)
        humanGrid.innerHTML = Object.entries(elements)
            .filter(([value]) => !fullKeys.includes(value))
            .map(([value, label]) =>
                `<label class="checkbox-label">
                    <input type="checkbox" name="human_elements[]" value="${value}" checked>
                    <span>${label}</span>
                </label>`
            ).join('');

        // v376: Update AI Platform placeholder to show content-type-relevant examples
        const aiPlatformInput = document.querySelector('input[name="ai_platform"]');
        if (aiPlatformInput) {
            const placeholders = {
                music:      'e.g., Suno, Udio, AIVA, Boomy, Amper, etc.',
                musicVideo: 'e.g., Suno, Udio, AIVA, Runway, Pika, etc.',
                art:        'e.g., Midjourney, DALL-E, Stable Diffusion, Adobe Firefly, etc.',
                film:       'e.g., Runway, Pika, Sora, Kling, HailuoAI, etc.'
            };
            aiPlatformInput.placeholder = placeholders[state.contentType] || placeholders.music;
        }
    }

    // ===== Validation =====
    
    function validateCurrentStep() {
        if (window.imcClearFieldErrors) window.imcClearFieldErrors();
        switch (state.currentStep) {
            case 0:
                // M1-e1c: AudioBook requires a sub-type before leaving step 0.
                if (state.contentType === 'audiobook' && !state.audiobook.format) {
                    imcToast('Please choose an AudioBook type (Single file).');
                    return false;
                }
                if (!state.contentType) {
                    var _openPanel = document.querySelector('.content-subtype-panel[style*="block"]');
                    imcToast(_openPanel ? 'Please choose a type from the options shown.'
                                        : 'Please choose what type of content you are minting.');
                    return false;
                }
                return true;
            case 1:
                // Collection validation handled by validateCollection() in nextStep
                return true;
            case 2:
                // NFT Information (title, description required)
                const nftTitle = document.getElementById('nft-title')?.value?.trim();
                const nftDesc = document.getElementById('nft-description')?.value?.trim();
                if (!nftTitle || !nftDesc) {
                    return imcFieldError(!nftTitle ? '#nft-title' : '#nft-description', 'Please fill in NFT title and description');
                }
                // M1-e1c: Book Details - author and narrator are required for AudioBooks.
                if (state.contentType === 'audiobook') {
                // M2-b: eBook requires a book type and an author - the markup marks both
                // with an asterisk, so validation must actually enforce them.
                if (state.contentType === 'ebook') {
                    const ebFormat = document.getElementById('ebook-format')?.value?.trim();
                    const ebAuthor = document.getElementById('ebook-author')?.value?.trim();
                    if (!ebFormat) { return imcFieldError('#ebook-format', 'Please choose the book type'); }
                    if (!ebAuthor) { return imcFieldError('#ebook-author', 'Please fill in the author'); }
                }
                    const abAuthor   = document.getElementById('ab-author')?.value?.trim();
                    const abNarrator = document.getElementById('ab-narrator')?.value?.trim();
                    if (!abAuthor || !abNarrator) {
                        return imcFieldError(!abAuthor ? '#ab-author' : '#ab-narrator', 'Please fill in the author and narrator');
                    }
                }
                // v178: Validate AI disclosure if "Yes" selected
                const aiUsed = document.querySelector('input[name="ai_used"]:checked')?.value;
                if (aiUsed === 'true') {
                    const aiPlatform = document.querySelector('input[name="ai_platform"]')?.value?.trim();
                    const aiDate = document.querySelector('input[name="ai_creation_date"]')?.value;
                    const aiElements = document.querySelectorAll('input[name="ai_elements[]"]:checked');
                    if (!aiPlatform) {
                        return imcFieldError('input[name="ai_platform"]', 'Please enter the AI platform used');
                    }
                    if (!aiDate) {
                        return imcFieldError('input[name="ai_creation_date"]', 'Please enter the AI creation date');
                    }
                    if (aiElements.length === 0) {
                        imcToast('Please select at least one AI-created element');
                        return false;
                    }
                }
                return true;
            case 3:
                // Track Information (music) or Film Descriptive (film) — only reached if licensed
                if (state.contentType === 'film') {
                    const filmTitle = document.getElementById('film-title')?.value?.trim();
                    const filmGenre = document.getElementById('film-genre')?.value;
                    const filmRating = document.getElementById('film-rating')?.value;
                    // v147: Dynamic film credits
                    const filmDirector = document.querySelector('[name="film_director_0"]')?.value?.trim();
                    const filmProducer = document.querySelector('[name="film_producer_0"]')?.value?.trim();
                    const filmWriter = document.querySelector('[name="film_writer_0"]')?.value?.trim();
                    const filmCast = document.querySelector('[name="film_cast_0"]')?.value?.trim();
                    if (!filmTitle) { return imcFieldError('#film-title', 'Please enter the official film title'); }
                    if (!filmGenre) { return imcFieldError('#film-genre', 'Please select a primary genre'); }
                    if (!filmRating) { return imcFieldError('#film-rating', 'Please select a target audience rating'); }
                    if (!filmDirector) { return imcFieldError('[name="film_director_0"]', 'Please enter at least one director'); }
                    if (!filmProducer) { return imcFieldError('[name="film_producer_0"]', 'Please enter at least one producer'); }
                    if (!filmWriter) { return imcFieldError('[name="film_writer_0"]', 'Please enter at least one writer'); }
                    if (!filmCast) { return imcFieldError('[name="film_cast_0"]', 'Please enter at least one cast member'); }
                    return true;
                }
                const workType = document.getElementById('nft-work-type')?.value;
                const genre = document.getElementById('nft-genre')?.value;
                if (!workType) {
                    return imcFieldError('#nft-work-type', 'Please select a work type');
                }
                if (!genre) {
                    return imcFieldError('#nft-genre', 'Please select a genre');
                }
                if (state.contentType === 'film') {
                    const filmProdCo = document.getElementById('film-production-company')?.value?.trim();
                    const filmClassification = document.getElementById('film-classification')?.value;
                    const filmRelease = document.getElementById('film-release-date')?.value;
                    const filmCountry = document.getElementById('film-country')?.value?.trim();
                    const filmLicense = document.getElementById('film-license-type')?.value;
                    const filmRightsCheck = document.getElementById('film-commercial-rights')?.checked;
                    if (!filmProdCo) { return imcFieldError('#film-production-company', 'Please enter the production company'); }
                    if (!filmClassification) { return imcFieldError('#film-classification', 'Please select a film classification'); }
                    // v146: Film copyright ownership must total 100%
                    let filmCrTotal = 0;
                    document.querySelectorAll('.film-copyright-pct').forEach(i => filmCrTotal += parseFloat(i.value) || 0);
                    const filmCrHolder = document.querySelector('[name="film_copyright_holder_0"]')?.value?.trim();
                    if (!filmCrHolder) { return imcFieldError('[name="film_copyright_holder_0"]', 'Please enter at least one copyright holder'); }
                    if (filmCrTotal !== 100) { imcToast(`Film copyright ownership totals ${filmCrTotal.toFixed(2)}% — must equal 100%`); return false; }
                    if (!filmRelease) { return imcFieldError('#film-release-date', 'Please enter the original release date'); }
                    if (!filmCountry) { return imcFieldError('#film-country', 'Please enter the country of origin'); }
                    if (!filmLicense) { return imcFieldError('#film-license-type', 'Please select a license/streaming rights type'); }
                    if (!filmRightsCheck) { imcToast('You must confirm you have commercial rights'); return false; }
                    return true;
                }
                const artistName = document.querySelector('[name="primary_artist_name_0"]')?.value?.trim();
                const writerName = document.querySelector('[name="writer_name_0"]')?.value?.trim();
                const writerOwnership = document.querySelector('[name="writer_ownership_0"]')?.value;
                if (!artistName) {
                    return imcFieldError('[name="primary_artist_name_0"]', 'Please enter at least one primary artist');
                }
                if (!writerName) {
                    imcToast('Please enter at least one writer');
                    return false;
                }
                if (!writerOwnership) {
                    imcToast('Please enter writer ownership percentage');
                    return false;
                }
                // v146: Master recording owner required, copyright optional
                const masterOwner = document.getElementById('master-owner')?.value?.trim();
                if (!masterOwner && state.contentType !== 'film') {
                    imcToast('Please enter at least one master recording owner');
                    return false;
                }
                // Check ownership totals if any values entered
                if (state.contentType !== 'film') {
                    let masterTotal = 0;
                    document.querySelectorAll('.master-owner-pct').forEach(i => masterTotal += parseFloat(i.value) || 0);
                    if (masterTotal > 0 && masterTotal !== 100) {
                        imcToast(`Master recording ownership totals ${masterTotal.toFixed(2)}% — must equal 100%`);
                        return false;
                    }
                    let pubTotal = 0;
                    document.querySelectorAll('[name^="publisher_ownership_"]').forEach(i => pubTotal += parseFloat(i.value) || 0);
                    if (pubTotal > 0 && pubTotal !== 100) {
                        imcToast(`Publisher ownership totals ${pubTotal.toFixed(2)}% — must equal 100%`);
                        return false;
                    }
                    // Composition copyright — mandatory, must total 100%
                    let compTotal = 0;
                    document.querySelectorAll('.comp-copyright-pct').forEach(i => compTotal += parseFloat(i.value) || 0);
                    const compHolder = document.querySelector('[name="comp_copyright_holder_0"]')?.value?.trim();
                    if (!compHolder) {
                        imcToast('Please enter at least one composition copyright holder');
                        return false;
                    }
                    if (compTotal !== 100) {
                        imcToast(`Composition copyright ownership totals ${compTotal.toFixed(2)}% — must equal 100%`);
                        return false;
                    }
                    // Sound recording copyright — mandatory, must total 100%
                    let soundTotal = 0;
                    document.querySelectorAll('.sound-copyright-pct').forEach(i => soundTotal += parseFloat(i.value) || 0);
                    const soundHolder = document.querySelector('[name="sound_copyright_holder_0"]')?.value?.trim();
                    if (!soundHolder) {
                        imcToast('Please enter at least one sound recording copyright holder');
                        return false;
                    }
                    if (soundTotal !== 100) {
                        imcToast(`Sound recording copyright ownership totals ${soundTotal.toFixed(2)}% — must equal 100%`);
                        return false;
                    }
                }
                const commercialRights = document.getElementById('commercial-rights')?.checked;
                if (!commercialRights) {
                    imcToast('You must confirm you have commercial rights');
                    return false;
                }
                // IDs step - optional, no hard validation
                return true;
            case 4:
                // Upload step (moved from old step 2)
                // v24: Album branch FIRST — album has no single primaryFile or previewFile.
                // Must exit before L2407 (primaryFile check) AND L2414 (previewFile check).
                // Alert messages intentionally use neutral wording to avoid isValidationError
                // keywords which would permanently block retry on recoverable upload errors (F2).
                if (state.contentType === 'album') {
                    // v461: Defensive sync — purge state entries without DOM cards.
                    // Draft restore creates tracks in state with file:null but never
                    // rebuilds DOM cards. These invisible ghosts block validation.
                    const _atc = document.getElementById('album-tracks-container');
                    if (_atc) {
                        const domIds = new Set(
                            [..._atc.querySelectorAll('.album-track-card')].map(c => c.dataset.trackId)
                        );
                        state.album.tracks = state.album.tracks.filter(t => domIds.has(t.id));
                    }
                    if (state.album.tracks.length < 1) {
                        imcToast('Please add at least one track to your album before continuing');
                        return false;
                    }
                    for (let i = 0; i < state.album.tracks.length; i++) {
                        const track = state.album.tracks[i];
                        if (!track.file && !track.masterContentHash) {
                            imcToast('Track ' + (i + 1) + ' needs a master audio file — please upload one');
                            return false;
                        }
                        if (!track.previewFile && !track.previewIpfs) {
                            imcToast('Track ' + (i + 1) + ' needs a preview clip (max 30 seconds) — please upload one');
                            return false;
                        }
                    }
                    if (!state.coverFile && !state.coverIpfs && !state.tiers.enabled) {
                        // v461: Single cover required only when NOT using rarity tiers
                        // (tiered mode has per-tier covers validated at the tier check below)
                        imcToast('Please upload album cover art before continuing');
                        return false;
                    }
                    // v461: When tiers enabled, fall through to tier validation below.
                    // When single cover, safe to exit here.
                    if (!state.tiers.enabled) {
                        return true; // Safe exit — never reaches primaryFile or previewFile checks below
                    }
                }
                // v144: Art tier mode has per-tier masters, no single primaryFile needed
                // v461: Album uses per-track files — no single primaryFile needed
                // v481: Music/MV/Film tier mode uses pool masters — no listing-level master needed
                // M2-b: an eBook has pages instead of a master, and no preview at all.
                if (state.contentType === 'ebook') {
                    if (!state.book.pages.length) { imcToast('Please add at least one page or PDF'); return false; }
                    if (!state.coverFile && !state.coverIpfs) {
                        imcToast('Please upload your front cover before continuing');
                        return false;
                    }
                    const _pt = bookPageTotal();
                    const _allImages = state.book.pages.every(function (p) { return p.kind !== 'pdf'; });
                    if (_allImages && _pt % 2 !== 0) {
                        imcToast('Odd page count (' + _pt + '). Two-page spreads need an even interior - add one more page.');
                        return false;
                    }
                    return true; // safe exit - no master, no preview
                }
                // M1-e2b: chaptered AudioBooks have no single master - validate chapters
                // and exit before the listing-level master check below.
                if (state.contentType === 'audiobook' && state.audiobook.format === 'chaptered') {
                    const chs = state.audiobook.chapters || [];
                    if (!chs.length) { imcToast('Please add at least one chapter'); return false; }
                    for (let i = 0; i < chs.length; i++) {
                        if (!chs[i].file && !chs[i].masterContentHash) {
                            imcToast('Chapter ' + (i + 1) + ' is missing its audio file');
                            return false;
                        }
                        if (!chs[i].title || !chs[i].title.trim()) {
                            imcToast('Chapter ' + (i + 1) + ' needs a title');
                            return false;
                        }
                    }
                    if (!state.coverFile && !state.coverIpfs) {
                        imcToast('Please upload your front cover before continuing');
                        return false;
                    }
                    if (!state.previewFile && !state.previewIpfs) {
                        imcToast('Please upload a preview audio sample (max 60 seconds)');
                        return false;
                    }
                    return true; // safe exit - never reaches the single-master checks
                }
                const isMediaTierMode = state.tiers.enabled && ['music','musicVideo','film'].includes(state.contentType);
                if (!state.primaryFile && !(state.contentType === 'art' && state.tiers.enabled) && state.contentType !== 'album' && !isMediaTierMode) {
                    imcToast(state.contentType === 'art' ? 'Please upload your master artwork file' : 'Please upload your master media file');
                    return false;
                }
                // Preview required for music, musicVideo, and film (not art, not album)
                if (state.contentType !== 'art' && state.contentType !== 'album' && !isMediaTierMode) { // v557: tiered media uses per-pool previews
                    const previewMaxSeconds = state.contentType === 'film' ? 120 : 30;
                    if (!state.previewFile) {
                        const previewLabel = state.contentType === 'film' ? 'clip' : state.contentType === 'musicVideo' ? 'clip or audio' : 'audio file';
                        imcToast(`Please upload a preview ${previewLabel} (≤${previewMaxSeconds} seconds)`);
                        return false;
                    }
                    // Validate preview duration
                    if (state.previewMeta.duration && state.previewMeta.duration > previewMaxSeconds) {
                        imcToast(`Preview must be ${previewMaxSeconds} seconds or less. Current: ` + state.previewMeta.duration + 's');
                        return false;
                    }
                }
                // v481: In media tier mode, each tier with a pool assignment must have a file uploaded
                if (isMediaTierMode) {
                    for (const tier of state.tiers.items) {
                        if (tier.masterPoolRef !== null) {
                            const poolItem = state.masterPool.find(p => p.id === tier.masterPoolRef);
                            if (!poolItem?.file && !tier.masterContentHash) {
                                imcToast(`Tier "${tier.name || '(unnamed)'}" is assigned to a pool master that has no file — upload the master file above first.`);
                                return false;
                            }
                            // v557: tiered media previews live per pool item -- require one for each fresh pool master
                            if (poolItem?.file && !poolItem.previewFile && !poolItem.previewIpfs) {
                                imcToast(`Tier "${tier.name || '(unnamed)'}" needs a preview clip — add a preview to its pool master above.`);
                                return false;
                            }
                        }
                    }
                }
                // Tier mode validation
                if (state.tiers.enabled) {
                    if (state.tiers.items.length < 2) {
                        imcToast('Rarity tiers require at least 2 tiers');
                        return false;
                    }
                    const isArtTiers = state.contentType === 'art';

                    // v591 Slice 6a: collect ALL tier boxes still missing a cover and report
                    // them together (mirrors the publish-time backend check), so the artist sees
                    // every gap at once instead of one alert per tier. Same condition as the
                    // per-tier check below; this just surfaces the full list first.
                    const _missingCovers = [];
                    state.tiers.items.forEach((t, i) => {
                        if (!t.coverFile && !t.coverIpfs && !t.watermarkable) _missingCovers.push(i + 1);
                    });
                    if (_missingCovers.length > 0) {
                        const _shown = _missingCovers.slice(0, 12).join(', ');
                        const _more = _missingCovers.length > 12 ? ` …(${_missingCovers.length} total)` : '';
                        imcToast(`Cannot publish yet — tier box(es) missing a ${isArtTiers ? 'preview/artwork' : 'cover image'}: ${_shown}${_more}. Upload these before publishing.`);
                        return false;
                    }

                    // v591 Slice 8: music/MV/film tiers draw their master from a pooled file via
                    // masterPoolRef (the listing default master is NOT stored in tier mode). A tier
                    // with no pooled master and no existing hash fails at mint with a missing
                    // content_hash — the exact failure seen when a broken CSV header dropped the
                    // master_file column. Collect any such tiers so they are named before submit.
                    if (['music', 'musicVideo', 'film'].includes(state.contentType)) {
                        const _missingMasters = [];
                        state.tiers.items.forEach((t, i) => {
                            const _pool = (t.masterPoolRef != null) ? state.masterPool.find(p => p.id === t.masterPoolRef) : null;
                            const _hasMaster = (_pool && (_pool.file || _pool.contentHash))
                                || !!t.masterContentHash
                                || (t.useDefaultMaster && !!state.masterContentHash);
                            if (!_hasMaster) _missingMasters.push(i + 1);
                        });
                        if (_missingMasters.length > 0) {
                            const _shown = _missingMasters.slice(0, 12).join(', ');
                            const _more = _missingMasters.length > 12 ? ` …(${_missingMasters.length} total)` : '';
                            imcToast(`Cannot publish yet — tier box(es) missing a master audio/video file: ${_shown}${_more}. Assign a master to each tier from the media pool before publishing.`);
                            return false;
                        }
                    }

                    for (const tier of state.tiers.items) {
                        if (!tier.name?.trim()) {
                            imcToast('Each tier must have a name');
                            return false;
                        }
                        // v43: Skip cover check for watermarkable art tiers — VPS auto-generates preview
                        if (!tier.coverFile && !tier.coverIpfs && !tier.watermarkable) {
                            imcToast(`Tier "${tier.name || '(unnamed)'}" needs a ${isArtTiers ? 'public preview image' : 'cover image'}`);
                            return false;
                        }
                        // v144/v702: Art tiers require a master artwork per tier. Accept a
                        // per-tier uploaded masterFile (manual box) OR a pool ref OR an existing hash.
                        if (isArtTiers && tier.masterPoolRef === null && !tier.masterContentHash && !tier.masterFile) {
                            imcToast(`Tier "${tier.name || '(unnamed)'}" needs a master artwork file — upload it in the tier's Master Artwork box`);
                            return false;
                        }
                        if (tier.editions < 1) {
                            imcToast(`Tier "${tier.name}" must have at least 1 edition`);
                            return false;
                        }
                    }
                    const totalTierEditions = state.tiers.items.reduce((s, t) => s + t.editions, 0);
                    if (totalTierEditions < 2) {
                        imcToast('Total editions across all tiers must be at least 2');
                        return false;
                    }
                } else {
                    // v43: Skip cover check for art when auto-watermark will generate it
                    if (!state.coverFile && !(state.contentType === 'art' && state.artAutoWatermark)) {
                        imcToast(state.contentType === 'art' ? 'Please upload a public preview image' : 'Please upload cover art');
                        return false;
                    }
                }
                return true;
            case 5:
                // OE-v1: If Open Edition selected, validate mint window
                if (state.editionType === 'open') {
                    if (!state.oeEndsAt) {
                        imcToast('Please select a mint window end date/time for your Open Edition.');
                        return false;
                    }
                    const startDt = oeEffectiveStartDate();
                    const endTs   = new Date(state.oeEndsAt).getTime();
                    const diffMs  = endTs - startDt.getTime();
                    if (endTs <= Date.now()) {
                        imcToast('Open Edition end time must be in the future.');
                        return false;
                    }
                    if (diffMs < 3600000) {
                        imcToast('Open Edition minimum duration is 1 hour.');
                        return false;
                    }
                    if (diffMs > 365 * 86400000) {
                        imcToast('Open Edition maximum duration is 1 year.');
                        return false;
                    }
                }
                return true;
            case 6:
                return true;
            default:
                return true;
        }
    }

    // ===== File Handling =====
    
    function handleFileSelect(e, type) {
        const file = e.target.files[0];
        if (!file) return;
        
        // Validate file size
        const maxSize = type === 'cover' ? FILE_LIMITS.cover : 
                       type === 'preview' ? FILE_LIMITS.preview :
                       (FILE_LIMITS[state.contentType] || FILE_LIMITS.music);
        
        if (file.size > maxSize) {
            imcToast(`File too large. Maximum: ${formatFileSize(maxSize)}`);
            e.target.value = '';
            return;
        }
        
        // Store file
        if (type === 'primary') {
            state.primaryFile = file;
            detectMediaProperties(file);
        } else if (type === 'preview') {
            state.previewFile = file;
            detectPreviewDuration(file);
        } else if (type === 'audio') {
            state.audioFile = file;
        } else if (type === 'cover') {
            state.coverFile = file;
            updateCoverPreview(file);
        }
        
        // Update UI
        showFilePreview(type, file);
    }
    
    /**
     * Detect preview media duration and validate ≤30s (music) or ≤120s (film)
     */
    function detectPreviewDuration(file) {
        const isFilmPreview = state.contentType === 'film';
        // M1-e1b: audiobook samples are 60s (server enforces the same limit).
        const maxDuration = isFilmPreview ? 120
            : ((state.contentType === 'audiobook') ? 60 : 30);
        // v204: Use video element if the file is a video type (e.g. MP4 preview for music videos)
        const isVideoFile = file.type && file.type.startsWith('video/');
        const mediaEl = (isFilmPreview || isVideoFile) ? document.createElement('video') : document.createElement('audio');
        mediaEl.src = URL.createObjectURL(file);
        mediaEl.addEventListener('loadedmetadata', () => {
            const duration = Math.round(mediaEl.duration);
            state.previewMeta.duration = duration;
            
            const durationBadge = document.getElementById('preview-duration-badge');
            const durationWarning = document.getElementById('preview-duration-warning');
            const detectedDuration = document.getElementById('preview-detected-duration');
            
            if (durationBadge) {
                durationBadge.textContent = `⏱ ${duration}s`;
                durationBadge.style.display = 'inline-block';
            }
            
            if (duration > maxDuration) {
                if (durationWarning) durationWarning.style.display = 'flex';
                if (detectedDuration) detectedDuration.textContent = `${duration}s (max ${maxDuration}s)`;
                if (durationBadge) durationBadge.style.color = '#ef4444';
            } else {
                if (durationWarning) durationWarning.style.display = 'none';
                if (durationBadge) durationBadge.style.color = '#10b981';
            }
            
            URL.revokeObjectURL(mediaEl.src);
        });
    }

    function showFilePreview(type, file) {
        const preview = document.getElementById(`${type}-preview`);
        const dropzone = document.getElementById(`${type}-dropzone`);
        const filename = document.getElementById(`${type}-filename`);
        const filesize = document.getElementById(`${type}-filesize`);
        
        if (preview) preview.style.display = 'flex';
        if (dropzone) dropzone.style.display = 'none';
        if (filename) filename.textContent = file.name;
        if (filesize) filesize.textContent = formatFileSize(file.size);
    }

    function removeFile(type) {
        const preview = document.getElementById(`${type}-preview`);
        const dropzone = document.getElementById(`${type}-dropzone`);
        const input = document.getElementById(`${type}-file-input`);
        
        if (type === 'primary') state.primaryFile = null;
        else if (type === 'preview') {
            state.previewFile = null;
            state.previewMeta = {};
            const durationBadge = document.getElementById('preview-duration-badge');
            const durationWarning = document.getElementById('preview-duration-warning');
            if (durationBadge) durationBadge.style.display = 'none';
            if (durationWarning) durationWarning.style.display = 'none';
        }
        else if (type === 'audio') state.audioFile = null;
        else if (type === 'cover') {
            state.coverFile = null;
            updatePreviewImage(null);
        }
        
        if (preview) preview.style.display = 'none';
        if (dropzone) dropzone.style.display = 'flex';
        if (input) input.value = '';
    }

    function updateCoverPreview(file) {
        const reader = new FileReader();
        reader.onload = (e) => {
            const img = document.getElementById('cover-preview-img');
            if (img) img.src = e.target.result;
            updatePreviewImage(e.target.result);
        };
        reader.readAsDataURL(file);
    }

    function updatePreviewImage(src) {
        const container = document.getElementById('preview-image');
        if (src) {
            container.innerHTML = `<img src="${src}" alt="Preview">`;
        } else {
            container.innerHTML = '<span>No image uploaded</span>';
        }
    }

    function detectMediaProperties(file) {
        const detected = document.getElementById('auto-detected');
        
        if (file.type.startsWith('audio/')) {
            const audio = document.createElement('audio');
            audio.src = URL.createObjectURL(file);
            audio.addEventListener('loadedmetadata', () => {
                state.detectedMeta.duration = Math.round(audio.duration);
                state.detectedMeta.format = file.type.split('/')[1].toUpperCase();
                
                document.getElementById('nft-duration').value = state.detectedMeta.duration;
                document.getElementById('detected-duration').textContent = formatDuration(state.detectedMeta.duration);
                document.getElementById('detected-format').textContent = state.detectedMeta.format;
                
                document.getElementById('detected-duration-wrap').style.display = 'block';
                document.getElementById('detected-format-wrap').style.display = 'block';
                detected.style.display = 'block';
                
                URL.revokeObjectURL(audio.src);
            });
        } else if (file.type.startsWith('video/')) {
            const video = document.createElement('video');
            video.src = URL.createObjectURL(file);
            video.addEventListener('loadedmetadata', () => {
                state.detectedMeta.duration = Math.round(video.duration);
                state.detectedMeta.resolution = `${video.videoWidth}x${video.videoHeight}`;
                state.detectedMeta.format = file.type.split('/')[1].toUpperCase();
                
                document.getElementById('nft-duration').value = state.detectedMeta.duration;
                document.getElementById('nft-resolution').value = state.detectedMeta.resolution;
                
                document.getElementById('detected-duration').textContent = formatDuration(state.detectedMeta.duration);
                document.getElementById('detected-resolution').textContent = state.detectedMeta.resolution;
                document.getElementById('detected-format').textContent = state.detectedMeta.format;
                
                document.getElementById('detected-duration-wrap').style.display = 'block';
                document.getElementById('detected-resolution-wrap').style.display = 'block';
                document.getElementById('detected-format-wrap').style.display = 'block';
                detected.style.display = 'block';
                
                URL.revokeObjectURL(video.src);
            });
        }
    }

    // ===== Dynamic List Management =====
    
    function addPrimaryArtist() {
        const list = document.getElementById('primary-artists-list');
        const index = state.counters.primaryArtists++;
        const row = createArtistRow(index, 'primary_artist');
        list.appendChild(row);
    }

    function addFeaturedArtist() {
        const list = document.getElementById('featured-artists-list');
        const index = state.counters.featuredArtists++;
        const row = createArtistRow(index, 'featured_artist');
        list.appendChild(row);
    }

    function createArtistRow(index, prefix) {
        const row = document.createElement('div');
        row.className = 'credit-row artist-row';
        row.dataset.index = index;
        row.innerHTML = `
            <div class="form-group">
                <label>Artist Name</label>
                <input type="text" name="${prefix}_name_${index}" placeholder="Artist name">
            </div>
            <div class="credit-actions">
                <button type="button" class="btn-remove-row" onclick="this.closest('.credit-row').remove()">✕</button>
            </div>
        `;
        return row;
    }

    function addWriter() {
        const list = document.getElementById('writers-list');
        const index = state.counters.writers++;
        const row = document.createElement('div');
        row.className = 'writer-row';
        row.dataset.index = index;
        row.innerHTML = `
            <div class="form-grid writer-grid">
                <div class="form-group">
                    <label>Writer Name</label>
                    <input type="text" name="writer_name_${index}" placeholder="Legal name">
                </div>
                <div class="form-group">
                    <label>Role</label>
                    <select name="writer_role_${index}">
                        ${Object.entries(CONFIG.writerRoles || {}).map(([code, label]) => 
                            `<option value="${code}">${label}</option>`
                        ).join('')}
                    </select>
                </div>
                <div class="form-group">
                    <label>Ownership %</label>
                    <input type="number" name="writer_ownership_${index}" min="0" max="100" step="0.01" placeholder="e.g., 50">
                </div>
                <div class="form-group">
                    <label>IPI Number</label>
                    <input type="text" name="writer_ipi_${index}" placeholder="9-11 digits">
                </div>
                <div class="form-group">
                    <label>P.R.O</label>
                    <select name="writer_pro_${index}">
                        ${Object.entries(CONFIG.proOrgs || {}).map(([code, label]) => 
                            `<option value="${code}">${label}</option>`
                        ).join('')}
                    </select>
                </div>
            </div>
            <button type="button" class="btn-remove-row" onclick="removeWriterRow(this)">✕</button>
        `;
        list.appendChild(row);
    }

    // Make removeWriterRow global
    window.removeWriterRow = function(btn) {
        btn.closest('.writer-row').remove();
        calculateWriterOwnership();
    };

    function calculateWriterOwnership() {
        let total = 0;
        document.querySelectorAll('[name^="writer_ownership_"]').forEach(input => {
            total += parseFloat(input.value) || 0;
        });
        
        const totalEl = document.getElementById('writer-ownership-total');
        const warningEl = document.getElementById('ownership-warning');
        
        if (totalEl) totalEl.textContent = `${total.toFixed(2)}%`;
        if (warningEl) {
            warningEl.style.display = (total !== 100) ? 'inline' : 'none';
        }
    }

    function addProducer() {
        const list = document.getElementById('producers-list');
        const index = state.counters.producers++;
        const row = createBasicCreditRow(index, 'producer', 'Producer');
        list.appendChild(row);
    }

    function addEngineer() {
        const list = document.getElementById('engineers-list');
        const index = state.counters.engineers++;
        const row = document.createElement('div');
        row.className = 'credit-row';
        row.dataset.index = index;
        row.innerHTML = `
            <div class="form-group">
                <label>Name</label>
                <input type="text" name="engineer_name_${index}" placeholder="Engineer name">
            </div>
            <div class="form-group">
                <label>Role</label>
                <select name="engineer_role_${index}">
                    ${Object.entries(CONFIG.engineerRoles || {}).map(([code, label]) => 
                        `<option value="${code}">${label}</option>`
                    ).join('')}
                </select>
            </div>
            <div class="credit-actions">
                <button type="button" class="btn-remove-row" onclick="this.closest('.credit-row').remove()">✕</button>
            </div>
        `;
        list.appendChild(row);
    }

    function addMusician() {
        const list = document.getElementById('musicians-list');
        const index = state.counters.musicians++;
        const row = document.createElement('div');
        row.className = 'credit-row';
        row.dataset.index = index;
        row.innerHTML = `
            <div class="form-group">
                <label>Name</label>
                <input type="text" name="musician_name_${index}" placeholder="Musician name">
            </div>
            <div class="form-group">
                <label>Instrument</label>
                <select name="musician_instrument_${index}">
                    ${(CONFIG.instruments || []).map(inst => 
                        `<option value="${inst}">${inst}</option>`
                    ).join('')}
                </select>
            </div>
            <div class="credit-actions">
                <button type="button" class="btn-remove-row" onclick="this.closest('.credit-row').remove()">✕</button>
            </div>
        `;
        list.appendChild(row);
    }

    function addPublisher() {
        const list = document.getElementById('publishers-list');
        const index = state.counters.publishers++;
        const row = document.createElement('div');
        row.className = 'publisher-row';
        row.dataset.index = index;
        row.innerHTML = `
            <div class="form-grid publisher-grid">
                <div class="form-group">
                    <label>Publisher Name</label>
                    <input type="text" name="publisher_name_${index}" placeholder="Publishing company">
                </div>
                <div class="form-group">
                    <label>Type</label>
                    <select name="publisher_type_${index}">
                        ${Object.entries(CONFIG.publisherTypes || {}).map(([code, label]) => 
                            `<option value="${code}">${label}</option>`
                        ).join('')}
                    </select>
                </div>
                <div class="form-group">
                    <label>Ownership %</label>
                    <input type="number" name="publisher_ownership_${index}" min="0" max="100" step="0.01">
                </div>
                <div class="form-group">
                    <label>Publisher IPI</label>
                    <input type="text" name="publisher_ipi_${index}" placeholder="9-11 digits">
                </div>
                <div class="form-group">
                    <label>Publisher P.R.O</label>
                    <select name="publisher_pro_${index}">
                        ${Object.entries(CONFIG.proOrgs || {}).map(([code, label]) => 
                            `<option value="${code}">${label}</option>`
                        ).join('')}
                    </select>
                </div>
            </div>
            <button type="button" class="btn-remove-row" onclick="removePublisherRow(this)">✕</button>
        `;
        list.appendChild(row);
    }

    window.removePublisherRow = function(btn) {
        btn.closest('.publisher-row').remove();
        calculatePublisherOwnership();
    };

    // v146: Publisher ownership calculation
    function calculatePublisherOwnership() {
        let total = 0;
        document.querySelectorAll('[name^="publisher_ownership_"]').forEach(input => {
            total += parseFloat(input.value) || 0;
        });
        const totalEl = document.getElementById('publisher-ownership-total');
        const warningEl = document.getElementById('publisher-ownership-warning');
        if (totalEl) totalEl.textContent = `${total.toFixed(2)}%`;
        if (warningEl) warningEl.style.display = (total !== 100 && total > 0) ? 'inline' : 'none';
    }

    // v146: Master Recording multi-owner
    function addMasterOwner() {
        const list = document.getElementById('master-owners-list');
        const index = state.counters.masterOwners++;
        const row = document.createElement('div');
        row.className = 'master-owner-row';
        row.dataset.index = index;
        row.innerHTML = `
            <div class="form-grid" style="grid-template-columns:1fr 120px auto;gap:8px;">
                <div class="form-group">
                    <label>Owner Name</label>
                    <input type="text" name="master_owner_name_${index}" placeholder="Owner name">
                </div>
                <div class="form-group">
                    <label>Ownership %</label>
                    <input type="number" name="master_owner_pct_${index}" min="0" max="100" step="0.01" class="master-owner-pct">
                </div>
                <div class="credit-actions" style="padding-top:22px;">
                    <button type="button" class="btn-remove-row" onclick="removeMasterOwnerRow(this)">✕</button>
                </div>
            </div>
        `;
        list.appendChild(row);
        calculateMasterOwnership();
    }
    window.removeMasterOwnerRow = function(btn) {
        btn.closest('.master-owner-row').remove();
        calculateMasterOwnership();
    };
    function calculateMasterOwnership() {
        let total = 0;
        document.querySelectorAll('.master-owner-pct').forEach(input => {
            total += parseFloat(input.value) || 0;
        });
        const totalEl = document.getElementById('master-ownership-total');
        const warningEl = document.getElementById('master-ownership-warning');
        if (totalEl) totalEl.textContent = `${total.toFixed(2)}%`;
        if (warningEl) warningEl.style.display = (total !== 100) ? 'inline' : 'none';
    }

    // v146: Film copyright multi-owner
    function addFilmCopyrightOwner() {
        const list = document.getElementById('film-copyright-owners-list');
        const index = state.counters.filmCopyrightOwners++;
        const row = document.createElement('div');
        row.className = 'film-copyright-row';
        row.dataset.index = index;
        row.innerHTML = `
            <div class="form-grid" style="grid-template-columns:1fr 120px 120px auto;gap:8px;">
                <div class="form-group">
                    <label>Copyright Holder</label>
                    <input type="text" name="film_copyright_holder_${index}" placeholder="e.g., Studio Name Ltd">
                </div>
                <div class="form-group">
                    <label>Ownership %</label>
                    <input type="number" name="film_copyright_ownership_${index}" min="0" max="100" step="0.01" class="film-copyright-pct">
                </div>
                <div class="form-group">
                    <label>Year</label>
                    <input type="number" name="film_copyright_year_${index}" min="1900" max="2099" value="${new Date().getFullYear()}">
                </div>
                <div class="credit-actions" style="padding-top:22px;">
                    <button type="button" class="btn-remove-row" onclick="removeFilmCopyrightRow(this)">✕</button>
                </div>
            </div>
        `;
        list.appendChild(row);
        calculateFilmCopyrightOwnership();
    }
    window.removeFilmCopyrightRow = function(btn) {
        btn.closest('.film-copyright-row').remove();
        calculateFilmCopyrightOwnership();
    };
    function calculateFilmCopyrightOwnership() {
        let total = 0;
        document.querySelectorAll('.film-copyright-pct').forEach(input => {
            total += parseFloat(input.value) || 0;
        });
        const totalEl = document.getElementById('film-copyright-ownership-total');
        const warningEl = document.getElementById('film-copyright-warning');
        if (totalEl) totalEl.textContent = `${total.toFixed(2)}%`;
        if (warningEl) warningEl.style.display = (total !== 100) ? 'inline' : 'none';
    }

    // v146: Composition copyright multi-owner
    function addCompCopyrightOwner() {
        const list = document.getElementById('comp-copyright-list');
        const index = state.counters.compCopyrightOwners++;
        const row = document.createElement('div');
        row.className = 'comp-copyright-row';
        row.dataset.index = index;
        row.innerHTML = `
            <div class="form-grid" style="grid-template-columns:1fr 120px 120px auto;gap:8px;">
                <div class="form-group">
                    <label>Copyright Holder</label>
                    <input type="text" name="comp_copyright_holder_${index}" placeholder="© Owner name">
                </div>
                <div class="form-group">
                    <label>Ownership %</label>
                    <input type="number" name="comp_copyright_pct_${index}" min="0" max="100" step="0.01" class="comp-copyright-pct">
                </div>
                <div class="form-group">
                    <label>Year</label>
                    <input type="number" name="comp_copyright_year_${index}" min="1900" max="2100" value="${new Date().getFullYear()}">
                </div>
                <div class="credit-actions" style="padding-top:22px;">
                    <button type="button" class="btn-remove-row" onclick="removeCompCopyrightRow(this)">✕</button>
                </div>
            </div>
        `;
        list.appendChild(row);
        calculateCompCopyrightOwnership();
    }
    window.removeCompCopyrightRow = function(btn) {
        btn.closest('.comp-copyright-row').remove();
        calculateCompCopyrightOwnership();
    };
    function calculateCompCopyrightOwnership() {
        let total = 0;
        document.querySelectorAll('.comp-copyright-pct').forEach(input => {
            total += parseFloat(input.value) || 0;
        });
        const totalEl = document.getElementById('comp-copyright-total');
        const warningEl = document.getElementById('comp-copyright-warning');
        if (totalEl) totalEl.textContent = `${total.toFixed(2)}%`;
        if (warningEl) warningEl.style.display = (total !== 100) ? 'inline' : 'none';
    }

    // v146: Sound recording copyright multi-owner
    function addSoundCopyrightOwner() {
        const list = document.getElementById('sound-copyright-list');
        const index = state.counters.soundCopyrightOwners++;
        const row = document.createElement('div');
        row.className = 'sound-copyright-row';
        row.dataset.index = index;
        row.innerHTML = `
            <div class="form-grid" style="grid-template-columns:1fr 120px 120px auto;gap:8px;">
                <div class="form-group">
                    <label>Copyright Holder</label>
                    <input type="text" name="sound_copyright_holder_${index}" placeholder="℗ Label or owner">
                </div>
                <div class="form-group">
                    <label>Ownership %</label>
                    <input type="number" name="sound_copyright_pct_${index}" min="0" max="100" step="0.01" class="sound-copyright-pct">
                </div>
                <div class="form-group">
                    <label>Year</label>
                    <input type="number" name="sound_copyright_year_${index}" min="1900" max="2100" value="${new Date().getFullYear()}">
                </div>
                <div class="credit-actions" style="padding-top:22px;">
                    <button type="button" class="btn-remove-row" onclick="removeSoundCopyrightRow(this)">✕</button>
                </div>
            </div>
        `;
        list.appendChild(row);
        calculateSoundCopyrightOwnership();
    }
    window.removeSoundCopyrightRow = function(btn) {
        btn.closest('.sound-copyright-row').remove();
        calculateSoundCopyrightOwnership();
    };
    function calculateSoundCopyrightOwnership() {
        let total = 0;
        document.querySelectorAll('.sound-copyright-pct').forEach(input => {
            total += parseFloat(input.value) || 0;
        });
        const totalEl = document.getElementById('sound-copyright-total');
        const warningEl = document.getElementById('sound-copyright-warning');
        if (totalEl) totalEl.textContent = `${total.toFixed(2)}%`;
        if (warningEl) warningEl.style.display = (total !== 100) ? 'inline' : 'none';
    }

    // v147: Dynamic film credits (directors, producers, writers, cast)
    function addFilmCredit(type, prefix, placeholder) {
        const counterKey = 'film' + type.charAt(0).toUpperCase() + type.slice(1);
        const list = document.getElementById(`film-${type}-list`);
        if (!list) return;
        const index = state.counters[counterKey]++;
        const row = document.createElement('div');
        row.className = 'credit-row film-credit-row';
        row.dataset.index = index;
        row.innerHTML = `
            <div class="form-group" style="flex:1;">
                <input type="text" name="${prefix}_${index}" placeholder="${placeholder} name">
            </div>
            <div class="credit-actions">
                <button type="button" class="btn-remove-row" onclick="this.closest('.credit-row').remove()">✕</button>
            </div>
        `;
        list.appendChild(row);
    }

    function addSample() {
        const list = document.getElementById('samples-list');
        const index = state.counters.samples++;
        const row = document.createElement('div');
        row.className = 'sample-row';
        row.dataset.index = index;
        row.innerHTML = `
            <div class="form-grid sample-grid">
                <div class="form-group">
                    <label>Original Track</label>
                    <input type="text" name="sample_track_${index}" placeholder="Original song title">
                </div>
                <div class="form-group">
                    <label>Original Artist</label>
                    <input type="text" name="sample_artist_${index}" placeholder="Original artist">
                </div>
                <div class="form-group">
                    <label>Original ISRC</label>
                    <input type="text" name="sample_isrc_${index}" placeholder="If known">
                </div>
                <div class="form-group">
                    <label>Clearance Type</label>
                    <select name="sample_clearance_${index}">
                        <option value="">Select...</option>
                        ${Object.entries(CONFIG.clearanceTypes || {}).map(([code, label]) => 
                            `<option value="${code}">${label}</option>`
                        ).join('')}
                    </select>
                </div>
            </div>
            <button type="button" class="btn-remove-row" onclick="removeSampleRow(this)">✕</button>
        `;
        list.appendChild(row);
    }

    window.removeSampleRow = function(btn) {
        btn.closest('.sample-row').remove();
    };

    function addCast() {
        const list = document.getElementById('video-cast-list');
        const index = state.counters.cast++;
        const row = document.createElement('div');
        row.className = 'credit-row';
        row.dataset.index = index;
        row.innerHTML = `
            <div class="form-group">
                <label>Name</label>
                <input type="text" name="cast_name_${index}" placeholder="Person name">
            </div>
            <div class="form-group">
                <label>Role</label>
                <input type="text" name="cast_role_${index}" placeholder="Dancer, Actor, etc.">
            </div>
            <div class="credit-actions">
                <button type="button" class="btn-remove-row" onclick="this.closest('.credit-row').remove()">✕</button>
            </div>
        `;
        list.appendChild(row);
    }

    // ===== Custom Display Traits =====
    
    // Trait template definitions
    const TRAIT_TEMPLATES = {
        genre: [
            { name: 'Genre', value: '' }  // Will be pre-filled from genre select
        ],
        mood: [
            { name: 'Mood', value: '' }
        ],
        rarity: [
            { name: 'Rarity', value: '' }
        ],
        frequency: [
            { name: 'Frequency', value: '432 Hz' },
            { name: 'Tuning', value: 'A=432' }
        ],
        collab: [
            { name: 'Featured Artist', value: '' }
        ],
        era: [
            { name: 'Era', value: '' }
        ]
    };
    
    function addCustomTrait(traitName = '', traitValue = '') {
        const list = document.getElementById('custom-traits-list');
        if (!list) return;
        
        const index = state.counters.customTraits++;
        const row = document.createElement('div');
        row.className = 'trait-row';
        row.dataset.index = index;
        row.innerHTML = `
            <div class="form-group">
                <label>Trait Name</label>
                <input type="text" name="custom_trait_name_${index}" 
                       value="${escapeHtml(traitName)}" 
                       placeholder="e.g., Mood, Rarity, Theme">
            </div>
            <div class="form-group">
                <label>Value</label>
                <input type="text" name="custom_trait_value_${index}" 
                       value="${escapeHtml(traitValue)}" 
                       placeholder="e.g., Energetic, Legendary, Summer">
            </div>
            <button type="button" class="btn-remove-trait" onclick="removeCustomTrait(this)">✕</button>
        `;
        list.appendChild(row);
        
        // Focus the first empty input
        const nameInput = row.querySelector(`[name="custom_trait_name_${index}"]`);
        const valueInput = row.querySelector(`[name="custom_trait_value_${index}"]`);
        if (!traitName && nameInput) {
            nameInput.focus();
        } else if (!traitValue && valueInput) {
            valueInput.focus();
        }
        
        updateTraitPreview();
    }
    
    // Global function so onclick works
    window.removeCustomTrait = function(btn) {
        btn.closest('.trait-row').remove();
        updateTraitPreview();
    };
    
    // Apply a trait template (adds pre-filled trait rows)
    window.applyTraitTemplate = function(templateName) {
        const template = TRAIT_TEMPLATES[templateName];
        if (!template) return;
        
        template.forEach(trait => {
            let value = trait.value;
            
            // Special case: Genre template pulls from the genre select
            if (trait.name === 'Genre' && !value) {
                const genreSelect = document.getElementById('nft-genre');
                value = genreSelect?.value || '';
            }
            
            // Check if this trait name already exists (avoid duplicates)
            const existingNames = document.querySelectorAll('[name^="custom_trait_name_"]');
            let alreadyExists = false;
            existingNames.forEach(input => {
                if (input.value.toLowerCase() === trait.name.toLowerCase()) {
                    alreadyExists = true;
                    // Flash the existing row to indicate it's already there
                    const row = input.closest('.trait-row');
                    if (row) {
                        row.style.borderColor = 'var(--imu-gold)';
                        setTimeout(() => { row.style.borderColor = ''; }, 1200);
                    }
                }
            });
            
            if (!alreadyExists) {
                addCustomTrait(trait.name, value);
            }
        });
    };
    
    // ===== Film Cue Sheet Dynamic Rows =====
    let cueSheetCounter = 1; // Row 0 already exists in HTML
    
    window.addCueSheetRow = function() {
        const list = document.getElementById('film-cue-sheet-list');
        if (!list) return;
        
        const idx = cueSheetCounter++;
        const row = document.createElement('div');
        row.className = 'cue-sheet-row dynamic-row';
        row.innerHTML = `
            <div class="form-grid" style="gap:8px;">
                <div class="form-group"><label>Song Title</label><input type="text" name="cue_title_${idx}" placeholder="Song title"></div>
                <div class="form-group"><label>Composer/Artist</label><input type="text" name="cue_artist_${idx}" placeholder="Composer or artist"></div>
                <div class="form-group"><label>Duration</label><input type="text" name="cue_duration_${idx}" placeholder="e.g., 3:24"></div>
                <div class="form-group"><label>Usage</label>
                    <select name="cue_usage_${idx}">
                        <option value="background">Background</option>
                        <option value="featured">Featured</option>
                        <option value="theme">Theme</option>
                        <option value="end_credits">End Credits</option>
                    </select>
                </div>
                <div class="form-group" style="align-self:end;">
                    <button type="button" class="btn-remove-cue" onclick="this.closest('.cue-sheet-row').remove()" title="Remove">✕</button>
                </div>
            </div>
        `;
        list.appendChild(row);
        row.querySelector(`[name="cue_title_${idx}"]`)?.focus();
    };

    function collectCustomTraits() {
        const traits = [];
        const rows = document.querySelectorAll('#custom-traits-list .trait-row');
        
        rows.forEach(row => {
            const nameInput = row.querySelector('[name^="custom_trait_name_"]');
            const valueInput = row.querySelector('[name^="custom_trait_value_"]');
            
            const name = nameInput?.value?.trim();
            const value = valueInput?.value?.trim();
            
            if (name && value) {
                // Detect numeric values for proper typing
                const numValue = parseFloat(value);
                if (!isNaN(numValue) && value === String(numValue)) {
                    traits.push({ trait_type: name, value: numValue });
                } else {
                    traits.push({ trait_type: name, value: value });
                }
            }
        });
        
        return traits;
    }
    
    function updateTraitPreview() {
        const summary = document.getElementById('trait-preview-summary');
        const tagsContainer = document.getElementById('trait-tags');
        if (!summary || !tagsContainer) return;
        
        const traits = collectCustomTraits();
        
        if (traits.length === 0) {
            summary.style.display = 'none';
            return;
        }
        
        summary.style.display = 'block';
        tagsContainer.innerHTML = traits.map(t => 
            `<span class="trait-tag"><span class="trait-tag-name">${escapeHtml(t.trait_type)}:</span> <span class="trait-tag-value">${escapeHtml(String(t.value))}</span></span>`
        ).join('');
    }

    function createBasicCreditRow(index, prefix, placeholder) {
        const row = document.createElement('div');
        row.className = 'credit-row';
        row.dataset.index = index;
        row.innerHTML = `
            <div class="form-group">
                <label>Name</label>
                <input type="text" name="${prefix}_name_${index}" placeholder="${placeholder} name">
            </div>
            <div class="credit-actions">
                <button type="button" class="btn-remove-row" onclick="this.closest('.credit-row').remove()">✕</button>
            </div>
        `;
        return row;
    }

    // ===== Preview & Metadata =====
    
    function updatePreview() {
        document.getElementById('preview-title').textContent = 
            document.getElementById('nft-title')?.value || '-';
        document.getElementById('preview-description').textContent = 
            document.getElementById('nft-description')?.value || '-';
        document.getElementById('preview-type').textContent = 
            state.contentType === 'musicVideo' ? 'Music Video Access' : 
            state.contentType === 'art' ? 'Art Access' : 
            state.contentType === 'film' ? 'Film Access' : 
            state.contentType === 'album' ? 'Album Access' :
            state.contentType === 'audiobook' ? 'AudioBook Access' : 'Music Access';
        document.getElementById('preview-schema').textContent = 
            state.contentType === 'musicVideo' ? 'Music Video' : 
            state.contentType === 'art' ? 'Art' : 
            state.contentType === 'film' ? 'Film' : 
            state.contentType === 'album' ? 'Album' : 'Music';
        
        const duration = document.getElementById('nft-duration')?.value;
        if (duration) {
            document.getElementById('preview-duration').textContent = formatDuration(parseInt(duration));
        }

        // v485: Populate price / royalty / editions / transferable in preview card
        // v612: price reflects the ACTIVE pricing mode -- static reads nft-price (XRP),
        // dynamic reads dynamic-usd-price (USD). Previously always read nft-price, so a
        // dynamic/OE listing showed a stale/empty static field. Display only; the submit
        // path (collectPricingData) was already correct.
        const _prevDynamic  = (state.pricingMode === 'dynamic');
        const _prevPrice    = parseFloat(document.getElementById('nft-price')?.value);
        const _prevUsd      = parseFloat(document.getElementById('dynamic-usd-price')?.value);
        const _prevRoyRaw   = parseFloat(document.getElementById('nft-royalty')?.value);
        const _prevRoyalty  = isNaN(_prevRoyRaw) ? 5 : _prevRoyRaw;
        const _prevEditions = parseInt(document.getElementById('nft-editions')?.value) || 1;
        const _prevTransferable = document.getElementById('nft-transferable')?.checked;
        const _prevPriceEl    = document.getElementById('preview-price');
        const _prevRoyaltyEl  = document.getElementById('preview-royalty');
        const _prevEditionsEl = document.getElementById('preview-editions');
        const _prevTransferEl = document.getElementById('preview-transferable');
        if (_prevPriceEl)    _prevPriceEl.textContent    = _prevDynamic
            ? ('$' + (!isNaN(_prevUsd) ? _prevUsd.toFixed(2) : '0.00') + ' USD')
            : ((!isNaN(_prevPrice) ? _prevPrice.toFixed(2) : '0.00') + ' XRP');
        if (_prevRoyaltyEl)  _prevRoyaltyEl.textContent  = _prevRoyalty.toFixed(1) + '%';
        // v613: Open Editions have unlimited supply -- show the infinity marker instead
        // of the (hidden) editions count. state.editionType is the same source the mint
        // payload uses (re-synced from the checked radio at submit), so preview == minted.
        if (_prevEditionsEl) _prevEditionsEl.textContent = (state.editionType === 'open') ? '∞ Unlimited' : _prevEditions.toLocaleString();
        if (_prevTransferEl) _prevTransferEl.textContent = (_prevTransferable !== undefined ? (_prevTransferable ? '✓ Yes' : '✗ No') : '—');
        const reviewName = document.getElementById('review-collection-name');
        const reviewTaxon = document.getElementById('review-collection-taxon');
        const reviewDesc = document.getElementById('review-collection-desc');
        const hiddenName = document.getElementById('nft-collection');
        const hiddenDesc = document.getElementById('nft-collection-desc');
        
        if (reviewName) {
            reviewName.textContent = state.collection.name || '—';
        }
        if (reviewTaxon) {
            const t = state.collection.taxon;
            reviewTaxon.textContent = (t !== null && t !== undefined) ? t : '—';
        }
        if (reviewDesc) {
            reviewDesc.textContent = state.collection.description || '(none)';
        }
        // Keep hidden inputs in sync so collectFormData() picks them up
        if (hiddenName) hiddenName.value = state.collection.name || '';
        if (hiddenDesc) hiddenDesc.value = state.collection.description || '';
    }

    // v612: keep the Review & Mint preview card (step 9) in sync with LIVE edits to the
    // price / royalty / editions / transferable controls. Previously updatePreview() fired
    // only on step entry, so edits weren't reflected until a save+refresh. Idempotent: the
    // _previewSync guard prevents double-binding. Pure UI refresh -- no data/submit change.
    function wirePreviewLiveRefresh() {
        ['nft-price', 'dynamic-usd-price', 'nft-royalty', 'nft-editions'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el && !el._previewSync) { el._previewSync = true; el.addEventListener('input', updatePreview); }
        });
        var tr = document.getElementById('nft-transferable');
        if (tr && !tr._previewSync) { tr._previewSync = true; tr.addEventListener('change', updatePreview); }
    }

    // M4 fix: Dynamic fee estimate based on content type and editions
    // ───────────────────────────────────────────────────────────────────────
    // OE-v1: Edition Type Toggle + Duration Picker
    // Uses spec field names: edition_type ('fixed'|'open'), open_edition_ends_at
    // ───────────────────────────────────────────────────────────────────────
    function initOEToggle() {
        const toggle = document.getElementById('edition-type-toggle');
        if (!toggle || toggle._oeInit) return;
        toggle._oeInit = true;

        document.querySelectorAll('input[name="edition_type"]').forEach(radio => {
            radio.addEventListener('change', () => applyOEEditionType(radio.value));
        });

        document.querySelectorAll('.oe-quickpick-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.oe-quickpick-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const customRange = document.getElementById('oe-custom-range');
                if (btn.dataset.custom) {
                    if (customRange) customRange.style.display = 'block';
                } else {
                    if (customRange) customRange.style.display = 'none';
                    const days = parseInt(btn.dataset.days);
                    const start = oeEffectiveStartDate();
                    const end   = new Date(start.getTime() + days * 86400000);
                    state.oeEndsAt       = end.toISOString();
                    state.oeDurationDays = days;
                    const hidden = document.getElementById('oe-open-edition-ends-at');
                    if (hidden) hidden.value = state.oeEndsAt;
                    updateOEDurationSummary(start, end);
                    updateFeeEstimate();
                }
            });
        });

        document.getElementById('oe-end-date')?.addEventListener('change', syncOECustomRange);
        document.getElementById('oe-end-time')?.addEventListener('change', syncOECustomRange);

        applyOEEditionType(state.editionType);
    }

    function oeEffectiveStartDate() {
        // Start = launch_at if scheduled, else now
        const launchType = document.querySelector('input[name="launch_type"]:checked')?.value;
        if (launchType === 'scheduled') {
            const dateVal = document.getElementById('launch-date')?.value;
            const timeVal = document.getElementById('launch-time')?.value;
            if (dateVal && timeVal) {
                const d = new Date(`${dateVal}T${timeVal}`);
                if (!isNaN(d)) return d;
            }
        }
        return new Date();
    }

    function syncOECustomRange() {
        const dateVal = document.getElementById('oe-end-date')?.value;
        const timeVal = document.getElementById('oe-end-time')?.value || '23:59';
        if (!dateVal) return;
        const endDt = new Date(`${dateVal}T${timeVal}`);
        if (isNaN(endDt)) return;
        const startDt = oeEffectiveStartDate();
        state.oeEndsAt       = endDt.toISOString();
        state.oeDurationDays = Math.round((endDt - startDt) / 86400000 * 100) / 100;
        const hidden = document.getElementById('oe-open-edition-ends-at');
        if (hidden) hidden.value = state.oeEndsAt;
        updateOEDurationSummary(startDt, endDt);
        updateFeeEstimate();
    }

    function updateOEDurationSummary(startDt, endDt) {
        const el = document.getElementById('oe-duration-summary');
        if (!el) return;
        if (!endDt) { el.style.display = 'none'; return; }
        const diffMs = endDt - (startDt || new Date());
        if (diffMs <= 0) {
            el.innerHTML = '<svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> End time must be in the future';
            el.style.display = 'block';
            return;
        }
        const diffH = diffMs / 3600000;
        const durationStr = diffH < 24
            ? diffH.toFixed(1) + ' hours'
            : (diffH / 24).toFixed(1) + ' days';
        const endStr = endDt.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
        el.innerHTML = `<svg class="imc-ic" aria-hidden="true"><use href="#ic-clock"></use></svg> Mint window: <strong>${durationStr}</strong> — closes ${endStr}`;
        el.style.display = 'block';
    }

    function applyOEEditionType(type) {
        state.editionType = type;
        const isOE = (type === 'open');

        document.querySelectorAll('input[name="edition_type"]').forEach(r => {
            r.checked = (r.value === type); // OE-v1 fix: keep DOM radio in sync with state
            const card = r.closest('.tier-toggle-card');
            if (card) card.classList.toggle('active', r.value === type);
        });

        const edCountGroup    = document.getElementById('editions-count-group');
        const oeDurationGroup = document.getElementById('oe-duration-group');
        const hintEl          = document.getElementById('oe-type-hint');
        // 'tier-builder-section' is the actual ID in page-mint.php step 8
        const tierSection     = document.getElementById('tier-builder-section');

        if (edCountGroup)    edCountGroup.style.display    = isOE ? 'none' : '';
        if (oeDurationGroup) oeDurationGroup.style.display = isOE ? ''     : 'none';
        if (hintEl) hintEl.textContent = isOE
            ? 'Open Edition: unlimited supply for the chosen time window. Tiers are not supported.'
            : 'Fixed Edition: supply is capped at your chosen editions count.';

        // Disable tier toggle for OE (single artwork only — spec requirement)
        if (tierSection) {
            tierSection.style.opacity       = isOE ? '0.4' : '';
            tierSection.style.pointerEvents = isOE ? 'none' : '';
            if (isOE && state.tiers.enabled) {
                state.tiers.enabled = false;
                document.querySelector('input[name="tier_mode"][value="single"]')?.click();
            }
        }

        updateFeeEstimate();
    }
    // ───────────────────────────────────────────────────────────────────────

    // FREE MINT PROMO -- server-authoritative quotas mirrored into CONFIG.freeMints
    // (page-mint computes remaining per type for the connected wallet at load; the
    // client decrements locally after each waived create; the SERVER re-checks at
    // create, so this is display-only and can never over-grant).
    // U0 (unlockables master): running fee total in the wizard nav bar. The stash is
    // COMPONENT-SHAPED ({ listing }, promo) so U3 adds an unlockables component without
    // changing this renderer's contract. Values are our own numbers/config -- no user input.
    function fmStashFee(amount, promo) {
        // U3: the stash is the single aggregation point -- the unlockable component
        // joins here so every fee branch, the fee box row and the footer stay in
        // lockstep from ONE seat (the U0 contract, now exercised).
        state.feeEstimateTotal = { listing: Number(amount) || 0, unlockables: ulFeeTotal(), promo: promo || null };
        updateUlFeeRow();
        updateFeeFooter();
    }
    function updateFeeFooter() {
        const chip = document.getElementById('wizard-fee-chip');
        if (!chip) return;
        const s = state.feeEstimateTotal;
        if (!state.contentType || !s) { chip.style.display = 'none'; return; }
        chip.style.display = 'flex';
        const ulT = Number(s.unlockables) || 0;
        const ulSub = ulT > 0 ? '<span class="wfc-sub">incl. ' + ulT.toFixed(2) + ' XRP unlockables</span>' : '';
        if (s.promo) {
            chip.innerHTML = (ulT > 0 ? 'Fees: <strong>' + ulT.toFixed(2) + ' XRP</strong>' : 'Listing fee: <strong>FREE \ud83c\udf81</strong>')
                + '<span class="wfc-sub">listing FREE \ud83c\udf81 -- ' + s.promo.remaining + ' of ' + s.promo.quota + ' free this month'
                + (ulT > 0 ? ' + unlockables' : '') + '</span>';
        } else {
            chip.innerHTML = 'Fees: <strong>' + ((Number(s.listing) || 0) + ulT).toFixed(2) + ' XRP</strong>' + ulSub;
        }
    }
    // ── U3 (Unlockables Master): fee grid mirror + pool state ─────────────
    // The grid comes from MINT_CONFIG.ulFeeGrid (server-injected; wp-config
    // IMC_UNLOCKABLE_FEE_GRID overrides BOTH sides). Display-only -- the server
    // re-prices from POOL ROW sizes at create.
    const UL_EXTS = ['pdf','epub','txt','zip','png','jpg','jpeg','tiff','tif','psd','bmp','webp','gif','mp3','wav','flac','m4a','aac','ogg','mp4','mov','webm'];
    function ulFeeForSize(bytes) {
        const grid = (typeof CONFIG !== 'undefined' && CONFIG && CONFIG.ulFeeGrid) ? CONFIG.ulFeeGrid : [];
        for (const rung of grid) { if (bytes <= rung[0]) return Number(rung[1]); }
        return null; // over cap
    }
    function ulUploadedPool() {
        return (state.unlockables && state.unlockables.pool || []).filter(p => p.status === 'done');
    }
    function ulFeeTotal() {
        return ulUploadedPool().reduce((s, p) => s + (ulFeeForSize(p.size) || 0), 0);
    }
    function updateUlFeeRow() {
        const row = document.getElementById('fee-ul-row');
        if (!row) return;
        const n = ulUploadedPool().length, t = ulFeeTotal();
        row.style.display = n > 0 ? '' : 'none';
        const c = document.getElementById('fee-ul-count');
        const a = document.getElementById('fee-ul-amount');
        if (c) c.textContent = String(n);
        if (a) a.textContent = t.toFixed(2) + ' XRP';
    }
    async function ulAddFile(file) {
        const pool = state.unlockables.pool;
        if (ulUploadedPool().length + pool.filter(p => p.status === 'uploading').length >= 10) {
            imcToast('Maximum 10 unlockable files per listing'); return;
        }
        const ext = (file.name.split('.').pop() || '').toLowerCase();
        if (!UL_EXTS.includes(ext)) { imcToast('.' + ext + ' is not allowed for unlockable content'); return; }
        if (file.size > 1073741824) { imcToast(file.name + ' is over the 1GB limit'); return; }
        const entry = { name: file.name, size: file.size, label: file.name.replace(/\.[^.]+$/, ''),
                        tierOrder: null, status: 'uploading', progress: 0, hash: null };
        pool.push(entry);
        ulRenderPool();
        try {
            const res = await storeUnlockableFile(file, (p) => { entry.progress = p; ulRenderPool(); });
            entry.hash = res.content_hash || (res.master_hash || null);
            entry.status = entry.hash ? 'done' : 'error';
            if (!entry.hash) entry.err = 'No hash returned';
        } catch (err) {
            entry.status = 'error';
            entry.err = err.message || 'Upload failed';
        }
        ulRenderPool();
        updateFeeEstimate();
        if (typeof scheduleMetadataSave === 'function') scheduleMetadataSave(); // U3-r2: persist the completed upload
    }
    function ulEsc(s) { const d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }
    function ulRenderPool() {
        const list = document.getElementById('ul-pool-list');
        if (!list) return;
        const pool = state.unlockables.pool;
        const tiers = (state.tiers && state.tiers.enabled && state.tiers.items) ? state.tiers.items : [];
        list.innerHTML = pool.map((p, i) => {
            const fee = ulFeeForSize(p.size);
            const feeTxt = fee !== null ? fee.toFixed(2) + ' XRP' : 'over cap';
            const stat = p.status === 'uploading' ? ('Uploading ' + (p.progress || 0) + '%')
                : p.status === 'done' ? '\u2713 Stored' : ('\u2717 ' + ulEsc(p.err || 'Failed'));
            const tierOpts = ['<option value="">All editions</option>']
                .concat(tiers.map((t, ti) => '<option value="' + ti + '"' + (String(p.tierOrder) === String(ti) ? ' selected' : '') + '>' + ulEsc(t.name || ('Tier ' + (ti + 1))) + ' only</option>')).join('');
            return '<div class="ul-pool-row" data-i="' + i + '">'
                + '<div class="ul-row-main"><span class="ul-row-name">' + ulEsc(p.name) + '</span>'
                + '<span class="ul-row-meta">' + (p.size / 1048576).toFixed(1) + ' MB \u00b7 ' + feeTxt + ' \u00b7 ' + stat + '</span></div>'
                + '<input type="text" class="ul-row-label" data-i="' + i + '" value="' + ulEsc(p.label) + '" maxlength="120" placeholder="Label shown to holders">'
                + (tiers.length >= 2 ? '<select class="ul-row-tier" data-i="' + i + '">' + tierOpts + '</select>' : '')
                + '<button type="button" class="btn-remove ul-row-remove" data-i="' + i + '">\u2715</button>'
                + '</div>';
        }).join('');
        list.querySelectorAll('.ul-row-label').forEach(el => el.addEventListener('input', (e) => {
            pool[parseInt(e.target.dataset.i, 10)].label = e.target.value;
        }));
        list.querySelectorAll('.ul-row-tier').forEach(el => el.addEventListener('change', (e) => {
            const v = e.target.value; pool[parseInt(e.target.dataset.i, 10)].tierOrder = (v === '' ? null : parseInt(v, 10));
        }));
        list.querySelectorAll('.ul-row-remove').forEach(el => el.addEventListener('click', (e) => {
            pool.splice(parseInt(e.currentTarget.dataset.i, 10), 1);
            ulRenderPool(); updateFeeEstimate();
            if (typeof scheduleMetadataSave === 'function') scheduleMetadataSave(); // U3-r2
        }));
        const sum = document.getElementById('ul-summary');
        if (sum) {
            const n = ulUploadedPool().length;
            sum.style.display = n > 0 ? '' : 'none';
            const sc = document.getElementById('ul-summary-count');
            const sf = document.getElementById('ul-summary-fee');
            if (sc) sc.textContent = n + ' unlockable file' + (n === 1 ? '' : 's');
            if (sf) sf.textContent = 'Fees: ' + ulFeeTotal().toFixed(2) + ' XRP (charged at publish)';
        }
    }
    function fmPromo() {
        const fm = (typeof CONFIG !== 'undefined' && CONFIG && CONFIG.freeMints) ? CONFIG.freeMints : null;
        if (!fm || !fm.active) return null;
        const t = state.contentType === 'musicVideo' ? 'musicvideo' : state.contentType;
        const rem = fm.remaining ? fm.remaining[t] : undefined;
        if (!(rem > 0)) return null;
        return { type: t, remaining: rem, quota: (fm.quotas && fm.quotas[t]) || 0, month: fm.monthLabel || '' };
    }

    function updateFeeEstimate() {
        // FREE MINT PROMO -- one early branch covers every type incl. album.
        const _fm = fmPromo();
        if (_fm) {
            const _fmBase  = document.getElementById('fee-base-amount');
            const _fmEds   = document.getElementById('fee-editions-count');
            const _fmPer   = document.getElementById('fee-per-edition');
            const _fmTotal = document.getElementById('fee-total');
            const _fmNote  = document.getElementById('fee-future-note');
            if (_fmBase)  _fmBase.textContent  = '0.00 XRP (free mint promo)';
            if (_fmPer)   _fmPer.textContent   = '0.00 XRP';
            if (_fmTotal) _fmTotal.textContent = 'FREE \ud83c\udf81 \u2014 ' + _fm.remaining + ' of ' + _fm.quota + ' free mints left in ' + _fm.month;
            if (_fmNote)  _fmNote.style.display = 'none';
            if (_fmEds && state.contentType !== 'album' && !(state.tiers.enabled && state.tiers.items.length >= 2)) {
                _fmEds.textContent = (state.editionType === 'open') ? '\u221e unlimited'
                    : String(parseInt(document.getElementById('nft-editions')?.value) || 1);
            }
            fmStashFee(0, { remaining: _fm.remaining, quota: _fm.quota });
            return;
        }
        // v24: Album fee = 5 XRP base + 1 XRP×tracks + 0.06 XRP×editions
        // Handle album FIRST — it uses track count, not master files
        if (state.contentType === 'album') {
            const trackCount = state.album.tracks.length || 0;
            const editions   = parseInt(document.getElementById('nft-editions')?.value) || 1;
            const typeLabel     = document.getElementById('fee-type-label');
            const baseAmount    = document.getElementById('fee-base-amount');
            const editionsCount = document.getElementById('fee-editions-count');
            const perEditionEl  = document.getElementById('fee-per-edition');
            const totalEl       = document.getElementById('fee-total');
            const futureNote    = document.getElementById('fee-future-note');
            const albumLabel    = (state.album.type === 'album' ? 'Album' : 'EP') + ' Access';

            if (state.editionType === 'open') {
                const oeBase = 5.0 * 1.25; // A3: server's v637 OE rule (was stale 3x)
                const total  = oeBase + (1.0 * trackCount);
                if (typeLabel)     typeLabel.textContent     = albumLabel + ' (Open Edition)';
                if (baseAmount)    baseAmount.textContent    = total.toFixed(2) + ' XRP (1.25× base + ' + trackCount + ' track' + (trackCount === 1 ? '' : 's') + ')';
                if (editionsCount) editionsCount.textContent = '∞ unlimited';
                if (perEditionEl)  perEditionEl.textContent  = '0.00 XRP';
                if (totalEl)       totalEl.textContent       = total.toFixed(2) + ' XRP';
                if (futureNote)    futureNote.style.display  = '';
                fmStashFee(total);
            } else {
                const baseFee        = 5.0 + (1.0 * trackCount);
                const perEditionTotal = 0.06 * editions;
                const total          = baseFee + perEditionTotal;
                if (typeLabel)     typeLabel.textContent     = albumLabel;
                if (baseAmount)    baseAmount.textContent    = baseFee.toFixed(2) + ' XRP (5 base + ' + trackCount + ' track' + (trackCount === 1 ? '' : 's') + ')';
                if (editionsCount) editionsCount.textContent = editions;
                if (perEditionEl)  perEditionEl.textContent  = perEditionTotal.toFixed(2) + ' XRP';
                if (totalEl)       totalEl.textContent       = total.toFixed(2) + ' XRP';
                if (futureNote)    futureNote.style.display  = 'none';
                fmStashFee(total);
            }
            return;
        }

        const feeSchedule = {
            audiobook:  { flat: 2.5, per: 0.06, label: 'AudioBook Access' },
            ebook:      { flat: 2.0, per: 0.03, label: 'eBook Access' },
            'art':        { flat: 2.0,  per: 0.03, label: 'Art Access' },
            'music':      { flat: 2.5,  per: 0.06, label: 'Music Access' },
            'musicVideo': { flat: 3.0,  per: 0.08, label: 'Music Video Access' },
            'film':       { flat: 15.0, per: 0.10, label: 'Film Access' }
        };
        const fee = feeSchedule[state.contentType] || feeSchedule['music'];
        let editions = parseInt(document.getElementById('nft-editions')?.value) || 1;
        // v210/fix: count UNIQUE master files (pool-aware) and, for tiered, use the
        // sum of tier editions — mirrors the server's create-time fee exactly.
        let masterFiles = 1;
        if (state.tiers.enabled && state.tiers.items.length >= 2) {
            if (state.contentType === 'art') {
                // Art: each tier's artwork is its own master — flat-fee band by tier count.
                masterFiles = state.tiers.items.length;
            } else {
                // Music/MV/Film: distinct master files; pooled masters billed once.
                masterFiles = new Set(state.tiers.items.map(t =>
                    t.masterPoolRef != null ? 'pool:' + t.masterPoolRef
                    : t.useDefaultMaster   ? 'default'
                    : t.masterContentHash  ? 'hash:' + t.masterContentHash
                    : 'tier:' + t.id
                )).size || 1;
            }
            // Per-NFT fee scales with TOTAL editions across tiers, not the single field.
            editions = state.tiers.items.reduce((s, t) => s + (parseInt(t.editions) || 0), 0) || state.tiers.items.length;
        }

        // v365: Art uses tiered flat fee instead of per-master multiplication
        function getArtTieredFlat(n) {
            if (n <= 100) return 2.0;
            if (n <= 500) return 4.0;
            return 6.0;
        }

        const typeLabel     = document.getElementById('fee-type-label');
        const baseAmount    = document.getElementById('fee-base-amount');
        const editionsCount = document.getElementById('fee-editions-count');
        const perEditionEl  = document.getElementById('fee-per-edition');
        const totalEl       = document.getElementById('fee-total');

        const isArt = state.contentType === 'art';

        // A3: OE = 1.25× flat fee, zero per-edition fee (server v637 rule; was stale 3×)
        if (state.editionType === 'open') {
            const oeFlat = isArt ? getArtTieredFlat(1) : fee.flat; // OE is single-master (tiers disabled for OE)
            const total = oeFlat * 1.25;
            if (typeLabel)     typeLabel.textContent     = fee.label + ' (Open Edition)';
            if (baseAmount)    baseAmount.textContent    = total.toFixed(2) + ' XRP (1.25× ' + (isArt ? 'tiered' : 'master file') + ' rate)';
            if (editionsCount) editionsCount.textContent = '∞ unlimited';
            if (perEditionEl)  perEditionEl.textContent  = '0.00 XRP';
            if (totalEl)       totalEl.textContent       = total.toFixed(2) + ' XRP';
            const futureNote = document.getElementById('fee-future-note');
            if (futureNote) futureNote.style.display = '';
            fmStashFee(total);
            return;
        }

        // Hide future note for fixed editions
        const futureNote = document.getElementById('fee-future-note');
        if (futureNote) futureNote.style.display = 'none';

        // v365: Art tiered flat fee
        const baseFee = isArt ? getArtTieredFlat(masterFiles) : fee.flat * masterFiles;
        const perEditionTotal = fee.per * editions;
        const total = baseFee + perEditionTotal;

        if (typeLabel)     typeLabel.textContent     = fee.label;
        if (baseAmount) {
            let baseLabel = baseFee.toFixed(2) + ' XRP';
            if (isArt && masterFiles > 1) {
                const tierBand = masterFiles <= 100 ? '≤100' : masterFiles <= 500 ? '≤500' : '500+';
                baseLabel += ' (tiered: ' + tierBand + ' tiers)';
            }
            baseAmount.textContent = baseLabel;
        }
        if (editionsCount) editionsCount.textContent = editions;
        if (perEditionEl)  perEditionEl.textContent  = perEditionTotal.toFixed(2) + ' XRP';
        if (totalEl)       totalEl.textContent       = total.toFixed(2) + ' XRP';
        fmStashFee(total);
    }

    function updateChecklist() {
        // v178: For unlicensed flows, auto-pass rights check (user doesn't see Step 6)
        const rightsPass = state.licensed
            ? document.getElementById('commercial-rights')?.checked
            : true;

        // v24: Album — writer/master DOM inputs are in hidden steps 3+5.
        // Force both to true: licensed album passes rights review at listing creation.
        // Unlicensed album (state.licensed=false) already passes via rightsPass=true above.
        const isAlbum = state.contentType === 'album';

        const checks = {
            'check-rights':  rightsPass,
            'check-writers': isAlbum ? true : (state.licensed ? !!document.querySelector('[name="writer_name_0"]')?.value : true),
            'check-master':  isAlbum ? true : (state.licensed ? !!document.getElementById('master-owner')?.value : true),
            'check-samples': true, // Always required to be answered
            'check-ai':      true  // Always required to be answered (now on Step 2)
        };
        
        Object.entries(checks).forEach(([id, passed]) => {
            const el = document.getElementById(id);
            if (el) {
                const icon = el.querySelector('.check-icon');
                if (icon) icon.textContent = passed ? '✓' : '○';
                el.classList.toggle('passed', passed);
            }
        });
    }

    // H5 fix: Strip empty objects, arrays, and blank strings from metadata
    function cleanEmptyFields(obj) {
        if (Array.isArray(obj)) {
            const cleaned = obj.map(cleanEmptyFields).filter(v => v !== undefined);
            return cleaned.length > 0 ? cleaned : undefined;
        }
        if (obj && typeof obj === 'object') {
            const cleaned = {};
            for (const [key, value] of Object.entries(obj)) {
                const v = cleanEmptyFields(value);
                if (v !== undefined && v !== '' && v !== null) cleaned[key] = v;
            }
            return Object.keys(cleaned).length > 0 ? cleaned : undefined;
        }
        // Keep booleans (including false), numbers (including 0), non-empty strings
        if (typeof obj === 'boolean' || typeof obj === 'number') return obj;
        if (typeof obj === 'string' && obj.trim() !== '') return obj;
        return undefined;
    }

    function generateMetadataPreview() {
        // v24: Album uses its own schema builder
        if (state.contentType === 'album') {
            const albumTracks = state.album.tracks.map((t, i) => ({
                track_number: i + 1,
                title:        t.title || `Track ${i + 1}`,
                preview_ipfs: t.previewIpfs || '',
                master_content_hash: t.masterContentHash || '',
                cover_ipfs:   t.coverIpfs || '',
                duration:     t.duration  || null,
                meta:         t.meta      || {}
            }));
            const fd  = collectFormData();
            const meta = buildAlbumMetadata(fd, albumTracks, state.coverIpfs || '', state.coverContentHash || null);
            document.getElementById('metadata-json-preview').textContent =
                JSON.stringify(meta, null, 2);
            return;
        }
        const metadata = (state.contentType === 'audiobook') ? buildAudiobookMetadata()
            : (state.contentType === 'ebook') ? buildEbookMetadata()
            : buildMetadata();
        const cleaned = cleanEmptyFields(metadata) || metadata;
        document.getElementById('metadata-json-preview').textContent = 
            JSON.stringify(cleaned, null, 2);
    }

    // ===== XLS-24d Compliant Metadata Building =====
    
    /**
     * M1-e1b: AudioBook metadata (single file).
     * Root audio stays set (store classifies audiobook as audio). audiobook{} is kept
     * on-chain like album{}; audiobook_details{} is stripped before pinning by the engine.
     */
    /**
     * M1-e2b: build the book manifest the server validates and stores.
     * Shape: { v:1, pool:[{ref,hash,kind,seconds}], versions:{ '1': { chapters:[...] } } }
     * Every chapter ref must resolve to a pool item or the server rejects it.
     * The versions map exists from day one so tiers need no migration later.
     */
    function buildBookManifest(chapterUploads, pageUploads) {
        const pool = [];
        const chapters = [];
        const pages = [];
        chapterUploads.forEach(function (c, i) {
            const ref = 'c' + (i + 1);
            const item = { ref: ref, hash: c.masterContentHash, kind: 'audio' };
            if (c.seconds) item.seconds = Math.round(c.seconds);
            pool.push(item);
            const ch = { n: i + 1, ref: ref, title: (c.title || ('Chapter ' + (i + 1))).slice(0, 200) };
            if (c.seconds) ch.seconds = Math.round(c.seconds);
            chapters.push(ch);
        });
        // M1-e2c: pages. A PDF contributes ONE pool item referenced by every page.
        if (pageUploads && pageUploads.length) {
            const pdfRefs = {};
            pageUploads.forEach(function (p, i) {
                let ref;
                if (p.src === 'pdf') {
                    if (!pdfRefs[p.hash]) {
                        ref = 'bk' + (Object.keys(pdfRefs).length + 1);
                        pdfRefs[p.hash] = ref;
                        pool.push({ ref: ref, hash: p.hash, kind: 'pdf', pages: pageUploads.length });
                    }
                    ref = pdfRefs[p.hash];
                    pages.push({ n: i + 1, ref: ref, src: 'pdf', page: p.page || (i + 1) });
                } else {
                    ref = 'pg' + (i + 1);
                    pool.push({ ref: ref, hash: p.hash, kind: 'page' });
                    pages.push({ n: i + 1, ref: ref, src: 'image' });
                }
            });
        }
        const version = { label: 'Standard' };
        if (chapters.length) version.chapters = chapters;
        if (pages.length)    version.pages = pages;
        return { v: 1, pool: pool, versions: { '1': version } };
    }

    /**
     * M2-b: eBook metadata. No audio and no animation_url - an eBook is a read
     * format, so the cover image is the display media. ebook_details{} is stripped
     * server-side (M2-a) and lives in the DB only.
     */
    function buildEbookMetadata() {
        const meta = buildMetadata();
        delete meta.audio;
        delete meta.animation_url;
        const fmt = document.getElementById('ebook-format')?.value || '';
        const eb = {
            format: fmt,
            page_count: bookPageTotal()
        };
        const author = document.getElementById('ebook-author')?.value.trim();
        const illus  = document.getElementById('ebook-illustrator')?.value.trim();
        if (author) eb.author = author;
        if (illus)  eb.illustrator = illus;
        meta.ebook = eb;
        meta.ebook_details = {
            publisher: document.getElementById('ebook-publisher')?.value.trim() || '',
            isbn:      document.getElementById('ebook-isbn')?.value.trim() || '',
            language:  document.getElementById('ebook-language')?.value.trim() || ''
        };
        // M2-e: parity with AudioBook - the back cover is public and watermarked, so it
        // belongs on-chain beside the front cover. The engine passes it through untouched.
        if (state.backCoverIpfs) { meta.back_cover = state.backCoverIpfs; }
        if (meta.properties) { meta.properties.nftType = 'eBook Access'; }
        return meta;
    }

    function buildAudiobookMetadata() {
        const meta = buildMetadata();
        const val  = (id) => (document.getElementById(id)?.value || '').trim();
        const ab = {
            format:    state.audiobook.format || 'single',
            author:    val('ab-author'),
            narrator:  val('ab-narrator'),
            publisher: val('ab-publisher'),
            series:    val('ab-series'),
            abridged:  !!document.getElementById('ab-abridged')?.checked
        };
        const details = {
            isbn:          val('ab-isbn'),
            language:      val('ab-language'),
            release_date:  val('ab-release-date'),
            rights_holder: val('ab-rights')
        };
        if (state.previewMeta && state.previewMeta.duration) {
            ab.sample_seconds = state.previewMeta.duration;
        }
        // M1-e2b: chapter titles + lengths travel on-chain (file hashes never do).
        if (ab.format === 'chaptered' && state.audiobook.chapters.length) {
            ab.chapter_count = state.audiobook.chapters.length;
            ab.chapters = state.audiobook.chapters.map(function (c, i) {
                const e = { n: i + 1, title: (c.title || ('Chapter ' + (i + 1))).slice(0, 200) };
                if (c.seconds) e.seconds = Math.round(c.seconds);
                return e;
            });
            const total = state.audiobook.chapters.reduce((s, c) => s + (c.seconds || 0), 0);
            if (total) ab.runtime_seconds = Math.round(total);
        }
        meta.audiobook = ab;
        meta.audiobook_details = details;
        if (state.backCoverIpfs) { meta.back_cover = state.backCoverIpfs; }
        if (meta.properties) { meta.properties.nftType = 'AudioBook Access'; }
        return meta;
    }

    function buildMetadata() {
        const formData = collectFormData();
        const type = state.contentType;
        const isVideo = type === 'musicVideo';
        const isArt = type === 'art';
        const isFilm = type === 'film';
        
        // Determine if downloads should be allowed on marketplaces
        // v147: Always allow download — dual asset approach handles protection
        const allowDownload = true;
        
        // ================================================================
        // BLUEPRINT-ALIGNED METADATA
        // Metadata is assembled here from the collected form state.
        
        // ================================================================
        
        const previewCid = state.previewIpfs || (isArt ? (state.coverIpfs || 'ipfs://PENDING') : 'ipfs://PENDING');
        
        // License type selection
        const licenseValue = isArt ? (formData.art_license_type || 'all_rights_reserved') :
                            isFilm ? (formData.film_license_type || 'all_rights_reserved') :
                            (formData.license_type || 'all_rights_reserved');
        
        const metadata = {
            name: formData.title || 'Untitled',
            description: formData.description || '',
            image: state.coverIpfs || 'ipfs://PENDING',
            
            external_url: 'https://imcollectibles.io',
            license: licenseValue,
            
            collection: {
                name: state.collection.name || formData.collection_name || 'Singles',
                family: 'IMCollectibles'
            },
            
            // DISPLAY TRAITS: Edition + artist-defined custom traits + content flags
            attributes: buildAttributes(formData),
            
            // MASTER ACCESS
            master_access: {
                protected: true,
                provider: 'imcollectibles',
                entitlement_api: 'https://imcollectibles.io/wp-json/imu-master/v1/access',
                content_hash: state.masterContentHash || 'sha256:PENDING'
            }
        };
        
        // v377: Include cover_access when cover art is watermarked (Music/MV/Film flows)
        // This allows NFT holders to request the original unprotected cover via entitlement API
        if (state.coverContentHash) {
            metadata.cover_access = {
                protected: true,
                provider: 'imcollectibles',
                entitlement_api: 'https://imcollectibles.io/wp-json/imu-master/v1/access',
                content_hash: state.coverContentHash
            };
        }
        
        if (isArt) {
            // ════════════════════════════════════════════════════
            // ART-SPECIFIC METADATA
            // ════════════════════════════════════════════════════
            metadata.schema = 'IMU Art Access';
            metadata.nftType = 'Art';
            metadata.properties = {
                files: buildFiles(),
                category: 'image',
                creators: buildCreators(formData)
            };
            metadata.master_access.formats_available = ['png', 'tiff', 'psd', 'jpeg-full'];
            
            // Art metadata object
            metadata.art = {
                version: '1.0',
                subtype: formData.art_subtype || 'digital_art',
                medium: formData.art_medium || undefined,
                dimensions: formData.art_dimensions || undefined
            };
            
            // Copyright info (optional)
            if (formData.art_copyright_holder) {
                metadata.art.copyright = {
                    holder: formData.art_copyright_holder,
                    year: formData.art_copyright_year ? parseInt(formData.art_copyright_year) : undefined,
                    registration: formData.art_copyright_registration || undefined
                };
            }
            
            // No audio/animation_url for art — image is the display
            
        } else if (isFilm) {
            // ════════════════════════════════════════════════════
            // FILM-SPECIFIC METADATA
            // ════════════════════════════════════════════════════
            metadata.schema = 'IMU Film Access';
            metadata.nftType = 'Film';
            
            // PUBLIC PLAYABLE: Preview clip (≤120s)
            metadata.animation_url = previewCid;
            
            metadata.properties = {
                files: buildFiles(),
                category: 'video',
                creators: buildCreators(formData)
            };
            metadata.master_access.formats_available = ['mp4-full', 'mov', 'mkv'];
            
            // PREVIEW OBJECT — preview trailer/clip
            metadata.preview = {
                video: previewCid,
                duration_seconds: state.previewMeta.duration || 120
            };
            
            // Film metadata object
            metadata.film = buildFilmObject(formData);
            
        } else {
            // ════════════════════════════════════════════════════
            // MUSIC / MUSIC VIDEO METADATA
            // ════════════════════════════════════════════════════
            metadata.schema = isVideo ? 'IMU Music Video Access' : 'IMU Music Access';
            metadata.nftType = isVideo ? 'Music Video' : 'Music';
            
            // PUBLIC PLAYABLE: Preview only (≤30s)
            metadata.audio = previewCid;
            metadata.animation_url = previewCid;
            
            metadata.properties = {
                files: buildFiles(),
                category: isVideo ? 'video' : 'audio',
                creators: buildCreators(formData)
            };
            
            metadata.master_access.formats_available = ['flac', 'mp3-320', 'aac-256'];
            
            // PREVIEW OBJECT — identifies the public preview
            // v598: music video uses a video preview key (matches film + per-edition builder);
            // music keeps audio. Apps read root audio/animation_url; explorers read this.
            metadata.preview = isVideo
                ? { video: previewCid, duration_seconds: state.previewMeta.duration || 30 }
                : { audio: previewCid, duration_seconds: state.previewMeta.duration || 30 };
            
            // Full XLS-24d music metadata
            metadata.music = buildMusicObject(formData);
            
            // Add video field for music videos
            if (isVideo) {
                metadata.video = previewCid;
                metadata.video_metadata = buildVideoMetadata(formData);
            }
        }
        
        // Top-level AI disclosure (all content types — captured from Step 2)
        // v43: Only include when AI was used — reduces metadata clutter
        if (formData.ai_used === 'true') {
            metadata.ai_disclosure = {
                ai_used: true,
                platform: formData.ai_platform || undefined,
                creation_date: formData.ai_creation_date || undefined,
                ai_created_elements: formData['ai_elements[]'] || [],
                human_created_elements: formData['human_elements[]'] || []
            };
        }
        
        return metadata;
    }

    function buildMusicObject(formData) {
        const durationMs = (parseInt(formData.duration) || 0) * 1000;
        
        return {
            version: '1.0',
            
            track: {
                title: formData.title || '',
                alternative_titles: formData.alternative_titles ? 
                    formData.alternative_titles.split(',').map(t => t.trim()).filter(t => t) : undefined,
                duration_ms: durationMs,
                work_type: formData.work_type || 'original',
                explicit: formData.explicit === 'on' || formData.explicit === true,
                language: formData.language || undefined,
                genre: formData.genre || undefined,
                subgenre: formData.subgenre || undefined,
                bpm: formData.bpm ? parseInt(formData.bpm) : undefined,
                key: formData.key || undefined,
                lyrics: formData.lyrics || undefined,
                creation_date: formData.creation_date || undefined
            },
            
            identifiers: {
                isrc: formData.isrc || undefined,
                iswc: formData.iswc || undefined,
                upc: formData.upc || undefined
            },
            
            artists: {
                primary: collectArtists('primary_artist', formData),
                featured: collectArtists('featured_artist', formData)
            },
            
            credits: {
                performers: collectArtists('primary_artist', formData), // Same as primary artists
                writers: collectWriters(formData),
                producers: collectBasicCredits('producer', formData),
                engineers: collectEngineers(formData),
                musicians: collectMusicians(formData)
            },
            
            publishing: buildPublishing(formData),
            
            // v150: Multi-owner master recording
            master_recording: (() => {
                const owners = [];
                let i = 0;
                while (i < 20) {
                    const name = formData[`master_owner_name_${i}`];
                    if (name) {
                        owners.push({
                            name: name,
                            ownership_pct: parseFloat(formData[`master_owner_pct_${i}`]) || 0
                        });
                    }
                    i++;
                }
                const primary = owners[0] || {};
                return {
                    owner: primary.name || '',
                    owners: owners.length > 0 ? owners : undefined,
                    phonographic_line: primary.name ?
                        `℗ ${new Date().getFullYear()} ${primary.name}` : undefined,
                    phonographic_year: new Date().getFullYear()
                };
            })(),
            
            release: {
                date: formData.release_date || undefined,
                first_release_territory: formData.first_release_territory || undefined,
                type: formData.release_type || undefined,
                album_name: formData.album_name || undefined,
                track_number: formData.track_number ? parseInt(formData.track_number) : undefined,
                total_tracks: formData.total_tracks ? parseInt(formData.total_tracks) : undefined
            },
            
            rights: {
                commercial_rights: formData.commercial_rights === 'on' || formData.commercial_rights === true,
                license_type: formData.license_type || 'all_rights_reserved',
                permissions: {
                    streaming: true, // v150: Always on (locked in UI)
                    download: true,  // v150: Always true — dual asset handles protection
                    remix: formData.permission_remix === 'on',
                    sync: formData.permission_sync === 'on',
                    commercial_use: formData.permission_commercial === 'on'
                },
                territories: ['worldwide'],
                exclusivity: false
            },
            
            royalties: {
                // v470: Read royalty directly from DOM — collectFormData() iteration unreliable for Step 9 fields (same root cause as v464)
                // v485: isNaN() fix — || 5 was falsy and turned explicit 0% into 5% (0 is falsy in JS). isNaN correctly treats 0 as valid.
                transfer_fee: (() => { const _r = parseFloat(document.getElementById('nft-royalty')?.value); return isNaN(_r) ? 5 : _r; })()
            },
            
            samples: {
                contains_samples: formData.contains_samples === 'true',
                cleared: formData.samples_cleared === 'on',
                clearance_proof_uri: formData.clearance_proof_uri || undefined,
                sample_details: collectSamples(formData)
            },
            
            ai_disclosure: {
                ai_used: formData.ai_used === 'true',
                platform: formData.ai_platform || undefined,
                creation_date: formData.ai_creation_date || undefined,
                ai_created_elements: formData['ai_elements[]'] || [],
                human_created_elements: formData['human_elements[]'] || 
                    ['lyrics', 'melody', 'vocal_performance', 'harmony', 'rhythm']
            }
        };
    }

    function buildVideoMetadata(formData) {
        const durationMs = (parseInt(formData.duration) || 0) * 1000;
        
        return {
            version: '1.0',
            
            content: {
                title: `${formData.title || 'Untitled'} (${formData.video_type || 'Music Video'})`,
                type: formData.video_type || 'official_video',
                runtime_ms: durationMs,
                release_date: formData.video_release_date || formData.release_date || undefined,
                creation_date: formData.creation_date || undefined
            },
            
            identifiers: {
                isrc: formData.isrc_video || undefined
            },
            
            technical: {
                resolution: formData.resolution || state.detectedMeta.resolution || undefined,
                aspect_ratio: formData.resolution ? 
                    calculateAspectRatio(formData.resolution) : undefined,
                frame_rate: formData.framerate ? parseInt(formData.framerate) : undefined,
                format: 'video/mp4'
            },
            
            credits: {
                director: formData.video_director ? 
                    [{ name: formData.video_director }] : [],
                producer: [],
                cinematographer: formData.video_cinematographer ? 
                    [{ name: formData.video_cinematographer }] : [],
                editor: formData.video_editor ? 
                    [{ name: formData.video_editor }] : [],
                colorist: formData.video_colorist ? 
                    [{ name: formData.video_colorist }] : [],
                choreographer: formData.video_choreographer ? 
                    [{ name: formData.video_choreographer }] : [],
                vfx: [],
                stylist: [],
                art_director: []
            },
            
            cast: collectCast(formData),
            
            production: {
                production_company: formData.production_company || undefined,
                filming_location: formData.filming_location || undefined,
                filming_country: formData.filming_country || undefined
            },
            
            rights: {
                commercial_rights: formData.commercial_rights === 'on'
            },
            
            ai_disclosure: {
                ai_used: formData.video_ai_used === 'true',
                platform: formData.video_ai_platform || undefined,
                creation_date: formData.ai_creation_date || undefined
            }
        };
    }

    // ===== Film Metadata Builder =====
    function buildFilmObject(formData) {
        return {
            version: '1.0',

            // Descriptive metadata (Page 1)
            descriptive: {
                official_title: formData.film_title || formData.title || '',
                original_language_title: formData.film_original_title || undefined,
                logline: formData.film_logline || undefined,
                short_synopsis: formData.film_short_synopsis || undefined,
                long_synopsis: formData.film_long_synopsis || undefined,
                classification: formData.film_classification || undefined,
                primary_genre: formData.film_genre || undefined,
                secondary_genres: formData.film_subgenres ?
                    formData.film_subgenres.split(',').map(g => g.trim()).filter(g => g) : undefined,
                language: formData.film_language || undefined,
                target_audience_rating: formData.film_rating || undefined,
                runtime_minutes: formData.film_runtime ? parseInt(formData.film_runtime) : undefined
            },

            // Credits
            credits: {
                // v147: Collect film credits from dynamic lists
                director: Array.from(document.querySelectorAll('[name^="film_director_"]')).map(i => i.value?.trim()).filter(v => v).join(', ') || undefined,
                producers: Array.from(document.querySelectorAll('[name^="film_producer_"]')).map(i => i.value?.trim()).filter(v => v),
                writers: Array.from(document.querySelectorAll('[name^="film_writer_"]')).map(i => i.value?.trim()).filter(v => v),
                lead_cast: Array.from(document.querySelectorAll('[name^="film_cast_"]')).map(i => i.value?.trim()).filter(v => v)
            },

            // Administrative & rights metadata (Page 2)
            rights: {
                production_company: formData.film_production_company || undefined,
                // v150: Multi-owner film copyright
                copyright_owners: (() => {
                    const owners = [];
                    let i = 0;
                    while (i < 20) {
                        const holder = formData[`film_copyright_holder_${i}`];
                        if (holder) {
                            owners.push({
                                holder: holder,
                                ownership_pct: parseFloat(formData[`film_copyright_ownership_${i}`]) || 0,
                                year: parseInt(formData[`film_copyright_year_${i}`]) || new Date().getFullYear()
                            });
                        }
                        i++;
                    }
                    return owners.length > 0 ? owners : undefined;
                })(),
                copyright_line: (() => {
                    const h = formData.film_copyright_holder_0;
                    const y = formData.film_copyright_year_0 || new Date().getFullYear();
                    return h ? `© ${y} ${h}` : undefined;
                })(),
                isan: formData.film_isan || undefined,
                eidr: formData.film_eidr || undefined,
                original_release_date: formData.film_release_date || undefined,
                country_of_origin: formData.film_country || undefined,
                territories_owned: formData.film_territories ?
                    formData.film_territories.split(',').map(t => t.trim()).filter(t => t) : undefined,
                license_expiration: formData.film_license_expiry || undefined,
                streaming_rights: formData.film_license_type || 'all_rights_reserved',
                commercial_rights: formData.film_commercial_rights === 'on'
            },

            // Music cue sheet (collected from dynamic list)
            music_cue_sheet: collectFilmCueSheet(formData)
        };
    }

    function collectFilmCueSheet(formData) {
        // v150: Cue sheet is now a file upload (CSV/PDF), not manual entry
        // The file is uploaded separately — just note its presence in metadata
        const cueSheetInput = document.getElementById('cue-sheet-file');
        if (cueSheetInput && cueSheetInput.files && cueSheetInput.files.length > 0) {
            return {
                type: 'file_upload',
                filename: cueSheetInput.files[0].name,
                format: cueSheetInput.files[0].name.endsWith('.pdf') ? 'pdf' : 'csv'
            };
        }
        return undefined;
    }

    function collectArtists(prefix, formData) {
        const artists = [];
        let i = 0;
        while (formData[`${prefix}_name_${i}`] !== undefined || i < 10) {
            const name = formData[`${prefix}_name_${i}`];
            if (name) {
                artists.push({
                    name: name
                });
            }
            i++;
        }
        return artists.length > 0 ? artists : undefined;
    }

    function collectWriters(formData) {
        const writers = [];
        let i = 0;
        while (formData[`writer_name_${i}`] !== undefined || i < 20) {
            const name = formData[`writer_name_${i}`];
            if (name) {
                writers.push({
                    name: name,
                    role: formData[`writer_role_${i}`] || 'CA',
                    ownership_share: parseFloat(formData[`writer_ownership_${i}`]) || 0,
                    ipi: formData[`writer_ipi_${i}`] || undefined,
                    pro: formData[`writer_pro_${i}`] || undefined
                });
            }
            i++;
        }
        return writers;
    }

    function collectBasicCredits(prefix, formData) {
        const credits = [];
        let i = 0;
        while (formData[`${prefix}_name_${i}`] !== undefined || i < 10) {
            const name = formData[`${prefix}_name_${i}`];
            if (name) {
                credits.push({
                    name: name
                });
            }
            i++;
        }
        return credits.length > 0 ? credits : undefined;
    }

    function collectEngineers(formData) {
        const engineers = [];
        let i = 0;
        while (formData[`engineer_name_${i}`] !== undefined || i < 10) {
            const name = formData[`engineer_name_${i}`];
            if (name) {
                engineers.push({
                    name: name,
                    role: formData[`engineer_role_${i}`] || 'mixing'
                });
            }
            i++;
        }
        return engineers.length > 0 ? engineers : undefined;
    }

    function collectMusicians(formData) {
        const musicians = [];
        let i = 0;
        while (formData[`musician_name_${i}`] !== undefined || i < 10) {
            const name = formData[`musician_name_${i}`];
            if (name) {
                musicians.push({
                    name: name,
                    instrument: formData[`musician_instrument_${i}`] || undefined
                });
            }
            i++;
        }
        return musicians.length > 0 ? musicians : undefined;
    }

    function buildPublishing(formData) {
        const isSelfPublished = formData.self_published === 'on';
        
        const publishers = [];
        if (!isSelfPublished) {
            let i = 0;
            while (formData[`publisher_name_${i}`] !== undefined || i < 10) {
                const name = formData[`publisher_name_${i}`];
                if (name) {
                    publishers.push({
                        name: name,
                        type: formData[`publisher_type_${i}`] || 'OP',
                        ownership_share: parseFloat(formData[`publisher_ownership_${i}`]) || undefined,
                        ipi: formData[`publisher_ipi_${i}`] || undefined,
                        pro: formData[`publisher_pro_${i}`] || undefined
                    });
                }
                i++;
            }
        } else {
            // v150: Self-published — use first comp copyright holder
            const selfName = document.querySelector('[name="comp_copyright_holder_0"]')?.value?.trim() || 'Self';
            publishers.push({
                name: selfName,
                type: 'SE'
            });
        }
        
        // v150: Collect composition copyright owners (multi-owner)
        const compCopyrightOwners = [];
        let ci = 0;
        while (ci < 20) {
            const holder = formData[`comp_copyright_holder_${ci}`];
            if (holder) {
                compCopyrightOwners.push({
                    holder: holder,
                    ownership_pct: parseFloat(formData[`comp_copyright_pct_${ci}`]) || 0,
                    year: parseInt(formData[`comp_copyright_year_${ci}`]) || new Date().getFullYear()
                });
            }
            ci++;
        }
        
        // v150: Collect sound recording copyright owners (multi-owner)
        const soundCopyrightOwners = [];
        let si = 0;
        while (si < 20) {
            const holder = formData[`sound_copyright_holder_${si}`];
            if (holder) {
                soundCopyrightOwners.push({
                    holder: holder,
                    ownership_pct: parseFloat(formData[`sound_copyright_pct_${si}`]) || 0,
                    year: parseInt(formData[`sound_copyright_year_${si}`]) || new Date().getFullYear()
                });
            }
            si++;
        }
        
        const primaryComp = compCopyrightOwners[0] || {};
        const primarySound = soundCopyrightOwners[0] || {};
        
        return {
            publishers: publishers.length > 0 ? publishers : undefined,
            copyright_line: primaryComp.holder ?
                `© ${primaryComp.year || new Date().getFullYear()} ${primaryComp.holder}` :
                `© ${new Date().getFullYear()}`,
            copyright_year: primaryComp.year || new Date().getFullYear(),
            composition_copyright: compCopyrightOwners.length > 0 ? compCopyrightOwners : undefined,
            sound_recording_copyright: soundCopyrightOwners.length > 0 ? soundCopyrightOwners : undefined
        };
    }

    function collectSamples(formData) {
        if (formData.contains_samples !== 'true') return undefined;
        
        const samples = [];
        let i = 0;
        while (formData[`sample_track_${i}`] !== undefined || i < 10) {
            const track = formData[`sample_track_${i}`];
            if (track) {
                samples.push({
                    original_track: track,
                    original_artist: formData[`sample_artist_${i}`] || undefined,
                    original_isrc: formData[`sample_isrc_${i}`] || undefined,
                    clearance_type: formData[`sample_clearance_${i}`] || undefined
                });
            }
            i++;
        }
        return samples.length > 0 ? samples : undefined;
    }

    function collectCast(formData) {
        const cast = [];
        let i = 0;
        while (formData[`cast_name_${i}`] !== undefined || i < 10) {
            const name = formData[`cast_name_${i}`];
            if (name) {
                cast.push({
                    name: name,
                    role: formData[`cast_role_${i}`] || undefined
                });
            }
            i++;
        }
        return cast.length > 0 ? cast : undefined;
    }

    function buildAttributes(formData) {
        // ================================================================
        // BLUEPRINT-COMPLIANT attributes[]
        // 
        // attributes[] = DISPLAY traits for XRPL marketplaces (Bithomp, xrp.cafe)
        // 
        // Auto-generated: Edition info only
        // Artist-defined: All custom traits from the UI
        //
        // NOT here (moved to proper locations):
        //   Content Type  → root nftType
        //   Schema Version → root schema  
        //   Downloadable  → root downloadable
        //   Work Type     → music.track.work_type
        //   Explicit      → music.track.explicit
        //   BPM/Genre/Key → music.track{}
        // ================================================================
        
        const attrs = [];
        
        // ── Permanent content flags (stamped in metadata) ──
        // Streaming Access Granted — always present
        attrs.push({ trait_type: 'Streaming Access', value: 'Granted' });
        
        // Explicit Content — only if checked
        if (formData.explicit === 'on' || formData.explicit === true) {
            attrs.push({ trait_type: 'Explicit Content', value: 'Yes' });
        }
        
        // M2-b: eBook traits - Book Type drives filtering; the rest are credits.
        if (state.contentType === 'ebook') {
            const ebV = (id) => (document.getElementById(id)?.value || '').trim();
            const ebFmtMap = { novel: 'Novel', short: 'Short Story', comic: 'Comic', magazine: 'Magazine' };
            if (ebV('ebook-format'))      attrs.push({ trait_type: 'Book Type',   value: ebFmtMap[ebV('ebook-format')] || ebV('ebook-format') });
            if (ebV('ebook-author'))      attrs.push({ trait_type: 'Author',      value: ebV('ebook-author') });
            if (ebV('ebook-illustrator')) attrs.push({ trait_type: 'Illustrator', value: ebV('ebook-illustrator') });
            if (ebV('ebook-language'))    attrs.push({ trait_type: 'Language',    value: ebV('ebook-language') });
            const _ebPages = bookPageTotal();
            if (_ebPages) attrs.push({ trait_type: 'Pages', value: String(_ebPages) });
        }
        // M1-e1c: AudioBook traits - the on-chain payoff of Book Details.
        if (state.contentType === 'audiobook') {
            const abVal = (id) => (document.getElementById(id)?.value || '').trim();
            const abFmt = (state.audiobook && state.audiobook.format) || 'single';
            attrs.push({ trait_type: 'Format', value: abFmt === 'chaptered' ? 'Chaptered' : 'Single File' });
            if (abVal('ab-author'))   attrs.push({ trait_type: 'Author',   value: abVal('ab-author') });
            if (abVal('ab-narrator')) attrs.push({ trait_type: 'Narrator', value: abVal('ab-narrator') });
            if (abVal('ab-series'))   attrs.push({ trait_type: 'Series',   value: abVal('ab-series') });
            if (document.getElementById('ab-abridged')?.checked) {
                attrs.push({ trait_type: 'Edition', value: 'Abridged' });
            }
        }

        // Art subtype trait (Digital Art / Fine Art)
        if (state.contentType === 'art' && formData.art_subtype) {
            const subtypeLabel = formData.art_subtype === 'fine_art' ? 'Fine Art' : 'Digital Art';
            attrs.push({ trait_type: 'Art Type', value: subtypeLabel });
        }
        
        // Edition info — only for multi-edition NFTs
        // Single editions: NO auto traits (artist controls all traits via custom UI)
        // Multiple editions: Edition placeholder (actual # assigned at mint time)
        // v464: Read editions directly from DOM for consistency with listing payload fix
        const editions = parseInt(document.getElementById('nft-editions')?.value) || 1;
        if (editions > 1) {
            attrs.push({ trait_type: 'Edition', value: '#{n}', max_value: editions });
        }
        
        // Artist-defined custom traits from the dynamic UI
        const customTraits = collectCustomTraits();
        customTraits.forEach(trait => {
            attrs.push(trait);
        });
        
        return attrs;
    }

    function buildFiles() {
        const files = [];
        if (state.coverIpfs) {
            files.push({ uri: state.coverIpfs, type: state.coverMime || 'image/jpeg' }); // 8b: true mime
        }
        // Preview goes to IPFS files[] (NOT the master)
        // 8b: art's public image IS the cover entry above (same URI) — the old duplicate
        // preview entry defaulted to 'audio/mpeg' (the P7 phantom). Born no more; P7 stays as belt.
        if (state.previewIpfs && state.contentType !== 'art') {
            // v204: Use actual file MIME type (handles both audio and video previews for musicVideo)
            const previewType = state.previewFile?.type || 
                ((state.contentType === 'film' || state.contentType === 'musicVideo') ? 'video/mp4' : 'audio/mpeg');
            files.push({ 
                uri: state.previewIpfs, 
                type: previewType
            });
        }
        // Music video secondary audio
        if (state.audioIpfs) {
            files.push({ uri: state.audioIpfs, type: 'audio/mpeg' });
        }
        return files;
    }

    function buildCreators(formData) {
        // v150: Creator is always the minting account (wallet fields removed from UI)
        const creators = [];
        if (CONFIG.account) {
            creators.push({ address: CONFIG.account, share: 100 });
        }
        return creators;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // v71: Multi-Currency Collection
    // ═══════════════════════════════════════════════════════════════════════════
    
    // ═══════════════════════════════════════════════════════════════════════════
    // v75: Collect Pricing Data (supports both static and dynamic modes)
    // ═══════════════════════════════════════════════════════════════════════════
    
    function collectPricingData() {
        const xrpPrice = parseFloat(document.getElementById('nft-price')?.value) || 0;
        const pricingMode = state.pricingMode || 'static';
        
        const data = {
            pricing_mode: pricingMode,
            price_xrp: xrpPrice
        };
        
        // Mint limit settings -- v566: read directly from the DOM (matches the v464/v470
        // Step-9 fix already used for price/editions/royalty). state.mintLimitPerWallet was
        // stale when the artist left the pre-filled default untouched, storing enabled=1 with 0.
        // Math.max(1, n) guarantees an enabled limit can never be 0 (defense in depth).
        // v931 (4 Sep 2026): HOISTED above the v664 free-mint early return. It used to sit at the
        // end of this function, so a FREE listing returned before ever reading it -- the payload
        // builders' `pricingData.mint_limit_enabled || 0` then wrote 0/0 no matter what the artist
        // set. Third instance of the same shape (cf. F5a, the v412 guard): a `pricing_mode === 'free'`
        // branch short-circuiting past something that applies to EVERY mode. The limit is not a price.
        const _mlEnabled   = document.getElementById('mint-limit-enabled')?.checked ? 1 : 0;
        const _mlPerWallet = parseInt(document.getElementById('mint-limit-per-wallet')?.value) || 0;
        data.mint_limit_enabled    = _mlEnabled;
        data.mint_limit_per_wallet = _mlEnabled ? Math.max(1, _mlPerWallet) : 0;
        
        // v664: FREE MINT -- a free listing must carry no PRICES anywhere, so a stray token
        // price can never make it selectable/purchasable. Return immediately with zeroed prices.
        // (The mint limit is read above and survives this return -- see v931.)
        if (pricingMode === 'free') {
            data.price_xrp = 0;
            data.price_usd = null;
            data.accepted_currencies = [];
            return data;
        }
        
        if (pricingMode === 'dynamic') {
            // v84: Dynamic mode = XRP ONLY (calculated from USD at checkout)
            data.price_usd = parseFloat(document.getElementById('dynamic-usd-price')?.value) || 0;
            
            // v193: Enforce $5,000 USD cap
            if (data.price_usd > 5000) {
                throw new Error('Maximum dynamic price is $5,000 USD for compliance reasons.');
            }
            
            // Only XRP accepted for dynamic pricing
            data.accepted_currencies = [
                { currency: 'XRP', enabled: true }
            ];

            // PP-5: the dynamic progressive config -- single USD increment, together by
            // construction. Rides the same POST key; the validator enforces the shape.
            const ppDynT = document.getElementById('pp-dyn-toggle');
            const ppDynInc = parseFloat(state.progressive.increments['USD']);
            if (ppDynT && ppDynT.checked && ppDynInc > 0) {
                data.progressive = { enabled: true, scope: 'together', increments: { USD: ppDynInc } };
                const ppDynCap = parseFloat(state.progressive.caps['USD']);
                if (ppDynCap > 0) data.progressive.cap = { USD: ppDynCap };
            }
            
        } else {
            // Static mode: Fixed prices per token (no discounts)
            const currencies = [
                { currency: 'XRP', price: xrpPrice, enabled: true }
            ];
            
            // Add static tokens with fixed prices
            state.staticTokens.forEach(t => {
                currencies.push({
                    currency: t.currency,
                    issuer: t.issuer,
                    price: t.price || 0,
                    enabled: true
                });
            });
            
            data.accepted_currencies = currencies;

            // PP-3: the progressive config rides the static payload (the server model).
            // PP-4: tiered listings included -- the ladder is listing-level (R8 final).
            if (state.progressive.enabled) {
                const ppInc = {}, ppCap = {};
                currencies.forEach(c => {
                    const iv = parseFloat(state.progressive.increments[c.currency]);
                    if (iv > 0) ppInc[c.currency] = iv;
                    const cv = parseFloat(state.progressive.caps[c.currency]);
                    if (cv > 0 && iv > 0) ppCap[c.currency] = cv;
                });
                if (Object.keys(ppInc).length > 0) {
                    data.progressive = { enabled: true,
                        scope: (Object.keys(ppInc).length > 1) ? state.progressive.scope : 'together',
                        increments: ppInc };
                    if (Object.keys(ppCap).length > 0) data.progressive.cap = ppCap;
                }
            }
        }
        
        return data;
    }
    
    // Legacy function for backward compatibility
    function collectAcceptedCurrencies() {
        const pricingData = collectPricingData();
        return pricingData.accepted_currencies || [];
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // v71: Allowlist Data Collection
    // ═══════════════════════════════════════════════════════════════════════════
    
    function collectAllowlistData() {
        const enableAllowlist = document.getElementById('enable-allowlist')?.checked;
        if (!enableAllowlist) return null;
        
        const allowlistType = document.getElementById('allowlist-type')?.value || 'early_access';
        const walletsText = document.getElementById('allowlist-wallets')?.value || '';
        
        const walletRegex = /^r[1-9A-HJ-NP-Za-km-z]{25,34}$/;
        // v729 (A3): send RAW LINES so per-wallet amounts ("rWallet...,3") reach the
        // server intact — previously only the bare addresses were sent and every
        // quantity was discarded (B3). Lines without a valid r-address are dropped.
        const wallets = walletsText
            .split(/[\r\n]+/)
            .map(l => l.trim())
            .filter(l => l.split(',').some(p => walletRegex.test(p.trim())));
        
        if (wallets.length === 0) return null;
        
        const data = {
            enabled: true,
            type: allowlistType,
            wallets: wallets,
            max_mint: parseInt(document.getElementById('allowlist-max-mint')?.value) || 5
        };
        
        if (allowlistType === 'early_access') {
            data.early_access_hours = parseInt(document.getElementById('allowlist-early-hours')?.value) || 24;
        } else if (allowlistType === 'discount' || allowlistType === 'discount_limited') {
            // v729 (A3): BOTH discount types now carry their pricing (B3 fix: previously
            // discount_limited sent nothing, so the discount never applied).
            const mode = document.getElementById('allowlist-discount-mode')?.value || 'percent';
            data.discount_mode = mode;
            if (mode === 'free') {
                data.custom_price = 0;
            } else if (mode === 'price') {
                const cp = parseFloat(document.getElementById('allowlist-custom-price')?.value);
                data.custom_price = (isFinite(cp) && cp >= 0) ? cp : 0;
            } else {
                data.discount_percent = parseInt(document.getElementById('allowlist-discount-percent')?.value) || 20;
            }
            // v731 (A4b): per-token rules — enabled rows with valid values only. Never on
            // free mode (server rejects there too; free is free in every currency).
            if (mode !== 'free') {
                const mrows = document.querySelectorAll('#allowlist-matrix-rows .al-matrix-row');
                const rules = [];
                mrows.forEach(r => {
                    if (!r.querySelector('.al-mx-on')?.checked) return;
                    const rmode = r.querySelector('.al-mx-mode')?.value === 'price' ? 'price' : 'percent';
                    const rv = parseFloat(r.querySelector('.al-mx-value')?.value);
                    if (!Number.isFinite(rv)) return;
                    if (rmode === 'percent' && (rv < 1 || rv > 99)) return;
                    if (rmode === 'price' && !(rv > 0)) return;
                    rules.push({ currency: r.dataset.currency, issuer: r.dataset.issuer || undefined, mode: rmode, value: rv });
                });
                if (rules.length) data.currency_benefits = rules;
            }
        }
        
        return JSON.stringify(data);
    }

    function calculateAspectRatio(resolution) {
        if (!resolution) return undefined;
        const [w, h] = resolution.split('x').map(Number);
        if (!w || !h) return undefined;
        
        const gcd = (a, b) => b === 0 ? a : gcd(b, a % b);
        const divisor = gcd(w, h);
        return `${w/divisor}:${h/divisor}`;
    }

    function collectFormData() {
        const form = document.getElementById('mint-wizard');
        const data = {};
        
        // Collect all inputs
        form.querySelectorAll('input, select, textarea').forEach(el => {
            if (el.name) {
                // v138: Skip checkbox arrays — handled in dedicated loop below
                if (el.type === 'checkbox' && el.name.endsWith('[]')) return;
                // v138: Skip file inputs — non-serializable
                if (el.type === 'file') return;
                
                if (el.type === 'checkbox') {
                    data[el.name] = el.checked ? 'on' : '';
                } else if (el.type === 'radio') {
                    if (el.checked) data[el.name] = el.value;
                } else {
                    data[el.name] = el.value;
                }
            }
        });
        
        // Collect checkbox arrays (names ending in [])
        form.querySelectorAll('input[type="checkbox"][name$="[]"]').forEach(el => {
            if (el.checked) {
                const name = el.name;
                if (!Array.isArray(data[name])) data[name] = [];
                data[name].push(el.value);
            }
        });
        
        return data;
    }

    // ===== Minting Process =====
    
async function handleMint() {
    if (!validateCurrentStep()) return;
    // v711 (Step I): block publication while a selected token cannot be received.
    if (!(await imcVerifyArtistTrustlines())) return;
    // C-β: new collections are created NOW — before any listing work, both paths.
    if (!(await ensureCollectionOnServer())) return;

    // OE-v1 fix: the checked edition_type radio is the source of truth at submit.
    const _selEd = document.querySelector('input[name="edition_type"]:checked')?.value;
    if (_selEd) state.editionType = _selEd;

    // v566: force a deliberate mint-limit value when the limit is enabled. The field is
    // now empty by default, so block submission (both single and album paths flow through
    // here) when it is left blank or invalid, instead of silently defaulting.
    const _mlOn  = document.getElementById('mint-limit-enabled')?.checked;
    const _mlQty = parseInt(document.getElementById('mint-limit-per-wallet')?.value);
    if (_mlOn && (!_mlQty || _mlQty < 1)) {
        imcToast('Please enter a maximum mints-per-wallet (1-100), or turn off the mint limit.');
        return;
    }
    
    // Check authorization
    if (!state.auth.isAuthorized) {
        imcToast('Please complete the Authorization step first. Click the "Authorize IMCollectibles" button to continue.');
        return;
    }
    
    // v23 FIX: Debounce guard — prevent double-submit from rapid clicks or tab duplicates.
    // Placed after auth check so an unauthorized artist who completes auth
    // and clicks again is never permanently locked. Guard resets in success
    // and error paths below. Re-entry via retry path is safe (reset runs first).
    if (handleMint._inProgress) {
        console.warn('[v23] handleMint already in progress — ignoring duplicate call');
        return;
    }
    handleMint._inProgress = true;
    
    showLoading('Preparing files...');
    startMintGuard(); // v43: Warn user if they try to navigate away
    
    try {
        // ================================================================
        // v24: ALBUM ACCESS NFTs — dedicated handler
        // Intercept FIRST before any single-track upload logic.
        // handleAlbumMint() manages its own loading/guard cleanup.
        // ================================================================
        if (state.contentType === 'album') {
            await handleAlbumMint();
            return;
        }

        // ================================================================
        // STEP 1: Upload COVER ART to IPFS (public)
        // v43: For watermarkable art, cover is auto-generated in Step 3/3b
        // v377: For music/mv/film, cover is watermarked via VPS before IPFS pin.
        //       Original cover stored on VPS for NFT holder access via entitlement API.
        //       Art flow is completely unchanged — still uses the v43 art watermark path.
        // ================================================================
        if (state.tiers.enabled) {
            // Tier mode: upload each tier's cover/preview
            const isArtTiers = state.contentType === 'art';
            const shouldWatermarkCovers = !isArtTiers; // v377: Music/MV/Film tier covers get watermarked
            for (let i = 0; i < state.tiers.items.length; i++) {
                const tier = state.tiers.items[i];
                // v43: Skip cover upload for watermarkable art tiers — VPS generates it in Step 3b
                if (isArtTiers && tier.watermarkable) continue;
                if (!tier.coverIpfs && tier.coverFile) {
                    if (shouldWatermarkCovers) {
                        // v377: Route non-art tier covers through VPS watermarking
                        showLoading(`Watermarking tier "${tier.name || i+1}" cover art... (${i+1}/${state.tiers.items.length})`);
                        const wmResult = await watermarkCoverFile(tier.coverFile);
                        if (wmResult.watermarked_ipfs && !wmResult.watermark_failed) {
                            tier.coverIpfs = wmResult.watermarked_ipfs;
                            tier.coverMime = wmResult.watermarked_mime || 'image/jpeg'; // 8b
                            tier.coverContentHash = wmResult.cover_content_hash;
                            console.log(`[Mint] Tier "${tier.name}" cover watermarked:`, wmResult.watermarked_ipfs);
                        } else {
                            // 8d PROTECT-OR-BLOCK (tier lane): abort before any listing work.
                            console.error(`[Mint] Tier "${tier.name}" cover watermark failed — blocking publish (8d)`);
                            throw new Error(`Cover protection failed for tier "${tier.name || i+1}". We could not apply the IMCollectibles watermark to your cover, so we stopped before publishing anything — your files are safe and nothing was created. Try again, or choose a different image (JPG or PNG work best).`);
                        }
                    } else {
                        // Art tiers (non-watermarkable): direct IPFS pin as before
                        showLoading(`Uploading tier "${tier.name || i+1}" public preview to IPFS... (${i+1}/${state.tiers.items.length})`);
                        const result = await uploadFile(tier.coverFile, 'cover');
                        tier.coverIpfs = result.ipfs_url;
                        tier.coverContentHash = result.master_hash || null; // 8c: DUAL-CID hashes every plain upload — keep it
                    }
                }
                if (!tier.coverIpfs) {
                    throw new Error(`${isArtTiers ? 'Public preview' : 'Cover image'} missing for tier "${tier.name || i+1}"`);
                }
            }
            // Use rarest (first) tier's cover as listing cover (may be null for watermarkable — set after Step 3b)
            if (!state.tiers.items[0]?.watermarkable) {
                state.coverIpfs = state.tiers.items[0].coverIpfs;
            }
            // v377: Propagate first tier's cover content hash to listing level
            if (state.tiers.items[0]?.coverContentHash) { // 8c: propagate whenever present (art tiers too — arms 8e)
                state.coverContentHash = state.tiers.items[0].coverContentHash;
            }
        } else {
            // Single cover mode
            // v43: Skip cover upload for watermarkable art — VPS generates it in Step 3
            if (state.contentType === 'art' && state.artAutoWatermark) {
                // Cover will be set from masterResult.preview_ipfs in Step 3
            } else if (state.contentType !== 'art' && state.coverFile && !state.coverIpfs) {
                // M1-e1b: the back cover follows the same protect-or-block path as the front.
                // v377: Music/MV/Film — watermark cover via VPS, store original for holders
                showLoading('Watermarking cover art...');
                const wmResult = await watermarkCoverFile(state.coverFile);
                if (wmResult.watermarked_ipfs && !wmResult.watermark_failed) {
                    state.coverIpfs = wmResult.watermarked_ipfs;
                    state.coverMime = wmResult.watermarked_mime || 'image/jpeg'; // 8b
                    if (wmResult.watermarked_note === 'animated_flattened') { // 8d
                        imcToast('Your animated cover was over the 15MB processing limit, so it was published as a high-quality still image. To keep the animation, use a shorter loop or a smaller file.');
                    }
                    state.coverContentHash = wmResult.cover_content_hash;
                    console.log('[Mint] Cover watermarked:', wmResult.watermarked_ipfs);
                } else {
                    // 8d PROTECT-OR-BLOCK: an unprotected cover is never published. No fee has
                    // been paid and no listing exists at this point — the abort is clean.
                    console.error('[Mint] Cover watermark failed — blocking publish (8d)');
                    throw new Error('Cover protection failed. We could not apply the IMCollectibles watermark to your cover, so we stopped before publishing anything — your files are safe and nothing was created. Try again, or choose a different image (JPG or PNG work best).');
                }
                if (!state.coverIpfs) {
                    throw new Error('Cover image is required');
                }
            } else {
                // Art (non-watermarkable) or cover already set
                if (!state.coverIpfs && state.coverFile) {
                    showLoading('Uploading cover art to IPFS...');
                    const coverResult = await uploadFile(state.coverFile, 'cover');
                    state.coverIpfs = coverResult.ipfs_url;
                }
                if (!state.coverIpfs && !(state.contentType === 'art' && state.artAutoWatermark)) {
                    throw new Error('Cover image is required');
                }
            }
        }
        
        // ================================================================
        // STEP 2: Upload PREVIEW to IPFS (public)
        // Music/MV/Film: audio/video clip (≤30s / ≤120s)
        // Art: cover image IS the preview — v226
        // v43: For watermarkable art, preview deferred to Step 3/3b
        // ================================================================
        if (state.contentType === 'art') {
            // Art preview = cover image (set here if cover already uploaded, else deferred)
            if (!state.previewIpfs && state.coverIpfs) {
                state.previewIpfs = state.coverIpfs;
            }
            // v43: Don't error here for watermarkable art — preview set after Step 3/3b
            if (!state.previewIpfs && !state.artAutoWatermark && !(state.tiers.enabled && state.tiers.items.some(t => t.watermarkable))) {
                throw new Error('Public preview image is required');
            }
        } else {
            // M2-fix: an eBook has no audio preview - its public preview IS the watermarked
            // front cover. Set it here, before the gate below, because the page-upload block
            // that used to do it runs later in the publish path.
            if (state.contentType === 'ebook' && !state.previewIpfs && state.coverIpfs) {
                state.previewIpfs = state.coverIpfs;
            }
            if (!state.previewIpfs && state.previewFile) {
                showLoading('Uploading preview audio to IPFS...');
                const previewResult = await uploadFile(state.previewFile, 'preview');
                state.previewIpfs = previewResult.ipfs_url;
            }
            
            if (!state.previewIpfs && state.contentType !== 'ebook' && !(state.tiers.enabled && ['music','musicVideo','film'].includes(state.contentType))) { // v557: tiered media previews are per-pool (uploaded below)
                throw new Error(state.contentType === 'film' ? 'Preview clip is required' : 'Preview audio is required');
            }
        }
        
        // ================================================================
        // STEP 3: Store the master file
        // v144: Skip for art tier mode — per-tier masters handled in Step 3b
        // v481: Skip for music/mv/film tier mode — pool masters handled in Step 3b
        // v43: For watermarkable art, VPS returns preview_ipfs alongside content_hash
        // ================================================================
        // M1-e2b2: a chaptered AudioBook has no listing-level master - its chapters are
        // the masters. Without this guard a leftover single-file master (from switching
        // sub-type mid-flow) would upload and take the master_content_hash slot.
        const _abChaptered  = state.contentType === 'audiobook' && state.audiobook.format === 'chaptered';
        const artTierMode   = state.contentType === 'art' && state.tiers.enabled;
        const musicTierMode = ['music','musicVideo','film'].includes(state.contentType) && state.tiers.enabled;
        if (!artTierMode && !musicTierMode && !_abChaptered && !state.masterContentHash && state.primaryFile) {
            showLoading(state.contentType === 'art' ? 'Storing master artwork securely...' : 'Storing master audio securely...');
            const masterResult = await storeMasterFile(state.primaryFile);
            state.masterContentHash = masterResult.content_hash;
            // (master storage binding happens server-side)

            // v43: Use VPS-generated watermarked preview for art
            if (state.contentType === 'art' && state.artAutoWatermark && masterResult.preview_ipfs) {
                state.coverIpfs = masterResult.preview_ipfs;
                state.previewIpfs = masterResult.preview_ipfs;
                state.coverMime = masterResult.preview_mime || 'image/jpeg'; // 8b
                if (masterResult.preview_note === 'animated_flattened') { // 8d
                    imcToast('Your animated cover was over the 15MB processing limit, so it was published as a high-quality still image. To keep the animation, use a shorter loop or a smaller file.');
                }
                console.log('[Mint] Art auto-watermark preview:', masterResult.preview_ipfs);
            }
        }

        // M2-fix: books set their listing master hash LATER in this function - page 1
        // for an eBook, chapter 1 for a chaptered AudioBook - so they legitimately
        // reach this point without one. Each has its own upload error handling below.
        const _bookDefersMaster = (state.contentType === 'ebook')
            || (state.contentType === 'audiobook' && state.audiobook && state.audiobook.format === 'chaptered');
        if (!artTierMode && !musicTierMode && !_bookDefersMaster && !state.masterContentHash) {
            throw new Error('Master file storage failed');
        }

        // v43: Verify art cover was set (either from Step 1 or from watermark)
        if (state.contentType === 'art' && !artTierMode && !state.coverIpfs) {
            throw new Error('Preview image generation failed — please upload a manual preview image');
        }
        
        // Upload separate audio track for music videos (if provided)
        if (state.contentType === 'musicVideo' && state.audioFile && !state.audioIpfs) {
            showLoading('Uploading video audio to IPFS...');
            const audioResult = await uploadFile(state.audioFile, 'audio');
            state.audioIpfs = audioResult.ipfs_url;
        }
        
        // ================================================================
        // STEP 3b: Store the pool master files
        // Each pool item is uploaded once regardless of how many tiers
        // reference it — VPS deduplicates by SHA256 hash anyway.
        // Art pool items get watermarked preview → tier.coverIpfs.
        // Music/MV/Film pool items → tier.masterContentHash via pool ref.
        // Music/MV/Film pool preview clips → tier.previewIpfs (optional).
        // ================================================================
        if (state.tiers.enabled) {
            // Step 3b-i: Upload each pool master exactly once
            for (let i = 0; i < state.masterPool.length; i++) {
                const poolItem = state.masterPool[i];
                if (poolItem.file && !poolItem.contentHash) {
                    const label = state.contentType === 'art' ? 'master artwork' : 'master file';
                    showLoading(`Storing ${poolItem.label} ${label} securely... (${i+1}/${state.masterPool.length})`);
                    const result = await storeMasterFile(poolItem.file);
                    poolItem.contentHash = result.content_hash;

                    // v43: Art pool items — watermarked preview becomes tier cover
                    if (state.contentType === 'art') {
                        const linkedTier = state.tiers.items.find(t => t.masterPoolRef === poolItem.id);
                        if (linkedTier) {
                            linkedTier.watermarkable = isWatermarkable(poolItem.file.name);
                            if (linkedTier.watermarkable && result.preview_ipfs) {
                                linkedTier.coverIpfs = result.preview_ipfs;
                                console.log(`[Mint] Pool item "${poolItem.label}" art watermark:`, result.preview_ipfs);
                            }
                        }
                    }
                }

                // v481: Upload pool preview clip to IPFS (music/mv/film only, optional)
                if (!['art'].includes(state.contentType) && poolItem.previewFile && !poolItem.previewIpfs) {
                    showLoading(`Uploading ${poolItem.label} preview clip to IPFS... (${i+1}/${state.masterPool.length})`);
                    const previewResult = await uploadFile(poolItem.previewFile, 'preview');
                    poolItem.previewIpfs = previewResult.ipfs_url;
                    console.log(`[Mint] Pool item "${poolItem.label}" preview IPFS:`, poolItem.previewIpfs);
                }
            }

            // Step 3b-ii: Propagate pool hashes + preview CIDs to tier state
            for (const tier of state.tiers.items) {
                if (tier.masterPoolRef !== null) {
                    const poolItem = state.masterPool.find(p => p.id === tier.masterPoolRef);
                    if (poolItem?.contentHash) {
                        tier.masterContentHash = poolItem.contentHash;
                        tier.useDefaultMaster = false;
                    }
                    // v481: Propagate per-pool preview IPFS to tier (null = inherit listing preview)
                    if (poolItem) {
                        tier.previewIpfs = poolItem.previewIpfs || null;
                    }
                }
                // null masterPoolRef = use listing's own default master (useDefaultMaster: true)
            }

            // v702: ART tier per-tier master upload (manual box path). ART + tiers only.
            // Each art tier's own masterFile (from the wired #tier-master-drop box) is stored
            // securely on the VPS via the same standalone storeMasterFile primitive the pool uses.
            // For watermarkable art the VPS returns preview_ipfs which becomes the tier's public
            // cover — the art dual-asset model (artwork IS the master; watermark IS the preview).
            // Gated on art+tiers, so music/mv/film/album/non-tiered never enter this loop.
            if (state.contentType === 'art' && state.tiers.enabled) {
                for (let ti = 0; ti < state.tiers.items.length; ti++) {
                    const tier = state.tiers.items[ti];
                    if (tier.masterFile && !tier.masterContentHash) {
                        showLoading(`Storing tier "${tier.name || (ti + 1)}" master artwork securely... (${ti + 1}/${state.tiers.items.length})`);
                        const tierMasterResult = await storeMasterFile(tier.masterFile);
                        tier.masterContentHash = tierMasterResult.content_hash;
                        tier.useDefaultMaster = false;
                        // Watermarkable art → VPS-generated preview becomes the public tier cover
                        if (isWatermarkable(tier.masterFile.name) && tierMasterResult.preview_ipfs) {
                            tier.watermarkable = true;
                            tier.coverIpfs = tierMasterResult.preview_ipfs;
                            console.log(`[Mint] Art tier "${tier.name}" master watermark preview:`, tierMasterResult.preview_ipfs);
                        }
                    }
                }
            }

            // v43: After all art pool masters stored, verify all art tiers have covers
            if (state.contentType === 'art') {
                for (const tier of state.tiers.items) {
                    if (!tier.coverIpfs) {
                        throw new Error(`Preview image missing for tier "${tier.name || '(unnamed)'}" — watermark may have failed`);
                    }
                }
                // Set listing cover from first tier (now guaranteed to have coverIpfs)
                state.coverIpfs = state.tiers.items[0].coverIpfs;
                if (!state.previewIpfs) {
                    state.previewIpfs = state.coverIpfs;
                }
            }
        }
        
        // ================================================================
        // STEP 4: Build and upload metadata
        // ================================================================
        // M1-e2b: chaptered AudioBook - store each chapter master in order (sequential,
        // matching the album pattern; parallel bursts are refused by the VPS).
        let _abChapterUploads = null;
        if (state.contentType === 'audiobook' && state.audiobook.format === 'chaptered') {
            _abChapterUploads = [];
            const chs = state.audiobook.chapters;
            for (let ci = 0; ci < chs.length; ci++) {
                const ch = chs[ci];
                let hash = ch.masterContentHash;
                if (!hash) {
                    showLoading('Uploading chapter ' + (ci + 1) + '/' + chs.length + ': ' + (ch.title || 'audio') + '...');
                    const res = await storeMasterFile(ch.file);
                    if (!res || !res.content_hash) {
                        throw new Error('Chapter ' + (ci + 1) + ' failed to upload. Please retry.');
                    }
                    hash = res.content_hash;
                    ch.masterContentHash = hash;
                    if (res.duration) ch.seconds = res.duration;
                }
                _abChapterUploads.push({ title: ch.title, masterContentHash: hash, seconds: ch.seconds });
            }
            // Chapter 1 becomes the listing master hash, exactly as album uses track 1.
            // The engine stamps properties.master_access from it, so holders get access
            // even before the chapter-aware grant lands in M1-f.
            if (_abChapterUploads.length) {
                state.masterContentHash = _abChapterUploads[0].masterContentHash;
            }
        }
        // M2-b: store book pages (holder-only masters). Sequential; a PDF is one upload
        // that expands into its page slots in the manifest.
        let _bookPageUploads = null;
        if (state.contentType === 'ebook' && state.book.pages.length) {
            _bookPageUploads = [];
            const srcs = state.book.pages;
            for (let si = 0; si < srcs.length; si++) {
                const src = srcs[si];
                let hash = src.masterContentHash;
                if (!hash) {
                    showLoading('Uploading ' + (si + 1) + '/' + srcs.length + ': ' + src.name + '...');
                    const res = await storeMasterFile(src.file);
                    if (!res || !res.content_hash) { throw new Error(src.name + ' failed to upload. Please retry.'); }
                    hash = res.content_hash;
                    src.masterContentHash = hash;
                }
                if (src.kind === 'pdf') {
                    // V4: this was ONE pooled ref repeated per page, which forced the reader
                    // to open the whole document before it could show anything. Split it
                    // server-side and emit real per-page assets instead - identical in shape
                    // to an all-image book, so the reader, the manifest validator and the
                    // grant all need no change at all.
                    const _pageHashes = await splitBookPages(hash, src.pageCount || 1);
                    _pageHashes.forEach(function (ph) {
                        _bookPageUploads.push({ src: 'image', hash: ph });
                    });
                } else {
                    _bookPageUploads.push({ src: 'image', hash: hash });
                }
            }
            // Page 1's source becomes the listing master hash, exactly as chapter 1 does
            // for a chaptered AudioBook. The engine stamps properties.master_access from
            // it, and the holder page gates the reader on that access - without it a
            // holder would be locked out of their own book.
            if (srcs.length && srcs[0].masterContentHash) {
                state.masterContentHash = srcs[0].masterContentHash;
            }
            // The front cover CID satisfies create's media_ipfs requirement (art precedent).
            if (!state.previewIpfs && state.coverIpfs) state.previewIpfs = state.coverIpfs;
        }
        // M1-e1b: watermark + store the optional back cover (same lane as the front cover).
        // M2-b: eBooks carry a back cover too - both covers are the public, watermarked
        // preview for a book, whichever type it is.
        if ((state.contentType === 'audiobook' || state.contentType === 'ebook') && state.backCoverFile && !state.backCoverIpfs) {
            showLoading('Watermarking back cover...');
            const bcRes = await watermarkCoverFile(state.backCoverFile);
            if (bcRes.watermarked_ipfs && !bcRes.watermark_failed) {
                state.backCoverIpfs = bcRes.watermarked_ipfs;
                // M2-fix: watermarkCoverFile returns cover_content_hash (the front cover
                // reads the same field). Reading content_hash left this null, and the
                // server's protect-or-block rule then rejected the listing.
                state.backCoverContentHash = bcRes.cover_content_hash || null;
            } else {
                throw new Error('Back cover protection failed. Please retry.');
            }
        }
        showLoading('Building XLS-24d metadata...');
        const metadata = (state.contentType === 'audiobook') ? buildAudiobookMetadata()
            : (state.contentType === 'ebook') ? buildEbookMetadata()
            : buildMetadata();
        
        // v518: external_url = site root. The old '/nft/PENDING' placeholder was never
        // updated post-mint (NFTokenID is unknown at metadata-build time) and shipped
        // on-chain verbatim. Plain domain is stable, valid, and marketplace-clickable.
        metadata.external_url = 'https://imcollectibles.io';
        
        showLoading('Uploading metadata to IPFS...');
        const metadataUri = await uploadMetadata(metadata);
        
        if (!metadataUri) {
            throw new Error('Failed to upload metadata');
        }
        
        // ================================================================
        // STEP 5: Create listing via mint-on-demand system
        // ================================================================
        showLoading('Creating listing...');
        
        const formData = collectFormData();
        
        // v595: tiered music/MV/film has no listing-level preview since v557 (previews are per-pool).
        // Populate a representative listing-level media_ipfs from the first available pool preview
        // (fallback: first tier preview, then first tier cover) so create's media_ipfs requirement
        // is satisfied, restoring the pre-v557 behaviour where media_ipfs was a preview CID.
        const _musicTierMode = ['music','musicVideo','film'].includes(state.contentType) && state.tiers.enabled;
        if (_musicTierMode && !state.previewIpfs) {
            const poolPrev = (state.masterPool || []).find(p => p && p.previewIpfs)?.previewIpfs;
            const tierPrev = (state.tiers.items || []).find(t => t && t.previewIpfs)?.previewIpfs;
            state.previewIpfs = poolPrev || tierPrev || state.tiers.items[0]?.coverIpfs || null;
        }

        // Collect all listing data — including new preview/master fields
        const listingData = {
            // M1-e1b: AudioBook sub-type + optional back cover (server stores all three).
            audiobook_format:        (state.contentType === 'audiobook' ? (state.audiobook.format || '') : ''),
            ebook_pages_json:        (state.contentType === 'ebook' && _bookPageUploads)
                ? JSON.stringify(buildBookManifest([], _bookPageUploads))
                : '',
            ebook_format:            (state.contentType === 'ebook' ? (document.getElementById('ebook-format')?.value || '') : ''),
            audiobook_chapters_json: _abChapterUploads
                ? JSON.stringify(buildBookManifest(_abChapterUploads, null))
                : '',
            back_cover_ipfs:         state.backCoverIpfs || '',
            back_cover_content_hash: state.backCoverContentHash || '',
            // FIX: eBook fell through this chain to the 'music' default, so every
            // eBook was created stamped as a music listing. Explicit map, ordered
            // so any future type that is missing lands on its own name, not music.
            nft_type: ({ audiobook: 'audiobook', ebook: 'ebook', musicVideo: 'musicvideo',
                         art: 'art', film: 'film', album: 'album', music: 'music' }
                       [state.contentType] || 'music'),
            nft_name: formData.title || metadata.name || 'Untitled',
            description: formData.description || metadata.description || '',
            artist_name: formData.primary_artist_name_0 || getFirstArtistName(formData) || '',
            cover_ipfs: state.coverIpfs,
            media_ipfs: state.previewIpfs,           // Preview CID (public)
            preview_ipfs: state.previewIpfs,          // Explicit preview field
            master_content_hash: state.masterContentHash, // SHA256 of master
            cover_content_hash: state.coverContentHash || null, // v377: SHA256 of original cover (Music/MV/Film)
            metadata_ipfs: metadataUri,
            metadata_json: JSON.stringify(metadata),
            collection_name: state.collection.name || formData.collection_name || '',
            collection_taxon: state.collection.taxon || 0,
            // v470: Read price directly from DOM — collectFormData() iteration unreliable for Step 9 fields (same root cause as v464)
            price_xrp: parseFloat(document.getElementById('nft-price')?.value) || 0,
            // v464: Read editions directly from DOM — collectFormData() iteration unreliable for Step 9 fields
            total_editions: parseInt(document.getElementById('nft-editions')?.value) || 1,
            // v470: Read royalty directly from DOM — collectFormData() iteration unreliable for Step 9 fields. CRITICAL: this value is baked into the on-chain TransferFee (immutable forever)
            // v485: isNaN() fix — || 5 treated explicit 0% as blank. isNaN correctly preserves 0.
            transfer_fee: (() => { const _r = parseFloat(document.getElementById('nft-royalty')?.value); return Math.round((isNaN(_r) ? 5 : _r) * 1000); })(), // Convert % to basis points
            // v470: Read transferable checkbox state directly from DOM — formData pattern was always returning 1 (broken since '' !== false && '' !== 'off' is true). Now correctly honors unchecked state.
            is_transferable: document.getElementById('nft-transferable')?.checked ? 1 : 0,
            downloadable: document.getElementById('mint-downloadable')?.checked ? 1 : 0, // v387: Artist opt-in per listing
            // Launch scheduling — v464: direct DOM reads for reliability
            launch_type: document.querySelector('input[name="launch_type"]:checked')?.value || 'immediate',
            launch_timezone: document.getElementById('launch-timezone')?.value || 'UTC',
            // v75: Pricing mode and multi-currency support
            ...(() => {
                const pricingData = collectPricingData();
                return {
                    pricing_mode: pricingData.pricing_mode || 'static',
                    price_usd: pricingData.price_usd || null,
                    accepted_currencies: JSON.stringify(pricingData.accepted_currencies || []),
                    progressive: pricingData.progressive ? JSON.stringify(pricingData.progressive) : '',
                    unlockables: JSON.stringify(ulUploadedPool().map(p => ({ content_hash: p.hash, label: p.label, tier_order: p.tierOrder }))),
                    mint_limit_enabled: pricingData.mint_limit_enabled || 0,
                    mint_limit_per_wallet: pricingData.mint_limit_per_wallet || 0
                };
            })(),
            // v71: Allowlist data
            allowlist_data: collectAllowlistData(),
            // OE-v1: Open Edition fields (spec names)
            edition_type: state.editionType || 'fixed',
            ...(state.editionType === 'open' ? {
                total_editions:             0,               // unlimited — override editions field
                open_edition_ends_at:       state.oeEndsAt || '',
                open_edition_duration_days: state.oeDurationDays || null
            } : {})
        };
        
        // Build launch_at datetime if scheduled — v464: direct DOM reads
        // v473: convert wall-clock time in artist's selected tz → UTC before
        // sending. Matches the dashboard's Edit Schedule write path so all
        // writes consistently produce UTC storage.
        const _launchDate = document.getElementById('launch-date')?.value;
        const _launchTime = document.getElementById('launch-time')?.value;
        if (listingData.launch_type === 'scheduled' && _launchDate && _launchTime) {
            listingData.launch_at = localTzToUTC(_launchDate, _launchTime, listingData.launch_timezone);
        }
        
        // v450: Validate scheduled listings have a launch date
        if (listingData.launch_type === 'scheduled' && !listingData.launch_at) {
            hideLoading();
            stopMintGuard();
            handleMint._inProgress = false;
            imcToast('Please set a release date and time for your scheduled listing, or switch to "Publish Immediately".');
            return;
        }
        
        // ============================================================
        // Attach rarity tiers data (if enabled)
        // ============================================================
        if (state.tiers.enabled && state.tiers.items.length >= 2) {
            // v143: Include per-tier master content hash
            const isArtTiers = state.contentType === 'art';
            const tiersPayload = state.tiers.items.map((tier, idx) => ({
                name: tier.name?.trim() || `Tier ${idx + 1}`,
                editions: tier.editions || 1,
                cover_ipfs: tier.coverIpfs,
                // Art tiers always have per-tier master; music/mv/film only when pool ref assigned
                master_content_hash: (isArtTiers || tier.masterPoolRef !== null) ? (tier.masterContentHash || null) : null,
                // v377: Cover content hash for watermarked Music/MV/Film tier covers
                cover_content_hash: (!isArtTiers && tier.coverContentHash) ? tier.coverContentHash : null,
                // v481: Per-tier preview IPFS CID (null = inherit listing-level preview)
                preview_ipfs: (!isArtTiers && tier.previewIpfs) ? tier.previewIpfs : null,
                has_unique_master: isArtTiers || (tier.masterPoolRef !== null),
                traits: tier.traits.filter(t => t.trait_type?.trim() && t.value?.trim())
            }));
            listingData.tiers_json = JSON.stringify(tiersPayload);
        }
        
        // Validate required fields
        if (!listingData.nft_name) {
            throw new Error('NFT name is required');
        }
        if (listingData.price_xrp < 0) {
            throw new Error('Price cannot be negative');
        }
        // OE-v1: Open editions use total_editions=0 (unlimited) — skip the < 1 guard
        if (listingData.edition_type !== 'open' && listingData.total_editions < 1) {
            throw new Error('Must have at least 1 edition');
        }
        
        // Check if mint-on-demand module is available
        if (!window.mintOnDemand) {
            throw new Error('Mint-on-demand module not loaded. Please refresh the page and try again.');
        }
        
        // Create the listing
        // v210: Refresh nonce first (may have expired during long mint sessions)
        if (window.mintOnDemand.refreshNonce) {
            await window.mintOnDemand.refreshNonce();
        }
        // v699: attach the optional tiered listing-card cover (display-only). Only for tiered
        // listings; cover_ipfs itself stays = the first tier's cover. Absent → server falls back.
        if (state.tiers.enabled && state.tierCardCoverFile) {
            if (!state.tierCardCoverIpfs) {
                const _tccRes = await uploadFile(state.tierCardCoverFile, 'cover');
                state.tierCardCoverIpfs = _tccRes.ipfs_url;
            }
            if (state.tierCardCoverIpfs) listingData.tier_card_cover = state.tierCardCoverIpfs;
        }
        const result = await window.mintOnDemand.createListing(listingData);
        
        if (!result || !result.listing_id) {
            throw new Error(result?.error || 'Failed to create listing');
        }
        
        // ================================================================
        // STEP 6: Show fee payment modal
        // ================================================================
        hideLoading();
        stopMintGuard(); // v43: Processing complete, safe to navigate
        handleMint._inProgress = false; // v23: Release debounce guard on success
        
        if (result.free_mint) {
            // FREE MINT PROMO -- the server auto-published; no fee to pay.
            if (CONFIG.freeMints && CONFIG.freeMints.remaining) {
                const _fmT = listingData.nft_type;
                if (CONFIG.freeMints.remaining[_fmT] > 0) CONFIG.freeMints.remaining[_fmT]--;
            }
            imcToast('\ud83c\udf81 Free mint used \u2014 your listing is LIVE!');
            if (elements.successModal) elements.successModal.style.display = 'flex';
        } else {
            // Prepare data for fee modal
            const feeModalData = {
                listing_id: result.listing_id,
                nft_name: listingData.nft_name,
                total_editions: listingData.total_editions,
                price_xrp: listingData.price_xrp,
                platform_fee_xrp: result.platform_fee_xrp || result.platform_fee_amount,
                platform_fee_drops: result.platform_fee_drops,
                fee_wallet: result.fee_wallet
            };
            
            // Show fee payment modal
            if (result.listing_fee_waived) { imcToast('Listing fee waived \ud83c\udf81 -- the fee shown covers your unlockable content.'); }
            window.mintOnDemand.showFeePaymentModal(feeModalData);
        }
        
        // Log success
        console.log('Listing created successfully:', result);
        
        // v405: Clear draft + IndexedDB files on successful publish
        clearDraft();
        
    } catch (error) {
        hideLoading();
        stopMintGuard(); // v43: Processing failed, safe to navigate
        handleMint._inProgress = false; // v23: Release debounce guard on error
        console.error('Listing creation error:', error);
        
        // v230: Default to retry for any error that isn't a local validation failure.
        // Upload/network errors come in many forms (timeout, NetworkError, Failed to fetch,
        // chunk rejected, processing failed, lost contact, etc.) — easier to exclude the
        // known non-retryable validation errors than to enumerate all network failure modes.
        const isValidationError = error.message.includes('is required') || 
                                  error.message.includes('is missing') ||
                                  error.message.includes('missing for') ||
                                  error.message.includes('Invalid') ||
                                  error.message.includes('cannot be') ||
                                  error.message.includes('Must have') ||
                                  error.message.includes('too long') ||
                                  error.message.includes('too short') ||
                                  error.message.includes('not authorized') ||
                                  error.message.includes('not allowed') ||
                                  error.message.includes('not loaded');
        
        if (!isValidationError) {
            imcRetry(
                'Upload failed: ' + error.message + '\n\n' +
                'Your progress has been saved. Already-uploaded files will be skipped.',
                function () { handleMint(); } // Re-enter — coverIpfs/previewIpfs/masterContentHash persist in state
            );
        } else {
            imcToast('Failed to create listing: ' + error.message);
        }
    }
}

// ============================================================================
// v24: ALBUM ACCESS NFT — MINT HANDLER (Change #21)
//
// Called by handleMint() when state.contentType === 'album'.
// Manages its own loading/guard lifecycle — handleMint() does NOT run further.
// ALL fields required per handover Part 2.
// v461: tiers_json included when rarity tiers enabled (cover art only, tracks shared).
// album_tracks_json always JSON.stringify(array) string — never raw array (F1).
// content_type mapped album→music in all VPS calls (F10).
// ============================================================================
async function handleAlbumMint() {
    // OE-v1 fix: the checked edition_type radio is the source of truth at submit.
    const _selEd = document.querySelector('input[name="edition_type"]:checked')?.value;
    if (_selEd) state.editionType = _selEd;
    const formData    = collectFormData();
    const pricingData = collectPricingData();

    try {
        // ── STEP A: Watermark + upload album cover(s) to IPFS ─────────────
        let coverIpfsUri     = state.coverIpfs;
        let coverContentHash = state.coverContentHash || null;

        if (state.tiers.enabled && state.tiers.items.length >= 2) {
            // v461: Per-tier cover watermark loop (same pattern as handleMint v377)
            // Album tiers = cover art only — each tier gets its own watermarked cover.
            for (let i = 0; i < state.tiers.items.length; i++) {
                const tier = state.tiers.items[i];
                if (!tier.coverIpfs && tier.coverFile) {
                    showLoading(`Watermarking tier "${tier.name || i+1}" cover art... (${i+1}/${state.tiers.items.length})`);
                    const wmResult = await watermarkCoverFile(tier.coverFile);
                    if (wmResult.watermarked_ipfs && !wmResult.watermark_failed) {
                        tier.coverIpfs = wmResult.watermarked_ipfs;
                        tier.coverContentHash = wmResult.cover_content_hash || null;
                        console.log(`[Album] Tier "${tier.name}" cover watermarked:`, wmResult.watermarked_ipfs);
                    } else {
                        // 8d PROTECT-OR-BLOCK (album tier lane)
                        console.error(`[Album] Tier "${tier.name}" cover watermark failed — blocking publish (8d)`);
                        throw new Error(`Cover protection failed for tier "${tier.name || i+1}". We could not apply the IMCollectibles watermark to your cover, so we stopped before publishing anything — your files are safe and nothing was created. Try again, or choose a different image (JPG or PNG work best).`);
                    }
                }
                if (!tier.coverIpfs) {
                    throw new Error(`Cover image missing for tier "${tier.name || i+1}"`);
                }
            }
            // Listing-level cover = first (rarest) tier's cover
            coverIpfsUri = state.tiers.items[0].coverIpfs;
            if (state.tiers.items[0].coverContentHash) {
                coverContentHash = state.tiers.items[0].coverContentHash;
            }
        } else {
            // Single cover mode — watermark and upload
            showLoading('Uploading album cover art...');
            if (!coverIpfsUri && state.coverFile) {
                const wmResult = await watermarkCoverFile(state.coverFile);
                if (wmResult.watermarked_ipfs && !wmResult.watermark_failed) {
                    coverIpfsUri     = wmResult.watermarked_ipfs;
                    coverContentHash = wmResult.cover_content_hash || null;
                } else {
                    // 8d PROTECT-OR-BLOCK (album single lane)
                    console.error('[Album] Cover watermark failed — blocking publish (8d)');
                    throw new Error('Cover protection failed. We could not apply the IMCollectibles watermark to your cover, so we stopped before publishing anything — your files are safe and nothing was created. Try again, or choose a different image (JPG or PNG work best).');
                }
            }
        }

        if (!coverIpfsUri) {
            throw new Error('Album cover upload did not complete — please try again');
        }

        const coverIpfsCid = coverIpfsUri.replace(/^ipfs:\/\//, '');
        state.coverIpfs = coverIpfsCid;
        if (coverContentHash) state.coverContentHash = coverContentHash;

        // ── STEP B: Per-track upload loop ────────────────────────────────
        // content_type is mapped album→music in storeMasterFile / uploadFile (F10)
        const albumTracksArray = [];

        for (let i = 0; i < state.album.tracks.length; i++) {
            const track    = state.album.tracks[i];
            const trackNum = i + 1;

            // Upload master if not already stored
            showLoading(`Uploading track ${trackNum}/${state.album.tracks.length}: master audio...`);
            let masterHash = track.masterContentHash;
            if (!masterHash && track.file) {
                const masterResult = await storeMasterFile(track.file);
                masterHash = masterResult.content_hash;
                track.masterContentHash = masterHash;
            }
            if (!masterHash) {
                throw new Error(`Track ${trackNum} master audio upload did not complete — please retry`);
            }

            // Upload preview if not already pinned
            showLoading(`Uploading track ${trackNum}/${state.album.tracks.length}: preview clip...`);
            let previewIpfsCid = track.previewIpfs
                ? track.previewIpfs.replace(/^ipfs:\/\//, '') : null;
            if (!previewIpfsCid && track.previewFile) {
                const prevResult = await uploadFile(track.previewFile, 'preview');
                previewIpfsCid = (prevResult.ipfs_url || '').replace(/^ipfs:\/\//, '');
                track.previewIpfs = previewIpfsCid;
            }
            if (!previewIpfsCid) {
                throw new Error(`Track ${trackNum} preview upload did not complete — please retry`);
            }

            albumTracksArray.push({
                track_number:        trackNum,
                title:               track.title || `Track ${trackNum}`,
                preview_ipfs:        previewIpfsCid,
                master_content_hash: masterHash,
                cover_ipfs:          track.coverIpfs || '',
                duration:            track.duration  || null,
                meta:                track.meta      || {}
            });
        }

        // ── STEP C: Build album metadata + pin to IPFS ───────────────────
        showLoading('Building album metadata...');
        const metadata    = buildAlbumMetadata(formData, albumTracksArray, coverIpfsCid, coverContentHash);
        const metadataUri = await uploadMetadata(metadata);
        if (!metadataUri) {
            throw new Error('Album metadata upload did not complete — please retry');
        }
        const metadataIpfsCid = metadataUri.replace(/^ipfs:\/\//, '');

        // Track 1 values used as listing-level backwards-compat fields
        const track1 = albumTracksArray[0];

        // ── STEP D: Determine launch schedule — v464: direct DOM reads ───
        const _albumLaunchType = document.querySelector('input[name="launch_type"]:checked')?.value || 'immediate';
        const _albumLaunchDate = document.getElementById('launch-date')?.value;
        const _albumLaunchTime = document.getElementById('launch-time')?.value;
        const _albumLaunchTz   = document.getElementById('launch-timezone')?.value || 'UTC';
        const isScheduled = _albumLaunchType === 'scheduled'
            && _albumLaunchDate && _albumLaunchTime;

        // ── STEP E: Build listing data — ALL fields required (Part 2) ────
        const listingData = {
            // Core identity
            nft_type:    'album',
            nft_name:    formData.title    || 'Untitled',
            description: formData.description || '',
            artist_name: formData.primary_artist_name_0
                || getFirstArtistName(formData) || '',

            // Media — Track 1 as listing-level media for backwards compat
            // v704: store cover_ipfs WITH the ipfs:// prefix (matches art/music/video and
            // what img.php needs). coverIpfsCid is bare (stripped at L6415 for the on-chain
            // metadata image field, which prepends ipfs:// itself) — so re-add the prefix
            // here for the stored listing cover only.
            cover_ipfs:          'ipfs://' + coverIpfsCid,
            media_ipfs:          track1.preview_ipfs,
            preview_ipfs:        track1.preview_ipfs,
            master_content_hash: track1.master_content_hash,
            cover_content_hash:  coverContentHash || null,

            // Metadata
            metadata_ipfs: metadataIpfsCid,
            metadata_json: JSON.stringify(metadata),

            // Collection
            collection_name:  state.collection.name  || formData.collection_name  || '',
            collection_taxon: state.collection.taxon || 0,

            // Pricing (from collectPricingData())
            // v470: Read price directly from DOM — collectFormData() iteration unreliable for Step 9 fields
            price_xrp:             parseFloat(document.getElementById('nft-price')?.value)   || 0,
            // v464: Read editions directly from DOM — collectFormData() iteration unreliable for Step 9 fields
            total_editions:        parseInt(document.getElementById('nft-editions')?.value) || 1,
            // v470: Read royalty directly from DOM — CRITICAL: this value is baked into the on-chain TransferFee (immutable forever)
            // v485: isNaN() fix — || 5 treated explicit 0% as blank. isNaN correctly preserves 0.
            transfer_fee:          (() => { const _r = parseFloat(document.getElementById('nft-royalty')?.value); return Math.round((isNaN(_r) ? 5 : _r) * 1000); })(),
            // v470: Read transferable checkbox state directly from DOM — album path was always returning 1 (formData.transferable !== false is always true). Now correctly honors unchecked state.
            is_transferable:       document.getElementById('nft-transferable')?.checked ? 1 : 0,
            downloadable:          document.getElementById('mint-downloadable')?.checked ? 1 : 0,
            pricing_mode:          pricingData.pricing_mode          || 'static',
            price_usd:             pricingData.price_usd             || null,
            accepted_currencies:   JSON.stringify(pricingData.accepted_currencies || []),
            progressive:           pricingData.progressive ? JSON.stringify(pricingData.progressive) : '',
            unlockables:           JSON.stringify(ulUploadedPool().map(p => ({ content_hash: p.hash, label: p.label, tier_order: p.tierOrder }))),
            mint_limit_enabled:    pricingData.mint_limit_enabled     || 0,
            mint_limit_per_wallet: pricingData.mint_limit_per_wallet  || 0,

            // Launch — v464: direct DOM reads (pre-computed in STEP D)
            // v473: convert wall-clock time in artist's selected tz → UTC
            // (parity with handleMint and dashboard Edit Schedule flow)
            launch_type:     _albumLaunchType,
            launch_timezone: _albumLaunchTz,
            ...(isScheduled ? {
                launch_at: localTzToUTC(_albumLaunchDate, _albumLaunchTime, _albumLaunchTz)
            } : {}),

            // Edition type (Open Edition support)
            edition_type: state.editionType || 'fixed',
            ...(state.editionType === 'open' ? {
                total_editions:             0,
                open_edition_ends_at:       state.oeEndsAt       || '',
                open_edition_duration_days: state.oeDurationDays || null
            } : {}),

            // Allowlist
            allowlist_data: collectAllowlistData(),

            // Album-specific — F1: JSON.stringify(array) string, never raw array
            album_tracks_json: JSON.stringify(albumTracksArray)
        };

        // v461: Attach rarity tiers data for album (cover art only, no per-tier masters)
        if (state.tiers.enabled && state.tiers.items.length >= 2) {
            const tiersPayload = state.tiers.items.map((tier, idx) => ({
                name: tier.name?.trim() || `Tier ${idx + 1}`,
                editions: tier.editions || 1,
                cover_ipfs: tier.coverIpfs,
                master_content_hash: null,       // Album tiers share tracks — no per-tier master
                cover_content_hash: tier.coverContentHash || null,
                has_unique_master: false,
                traits: tier.traits.filter(t => t.trait_type?.trim() && t.value?.trim())
            }));
            listingData.tiers_json = JSON.stringify(tiersPayload);
        }

        // ── STEP F: Create listing ────────────────────────────────────────
        // v450: Validate scheduled listings have a launch date (same guard as standard path)
        if (listingData.launch_type === 'scheduled' && !listingData.launch_at) {
            hideLoading();
            stopMintGuard();
            handleMint._inProgress = false;
            imcToast('Please set a release date and time for your scheduled listing, or switch to "Publish Immediately".');
            return;
        }
        showLoading('Creating album listing...');
        // v699: attach the optional tiered listing-card cover (display-only). Only for tiered
        // listings; cover_ipfs itself stays = the first tier's cover. Absent → server falls back.
        if (state.tiers.enabled && state.tierCardCoverFile) {
            if (!state.tierCardCoverIpfs) {
                const _tccRes = await uploadFile(state.tierCardCoverFile, 'cover');
                state.tierCardCoverIpfs = _tccRes.ipfs_url;
            }
            if (state.tierCardCoverIpfs) listingData.tier_card_cover = state.tierCardCoverIpfs;
        }
        const result = await window.mintOnDemand.createListing(listingData);

        // ── STEP G: Clean up guards, show fee payment modal ──────────────
        hideLoading();
        stopMintGuard();
        handleMint._inProgress = false;

        if (result.free_mint) {
            // FREE MINT PROMO -- the server auto-published; no fee to pay.
            if (CONFIG.freeMints && CONFIG.freeMints.remaining && CONFIG.freeMints.remaining['album'] > 0) {
                CONFIG.freeMints.remaining['album']--;
            }
            imcToast('\ud83c\udf81 Free mint used \u2014 your album listing is LIVE!');
            if (elements.successModal) elements.successModal.style.display = 'flex';
        } else {
            if (result.listing_fee_waived) { imcToast('Listing fee waived \ud83c\udf81 -- the fee shown covers your unlockable content.'); }
            window.mintOnDemand.showFeePaymentModal({
                listing_id: result.listing_id,
                nft_name:   listingData.nft_name,
                fee_amount: result.fee_amount,
                fee_wallet: result.fee_wallet
            });
        }

        clearDraft();

    } catch (error) {
        hideLoading();
        stopMintGuard();
        handleMint._inProgress = false;

        const isRetryable = error.message.includes('did not complete')
            || error.message.includes('please retry')
            || error.message.includes('please try again')
            || error.message.includes('Cover protection failed'); // 8d: retry re-enters; protected tiers skip via coverIpfs guards

        if (isRetryable) {
            imcRetry(
                'Album upload error: ' + error.message + '\n\n' +
                'Already-uploaded files are saved.',
                function () { handleMint(); }
            );
        } else {
            imcToast('Album listing could not be created: ' + error.message);
        }
        console.error('[Album] handleAlbumMint error:', error);
    }
}

// ============================================================================
// v24: BUILD ALBUM METADATA — XLS-24d Album Schema (Change #22)
//
// album{} block is NOT in extended_fields strip — preserved in per-edition IPFS.
// cover_access at root level (not inside properties) — matches app parsing spec.
// Track 1 master_access in properties for backwards compat with existing players.
// ============================================================================
function buildAlbumMetadata(formData, albumTracksArray, coverIpfsCid, coverContentHash) {
    const entitlementApi = (CONFIG?.siteUrl || window.location.origin)
        + '/wp-json/imu-master/v1/session-access';

    const track1     = albumTracksArray[0] || {};
    const albumType  = (state.album.type === 'album') ? 'Album' : 'EP';

    // Per-track preview entries for album{}.tracks[]
    const tracksMeta = albumTracksArray.map(t => ({
        track_number: t.track_number,
        title:        t.title,
        preview:      { audio: `ipfs://${t.preview_ipfs}` },
        cover_ipfs:   t.cover_ipfs || ''
    }));

    const metadata = {
        name:          (formData.title || 'Untitled') + ' #{{edition}}',
        description:   formData.description || '',
        image:         `ipfs://${coverIpfsCid}`,
        audio:         `ipfs://${track1.preview_ipfs || ''}`,
        animation_url: `ipfs://${track1.preview_ipfs || ''}`,
        external_url:  CONFIG?.siteUrl || window.location.origin,

        // v24: Album block — NOT in extended_fields, always preserved on-chain
        album: {
            type:        albumType,
            title:       formData.title || 'Untitled',
            track_count: albumTracksArray.length,
            tracks:      tracksMeta
        },

        // v379: Cover access at root level (matches app NFTRepository parsing)
        ...(coverContentHash ? {
            cover_access: {
                content_hash:    coverContentHash,
                entitlement_api: entitlementApi
            }
        } : {}),

        collection: {
            name:   state.collection.name || formData.collection_name || '',
            family: 'IMCollectibles'
        },

        attributes: buildAttributes(formData),

        properties: {
            // Track 1 master_access — backwards compat for apps without album support
            master_access: {
                content_hash:    track1.master_content_hash || '',
                entitlement_api: entitlementApi,
                mime_type:       'audio/mpeg'
            },
            platform: 'IMCollectibles',
            schema:   'xls-24d',
            nftType:  'Album Access'
        }
    };

    return metadata;
}

// --------------------------------------------------------------------------
// NOT IN THIS REPOSITORY. Uploading a protected file to the media service, and
// the credential that authorises it, belong to that service's trust boundary.
// It is maintained as separate infrastructure and is not included here. The call
// sites are left intact so the vault flow reads end to end.
// --------------------------------------------------------------------------
async function getUlTicket() {
    return null;   // call sites test `if (ticket)` and proceed without one
}

async function imuUpTicket() { return await getUlTicket(); }

// U3-r6: QUIET client hash for vault files. generateSHA256 belongs to the MASTER
// flow -- it raises the full-page 'Verifying file integrity' overlay and relies on
// that flow's later showLoading/hideLoading chain to clear it. The vault lane is a
// background row-level upload with per-row status, so borrowing it left the overlay
// stuck on screen forever (even on success). Same 200MB guard, zero UI side effects.
async function ulSha256(file) {
    if (file.size >= 200 * 1024 * 1024) return null; // chunked lane: server hash is authoritative
    const buffer = await file.arrayBuffer();
    const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
    return Array.from(new Uint8Array(hashBuffer)).map(b => b.toString(16).padStart(2, '0')).join('');
}

async function storeUnlockableFile(file, onProgress) {
    throw new Error('storeUnlockableFile is not available in this build');
}

async function storeUnlockableChunked(file, clientHash, endpoint, ticket, onProgress) {
    throw new Error('storeUnlockableChunked is not available in this build');
}

/**
 * NOT IN THIS REPOSITORY. Page extraction for book formats is performed by the
 * media service, which is maintained as separate infrastructure. The call sites
 * are left intact so the flow reads end to end.
 */
async function splitBookPages(contentHash, expectedPages) {
    throw new Error('splitBookPages is not available in this build');
}

// --------------------------------------------------------------------------
// NOT IN THIS REPOSITORY. Uploading the protected master and binding it to the
// public asset is handled by the media service, which is maintained as separate
// infrastructure and is not included here. The call sites are left intact so the
// mint flow still reads end to end.
// --------------------------------------------------------------------------
async function storeMasterFile() {
    throw new Error('storeMasterFile is not available in this build');
}

async function storeMasterSimple() {
    throw new Error('storeMasterSimple is not available in this build');
}

async function storeMasterChunked() {
    throw new Error('storeMasterChunked is not available in this build');
}

/**
 * Generate SHA256 hash of a file (client-side)
 * v226: Handles files of all sizes gracefully
 * - Small files (<200MB): Direct ArrayBuffer hash via Web Crypto
 * - Large files (≥200MB): Returns null — server-only hashing for chunked uploads
 *   (individual HTTP chunks have TCP integrity; server generates authoritative hash)
 */
async function generateSHA256(file) {
    // Large files: skip client-side hashing to avoid crashing browser
    // Server generates SHA-256 via hash_file() which streams natively in PHP
    if (file.size >= 200 * 1024 * 1024) {
        if (typeof showLoading === 'function') {
            showLoading('Preparing large file upload...');
        }
        return null; // Signal to storeMasterFile to skip client verification
    }

    // Small/medium files: hash in memory
    if (typeof showLoading === 'function') {
        showLoading('Verifying file integrity...');
    }
    const buffer = await file.arrayBuffer();
    const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
    const hashArray = Array.from(new Uint8Array(hashBuffer));
    return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
}

/**
 * Helper to get first artist name from form data
 */
function getFirstArtistName(formData) {
    // Try various field name patterns
    const patterns = [
        'primary_artist_name_0',
        'primaryArtistName0',
        'artist_name',
        'artistName'
    ];
    
    for (const pattern of patterns) {
        if (formData[pattern]) {
            return formData[pattern];
        }
    }
    
    return '';
}

/**
 * Cover protection for Music/MV/Film flows.
 *
 * Hands the cover to the media service, which returns the watermarked preview CID
 * and a content hash. Storage and watermarking are handled by that service, which
 * is maintained as separate infrastructure and is not included in this repository.
 *
 * On watermark_failed=true, callers BLOCK the publish -- never publish a raw cover.
 */
async function watermarkCoverFile() {
    throw new Error('watermarkCoverFile is not available in this build');
}


    // ===== Upload Functions =====
    
    async function uploadFile(file, type) {
        const endpoint = VPS_MEDIA.endpoint;
        
        // Use chunked upload for large files
        if (file.size > CHUNK_SIZE) {
            return await uploadFileChunked(file, type);
        }
        
        // v229: Increased timeout + retries for slow/unstable connections
        // At 10 KB/s, a 4.9MB file needs ~500s — previous 60s was insufficient
        const MAX_RETRIES = 5;
        const TIMEOUT_MS = 300000; // 5 minutes per upload
        
        for (let attempt = 1; attempt <= MAX_RETRIES; attempt++) {
            try {
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), TIMEOUT_MS);
                
                const imuT = await imuUpTicket();
                const formData = new FormData();
                formData.append('action', 'simple');
                if (imuT) formData.append('upload_ticket', imuT);
                formData.append('file', file);
                formData.append('upload_type', type);
                // v24: F10 — map album→music so VPS accepts WAV/FLAC for album tracks
                formData.append('content_type', state.contentType === 'album' ? 'music' : state.contentType);
                
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {},
                    body: formData,
                    signal: controller.signal
                });
                
                clearTimeout(timeoutId);
                const data = await response.json();
                
                if (data.success) {
                    return {
                        ipfs_hash: data.data.ipfs_hash,
                        ipfs_url: data.data.ipfs_url,
                        gateway_url: data.data.gateway_url
                    };
                } else {
                    // v224: VPS sendError returns {error:"msg"} at top level, not under data
                    throw new Error(data.error || data.data?.error || 'Upload failed');
                }
            } catch (err) {
                const isTimeout = err.name === 'AbortError';
                const msg = isTimeout ? 'Upload timed out' : err.message;
                console.warn(`Upload attempt ${attempt}/${MAX_RETRIES} failed: ${msg}`);
                
                if (attempt === MAX_RETRIES) {
                    throw new Error(`Upload failed after ${MAX_RETRIES} attempts: ${msg}`);
                }
                // v229: Exponential backoff: 3s, 6s, 12s, 24s
                const backoffMs = 3000 * Math.pow(2, attempt - 1);
                await new Promise(r => setTimeout(r, backoffMs));
            }
        }
    }

    async function uploadFileChunked(file, uploadType) {
        const endpoint = VPS_MEDIA.endpoint;
        const uploadState = uploadType === 'primary' ? state.primaryUpload : 
                          uploadType === 'audio' ? state.audioUpload : state.coverUpload;
        
        uploadState.status = 'uploading';
        uploadState.progress = 0;
        
        // Initialize upload
        const initFormData = new FormData();
        const imuTInit = await imuUpTicket();
        initFormData.append('action', 'init'); // v213: Must match VPS media-processor.php case 'init'
        if (imuTInit) initFormData.append('upload_ticket', imuTInit);
        initFormData.append('filename', file.name);
        initFormData.append('filesize', file.size);
        initFormData.append('mimetype', file.type);
        initFormData.append('upload_type', uploadType);
        // R6: coerce album->music like the other three send sites (L7388/L7449 and the
        // simple lane). This path sent the RAW state.contentType, so an album master
        // >2MB posted content_type='album' and relied on handleInit's tolerant coercion
        // to land on 'music' by luck. Harmless today, but it makes the coercion
        // non-central -- a new type whose fallback is NOT harmless would break here.
        initFormData.append('content_type', state.contentType === 'album' ? 'music' : state.contentType);
        
        // v212: Timeout for chunked upload init
        const initController = new AbortController();
        const initTimeout = setTimeout(() => initController.abort(), 30000);
        
        const initResponse = await fetch(endpoint, {
            method: 'POST',
            headers: {},
            body: initFormData,
            signal: initController.signal
        });
        clearTimeout(initTimeout);
        
        const initData = await initResponse.json();
        
        if (!initData.success) {
            // v224: VPS sendError returns {error:"msg"} at top level
            throw new Error(initData.error || initData.data?.error || 'Failed to initialize upload');
        }
        
        const upload_id = initData.data.upload_id;
        uploadState.id = upload_id;
        
        // Upload chunks
        const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
        
        for (let i = 0; i < totalChunks; i++) {
            const start = i * CHUNK_SIZE;
            const end = Math.min(start + CHUNK_SIZE, file.size);
            const chunk = file.slice(start, end);
            
            const chunkFormData = new FormData();
            const imuT = await imuUpTicket();
            chunkFormData.append('action', 'chunk'); // v213: Must match VPS media-processor.php case 'chunk'
            if (imuT) chunkFormData.append('upload_ticket', imuT);
            chunkFormData.append('upload_id', upload_id);
            chunkFormData.append('chunk_index', i);
            chunkFormData.append('chunk', chunk);
            
            // v229: Increased timeout + retries for slow/unstable connections (e.g. Africa, mobile)
            // At 10 KB/s, a 2.5MB chunk needs ~250 seconds — previous 90s was insufficient
            let chunkSuccess = false;
            for (let attempt = 1; attempt <= 5; attempt++) {
                try {
                    const chunkController = new AbortController();
                    const chunkTimeout = setTimeout(() => chunkController.abort(), 300000); // 5 minutes per chunk
                    
                    const chunkResponse = await fetch(endpoint, {
                        method: 'POST',
                        headers: {},
                        body: chunkFormData,
                        signal: chunkController.signal
                    });
                    clearTimeout(chunkTimeout);
                    
                    // v229: Parse response — was missing, masked VPS errors
                    const chunkData = await chunkResponse.json();
                    if (!chunkData.success && !chunkData.data?.duplicate) {
                        throw new Error(chunkData.error || chunkData.data?.error || 'Chunk rejected by server');
                    }
                    
                    chunkSuccess = true;
                    break;
                } catch (chunkErr) {
                    const isTimeout = chunkErr.name === 'AbortError';
                    const msg = isTimeout ? 'timeout (slow connection — retrying)' : chunkErr.message;
                    console.warn(`Chunk ${i+1}/${totalChunks} attempt ${attempt}/5 failed: ${msg}`);
                    if (attempt === 5) throw new Error(`Chunk upload failed after 5 attempts (chunk ${i+1}/${totalChunks}): ${msg}`);
                    // v229: Exponential backoff: 3s, 6s, 12s, 24s
                    const backoffMs = 3000 * Math.pow(2, attempt - 1);
                    await new Promise(r => setTimeout(r, backoffMs));
                }
            }
            
            uploadState.progress = Math.round(((i + 1) / totalChunks) * 50);
            updateUploadProgress(uploadType, uploadState.progress);
        }
        
        // Complete upload — all chunks are on VPS, this triggers reassembly + IPFS pin
        uploadState.status = 'processing';
        uploadState.step = 'Processing...';
        updateLoadingText('Processing media...');
        
        const completeFormData = new FormData();
        completeFormData.append('action', 'complete');
        const imuTDone = await imuUpTicket();
        if (imuTDone) completeFormData.append('upload_ticket', imuTDone);
        completeFormData.append('upload_id', upload_id);
        
        // v230: Retry complete — failing here after all chunks uploaded would be catastrophic
        let completeData;
        for (let cAttempt = 1; cAttempt <= 3; cAttempt++) {
            try {
                const completeController = new AbortController();
                const completeTimeout = setTimeout(() => completeController.abort(), 180000); // 3 minutes
                
                const completeResponse = await fetch(endpoint, {
                    method: 'POST',
                    headers: {},
                    body: completeFormData,
                    signal: completeController.signal
                });
                clearTimeout(completeTimeout);
                
                completeData = await completeResponse.json();
                break; // Success
            } catch (completeErr) {
                const msg = completeErr.name === 'AbortError' ? 'timeout' : completeErr.message;
                console.warn(`Complete request attempt ${cAttempt}/3 failed: ${msg}`);
                if (cAttempt === 3) throw new Error(`Failed to finalize upload after 3 attempts: ${msg}`);
                await new Promise(r => setTimeout(r, 5000 * cAttempt)); // 5s, 10s backoff
            }
        }
        
        // v213: VPS handleComplete returns result in two possible formats:
        // 1. Synchronous completion: {success:true, data:{image:{url:...}, audio:{url:...}}} — result IS data
        // 2. Deferred processing: poll handleStatus which returns {success:true, data:{status:'complete', result:{...}}}
        // v232: Check for error FIRST — if complete returned {success:false, error:'...'}, throw immediately
        if (!completeData.success && completeData.error) {
            throw new Error(completeData.error);
        }
        if (completeData.success && completeData.data) {
            // Check if result is directly in data (VPS completed synchronously)
            const d = completeData.data;
            const directUrl = d.web?.url || d.audio?.url || d.image?.url || d.ipfs?.url;
            
            if (d.status === 'complete' && d.result) {
                // Wrapped format from handleStatus poll
                uploadState.status = 'complete';
                uploadState.progress = 100;
                const result = d.result;
                const ipfsUrl = result.web?.url || result.audio?.url || result.image?.url || result.ipfs?.url;
                return {
                    ipfs_hash: ipfsUrl?.replace('ipfs://', ''),
                    ipfs_url: ipfsUrl,
                    gateway_url: result.web?.gateway || result.audio?.gateway || result.image?.gateway
                };
            } else if (directUrl) {
                // Direct format from handleComplete (no status wrapper)
                uploadState.status = 'complete';
                uploadState.progress = 100;
                return {
                    ipfs_hash: directUrl.replace('ipfs://', ''),
                    ipfs_url: directUrl,
                    gateway_url: d.web?.gateway || d.audio?.gateway || d.image?.gateway
                };
            }
        }
        
        // Fall through to polling if neither format matched
        return await pollProcessingStatus(upload_id, uploadType);
    }

    async function pollProcessingStatus(uploadId, uploadType) {
        const uploadState = uploadType === 'primary' ? state.primaryUpload : 
                          uploadType === 'audio' ? state.audioUpload : state.coverUpload;
        const endpoint = VPS_MEDIA.endpoint;
        const maxAttempts = 120;
        let consecutiveErrors = 0;
        
        for (let i = 0; i < maxAttempts; i++) {
            await new Promise(resolve => setTimeout(resolve, 5000));
            
            // v230: Wrapped in try/catch — a failed poll must NOT crash the flow
            // after all chunks have been successfully uploaded to VPS
            try {
                const pollController = new AbortController();
                const pollTimeout = setTimeout(() => pollController.abort(), 15000);
                const imuTs = await imuUpTicket();
                const response = await fetch(`${endpoint}?action=status&upload_id=${uploadId}`, {
                    headers: imuTs ? { 'X-Upload-Ticket': imuTs } : {},
                    signal: pollController.signal
                });
                clearTimeout(pollTimeout);
                const data = await response.json();
                
                consecutiveErrors = 0; // Reset on success
                
                if (data.success) {
                    const { status, progress, step, result, error } = data.data;
                    
                    uploadState.progress = 50 + (progress / 2);
                    uploadState.step = step;
                    updateLoadingText(step || 'Processing...');
                    
                    if (status === 'complete') {
                        uploadState.status = 'complete';
                        uploadState.progress = 100;
                        
                        const ipfsUrl = result.web?.url || result.audio?.url || result.image?.url || result.ipfs?.url;
                        
                        return {
                            ipfs_hash: ipfsUrl?.replace('ipfs://', ''),
                            ipfs_url: ipfsUrl,
                            gateway_url: result.web?.gateway || result.audio?.gateway || result.image?.gateway
                        };
                    } else if (status === 'failed') {
                        // v232: Prefix with [SERVER] so catch block rethrows instead of swallowing
                        throw new Error(`[SERVER] ${error || 'Processing failed on server'}`);
                    }
                }
            } catch (pollErr) {
                // v232: If server explicitly reported a failure status, pass through immediately
                // (covers all server errors, not just 'Processing failed')
                if (pollErr.message?.startsWith('[SERVER]')) {
                    throw new Error(pollErr.message.replace('[SERVER] ', ''));
                }
                
                consecutiveErrors++;
                const msg = pollErr.name === 'AbortError' ? 'poll timeout' : pollErr.message;
                console.warn(`Status poll ${i+1}/${maxAttempts} failed (${consecutiveErrors} consecutive): ${msg}`);
                updateLoadingText('Processing... (connection unstable, retrying)');
                
                // Only give up after 10 consecutive failures (~50s of no contact)
                if (consecutiveErrors >= 10) {
                    throw new Error(`Lost contact with server during processing (${consecutiveErrors} consecutive poll failures)`);
                }
                // Otherwise silently continue polling — VPS is still working
            }
        }
        
        throw new Error('Processing timed out');
    }

    async function uploadMetadata(metadata) {
        const endpoint = VPS_MEDIA.endpoint;
        
        // v229: Added retry loop — was single attempt, failed on any connection blip
        for (let attempt = 1; attempt <= 3; attempt++) {
            try {
                const imuT = await imuUpTicket();
                const formData = new FormData();
                formData.append('action', 'metadata');
                if (imuT) formData.append('upload_ticket', imuT);
                formData.append('metadata', JSON.stringify(metadata));
                
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 60000); // v229: 60s (was 30s — Pinata pin can be slow)
                
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {},
                    body: formData,
                    signal: controller.signal
                });
                clearTimeout(timeoutId);
                
                const data = await response.json();
                
                if (data.success && data.data?.ipfs_hash) {
                    return `ipfs://${data.data.ipfs_hash}`;
                } else {
                    throw new Error(data.error || data.data?.error || 'Metadata upload failed');
                }
            } catch (err) {
                const msg = err.name === 'AbortError' ? 'timeout' : err.message;
                console.warn(`Metadata upload attempt ${attempt}/3 failed: ${msg}`);
                if (attempt === 3) throw new Error(`Metadata upload failed after 3 attempts: ${msg}`);
                await new Promise(r => setTimeout(r, 3000 * attempt));
            }
        }
    }

    function updateUploadProgress(uploadType, progress) {
        const progressBar = document.getElementById(`${uploadType}-progress-fill`);
        const progressText = document.getElementById(`${uploadType}-progress-text`);
        const progressContainer = document.getElementById(`${uploadType}-progress`);
        
        if (progressContainer) progressContainer.style.display = 'block';
        if (progressBar) progressBar.style.width = `${progress}%`;
        if (progressText) progressText.textContent = `${progress}%`;
    }

    // ===== Utilities =====
    
    // v43: Prevent accidental navigation during file processing
    let _mintInProgress = false;
    function _beforeUnloadGuard(e) {
        if (_mintInProgress) {
            e.preventDefault();
            e.returnValue = 'Your mint is still processing. Leaving now may cause it to fail.';
            return e.returnValue;
        }
    }

    function showLoading(text) {
        if (elements.loadingText) elements.loadingText.textContent = text || 'Processing...';
        if (elements.loadingOverlay) elements.loadingOverlay.style.display = 'flex';
        // v43: Show warning when mint is in progress
        const warn = document.getElementById('mint-loading-warning');
        if (warn && _mintInProgress) warn.style.display = 'block';
    }

    function hideLoading() {
        if (elements.loadingOverlay) elements.loadingOverlay.style.display = 'none';
        const warn = document.getElementById('mint-loading-warning');
        if (warn) warn.style.display = 'none';
    }

    function startMintGuard() {
        _mintInProgress = true;
        window.addEventListener('beforeunload', _beforeUnloadGuard);
    }

    function stopMintGuard() {
        _mintInProgress = false;
        window.removeEventListener('beforeunload', _beforeUnloadGuard);
    }

    function updateLoadingText(text) {
        if (elements.loadingText) elements.loadingText.textContent = text;
    }

    function showSuccess(txHash, nftId) {
        document.getElementById('success-nft-id').textContent = nftId || 'Pending...';
        document.getElementById('success-tx-link').href = `https://livenet.xrpl.org/transactions/${txHash}`;
        if (elements.successModal) elements.successModal.style.display = 'flex';
    }

    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        return (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
    }

    function formatDuration(seconds) {
        const mins = Math.floor(seconds / 60);
        const secs = seconds % 60;
        return `${mins}:${secs.toString().padStart(2, '0')}`;
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                  .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    // ===== Wallet & Collection =====
    
    async function handleWalletConnect() {
        showLoading('Connecting wallet...');
        
        try {
            const response = await fetch(CONFIG.endpoints.xummProxy, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'create_payload',
                    payload: {
                        txjson: { TransactionType: 'SignIn' }
                    }
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                // v591 mobile (additive): same-tab SignIn. The SignIn payload carries a webhook +
                // return_url (xaman-signin-complete.php) that set the session on a fresh load, so
                // returning same-tab lands the user already connected. Desktop keeps the new tab.
                if (/Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent || '')) {
                    window.location.href = data.data.next.always;
                } else {
                    window.open(data.data.next.always, '_blank');
                }
                // Wallet connection handled by page reload/cookie
            }
            
        } catch (error) {
            console.error('Wallet connect error:', error);
        }
        
        hideLoading();
    }

    // =========================================================================
    // COLLECTION CREATION & VALIDATION (Step 0)
    // =========================================================================
    
    /**
     * Handle "Create Collection & Continue" button click
     * Validates form → creates collection on server → advances to Step 1
     */
    async function handleCollectionContinue() {
        if (!validateCollection()) return;
        // C-β: DEFERRED WRITE — nothing is created here anymore. Details are stashed
        // (validateCollection already wrote name/description/taxon into state) and the
        // collection row + cover upload happen at publish (ensureCollectionOnServer).
        // Kills CS-1 (orphan rows) and CP-1 (Back+Continue duplicate-create).
        const statusEl = document.getElementById('collection-status');
        if (statusEl) {
            statusEl.style.display = 'block';
            statusEl.className = 'collection-status info';
            statusEl.innerHTML = '<svg class="imc-ic" aria-hidden="true"><use href="#ic-check-circle"></use></svg> Collection ready — it will be created when you publish.';
        }
        setTimeout(() => goToStep(2), 400);
    }
    
    /**
     * Handle "Continue with Collection" button click (existing collection tab)
     */
    function handleExistingContinue() {
        if (!state.collection.existingId) {
            imcToast('Please select a collection first');
            return;
        }
        goToStep(2);
    }
    
    /**
     * POST to collections API to create the collection on the server
     */
    /**
     * C-β: publish-time collection creation (idempotent).
     * Runs once per session for new collections; a retry re-entering handleMint
     * skips it via existingId. Cover upload stays NON-BLOCKING (today's semantics).
     */
    async function ensureCollectionOnServer() {
        if (!state.collection.isNew || state.collection.existingId) return true;
        try {
            if (state.collection.coverImage && !state.collection.coverIpfs) {
                try {
                    const coverResult = await uploadFile(state.collection.coverImage, 'cover');
                    state.collection.coverIpfs = coverResult.ipfs_url || coverResult.cid || null;
                } catch (coverErr) {
                    console.warn('Cover upload failed (non-blocking):', coverErr);
                }
            }
            const result = await createCollectionOnServer();
            if (!result.success) {
                throw new Error(result.error || 'Failed to create collection');
            }
            state.collection.existingId = result.collection.id;
            state.collection.taxon = result.collection.collection_taxon;
            return true;
        } catch (error) {
            console.error('Publish-time collection creation failed:', error);
            imcToast('Collection creation failed: ' + error.message + '\n\nYour listing was not created. Please review the Collection step and try again.');
            return false;
        }
    }

    async function createCollectionOnServer() {
        const endpoint = CONFIG.endpoints?.collectionsApi || 
            `${window.location.origin}/wp-content/themes/astra/xrpl-nft-marketplace/backend/collections-api-handler.php`;
        
        const formData = new FormData();
        formData.append('action', 'create');
        formData.append('nonce', CONFIG.nonce);
        formData.append('artist_account', CONFIG.account);
        formData.append('collection_name', state.collection.name);
        formData.append('collection_description', state.collection.description || '');
        formData.append('collection_taxon', String(state.collection.taxon));
        if (state.collection.coverIpfs) {
            formData.append('cover_image_ipfs', state.collection.coverIpfs);
        }
        
        const response = await fetch(endpoint, {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        return data;
    }
    
    /**
     * Real-time taxon availability check (called on blur of taxon input)
     */
    async function validateTaxonAvailability() {
        const taxonInput = document.getElementById('collection-taxon');
        const statusEl = document.getElementById('taxon-status');
        if (!taxonInput || !statusEl) return;
        
        const val = taxonInput.value.trim();
        if (!val) {
            statusEl.textContent = '';
            statusEl.className = 'taxon-status';
            return;
        }
        
        const taxonNum = parseInt(val, 10);
        if (isNaN(taxonNum) || taxonNum < 0 || taxonNum > 4294967295) {
            statusEl.textContent = 'Out of range (0-4,294,967,295)';
            statusEl.className = 'taxon-status error';
            return;
        }
        
        if (!CONFIG.account) {
            statusEl.textContent = '⚠️ Connect wallet first';
            statusEl.className = 'taxon-status error';
            return;
        }
        
        statusEl.innerHTML = '<span class="spinner-small"></span> Checking...';
        statusEl.className = 'taxon-status checking';
        
        try {
            const endpoint = CONFIG.endpoints?.collectionsApi || 
                `${window.location.origin}/wp-content/themes/astra/xrpl-nft-marketplace/backend/collections-api-handler.php`;
            
            const response = await fetch(`${endpoint}?action=check_taxon&account=${CONFIG.account}&taxon=${taxonNum}`);
            const data = await response.json();
            
            if (data.success && data.available) {
                statusEl.textContent = '✅ Available';
                statusEl.className = 'taxon-status success';
            } else if (data.success && !data.available) {
                statusEl.textContent = `Used by "${data.existing_collection}"`;
                statusEl.className = 'taxon-status error';
            } else {
                statusEl.textContent = '⚠️ ' + (data.error || 'Check failed');
                statusEl.className = 'taxon-status error';
            }
        } catch (err) {
            console.error('Taxon check failed:', err);
            statusEl.textContent = '⚠️ Could not verify';
            statusEl.className = 'taxon-status error';
        }
    }

    async function loadExistingCollections() {
        const list = document.getElementById('existing-collections-list');
        const noMsg = document.getElementById('no-collections-msg');
        
        if (!CONFIG.account) {
            if (list) list.innerHTML = '';
            if (noMsg) noMsg.style.display = 'block';
            return;
        }
        
        if (list) list.innerHTML = '<div class="loading-collections"><div class="spinner-small"></div> Loading collections...</div>';
        if (noMsg) noMsg.style.display = 'none';
        
        try {
            const endpoint = CONFIG.endpoints?.collectionsApi || 
                `${window.location.origin}/wp-content/themes/astra/xrpl-nft-marketplace/backend/collections-api-handler.php`;
            
            const response = await fetch(`${endpoint}?action=get_by_artist&account=${CONFIG.account}`);
            const data = await response.json();
            
            if (!data.success || !data.collections || data.collections.length === 0) {
                if (list) list.innerHTML = '';
                if (noMsg) noMsg.style.display = 'block';
                return;
            }
            
            // v139: Render collection cards with enriched data
            if (list) {
                list.innerHTML = data.collections.map(col => {
                    // v139: Try collection cover, then listing cover, normalize IPFS format
                    let coverIpfs = col.cover_image_ipfs || col.listing_cover || '';
                    // Strip ipfs:// prefix if present, just need the CID
                    coverIpfs = coverIpfs.replace(/^ipfs:\/\//, '');
                    
                    const minted = Math.max(parseInt(col.nft_count) || 0, parseInt(col.actual_minted) || 0);
                    const total = parseInt(col.total_editions) || 0;
                    const nftType = col.listing_nft_type;
                    const typeIcon = nftType === 'musicvideo' ? '<svg class="imc-ic" aria-hidden="true"><use href="#ic-clapper"></use></svg>' : nftType === 'art' ? '<svg class="imc-ic" aria-hidden="true"><use href="#ic-image"></use></svg>' : nftType === 'film' ? '<svg class="imc-ic" aria-hidden="true"><use href="#ic-video"></use></svg>' : '<svg class="imc-ic" aria-hidden="true"><use href="#ic-music"></use></svg>';
                    const countText = total > 0 
                        ? `${minted} / ${total} minted` 
                        : (minted > 0 ? `${minted} minted` : 'No NFTs yet');
                    
                    return `
                    <div class="existing-collection-card ${state.collection.existingId == col.id ? 'selected' : ''}" 
                         data-collection-id="${col.id}" 
                         data-collection-taxon="${col.collection_taxon}"
                         data-collection-name="${escapeHtml(col.collection_name)}"
                         data-collection-description="${escapeHtml(col.collection_description || '')}">
                        <div class="collection-card-cover">
                            ${coverIpfs
                                ? `<img src="https://<your-pinata-gateway>/ipfs/${coverIpfs}" alt="${escapeHtml(col.collection_name)}" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'" />
                                   <div class="collection-no-cover" style="display:none">${typeIcon}</div>`
                                : `<div class="collection-no-cover">${typeIcon}</div>`
                            }
                        </div>
                        <div class="collection-card-info">
                            <h4>${escapeHtml(col.collection_name)}</h4>
                            <span class="collection-taxon-badge">Taxon: ${col.collection_taxon}</span>
                            <span class="collection-count">${countText}</span>
                        </div>
                    </div>
                `}).join('');

                // v378: Bind click handlers via event delegation (safe — no inline JS strings)
                list.querySelectorAll('.existing-collection-card').forEach(card => {
                    card.addEventListener('click', () => {
                        const id = parseInt(card.dataset.collectionId);
                        const name = card.dataset.collectionName || '';
                        const taxon = parseInt(card.dataset.collectionTaxon) || 0;
                        const description = card.dataset.collectionDescription || '';
                        selectExistingCollection(id, name, taxon, description);
                    });
                });
            }
            if (noMsg) noMsg.style.display = 'none';
            
        } catch (error) {
            console.error('Failed to load collections:', error);
            if (list) list.innerHTML = '<div class="error-msg">Failed to load collections</div>';
        }
    }
    
    // v378: Collection card selection handler (called via event delegation, not inline onclick)
    function selectExistingCollection(id, name, taxon, description) {
        state.collection.existingId = id;
        state.collection.name = name;
        state.collection.taxon = taxon;
        state.collection.description = description || '';
        state.collection.isNew = false;
        
        // Update UI selection
        document.querySelectorAll('.existing-collection-card').forEach(card => {
            card.classList.toggle('selected', parseInt(card.dataset.collectionId) === id);
        });
        
        // Show the existing collection action bar
        const actionBar = document.getElementById('existing-collection-action');
        const infoEl = document.getElementById('selected-collection-info');
        if (actionBar) actionBar.style.display = '';
        if (infoEl) infoEl.textContent = `Selected: "${name}" (Taxon: ${taxon})`;
    }

    async function handleCollectionCoverUpload(e) {
        const file = e.target.files[0];
        if (!file) return;
        
        state.collection.coverImage = file;
        
        const reader = new FileReader();
        reader.onload = (event) => {
            const preview = document.getElementById('collection-cover-preview');
            if (preview) {
                preview.innerHTML = `<img src="${event.target.result}" alt="Collection cover">`;
                preview.style.display = 'block';
            }
            const dropzone = document.getElementById('collection-cover-dropzone');
            if (dropzone) dropzone.style.display = 'none';
        };
        reader.readAsDataURL(file);
    }

    // v699: tiered listing-card cover — mirror of handleCollectionCoverUpload
    async function handleTierCardCoverUpload(e) {
        const file = e.target.files[0];
        if (!file) return;

        state.tierCardCoverFile = file;
        state.tierCardCoverIpfs = null; // reset — re-pins on next submit

        const reader = new FileReader();
        reader.onload = (event) => {
            const preview = document.getElementById('tier-card-cover-preview');
            if (preview) {
                preview.innerHTML = `<img src="${event.target.result}" alt="Listing card cover">`;
                preview.style.display = 'block';
            }
            const dropzone = document.getElementById('tier-card-cover-dropzone');
            if (dropzone) dropzone.style.display = 'none';
        };
        reader.readAsDataURL(file);
    }

    // =========================================================================
    // AUTHORIZATION FUNCTIONS (Mint-on-Demand)
    // =========================================================================

    /**
     * Check if artist is authorized with IMCollectibles
     */
    async function checkAuthorizationStatus() {
        if (!CONFIG.account) return;
        
        state.auth.isChecking = true;
        showAuthState('checking');
        disableMintWizard();
        
        try {
            const endpoint = CONFIG.endpoints?.authMinter || 
                            `${window.location.origin}/wp-content/themes/astra/xrpl-nft-marketplace/backend/authorized-minter-handler.php`;
            
            const response = await fetch(
                `${endpoint}?action=check_status&account=${CONFIG.account}`
            );
            const data = await response.json();
            
            if (!data.success) {
                console.error('Auth check failed:', data.error);
                showAuthState('required');
                return;
            }
            
            state.auth.isAuthorized = data.data.is_authorized;
            state.auth.status = data.data.status;
            state.auth.currentMinter = data.data.current_minter;
            
            if (data.data.status === 'authorized') {
                showAuthState('success');
                enableMintWizard();
            } else if (data.data.status === 'has_other_minter') {
                const currentMinter = data.data.current_minter || 'Unknown';
                
                if (elements.authOtherMinter) {
                    elements.authOtherMinter.textContent = currentMinter;
                }
                
                // Unified flow — all non-IMC minters get the same switch experience
                if (elements.conflictTitle) {
                    elements.conflictTitle.textContent = 'Another Marketplace Authorized';
                }
                if (elements.conflictMessage) {
                    elements.conflictMessage.textContent = 'Your wallet currently has another marketplace set as authorized minter:';
                }
                if (elements.authBtnUpdate) {
                    elements.authBtnUpdate.textContent = 'Switch to IMCollectibles';
                }
                
                showAuthState('conflict');
                disableMintWizard();
            } else {
                showAuthState('required');
                disableMintWizard();
            }
            
        } catch (error) {
            console.error('Auth check error:', error);
            showAuthState('required');
        } finally {
            state.auth.isChecking = false;
        }
    }

    /**
     * Request authorization (create XUMM payload)
     * Works for both initial authorization and updating existing authorization
     */
    async function requestAuthorization(event) {
        // Determine which button was clicked
        const clickedBtn = event?.target;
        const isUpdateFlow = clickedBtn?.id === 'btn-update-auth';
        
        // Get the active button (could be either authorize or update)
        const activeBtn = isUpdateFlow ? elements.authBtnUpdate : elements.authBtnAuthorize;
        if (!activeBtn) return;
        
        const originalText = activeBtn.textContent;
        activeBtn.disabled = true;
        activeBtn.textContent = 'Creating request...';
        
        try {
            const endpoint = CONFIG.endpoints?.authMinter || 
                            `${window.location.origin}/wp-content/themes/astra/xrpl-nft-marketplace/backend/authorized-minter-handler.php`;
            
            // --- Joey branch (v552, additive): sign AccountSet locally, then verify on-chain ---
            if (window.__joeySession && window.__joeySession.live && window.imuWallet) {
                if (CONFIG.account !== window.__joeySession.account) { imcToast('Connected wallet does not match your account. Please reconnect.'); return; }
                const jfd = new FormData();
                jfd.append('action', 'request_auth'); jfd.append('account', CONFIG.account); jfd.append('nonce', CONFIG.nonce); jfd.append('wallet', 'joey');
                const jres = await fetch(endpoint, { method: 'POST', body: jfd });
                const jd = await jres.json();
                if (!jd.success || !jd.data || !jd.data.txjson) throw new Error(jd.error || 'Failed to prepare authorization');
                let jsigned;
                try { jsigned = await (window.imuJoeySign||window.imuWallet.sign)(jd.data.txjson); }
                catch (e) { const em=(e&&e.message)||''; imcToast(/timed out|timeout/i.test(em)?'Signing timed out. Please try again.':/cancel/i.test(em)?'Signing cancelled.':'Signing was rejected.'); return; }
                const jtx = jsigned && (jsigned.hash || jsigned.tx_hash);
                if (!jtx) { imcToast('No transaction hash returned from your wallet.'); return; }
                const jvfd = new FormData();
                jvfd.append('action', 'joey_verify_authorize'); jvfd.append('account', CONFIG.account); jvfd.append('tx_hash', jtx); jvfd.append('nonce', CONFIG.nonce);
                const jvr = await fetch(endpoint, { method: 'POST', body: jvfd });
                const jvd = await jvr.json();
                if (!jvd.success) throw new Error(jvd.error || 'Authorization verification failed');
                for (let attempt = 0; attempt <= 3; attempt++) {
                    if (attempt > 0) await new Promise(r => setTimeout(r, 3000));
                    await checkAuthorizationStatus();
                    if (state.auth.status === 'authorized') break;
                }
                return;
            }

            const formData = new FormData();
            formData.append('action', 'request_auth');
            formData.append('account', CONFIG.account);
            formData.append('nonce', CONFIG.nonce);
            
            const response = await fetch(endpoint, {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (!data.success) {
                imcToast(data.error || 'Failed to create authorization request');
                return;
            }

            // v684: server-decided Joey response on the Xaman path (xrpl_wallet_type authority,
            // v683). No uuid here, so the modal + poll would spin on undefined. Bounce; the
            // finally block below restores the button state.
            if (data.data?.wallet === 'joey' && data.data?.txjson) {
                imcToast('Wallet still connecting — please try again in a moment.');
                return;
            }
            
            // Show modal with QR
            showAuthModal(data.data);
            
            // Start polling
            startAuthPolling(data.data.uuid);
            
        } catch (error) {
            console.error('Auth request error:', error);
            imcToast('Failed to create authorization request');
        } finally {
            // Re-enable whichever button was clicked
            if (elements.authBtnAuthorize) {
                elements.authBtnAuthorize.disabled = false;
                elements.authBtnAuthorize.textContent = 'Authorize IMCollectibles';
            }
            if (elements.authBtnUpdate) {
                elements.authBtnUpdate.disabled = false;
                elements.authBtnUpdate.textContent = 'Switch to IMCollectibles';
            }
        }
    }

    /**
     * Show XUMM authorization modal
     */
    function showAuthModal(data) {
        if (data.qr_png && elements.authQrCode) {
            elements.authQrCode.src = data.qr_png;
        }
        if (data.deeplink && elements.authDeepLink) {
            elements.authDeepLink.href = data.deeplink;
            // v590 mobile (additive): open Xaman in the SAME tab for authorisation.
            // Safe on both return paths -- SetAuthorizedMinter is the on-chain tx; the
            // poll only CONFIRMS it, and the return /mint/?auth_signed=1 reloads and
            // re-detects via checkAuthorizationStatus. No frontend completion step.
            // Desktop is untouched (gated behind the mobile UA test).
            if (/Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent || '')) {
                elements.authDeepLink.setAttribute('target', '_self');
            }
        }
        if (elements.authPollStatus) {
            elements.authPollStatus.innerHTML = '<div class="spinner-small"></div><span>Waiting for signature...</span>';
        }
        if (elements.authModal) {
            elements.authModal.style.display = 'flex';
        }
    }

    /**
     * Close authorization modal
     */
    function closeAuthModal() {
        if (elements.authModal) {
            elements.authModal.style.display = 'none';
        }
        stopAuthPolling();
    }

    /**
     * Start polling for authorization signature
     */
    function startAuthPolling(uuid) {
        stopAuthPolling();
        
        state.auth.pendingUuid = uuid;
        
        state.auth.pollInterval = setInterval(async () => {
            try {
                const endpoint = CONFIG.endpoints?.authMinter || 
                                `${window.location.origin}/wp-content/themes/astra/xrpl-nft-marketplace/backend/authorized-minter-handler.php`;
                
                const response = await fetch(`${endpoint}?action=poll&uuid=${uuid}`);
                const data = await response.json();
                
                if (!data.success) return;
                
                if (data.data.signed) {
                    
                    // v202: Check if transaction failed on-chain
                    if (data.data.tx_failed) {
                        stopAuthPolling();
                        if (elements.authPollStatus) {
                            elements.authPollStatus.innerHTML = `<span style="color:#ef4444;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-x-circle"></use></svg> Transaction failed: ${data.data.tx_error || 'Unknown error'}</span>`;
                        }
                        return;
                    }
                    
                    // v202: If tx not yet confirmed on-chain, show "confirming..." and keep polling
                    if (!data.data.tx_confirmed) {
                        if (elements.authPollStatus) {
                            elements.authPollStatus.innerHTML = '<span style="color:#f59e0b;">⏳ Signed! Confirming on chain...</span>';
                        }
                        return; // Don't stop polling — next interval will re-check
                    }
                    
                    // Transaction confirmed on-chain! Now stop polling
                    stopAuthPolling();
                    
                    if (elements.authPollStatus) {
                        elements.authPollStatus.innerHTML = '<span style="color:#10b981;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-check-circle"></use></svg> Confirmed on chain! Verifying authorization...</span>';
                    }
                    
                    // v202: Retry logic — XRPL validated ledger may lag 1-2 ledger closes
                    // Wait 4s initially (one full ledger close), then retry up to 3 more times
                    const maxRetries = 3;
                    const retryDelay = 3000; // 3s between retries
                    
                    setTimeout(async () => {
                        closeAuthModal();
                        
                        for (let attempt = 0; attempt <= maxRetries; attempt++) {
                            if (attempt > 0) {
                                await new Promise(r => setTimeout(r, retryDelay));
                            }
                            
                            await checkAuthorizationStatus();
                            
                            if (state.auth.status === 'authorized') {
                                console.log(`Auth confirmed on attempt ${attempt + 1}`);
                                return;
                            }
                            
                            console.log(`Auth check attempt ${attempt + 1}/${maxRetries + 1} — not yet propagated, retrying...`);
                        }
                        
                        // All retries exhausted — show manual refresh prompt
                        console.warn('Auth status not yet propagated after retries.');
                        if (elements.authPollStatus) {
                            elements.authPollStatus.innerHTML = '<span style="color:#f59e0b;">Authorization signed! Please refresh the page in a few seconds if the mint wizard hasn\'t appeared.</span>';
                        }
                        
                    }, 4000); // Initial 4s delay for ledger close
                    
                } else if (data.data.expired) {
                    stopAuthPolling();
                    
                    if (elements.authPollStatus) {
                        elements.authPollStatus.innerHTML = '<span style="color:#ef4444;">Request expired. Please try again.</span>';
                    }
                }
                
            } catch (error) {
                console.error('Auth poll error:', error);
            }
        }, 3000);
        
        // Stop after 30 minutes
        setTimeout(stopAuthPolling, 30 * 60 * 1000);
    }

    /**
     * Stop authorization polling
     */
    function stopAuthPolling() {
        if (state.auth.pollInterval) {
            clearInterval(state.auth.pollInterval);
            state.auth.pollInterval = null;
        }
        state.auth.pendingUuid = null;
    }

    /**
     * Show specific authorization state
     */
    function showAuthState(stateName) {
        if (elements.authChecking) {
            elements.authChecking.style.display = stateName === 'checking' ? 'flex' : 'none';
        }
        if (elements.authSuccess) {
            elements.authSuccess.style.display = stateName === 'success' ? 'flex' : 'none';
        }
        if (elements.authConflict) {
            elements.authConflict.style.display = stateName === 'conflict' ? 'flex' : 'none';
        }
        if (elements.authRequired) {
            elements.authRequired.style.display = stateName === 'required' ? 'block' : 'none';
        }
    }

    /**
     * Enable mint wizard (after authorization confirmed)
     */
    function enableMintWizard() {
        if (elements.wizard) {
            elements.wizard.classList.remove('disabled');
            elements.wizard.style.opacity = '1';
            elements.wizard.style.pointerEvents = 'auto';
        }
        updateNavigation();
        updateProgressBar();
    }

    /**
     * Disable mint wizard (until authorized)
     */
    function disableMintWizard() {
        if (elements.wizard) {
            elements.wizard.classList.add('disabled');
            elements.wizard.style.opacity = '0.5';
            elements.wizard.style.pointerEvents = 'none';
        }
    }

    // ================================================================
    // SAVE AS DRAFT — Client-side draft persistence
    // ================================================================
    
    const DRAFT_STORAGE_KEY = 'imc_mint_draft';
    const DRAFT_FILE_DB = 'imc_draft_files';
    const DRAFT_FILE_STORE = 'files';
    const DRAFT_FILE_MAX_SIZE = 50 * 1024 * 1024;  // 50MB per file
    const DRAFT_FILE_TOTAL_CAP = 200 * 1024 * 1024; // 200MB total

    // ── IndexedDB File Store ─────────────────────────────────────────────
    const DraftFileStore = {
        _db: null,

        async open() {
            if (this._db) return this._db;
            return new Promise((resolve, reject) => {
                try {
                    const req = indexedDB.open(DRAFT_FILE_DB, 1);
                    req.onupgradeneeded = (e) => {
                        const db = e.target.result;
                        if (!db.objectStoreNames.contains(DRAFT_FILE_STORE)) {
                            db.createObjectStore(DRAFT_FILE_STORE);
                        }
                    };
                    req.onsuccess = (e) => { this._db = e.target.result; resolve(this._db); };
                    req.onerror = () => reject(req.error);
                } catch (e) { reject(e); }
            });
        },

        async storeFile(key, file) {
            if (!file || file.size > DRAFT_FILE_MAX_SIZE) return false;
            try {
                const db = await this.open();
                const buf = await file.arrayBuffer();
                const record = { buffer: buf, name: file.name, size: file.size, type: file.type, lastModified: file.lastModified };
                return new Promise((resolve, reject) => {
                    const tx = db.transaction(DRAFT_FILE_STORE, 'readwrite');
                    tx.objectStore(DRAFT_FILE_STORE).put(record, key);
                    tx.oncomplete = () => resolve(true);
                    tx.onerror = () => { console.warn('[IMC] IDB store error:', tx.error); resolve(false); };
                });
            } catch (e) { console.warn('[IMC] IDB storeFile error:', e.message); return false; }
        },

        async getFile(key) {
            try {
                const db = await this.open();
                return new Promise((resolve, reject) => {
                    const tx = db.transaction(DRAFT_FILE_STORE, 'readonly');
                    const req = tx.objectStore(DRAFT_FILE_STORE).get(key);
                    req.onsuccess = () => {
                        const r = req.result;
                        if (!r || !r.buffer) { resolve(null); return; }
                        resolve(new File([r.buffer], r.name, { type: r.type, lastModified: r.lastModified }));
                    };
                    req.onerror = () => resolve(null);
                });
            } catch (e) { return null; }
        },

        async deleteFile(key) {
            try {
                const db = await this.open();
                return new Promise((resolve) => {
                    const tx = db.transaction(DRAFT_FILE_STORE, 'readwrite');
                    tx.objectStore(DRAFT_FILE_STORE).delete(key);
                    tx.oncomplete = () => resolve(true);
                    tx.onerror = () => resolve(false);
                });
            } catch (e) { return false; }
        },

        async clearAll() {
            try {
                const db = await this.open();
                return new Promise((resolve) => {
                    const tx = db.transaction(DRAFT_FILE_STORE, 'readwrite');
                    tx.objectStore(DRAFT_FILE_STORE).clear();
                    tx.oncomplete = () => resolve(true);
                    tx.onerror = () => resolve(false);
                });
            } catch (e) { return false; }
        },

        async totalSize() {
            try {
                const db = await this.open();
                return new Promise((resolve) => {
                    const tx = db.transaction(DRAFT_FILE_STORE, 'readonly');
                    const store = tx.objectStore(DRAFT_FILE_STORE);
                    const req = store.openCursor();
                    let total = 0;
                    req.onsuccess = (e) => {
                        const cursor = e.target.result;
                        if (cursor) { total += (cursor.value?.size || 0); cursor.continue(); }
                        else resolve(total);
                    };
                    req.onerror = () => resolve(0);
                });
            } catch (e) { return 0; }
        },

        available() {
            try { return typeof indexedDB !== 'undefined' && !!indexedDB; }
            catch (e) { return false; }
        }
    };

    // ── Helper: Store a file to IDB if eligible, return metadata ──────────
    async function draftStoreFile(key, file, currentTotal) {
        if (!file) return { stored: false };
        const meta = { name: file.name, size: file.size, type: file.type, stored: false };
        if (!DraftFileStore.available()) return meta;
        if (file.size > DRAFT_FILE_MAX_SIZE) return meta; // Too large — metadata only
        if (currentTotal + file.size > DRAFT_FILE_TOTAL_CAP) return meta; // Would exceed cap
        try {
            meta.stored = await DraftFileStore.storeFile(key, file);
        } catch (e) {
            console.warn('[IMC] Draft file store failed for', key, e.message);
        }
        return meta;
    }

    // =========================================================================
    // v24: ALBUM TRACK MANAGEMENT (Changes #17 + #19)
    // =========================================================================

    /**
     * Add a new track card to the album upload section.
     * Appends to #album-tracks-container and pushes to state.album.tracks.
     */
    // ========================================================================
    // M1-e2c: book pages. Natural filename order (page-2 before page-10), deduped,
    // capped, and parity-checked for two-page spreads.
    // ========================================================================
    const MAX_BOOK_PAGES = 1000;
    const MAX_PAGE_BYTES = 10 * 1024 * 1024;

    function naturalCompare(a, b) {
        return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
    }

    // M2-b: ONE picker for images and PDFs together, as the Book Model always
    // specified. Each entry is a SOURCE: an image is one page, a PDF is N pages
    // (read by PDF.js). The manifest expands sources into page slots at publish.
    const MAX_PDF_BYTES = 500 * 1024 * 1024;
    async function addBookPages(fileList) {
        const incoming = Array.from(fileList || [])
            .filter(f => (f.type && f.type.startsWith('image/')) || /\.pdf$/i.test(f.name) || f.type === 'application/pdf')
            .sort((a, b) => naturalCompare(a.name, b.name));
        if (!incoming.length) { imcToast('No page images or PDFs found in that selection.'); return; }
        let added = 0, tooBig = 0, dupes = 0, failed = 0;
        for (let i = 0; i < incoming.length; i++) {
            const f = incoming[i];
            const isPdf = (f.type === 'application/pdf') || /\.pdf$/i.test(f.name);
            if (bookPageTotal() >= MAX_BOOK_PAGES) break;
            if (f.size > (isPdf ? MAX_PDF_BYTES : MAX_PAGE_BYTES)) { tooBig++; continue; }
            if (state.book.pages.some(p => p.name === f.name && p.size === f.size)) { dupes++; continue; }
            let pageCount = 1;
            if (isPdf) {
                try {
                    showLoading('Reading ' + f.name + '...');
                    pageCount = await readPdfPageCount(f);
                } catch (e) {
                    console.error('[M2-b] PDF read failed', e);
                    failed++;
                    continue;
                } finally { hideLoading(); }
            }
            if (bookPageTotal() + pageCount > MAX_BOOK_PAGES) { tooBig++; continue; }
            state.book.pages.push({
                id: 'p_' + Date.now() + '_' + added, name: f.name, size: f.size, file: f,
                kind: isPdf ? 'pdf' : 'image', pageCount: pageCount, masterContentHash: null
            });
            added++;
        }
        renderBookPages();
        let msg = added + ' item' + (added === 1 ? '' : 's') + ' added (' + bookPageTotal() + ' pages)';
        if (dupes)  msg += ', ' + dupes + ' duplicate skipped';
        if (tooBig) msg += ', ' + tooBig + ' too large or over the page cap';
        if (failed) msg += ', ' + failed + ' PDF could not be read';
        imcToast(msg);
    }

    function bookPageTotal() {
        return state.book.pages.reduce(function (n, p) { return n + (p.pageCount || 1); }, 0);
    }

    function removeBookPage(id) {
        state.book.pages = state.book.pages.filter(p => p.id !== id);
        renderBookPages();
    }

    function bookParityNote() {
        const el = document.getElementById('bp-parity-note');
        if (!el) return;
        const n = bookPageTotal();
        if (!n) { el.textContent = ''; el.className = 'bp-parity'; return; }
        if (n % 2 === 0) {
            el.textContent = n + ' pages - spreads pair cleanly.';
            el.className = 'bp-parity ok';
        } else {
            el.textContent = n + ' pages - odd count, so the last page shows alone in two-page view. Add one more to complete the final spread.';
            el.className = 'bp-parity warn';
        }
    }

    function renderBookPages() {
        const list = document.getElementById('bp-pages-list');
        if (!list) return;
        list.innerHTML = '';
        let running = 0;
        state.book.pages.forEach(function (p, i) {
            const span = p.pageCount || 1;
            const first = running + 1;
            running += span;
            const card = document.createElement('div');
            card.className = 'bp-page-card' + (p.kind === 'pdf' ? ' is-pdf' : '');
            card.draggable = true;
            card.dataset.idx = i;
            if (p.kind === 'pdf') {
                const badge = document.createElement('div');
                badge.className = 'bp-pdf-badge';
                badge.textContent = 'PDF';
                card.appendChild(badge);
            } else {
                const img = document.createElement('img');
                img.alt = p.name;
                if (p.file) { img.src = URL.createObjectURL(p.file); }
                card.appendChild(img);
            }
            const num = document.createElement('span');
            num.className = 'bp-page-num';
            num.textContent = (span > 1) ? (first + '-' + (first + span - 1)) : String(first);
            if (p.masterContentHash) num.textContent += ' (uploaded)';
            const rm = document.createElement('button');
            rm.type = 'button'; rm.className = 'bp-page-remove'; rm.textContent = 'x';
            rm.addEventListener('click', function () { removeBookPage(p.id); });
            card.appendChild(num); card.appendChild(rm);
            // M2-b: drag to reorder. Order is changed in state, then re-rendered once.
            card.addEventListener('dragstart', function (e) {
                card.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', String(i));
            });
            card.addEventListener('dragend', function () { card.classList.remove('dragging'); });
            card.addEventListener('dragover', function (e) { e.preventDefault(); card.classList.add('drop-target'); });
            card.addEventListener('dragleave', function () { card.classList.remove('drop-target'); });
            card.addEventListener('drop', function (e) {
                e.preventDefault();
                card.classList.remove('drop-target');
                const from = parseInt(e.dataTransfer.getData('text/plain'), 10);
                const to = i;
                if (isNaN(from) || from === to) return;
                const moved = state.book.pages.splice(from, 1)[0];
                state.book.pages.splice(to, 0, moved);
                renderBookPages();
            });
            list.appendChild(card);
        });
        bookParityNote();
    }
    function handleBookPagesCSV(file) {
        const reader = new FileReader();
        reader.onload = function (e) {
            const rows = String(e.target.result || '').split(/\r?\n/).filter(function (r) { return r.trim(); });
            if (!rows.length) { imcToast('That CSV looks empty.'); return; }
            const start = /^\s*page\s*,/i.test(rows[0]) ? 1 : 0;
            const wanted = [];
            for (let i = start; i < rows.length; i++) {
                const cols = rows[i].split(',').map(function (c) { return c.trim().replace(/^"|"$/g, ''); });
                if (cols.length < 2 || !cols[1]) continue;
                wanted.push({ n: parseInt(cols[0], 10) || (wanted.length + 1), name: cols[1] });
            }
            if (!wanted.length) { imcToast('No usable rows found in that CSV.'); return; }
            const missing = wanted.filter(function (w) { return !state.book.pages.some(function (p) { return p.name === w.name; }); });
            if (missing.length) {
                imcToast('CSV lists ' + missing.length + ' file(s) not added yet, e.g. ' + missing[0].name + '. Add the pages first, then import.');
                return;
            }
            wanted.sort(function (a, b) { return a.n - b.n; });
            state.book.pages = wanted.map(function (w) { return state.book.pages.find(function (p) { return p.name === w.name; }); });
            renderBookPages();
            imcToast('Page order applied from CSV (' + wanted.length + ' pages)');
        };
        reader.readAsText(file);
    }

    // ========================================================================
    // M1-e2b: AudioBook chapter manager. Modelled on the album track manager,
    // but kept entirely separate so the album flow cannot regress.
    // ========================================================================
    function renumberChapters() {
        const c = document.getElementById('audiobook-chapters-container');
        if (c) {
            c.querySelectorAll('.ab-chapter-card').forEach((card, i) => {
                const n = card.querySelector('.ab-chapter-num');
                if (n) n.textContent = String(i + 1);
            });
        }
        const cnt = document.getElementById('ab-chapter-count');
        if (cnt) {
            const total = state.audiobook.chapters.length;
            cnt.textContent = total ? (total + (total === 1 ? ' chapter' : ' chapters')) : '';
        }
    }

    function addChapterCard() {
        if (state.audiobook.chapters.length >= MAX_CHAPTERS) {
            imcToast('Maximum ' + MAX_CHAPTERS + ' chapters per AudioBook');
            return;
        }
        const chapterId = Date.now() + '_' + state.audiobook.chapters.length;
        const num = state.audiobook.chapters.length + 1;
        state.audiobook.chapters.push({
            id: chapterId, title: '', file: null, masterContentHash: null, seconds: null
        });
        const container = document.getElementById('audiobook-chapters-container');
        if (!container) return;
        const card = document.createElement('div');
        card.className = 'ab-chapter-card';
        card.dataset.chapterId = chapterId;
        card.innerHTML = '<div class="ab-chapter-num">' + num + '</div>'
            + '<div class="ab-chapter-body">'
            + '<input type="text" class="ab-chapter-title-input" maxlength="200" placeholder="Chapter title">'
            + '<span class="ab-chapter-file">No file chosen</span>'
            + '<input type="file" accept="audio/*" hidden>'
            + '</div>'
            + '<div class="ab-chapter-actions">'
            + '<button type="button" class="btn-secondary ab-chapter-choose">Choose Audio</button>'
            + '<button type="button" class="btn-remove ab-chapter-remove">&#10005;</button>'
            + '</div>';
        container.appendChild(card);
        const titleInput = card.querySelector('.ab-chapter-title-input');
        const fileInput  = card.querySelector('input[type="file"]');
        titleInput.addEventListener('input', function () {
            const ch = state.audiobook.chapters.find(x => x.id === chapterId);
            if (ch) ch.title = titleInput.value;
        });
        card.querySelector('.ab-chapter-choose').addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', e => handleChapterFileSelect(e, chapterId, card));
        card.querySelector('.ab-chapter-remove').addEventListener('click', () => removeChapter(chapterId, card));
        renumberChapters();
    }

    function removeChapter(chapterId, card) {
        state.audiobook.chapters = state.audiobook.chapters.filter(c => c.id !== chapterId);
        if (card && card.parentNode) card.parentNode.removeChild(card);
        renumberChapters();
    }

    function handleChapterFileSelect(e, chapterId, card) {
        const f = e.target.files && e.target.files[0];
        if (!f) return;
        if (!f.type || !f.type.startsWith('audio/')) { imcToast('Chapters must be audio files.'); return; }
        if (f.size > (FILE_LIMITS.audiobook || FILE_LIMITS.music)) { imcToast('Chapter file is too large (max 1GB).'); return; }
        const ch = state.audiobook.chapters.find(x => x.id === chapterId);
        if (!ch) return;
        ch.file = f;
        ch.masterContentHash = null;
        const label = card.querySelector('.ab-chapter-file');
        if (label) {
            label.textContent = f.name + '  (' + (f.size / 1048576).toFixed(1) + ' MB)';
            label.classList.add('is-set');
        }
        if (!ch.title) {
            const guess = f.name.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').trim();
            const ti = card.querySelector('.ab-chapter-title-input');
            if (ti && guess) { ti.value = guess; ch.title = guess; }
        }
    }

    window.addChapterCard = addChapterCard;

    function addAlbumTrackCard() {
        if (state.album.tracks.length >= MAX_ALBUM_TRACKS) {
            imcToast('Maximum ' + MAX_ALBUM_TRACKS + ' tracks per album — please reduce your track count');
            return;
        }
        const trackId = Date.now() + '_' + state.album.tracks.length;
        const trackNum = state.album.tracks.length + 1;

        // Push to state immediately so validation can find it
        state.album.tracks.push({
            id: trackId,
            title: '',
            file: null,
            previewFile: null,
            previewIpfs: null,
            masterContentHash: null,
            coverIpfs: null,
            duration: null
        });

        const container = document.getElementById('album-tracks-container');
        if (!container) return;

        const card = document.createElement('div');
        card.className = 'album-track-card';
        card.dataset.trackId = trackId;
        card.innerHTML = `
            <div class="album-track-header">
                <span class="album-track-num">Track <strong class="track-number-label">${trackNum}</strong></span>
                <input type="text" class="album-track-title-input" placeholder="Track title (required)"
                    oninput="(function(el){var tid='${trackId}';var t=window._imc_album_track(tid);if(t)t.title=el.value;})(this)">
                <div class="album-track-actions">
                    <button type="button" class="btn-ghost album-track-meta-btn"
                        onclick="openTrackMetadataPopup('${trackId}')"><svg class="imc-ic" aria-hidden="true"><use href="#ic-clipboard"></use></svg> Details</button>
                    <button type="button" class="btn-ghost album-track-remove-btn"
                        onclick="removeAlbumTrack('${trackId}')">✕</button>
                </div>
            </div>
            <div class="album-track-uploads">
                <div class="album-track-upload-zone" id="master-zone-${trackId}">
                    <p class="upload-zone-label"><svg class="imc-ic" aria-hidden="true"><use href="#ic-lock"></use></svg> Master Audio</p>
                    <p class="upload-zone-hint" id="master-hint-${trackId}">MP3, WAV, FLAC (Max 500MB)</p>
                    <input type="file" id="master-input-${trackId}" accept="audio/*" hidden
                        onchange="handleTrackFileSelect('${trackId}','master',this.files[0])">
                    <button type="button" class="btn-secondary btn-sm"
                        onclick="document.getElementById('master-input-${trackId}').click()">Choose Master</button>
                </div>
                <div class="album-track-upload-zone" id="preview-zone-${trackId}">
                    <p class="upload-zone-label">▶️ Preview Clip</p>
                    <p class="upload-zone-hint" id="preview-hint-${trackId}">MP3, WAV, FLAC (Max 30s) — Public on IPFS</p>
                    <input type="file" id="preview-input-${trackId}" accept="audio/*" hidden
                        onchange="handleTrackFileSelect('${trackId}','preview',this.files[0])">
                    <button type="button" class="btn-secondary btn-sm"
                        onclick="document.getElementById('preview-input-${trackId}').click()">Choose Preview</button>
                </div>
            </div>
        `;
        container.appendChild(card);
        renumberTrackCards();
        updateFeeEstimate();
    }

    // Expose track state lookup for inline handlers
    window._imc_album_track = function(trackId) {
        return state.album.tracks.find(t => t.id === trackId) || null;
    };

    /**
     * Remove a track card by trackId — removes from DOM and state.
     */
    function removeAlbumTrack(trackId) {
        // Remove from state
        state.album.tracks = state.album.tracks.filter(t => t.id !== trackId);
        // Remove from DOM
        const card = document.querySelector(`.album-track-card[data-track-id="${trackId}"]`);
        if (card) card.remove();
        renumberTrackCards();
        updateFeeEstimate();
    }
    window.removeAlbumTrack = removeAlbumTrack;

    /**
     * Handle file selection for a track's master or preview slot.
     * Validates size/type, stores File on state, updates UI label.
     */
    function handleTrackFileSelect(trackId, fileType, file) {
        if (!file) return;
        const track = state.album.tracks.find(t => t.id === trackId);
        if (!track) return;

        const maxSize = fileType === 'master' ? FILE_LIMITS.album : FILE_LIMITS.preview;
        if (file.size > maxSize) {
            const maxMB = Math.round(maxSize / 1024 / 1024);
            imcToast('File size exceeds ' + maxMB + 'MB limit — please choose a smaller file');
            return;
        }

        if (fileType === 'master') {
            track.file = file;
            const hint = document.getElementById('master-hint-' + trackId);
            if (hint) hint.textContent = '✅ ' + file.name + ' (' + (file.size / 1024 / 1024).toFixed(1) + 'MB)';
        } else {
            track.previewFile = file;
            // Validate preview duration client-side
            const url = URL.createObjectURL(file);
            const audio = new Audio(url);
            audio.addEventListener('loadedmetadata', () => {
                URL.revokeObjectURL(url);
                if (audio.duration > 30.5) {
                    imcToast('Preview for track "' + (track.title || trackId) + '" exceeds 30 seconds (' + Math.round(audio.duration) + 's) — please trim and re-upload');
                    track.previewFile = null;
                    const hint = document.getElementById('preview-hint-' + trackId);
                    if (hint) hint.textContent = 'MP3, WAV, FLAC (Max 30s) — Public on IPFS';
                    return;
                }
                track.duration = Math.round(audio.duration);
                const hint = document.getElementById('preview-hint-' + trackId);
                if (hint) hint.textContent = '✅ ' + file.name + ' (' + Math.round(audio.duration) + 's)';
            });
        }
    }
    window.handleTrackFileSelect = handleTrackFileSelect;

    /**
     * Renumber track cards after add/remove to keep labels sequential.
     */
    function renumberTrackCards() {
        const cards = document.querySelectorAll('.album-track-card');
        cards.forEach((card, idx) => {
            const label = card.querySelector('.track-number-label');
            if (label) label.textContent = idx + 1;
        });
    }

    // =========================================================================
    // v24: TRACK METADATA POPUP (Change #19)
    // 5-page modal for per-track ISRC, writers, publishers, contributors, IDs
    // =========================================================================

    let _trackPopupId = null;       // trackId currently being edited
    let _trackPopupPage = 1;        // current page (1-5)
    const _POPUP_PAGES = 5;

    /**
     * Open the 5-page track metadata popup for the given track.
     */
    function openTrackMetadataPopup(trackId) {
        const track = state.album.tracks.find(t => t.id === trackId);
        if (!track) return;
        _trackPopupId = trackId;
        _trackPopupPage = 1;
        window._popupPage = 1; // Sync window ref so popup nav buttons start from page 1
        populatePopupFields(track);
        const overlay = document.getElementById('album-track-popup-overlay');
        if (overlay) {
            overlay.style.display = 'flex';
            showPopupPage(1);
        }
    }
    window.openTrackMetadataPopup = openTrackMetadataPopup;

    /**
     * Show a specific page within the popup (1–5).
     */
    function showPopupPage(page) {
        _trackPopupPage = page;
        window._popupPage = page; // v455: Sync with page-mint.php onclick handlers (wrapper was overwritten by this assignment at line 7069)
        for (let i = 1; i <= _POPUP_PAGES; i++) {
            const pg = document.getElementById('track-popup-page-' + i);
            if (pg) pg.style.display = (i === page) ? '' : 'none';
        }
        const prevBtn = document.getElementById('popup-prev-btn');
        const nextBtn = document.getElementById('popup-next-btn');
        const saveBtn = document.getElementById('popup-save-btn');
        if (prevBtn) prevBtn.style.display = page > 1 ? '' : 'none';
        if (nextBtn) nextBtn.style.display = page < _POPUP_PAGES ? '' : 'none';
        if (saveBtn) saveBtn.style.display = page === _POPUP_PAGES ? '' : 'none';
        // Update page indicator
        const indicator = document.getElementById('popup-page-indicator');
        if (indicator) indicator.textContent = page + ' / ' + _POPUP_PAGES;
    }
    window.showPopupPage = showPopupPage;

    /**
     * Populate popup fields from existing track metadata.
     */
    function populatePopupFields(track) {
        const meta = track.meta || {};
        // Page 1 — Basic Track Info
        setVal('popup-track-title', track.title || '');
        setVal('popup-track-genre', meta.genre || '');
        setVal('popup-track-bpm', meta.bpm || '');
        setVal('popup-track-key', meta.key || '');
        setVal('popup-track-explicit', meta.explicit || 'no');
        // Page 2 — Songwriters & Composers
        setVal('popup-writer-name-0', meta.writer_name || '');
        setVal('popup-writer-pro', meta.writer_pro || '');
        setVal('popup-writer-ipi', meta.writer_ipi || '');
        // Page 3 — Publishing & Rights
        setVal('popup-publisher-name', meta.publisher_name || '');
        setVal('popup-publisher-pro', meta.publisher_pro || '');
        setVal('popup-publisher-ipi', meta.publisher_ipi || '');
        setVal('popup-master-owner', meta.master_owner || '');
        // Page 4 — Additional Contributors
        setVal('popup-featured-artist', meta.featured_artist || '');
        setVal('popup-producer', meta.producer || '');
        setVal('popup-engineer', meta.engineer || '');
        // Page 5 — IDs & Identifiers
        setVal('popup-isrc', meta.isrc || '');
        setVal('popup-iswc', meta.iswc || '');

        function setVal(id, val) {
            const el = document.getElementById(id);
            if (el) el.value = val;
        }
    }

    /**
     * Collect popup fields into a metadata object.
     */
    function collectPopupMetadata() {
        function getVal(id) {
            return document.getElementById(id)?.value?.trim() || '';
        }
        return {
            genre:          getVal('popup-track-genre'),
            bpm:            getVal('popup-track-bpm'),
            key:            getVal('popup-track-key'),
            explicit:       getVal('popup-track-explicit'),
            writer_name:    getVal('popup-writer-name-0'),
            writer_pro:     getVal('popup-writer-pro'),
            writer_ipi:     getVal('popup-writer-ipi'),
            publisher_name: getVal('popup-publisher-name'),
            publisher_pro:  getVal('popup-publisher-pro'),
            publisher_ipi:  getVal('popup-publisher-ipi'),
            master_owner:   getVal('popup-master-owner'),
            featured_artist:getVal('popup-featured-artist'),
            producer:       getVal('popup-producer'),
            engineer:       getVal('popup-engineer'),
            isrc:           getVal('popup-isrc'),
            iswc:           getVal('popup-iswc'),
        };
    }

    /**
     * Save popup data back to the track state and close.
     */
    function saveTrackMetadata() {
        const track = _trackPopupId ? state.album.tracks.find(t => t.id === _trackPopupId) : null;
        if (!track) { closeTrackMetadataPopup(); return; }
        // Sync title from popup back to card input and track state
        const titleVal = document.getElementById('popup-track-title')?.value?.trim() || '';
        if (titleVal) {
            track.title = titleVal;
            const cardInput = document.querySelector(`.album-track-card[data-track-id="${_trackPopupId}"] .album-track-title-input`);
            if (cardInput) cardInput.value = titleVal;
        }
        track.meta = collectPopupMetadata();
        closeTrackMetadataPopup();
    }
    window.saveTrackMetadata = saveTrackMetadata;

    function closeTrackMetadataPopup() {
        const overlay = document.getElementById('album-track-popup-overlay');
        if (overlay) overlay.style.display = 'none';
        _trackPopupId = null;
    }
    window.closeTrackMetadataPopup = closeTrackMetadataPopup;

    // Expose addAlbumTrackCard globally for the "Add Track" button onclick
    window.addAlbumTrackCard = addAlbumTrackCard;

    function saveDraft() {
        try {
            // v137: Test localStorage availability
            try {
                localStorage.setItem('__imc_test__', '1');
                localStorage.removeItem('__imc_test__');
            } catch (se) {
                console.error('localStorage unavailable:', se);
                imcToast('Draft saving is not available. Your browser may be in private/incognito mode or storage is full.');
                return false;
            }
            
            const formData = collectFormData();
            
            // v137: Sanitize collection — strip ALL File/Blob/non-serializable values
            const cleanCollection = {
                isNew: !!state.collection.isNew,
                existingId: state.collection.existingId || null,
                existingTaxon: state.collection.existingTaxon || null,
                name: String(state.collection.name || ''),
                description: String(state.collection.description || ''),
                coverIpfs: state.collection.coverIpfs || null,
                taxon: state.collection.taxon || null
                // coverImage (File object) intentionally excluded
            };
            
            // v137: Sanitize tiers — strip ALL File/Blob/non-serializable values
            // v143: Updated tier serialization with master file fields
            const cleanTiers = {
                enabled: !!state.tiers.enabled,
                nextId: state.tiers.nextId || 0,
                items: (state.tiers.items || []).map(tier => ({
                    id: tier.id,
                    name: String(tier.name || ''),
                    editions: Number(tier.editions) || 0,
                    coverIpfs: tier.coverIpfs || null,
                    coverContentHash: tier.coverContentHash || null, // v377
                    masterContentHash: tier.masterContentHash || null,
                    masterPoolRef: tier.masterPoolRef ?? null,       // Pool master reference
                    useDefaultMaster: tier.useDefaultMaster !== false,
                    watermarkable: !!tier.watermarkable,             // Art auto-watermark flag
                    traits: (tier.traits || []).map(t => ({
                        trait_type: String(t.trait_type || ''),
                        value: String(t.value || '')
                    }))
                    // coverFile (File object) intentionally excluded — saved to IDB below
                }))
            };

            // Sanitize masterPool — hashes, labels, and preview CIDs (files saved to IDB below)
            const cleanMasterPool = (state.masterPool || []).map(p => ({
                id: p.id,
                label: String(p.label || ''),
                contentHash: p.contentHash || null,
                previewIpfs: p.previewIpfs || null
            }));
            
            const draft = {
                version: 5,
                savedAt: new Date().toISOString(),
                currentStep: state.currentStep || 0,
                contentType: state.contentType || null,
                licensed: !!state.licensed,
                collection: cleanCollection,
                audiobook: {
                    format: (state.audiobook && state.audiobook.format) || null,
                    chapters: (state.audiobook && state.audiobook.chapters || []).map(function (c) {
                        return { id: c.id, title: c.title || '', masterContentHash: c.masterContentHash || null, seconds: c.seconds || null };
                    })
                },
                book: {
                    pages: (state.book.pages || []).map(function (p) {
                        return { id: p.id, name: p.name, size: p.size, kind: p.kind || 'image',
                                 pageCount: p.pageCount || 1, masterContentHash: p.masterContentHash || null };
                    })
                },
                backCoverIpfs: state.backCoverIpfs || null,
                backCoverContentHash: state.backCoverContentHash || null,
                coverIpfs: state.coverIpfs || null,
                previewIpfs: state.previewIpfs || null,
                masterContentHash: state.masterContentHash || null,
                coverContentHash: state.coverContentHash || null, // v377: Watermarked cover hash
                tiers: cleanTiers,
                masterPool: cleanMasterPool,               // Pool master metadata (files in IDB)
                unlockables: ulUploadedPool().map(p => ({ hash: p.hash, label: p.label, tierOrder: p.tierOrder, size: p.size, name: p.name, status: 'done' })), // U3: stored vault files (bytes already on the VPS)
                formData: formData,
                // v140: Save custom traits separately since their DOM rows need recreation
                customTraits: collectCustomTraits(),
                // v405: Additional state for file restore
                artAutoWatermark: !!state.artAutoWatermark,
                previewMeta: state.previewMeta || {},
                detectedMeta: state.detectedMeta || {},
                editionType: state.editionType || 'fixed',
                mintLimitEnabled: !!state.mintLimitEnabled,          // F6 (Phase 1, 2 Sep 2026): was never in the draft --
                mintLimitPerWallet: Number(state.mintLimitPerWallet) || 0, // resume reset it to OFF, publish read 0/0 from the DOM
                oeEndsAt: state.oeEndsAt || null,
                // v24: Album state — track metadata only (File objects excluded per serialiser below)
                album: state.contentType === 'album' ? {
                    type:   state.album.type || 'ep',
                    tracks: state.album.tracks.map(t => ({
                        id:                 t.id,
                        title:              t.title              || '',
                        previewIpfs:        t.previewIpfs        || null,
                        masterContentHash:  t.masterContentHash  || null,
                        coverIpfs:          t.coverIpfs          || null,
                        duration:           t.duration           || null,
                        meta:               t.meta               || {}
                        // file / previewFile are File objects — excluded by replacer below
                    }))
                } : null,
                // v405: File metadata — updated async after IDB store
                fileMetadata: {}
            };
            
            // v137: Safe JSON.stringify with replacer that catches ANY remaining non-serializable values
            const json = JSON.stringify(draft, function(key, value) {
                if (value instanceof File || value instanceof Blob) return undefined;
                if (value instanceof HTMLElement) return undefined;
                if (typeof value === 'function') return undefined;
                if (value instanceof Event) return undefined;
                return value;
            });
            
            localStorage.setItem(DRAFT_STORAGE_KEY, json);
            
            // v405: Async — store eligible files to IndexedDB, then update metadata in draft
            // v412: Capture the Promise so Preview Collection can await completion
            //       instead of relying on a fixed 600ms timeout (which fails for tier files).
            if (DraftFileStore.available()) {
                window._imcDraftFilesReady = (async () => {
                    try {
                        await DraftFileStore.clearAll(); // Fresh store each save
                        let totalStored = 0;
                        const fileMeta = {};

                        // Global files
                        const fileMap = [
                            ['primaryFile', state.primaryFile],
                            ['previewFile', state.previewFile],
                            ['audioFile', state.audioFile],
                            ['coverFile', state.coverFile],
                            ['backCoverFile', state.backCoverFile],
                            ['collectionCover', state.collection?.coverImage],
                        ];
                        for (const [key, file] of fileMap) {
                            if (!file) continue;
                            const meta = await draftStoreFile(key, file, totalStored);
                            fileMeta[key] = meta;
                            if (meta.stored) totalStored += file.size;
                        }

                        // Tier cover files (keyed by array index for stable rebuild mapping)
                        if (state.tiers.enabled && state.tiers.items) {
                            for (let i = 0; i < state.tiers.items.length; i++) {
                                const tier = state.tiers.items[i];
                                if (tier.coverFile) {
                                    const meta = await draftStoreFile(`tier_${i}_coverFile`, tier.coverFile, totalStored);
                                    fileMeta[`tier_${i}_coverFile`] = meta;
                                    if (meta.stored) totalStored += tier.coverFile.size;
                                }
                            }
                        }

                        // Pool master files (keyed by pool index — replaces tier_N_masterFile)
                        if (state.masterPool && state.masterPool.length > 0) {
                            for (let i = 0; i < state.masterPool.length; i++) {
                                const poolItem = state.masterPool[i];
                                if (poolItem.file) {
                                    const meta = await draftStoreFile(`pool_${i}_masterFile`, poolItem.file, totalStored);
                                    fileMeta[`pool_${i}_masterFile`] = meta;
                                    if (meta.stored) totalStored += poolItem.file.size;
                                }
                                // v481: Save pool preview clip to IDB
                                if (poolItem.previewFile) {
                                    const pmeta = await draftStoreFile(`pool_${i}_previewFile`, poolItem.previewFile, totalStored);
                                    fileMeta[`pool_${i}_previewFile`] = pmeta;
                                    if (pmeta.stored) totalStored += poolItem.previewFile.size;
                                }
                            }
                        }

                        // Update localStorage draft with file metadata
                        const updatedDraft = JSON.parse(localStorage.getItem(DRAFT_STORAGE_KEY) || '{}');
                        updatedDraft.fileMetadata = fileMeta;
                        localStorage.setItem(DRAFT_STORAGE_KEY, JSON.stringify(updatedDraft, function(key, value) {
                            if (value instanceof File || value instanceof Blob) return undefined;
                            return value;
                        }));
                        console.log(`[IMC] Draft files stored: ${Object.keys(fileMeta).filter(k => fileMeta[k].stored).length} files, ${Math.round(totalStored/1024)}KB`);
                    } catch (e) {
                        console.warn('[IMC] Draft file storage error (draft text saved OK):', e.message);
                    }
                })();
            }
            
            // Visual feedback
            const btn = document.getElementById('wizard-save-draft');
            if (btn) {
                const origText = btn.innerHTML;
                btn.innerHTML = '<svg class="imc-ic" aria-hidden="true"><use href="#ic-check-circle"></use></svg> Draft Saved!';
                btn.classList.add('draft-saved');
                setTimeout(() => {
                    btn.innerHTML = origText;
                    btn.classList.remove('draft-saved');
                }, 2000);
            }
            console.log(`[IMC] Draft saved (${Math.round(json.length / 1024)}KB) step=${state.currentStep} type=${state.contentType}`);
            return true;
        } catch (e) {
            console.error('[IMC] Save draft failed:', e.name, e.message, e);
            if (e.name === 'QuotaExceededError' || e.code === 22) {
                imcToast('Cannot save draft — browser storage is full. Try clearing old data.');
            } else {
                imcToast('Could not save draft: ' + (e.message || 'Unknown error') + '\nCheck browser console (F12) for details.');
            }
            return false;
        }
    }

    /* ========================================================================
       v591 Slice 2: METADATA AUTO-SAVE (additive; saveDraft() is unchanged).
       Persists TYPED metadata — tier traits, tier names/editions, collection
       fields, form data — to the SAME draft key on every input, debounced.
       It does NOT write to IndexedDB and PRESERVES the fileMetadata that the
       heavy saveDraft() establishes, so file linkage is never disturbed.
       Mirrors saveDraft()'s metadata serialisation; keep in sync if that
       serialisation changes. Best-effort: errors are swallowed so typing is
       never interrupted.
       ======================================================================== */
    let _metaSaveTimer = null;
    const META_SAVE_DELAY_MS = 1200;

    function buildMetadataDraft(existing) {
        // Start from the existing draft so heavy-save fields (esp. fileMetadata)
        // survive untouched; overwrite only the metadata captured from state.
        const draft = (existing && typeof existing === 'object') ? existing : {};
        draft.version       = 5;
        draft.savedAt       = new Date().toISOString();
        draft.currentStep   = state.currentStep || 0;
        draft.contentType   = state.contentType || null;
        draft.licensed      = !!state.licensed;
        draft.collection = {
            isNew:         !!state.collection.isNew,
            existingId:    state.collection.existingId || null,
            existingTaxon: state.collection.existingTaxon || null,
            name:          String(state.collection.name || ''),
            description:   String(state.collection.description || ''),
            coverIpfs:     state.collection.coverIpfs || null,
            taxon:         state.collection.taxon || null
        };
        draft.coverIpfs         = state.coverIpfs || null;
        draft.previewIpfs       = state.previewIpfs || null;
        draft.masterContentHash = state.masterContentHash || null;
        draft.coverContentHash  = state.coverContentHash || null;
        draft.tiers = {
            enabled: !!state.tiers.enabled,
            nextId:  state.tiers.nextId || 0,
            items: (state.tiers.items || []).map(function(tier) {
                return {
                    id:                tier.id,
                    name:              String(tier.name || ''),
                    editions:          Number(tier.editions) || 0,
                    coverIpfs:         tier.coverIpfs || null,
                    coverContentHash:  tier.coverContentHash || null,
                    masterContentHash: tier.masterContentHash || null,
                    masterPoolRef:     (tier.masterPoolRef != null) ? tier.masterPoolRef : null,
                    useDefaultMaster:  tier.useDefaultMaster !== false,
                    watermarkable:     !!tier.watermarkable,
                    traits: (tier.traits || []).map(function(t) {
                        return { trait_type: String(t.trait_type || ''), value: String(t.value || '') };
                    })
                };
            })
        };
        draft.masterPool = (state.masterPool || []).map(function(p) {
            return { id: p.id, label: String(p.label || ''), contentHash: p.contentHash || null, previewIpfs: p.previewIpfs || null };
        });
        draft.formData     = (typeof collectFormData === 'function') ? collectFormData() : (draft.formData || {});
        draft.customTraits = (typeof collectCustomTraits === 'function') ? collectCustomTraits() : (draft.customTraits || []);
        draft.artAutoWatermark = !!state.artAutoWatermark;
        draft.previewMeta   = state.previewMeta || {};
        draft.detectedMeta  = state.detectedMeta || {};
        draft.editionType   = state.editionType || 'fixed';
        draft.oeEndsAt      = state.oeEndsAt || null;
        draft.album = (state.contentType === 'album' && state.album) ? {
            type: state.album.type || 'ep',
            tracks: (state.album.tracks || []).map(function(t) {
                return {
                    id: t.id, title: t.title || '', previewIpfs: t.previewIpfs || null,
                    masterContentHash: t.masterContentHash || null, coverIpfs: t.coverIpfs || null,
                    duration: t.duration || null, meta: t.meta || {}
                };
            })
        } : (draft.album || null);
        // U3-r2: keep in sync with saveDraft (the docblock law) -- without this,
        // an auto-save-created draft carries masters but silently drops the vault.
        draft.unlockables = ulUploadedPool().map(function(p) {
            return { hash: p.hash, label: p.label, tierOrder: p.tierOrder, size: p.size, name: p.name, status: 'done' };
        });
        if (!draft.fileMetadata) draft.fileMetadata = {}; // PRESERVE existing; default only when absent
        return draft;
    }

    function saveDraftMetadata() {
        try {
            if (!state || !state.contentType) return;           // nothing meaningful started yet
            if (typeof DRAFT_STORAGE_KEY === 'undefined') return;
            let existing = null;
            try { existing = JSON.parse(localStorage.getItem(DRAFT_STORAGE_KEY) || 'null'); } catch (e) { existing = null; }
            const draft = buildMetadataDraft(existing);
            const json = JSON.stringify(draft, function(key, value) {
                if (value instanceof File || value instanceof Blob) return undefined;
                if (value instanceof HTMLElement) return undefined;
                if (typeof value === 'function') return undefined;
                return value;
            });
            localStorage.setItem(DRAFT_STORAGE_KEY, json);
        } catch (e) {
            // Best-effort only — never interrupt the artist's typing.
            console.warn('[IMC] metadata auto-save skipped:', e && e.message);
        }
    }

    function scheduleMetadataSave() {
        if (_metaSaveTimer) clearTimeout(_metaSaveTimer);
        _metaSaveTimer = setTimeout(saveDraftMetadata, META_SAVE_DELAY_MS);
    }

    // Delegated listeners catch dynamically-added trait rows AND every wizard field
    // without re-wiring individual handlers. Capture phase = robust to stopPropagation.
    document.addEventListener('input', scheduleMetadataSave, true);
    document.addEventListener('change', scheduleMetadataSave, true);

    // Flush any pending metadata on tab-hide / unload so a refresh can't lose the last
    // edits. Separate from the existing _beforeUnloadGuard (mint-in-progress warning).
    window.addEventListener('beforeunload', function() {
        if (_metaSaveTimer) { clearTimeout(_metaSaveTimer); _metaSaveTimer = null; saveDraftMetadata(); }
    });
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'hidden' && _metaSaveTimer) {
            clearTimeout(_metaSaveTimer); _metaSaveTimer = null; saveDraftMetadata();
        }
    });

    function loadDraft() {
        try {
            const raw = localStorage.getItem(DRAFT_STORAGE_KEY);
            if (!raw) return null;
            const draft = JSON.parse(raw);
            if (!draft || !draft.version) return null;
            if (draft.version === 4) {
                // C-α migration: old 10-step numbering → new 7-step spine
                var O2N = {0:1, 1:0, 2:2, 3:3, 4:3, 5:3, 6:3, 7:3, 8:4, 9:5};
                draft.currentStep = (draft.currentStep in O2N) ? O2N[draft.currentStep] : 2;
                draft.version = 5;
            }
            return draft;
        } catch (e) {
            return null;
        }
    }

    function resumeDraft(draft) {
        if (!draft || !draft.formData) return;
        
        // Restore state
        state.contentType = draft.contentType || null;
        state.licensed = draft.licensed || false;
        state.collection = draft.collection || {};
        // M1-e1b: restore AudioBook sub-type + back cover references.
        state.audiobook = {
            format: (draft.audiobook && draft.audiobook.format) || null,
            chapters: (draft.audiobook && draft.audiobook.chapters || []).map(function (c) {
                return { id: c.id || (Date.now() + '_' + Math.random()), title: c.title || '',
                         file: null, masterContentHash: c.masterContentHash || null, seconds: c.seconds || null };
            })
        };
        state.backCoverIpfs = draft.backCoverIpfs || null;
        state.book = {
            pages: (draft.book && draft.book.pages || []).map(function (p) {
                return { id: p.id, name: p.name, size: p.size, file: null, kind: p.kind || 'image',
                         pageCount: p.pageCount || 1, masterContentHash: p.masterContentHash || null };
            })
        };
        if (state.book.pages.length) {
            renderBookPages();
        }
        state.backCoverContentHash = draft.backCoverContentHash || null;
        if (state.audiobook.format) {
            const _abR = document.getElementById('ab-format-' + state.audiobook.format);
            if (_abR) _abR.checked = true;
        }
        // M1-e2b: rebuild chapter cards from the restored draft (files re-attach).
        if (state.audiobook.chapters.length) {
            const _c = document.getElementById('audiobook-chapters-container');
            if (_c) {
                const restored = state.audiobook.chapters.slice();
                _c.innerHTML = '';
                state.audiobook.chapters = [];
                restored.forEach(function (ch) {
                    addChapterCard();
                    const added = state.audiobook.chapters[state.audiobook.chapters.length - 1];
                    added.title = ch.title;
                    added.masterContentHash = ch.masterContentHash;
                    added.seconds = ch.seconds;
                    const card = _c.lastElementChild;
                    if (card) {
                        const ti = card.querySelector('.ab-chapter-title-input');
                        if (ti) ti.value = ch.title || '';
                        const fl = card.querySelector('.ab-chapter-file');
                        if (fl) fl.textContent = ch.masterContentHash ? 'Already uploaded' : 'Re-attach audio file';
                    }
                });
                renumberChapters();
            }
        }
        state.coverIpfs = draft.coverIpfs || null;
        state.previewIpfs = draft.previewIpfs || null;
        state.masterContentHash = draft.masterContentHash || null;
        state.coverContentHash = draft.coverContentHash || null; // v377
        if (draft.tiers) state.tiers = draft.tiers;
        // Restore master pool metadata (hashes, labels, preview CIDs — files restored from IDB below)
        if (draft.unlockables && Array.isArray(draft.unlockables)) {
            state.unlockables.pool = draft.unlockables.filter(p => p && p.hash).map(p => ({ ...p, status: 'done', progress: 100 }));
            if (typeof ulRenderPool === 'function') ulRenderPool();
        }
        if (draft.masterPool && Array.isArray(draft.masterPool)) {
            state.masterPool = draft.masterPool.map(p => ({ ...p, file: null, previewFile: null }));
        }
        // v405: Restore additional state for file context
        state.artAutoWatermark = draft.artAutoWatermark || false;
        if (draft.previewMeta) state.previewMeta = draft.previewMeta;
        if (draft.detectedMeta) state.detectedMeta = draft.detectedMeta;
        if (draft.editionType) state.editionType = draft.editionType;
        // F6 (Phase 1): restore the mint limit into BOTH state and the DOM -- collectPricingData reads the DOM (v566).
        if (typeof draft.mintLimitEnabled !== 'undefined') {
            state.mintLimitEnabled   = !!draft.mintLimitEnabled;
            state.mintLimitPerWallet = Number(draft.mintLimitPerWallet) || 0;
            const _mlE = document.getElementById('mint-limit-enabled'); if (_mlE) _mlE.checked = state.mintLimitEnabled;
            const _mlP = document.getElementById('mint-limit-per-wallet'); if (_mlP && state.mintLimitPerWallet > 0) _mlP.value = state.mintLimitPerWallet;
        }
        if (draft.oeEndsAt) state.oeEndsAt = draft.oeEndsAt;
        // v24: Restore album state (track metadata — files are not stored in draft)
        if (draft.album && draft.contentType === 'album') {
            state.album.type   = draft.album.type   || 'ep';
            state.album.tracks = (draft.album.tracks || []).map(t => ({
                ...t,
                file:        null, // File objects cannot be serialised — must re-upload
                previewFile: null
            }));

            // v461: Rebuild DOM track cards (same pattern as tier rebuild at ~7476).
            // Without this, ghost tracks sit in state with file:null and no DOM card,
            // causing validation to fail on invisible tracks the artist can't see.
            if (state.album.tracks.length > 0) {
                const savedTracks = JSON.parse(JSON.stringify(state.album.tracks));
                state.album.tracks = [];
                const trackContainer = document.getElementById('album-tracks-container');
                if (trackContainer) trackContainer.innerHTML = '';

                savedTracks.forEach(saved => {
                    addAlbumTrackCard(); // creates DOM card + pushes new state entry
                    const newTrack = state.album.tracks[state.album.tracks.length - 1];
                    if (newTrack) {
                        // Merge saved metadata into the new state item
                        newTrack.title              = saved.title              || '';
                        newTrack.previewIpfs         = saved.previewIpfs        || null;
                        newTrack.masterContentHash   = saved.masterContentHash  || null;
                        newTrack.coverIpfs           = saved.coverIpfs          || null;
                        newTrack.duration            = saved.duration           || null;
                        newTrack.meta                = saved.meta               || {};

                        // Restore title input
                        const card = document.querySelector(`.album-track-card[data-track-id="${newTrack.id}"]`);
                        if (card) {
                            const titleInput = card.querySelector('.album-track-title-input');
                            if (titleInput && newTrack.title) titleInput.value = newTrack.title;
                        }

                        // Update hints to show re-upload status
                        const masterHint = document.getElementById('master-hint-' + newTrack.id);
                        if (masterHint) {
                            masterHint.textContent = newTrack.masterContentHash
                                ? 'Previously uploaded — ready'
                                : '⚠️ Master file needs re-upload';
                        }
                        const previewHint = document.getElementById('preview-hint-' + newTrack.id);
                        if (previewHint) {
                            previewHint.textContent = newTrack.previewIpfs
                                ? '✅ Previously uploaded'
                                : '⚠️ Preview clip needs re-upload';
                        }
                    }
                });
            }
        }

        // Restore content type selection visual
        if (state.contentType) {
            elements.contentTypeCards?.forEach(card => {
                card.classList.toggle('selected', card.dataset.type === state.contentType);
            });
            // M3: a saved draft must reopen its group, or the marked card stays hidden.
            var _dg = groupForType(state.contentType);
            if (_dg) { openContentGroup(_dg); }
        }

        // Restore licensed toggle
        if (state.contentType === 'film') {
            const filmRadio = document.querySelector(`input[name="film_licensed"][value="${draft.licensed ? 'yes' : 'no'}"]`);
            if (filmRadio) filmRadio.checked = true;
        } else if (state.contentType === 'album') {
            // v24: Restore album_licensed radio
            const albumRadio = document.querySelector(`input[name="album_licensed"][value="${draft.licensed ? 'yes' : 'no'}"]`);
            if (albumRadio) albumRadio.checked = true;
            // Restore album_type radio
            const albumTypeRadio = document.querySelector(`input[name="album_type"][value="${state.album.type || 'ep'}"]`);
            if (albumTypeRadio) albumTypeRadio.checked = true;
        } else {
            const musicRadio = document.querySelector(`input[name="music_licensed"][value="${draft.licensed ? 'yes' : 'no'}"]`);
            if (musicRadio) musicRadio.checked = true;
        }
        
        // Restore all form fields from formData
        // v139: Use CSS.escape() + try-catch to prevent crash on special chars like genres[]
        const fd = draft.formData;
        Object.keys(fd).forEach(key => {
            try {
                // Skip array checkbox fields (name ends with [])
                if (key.includes('[')) {
                    if (Array.isArray(fd[key])) {
                        fd[key].forEach(val => {
                            try {
                                const cb = document.querySelector(`input[type="checkbox"][name="${CSS.escape(key)}"][value="${CSS.escape(val)}"]`);
                                if (cb) cb.checked = true;
                            } catch(ignore) {}
                        });
                    }
                    return;
                }
                const el = document.querySelector(`[name="${CSS.escape(key)}"]`)
                        || document.getElementById(key)
                        || document.getElementById('nft-' + key);
                if (el) {
                    if (el.type === 'checkbox') {
                        el.checked = fd[key] === 'on' || fd[key] === true;
                    } else if (el.type === 'radio') {
                        const radio = document.querySelector(`input[name="${CSS.escape(key)}"][value="${CSS.escape(String(fd[key]))}"]`);
                        if (radio) radio.checked = true;
                    } else {
                        el.value = fd[key];
                    }
                }
            } catch (e) {
                console.warn('[IMC] Draft restore skipped field "' + key + '":', e.message);
            }
        });
        
        // Restore collection fields
        if (draft.collection.name) {
            const nameEl = document.getElementById('collection-name');
            if (nameEl) nameEl.value = draft.collection.name;
        }
        if (draft.collection.taxon !== undefined) {
            const taxonEl = document.getElementById('collection-taxon');
            if (taxonEl) taxonEl.value = draft.collection.taxon;
        }
        
        updateFieldVisibility();
        updateUploadLabels();
        
        // M3 fix: Explicitly restore description (textarea may not update while hidden)
        if (draft.formData?.description) {
            const descEl = document.getElementById('nft-description');
            if (descEl) descEl.value = draft.formData.description;
        }
        
        // v140: Restore custom traits by recreating DOM rows
        if (draft.customTraits && Array.isArray(draft.customTraits) && draft.customTraits.length > 0) {
            // Clear any existing trait rows first
            const traitsList = document.getElementById('custom-traits-list');
            if (traitsList) traitsList.innerHTML = '';
            state.counters.customTraits = 0;
            
            draft.customTraits.forEach(trait => {
                addCustomTrait(String(trait.trait_type || ''), String(trait.value || ''));
            });
        }
        
        // v142: Restore tier cards from saved tier data
        if (state.tiers.enabled && state.tiers.items && state.tiers.items.length >= 2) {
            // Save tier data, then clear state so addTierCard can rebuild
            const savedTiers = JSON.parse(JSON.stringify(state.tiers.items));
            state.tiers.items = [];
            state.tiers.nextId = 0;
            
            // Clear DOM container
            const tierContainer = document.getElementById('tier-cards-container');
            if (tierContainer) tierContainer.innerHTML = '';
            
            // Toggle the tier mode radio to show tier section
            const tiersRadio = document.querySelector('input[name="tier_mode"][value="tiers"]');
            if (tiersRadio) {
                tiersRadio.checked = true;
                tiersRadio.dispatchEvent(new Event('change', { bubbles: true }));
            }
            
            // Rebuild each tier card (addTierCard creates DOM + pushes new state item)
            savedTiers.forEach(savedTier => {
                addTierCard(savedTier.name || '', savedTier.editions || 1);
                
                // Merge saved data back into the newly created state item
                const newTier = state.tiers.items[state.tiers.items.length - 1];
                if (newTier) {
                    newTier.coverIpfs = savedTier.coverIpfs || null;
                    newTier.coverContentHash = savedTier.coverContentHash || null; // v377
                    // v143/pool: Restore master content hash and pool reference
                    newTier.masterContentHash = savedTier.masterContentHash || null;
                    newTier.useDefaultMaster = savedTier.useDefaultMaster !== false;
                    
                    // Show IPFS cover preview if available
                    if (newTier.coverIpfs) {
                        const previewDiv = document.getElementById(`tier-cover-preview-${newTier.id}`);
                        const previewImg = document.getElementById(`tier-cover-img-${newTier.id}`);
                        const dropzone = document.getElementById(`tier-cover-drop-${newTier.id}`);
                        if (previewDiv && previewImg) {
                            previewImg.src = `https://<your-pinata-gateway>/ipfs/${newTier.coverIpfs.replace(/^ipfs:\/\//, '')}`;
                            previewDiv.style.display = 'flex';
                            if (dropzone) dropzone.style.display = 'none';
                        }
                    }
                    
                    // Restore pool masterPoolRef + watermarkable + pool select UI
                    newTier.masterPoolRef = savedTier.masterPoolRef ?? null;
                    newTier.watermarkable = !!savedTier.watermarkable;
                    if (newTier.masterPoolRef !== null) {
                        const poolSel = document.querySelector(`#tier-card-${newTier.id} .tier-pool-select`);
                        if (poolSel) {
                            poolSel.value = String(newTier.masterPoolRef);
                            const pi = state.masterPool.find(p => p.id === newTier.masterPoolRef);
                            const nm = document.getElementById(`tier-pool-name-${newTier.id}`);
                            if (nm && pi) nm.textContent = `\u2705 ${pi.label}`;
                        }
                    }
                    if (newTier.watermarkable) {
                        const wm = document.getElementById(`tier-wm-notice-${newTier.id}`);
                        if (wm) wm.style.display = 'block';
                    }
                    
                    // Rebuild tier traits
                    if (savedTier.traits && savedTier.traits.length > 0) {
                        newTier.traits = []; // Clear default empty traits
                        savedTier.traits.forEach(trait => {
                            addTierTrait(newTier.id);
                            const traitIdx = newTier.traits.length - 1;
                            if (newTier.traits[traitIdx]) {
                                newTier.traits[traitIdx].trait_type = trait.trait_type || '';
                                newTier.traits[traitIdx].value = String(trait.value || '');
                            }
                            // Set input values in DOM
                            const row = document.getElementById(`tier-trait-${newTier.id}-${traitIdx}`);
                            if (row) {
                                const nameInput = row.querySelector('[data-field="trait_type"]');
                                const valueInput = row.querySelector('[data-field="value"]');
                                if (nameInput) nameInput.value = trait.trait_type || '';
                                if (valueInput) valueInput.value = String(trait.value || '');
                            }
                        });
                    }
                }
            });
        }
        
        // v405: Restore files from IndexedDB (async — UI updates after page renders)
        const fileMeta = draft.fileMetadata || {};
        const hasStoredFiles = Object.values(fileMeta).some(m => m && m.stored);
        
        if (hasStoredFiles && DraftFileStore.available()) {
            (async () => {
                try {
                    // Global files
                    const primaryFile = await DraftFileStore.getFile('primaryFile');
                    if (primaryFile) {
                        state.primaryFile = primaryFile;
                        if (state.contentType === 'art') {
                            // Art master uses dedicated zone
                            const nameEl = document.getElementById('art-master-filename');
                            const sizeEl = document.getElementById('art-master-filesize');
                            if (nameEl) nameEl.textContent = primaryFile.name;
                            if (sizeEl) sizeEl.textContent = formatFileSize(primaryFile.size);
                            const artDrop = document.getElementById('art-master-dropzone');
                            const artPrev = document.getElementById('art-master-preview');
                            if (artDrop) artDrop.style.display = 'none';
                            if (artPrev) artPrev.style.display = 'flex';
                            state.artAutoWatermark = isWatermarkable(primaryFile.name);
                            // Toggle cover visibility based on watermark
                            if (state.artAutoWatermark) {
                                const cd = document.getElementById('cover-dropzone');
                                const cp = document.getElementById('cover-preview');
                                const wn = document.getElementById('art-watermark-notice');
                                if (cd) cd.style.display = 'none';
                                if (cp) cp.style.display = 'none';
                                if (wn) wn.style.display = 'block';
                            }
                        } else {
                            showFilePreview('primary', primaryFile);
                            detectMediaProperties(primaryFile);
                        }
                    }

                    const previewFile = await DraftFileStore.getFile('previewFile');
                    if (previewFile) {
                        state.previewFile = previewFile;
                        showFilePreview('preview', previewFile);
                        detectPreviewDuration(previewFile);
                    }

                    const audioFile = await DraftFileStore.getFile('audioFile');
                    if (audioFile) {
                        state.audioFile = audioFile;
                        showFilePreview('audio', audioFile);
                    }

                    const backCoverFile = await DraftFileStore.getFile('backCoverFile');
                    if (backCoverFile) { state.backCoverFile = backCoverFile; }
                    const coverFile = await DraftFileStore.getFile('coverFile');
                    if (coverFile) {
                        state.coverFile = coverFile;
                        showFilePreview('cover', coverFile);
                        updateCoverPreview(coverFile);
                    }

                    const collCover = await DraftFileStore.getFile('collectionCover');
                    if (collCover) {
                        state.collection.coverImage = collCover;
                        const reader = new FileReader();
                        reader.onload = (ev) => {
                            const prev = document.getElementById('collection-cover-preview');
                            if (prev) { prev.innerHTML = `<img src="${ev.target.result}" alt="Collection cover">`; prev.style.display = 'block'; }
                            const drop = document.getElementById('collection-cover-dropzone');
                            if (drop) drop.style.display = 'none';
                        };
                        reader.readAsDataURL(collCover);
                    }

                    // Tier files (keyed by array index matching rebuild order)
                    if (state.tiers.enabled && state.tiers.items) {
                        for (let i = 0; i < state.tiers.items.length; i++) {
                            const tier = state.tiers.items[i];

                            const tierCover = await DraftFileStore.getFile(`tier_${i}_coverFile`);
                            if (tierCover) {
                                tier.coverFile = tierCover;
                                tier.coverIpfs = null;
                                const tReader = new FileReader();
                                tReader.onload = (ev) => {
                                    const img = document.getElementById(`tier-cover-img-${tier.id}`);
                                    if (img) img.src = ev.target.result;
                                    const dp = document.getElementById(`tier-cover-drop-${tier.id}`);
                                    const pp = document.getElementById(`tier-cover-preview-${tier.id}`);
                                    if (dp) dp.style.display = 'none';
                                    if (pp) pp.style.display = 'flex';
                                };
                                tReader.readAsDataURL(tierCover);
                            }

                        // Pool master files are restored after tier loop (see below)
                        }
                    }

                    // Restore pool master files (try pool_N first, fall back to tier_N for pre-v480 drafts)
                    if (state.masterPool && state.masterPool.length > 0) {
                        for (let i = 0; i < state.masterPool.length; i++) {
                            const poolItem = state.masterPool[i];
                            let poolFile = await DraftFileStore.getFile(`pool_${i}_masterFile`);
                            if (!poolFile) poolFile = await DraftFileStore.getFile(`tier_${i}_masterFile`);
                            if (poolFile) {
                                poolItem.file = poolFile;
                                console.log(`[IMC] Pool master ${i} restored: ${poolFile.name}`);
                            }
                            // v481: Restore pool preview clip
                            const poolPreview = await DraftFileStore.getFile(`pool_${i}_previewFile`);
                            if (poolPreview) {
                                poolItem.previewFile = poolPreview;
                                console.log(`[IMC] Pool preview ${i} restored: ${poolPreview.name}`);
                            }
                        }
                        renderPoolZones();
                    }

                    console.log('[IMC] Draft files restored from IndexedDB');
                } catch (e) {
                    console.warn('[IMC] Draft file restore error (form data OK):', e.message);
                }
            })();
        }

        // v405: Show "re-upload required" banners for files that were too large to store
        Object.entries(fileMeta).forEach(([key, meta]) => {
            if (meta && !meta.stored && meta.name) {
                console.log(`[IMC] Draft file "${key}" needs re-upload: ${meta.name} (${formatFileSize(meta.size)})`);
            }
        });
        
        // v405: Restore OE edition type state (radio is set by formData but state needs explicit restore)
        if (draft.editionType && typeof applyOEEditionType === 'function') {
            try { applyOEEditionType(draft.editionType); } catch(e) {}
        }

        // Navigate to the step they were on (or upload step if files lost)
        // v405: Fixed fallback from step 2 → step 8 (upload step moved in wizard rearrange)
        const hasFiles = hasStoredFiles || state.coverIpfs || state.primaryFile || state.coverFile;
        const targetStep = (draft.currentStep > 4 && !hasFiles) ? 4 : draft.currentStep;
        goToStep(targetStep);
    }

    function clearDraft() {
        localStorage.removeItem(DRAFT_STORAGE_KEY);
        // v405: Also clear IndexedDB file store
        if (DraftFileStore.available()) {
            DraftFileStore.clearAll().catch(() => {});
        }
    }

    function checkForDraft() {
        const draft = loadDraft();
        if (!draft) {
            // v405: Orphan cleanup — if no localStorage draft exists, clear any stale IndexedDB files
            if (DraftFileStore.available()) {
                DraftFileStore.clearAll().catch(() => {});
            }
            return;
        }
        
        const savedDate = new Date(draft.savedAt);
        const age = Date.now() - savedDate.getTime();
        const maxAge = 7 * 24 * 60 * 60 * 1000; // 7 days
        
        if (age > maxAge) {
            clearDraft();
            return;
        }
        
        const typeName = draft.contentType === 'musicVideo' ? 'Music Video' :
                        draft.contentType === 'art' ? 'Art' :
                        draft.contentType === 'film' ? 'Film' :
                        draft.contentType === 'music' ? 'Music' : 'NFT';
        // PP-6 P1: defensive — a malformed date or missing text node must never
        // silently kill the banner. The banner reveal is the one thing that MUST run.
        let timeAgo = '';
        try { timeAgo = formatTimeAgo(savedDate); } catch (e) { timeAgo = 'earlier'; }
        
        // Show draft resume banner
        const banner = document.getElementById('draft-resume-banner');
        if (banner) {
            try {
                banner.querySelector('.draft-info-text').textContent = 
                    `You have a saved ${typeName} Access draft from ${timeAgo}`;
            } catch (e) { /* text is decoration; the reveal below is the function */ }
            banner.style.display = 'flex';
            banner.style.visibility = 'visible'; // fix 2: the PHP gate set visibility, JS must clear it
            // P1 fix 3 (per ruling): dynamically push the banner below the sticky/fixed
            // sub-header bar if it overlaps -- measured, not assumed, so any header CSS
            // (fixed, sticky, collapsed) is handled.
            try {
                // U3-r3: the one-shot measurement raced the wizard state -- at reveal
                // (+500ms) the sticky bar is display:none (it only shows under
                // body.wizard-active), measures as a zero rect, and no push is applied;
                // entering the wizard then slides the banner under the now-sticky bar.
                // The measurer is now a re-armed function: reveal, layout settle,
                // resize, and a body-class observer so the wizard-active flip itself
                // repositions. Margin resets before each measure so pushes never
                // compound. Targets the bar by id (the [class*="msb"] selector could
                // match child spans first).
                const positionDraftBanner = function() {
                    if (!banner || banner.style.display === 'none') return;
                    const bar = document.getElementById('mint-sticky-bar')
                        || document.querySelector('.mint-sticky-bar, .wizard-header');
                    if (!bar) return;
                    const cs = getComputedStyle(bar);
                    banner.style.marginTop = ''; // measure the natural position
                    if (cs.display === 'none') return; // bar not in play in this screen state
                    if (cs.position !== 'fixed' && cs.position !== 'sticky') return;
                    const bRect = banner.getBoundingClientRect();
                    const hRect = bar.getBoundingClientRect();
                    if (hRect.height > 0 && bRect.top < hRect.bottom) {
                        banner.style.marginTop = Math.ceil(hRect.bottom - bRect.top + 12) + 'px';
                    }
                };
                requestAnimationFrame(positionDraftBanner);
                setTimeout(positionDraftBanner, 350);
                window.addEventListener('resize', positionDraftBanner, { passive: true });
                if (typeof MutationObserver === 'function') {
                    new MutationObserver(positionDraftBanner)
                        .observe(document.body, { attributes: true, attributeFilter: ['class'] });
                }
            } catch (e) { /* positioning is enhancement; the banner stays visible regardless */ }
        }
    }

    function formatTimeAgo(date) {
        const diff = Date.now() - date.getTime();
        const mins = Math.floor(diff / 60000);
        const hrs = Math.floor(diff / 3600000);
        const days = Math.floor(diff / 86400000);
        if (days > 0) return `${days} day${days > 1 ? 's' : ''} ago`;
        if (hrs > 0) return `${hrs} hour${hrs > 1 ? 's' : ''} ago`;
        if (mins > 0) return `${mins} minute${mins > 1 ? 's' : ''} ago`;
        return 'just now';
    }

    // Wire up draft on init
    const origInit = init;
    function initWithDraft() {
        // Check for saved draft after DOM ready
        setTimeout(() => checkForDraft(), 500);
    }
    document.addEventListener('DOMContentLoaded', initWithDraft);

    // v405: clearDraft() now called in handleMint success path (line ~4855)
    
    // Expose draft functions
    window.saveMintDraft = saveDraft;
    window.resumeMintDraft = function() {
        const draft = loadDraft();
        if (draft) {
            resumeDraft(draft);
            const banner = document.getElementById('draft-resume-banner');
            if (banner) banner.style.display = 'none';
        }
    };
    window.discardMintDraft = function() {
        clearDraft();
        const banner = document.getElementById('draft-resume-banner');
        if (banner) banner.style.display = 'none';
    };

    // Expose authorization functions for debugging
    window.checkAuthorizationStatus = checkAuthorizationStatus;
    window.authState = state.auth;
    window.goToStep = goToStep;

})();
/* ============================================================================
   v409: INFO TIPS (ℹ️) + STEP GUIDE (📖)
   Self-contained — no existing functions or state modified
   ============================================================================ */
(function() {
    'use strict';

    // ── Tip definitions: [targetSelector, tipText] ─────────────────────────
    // Each entry injects an ℹ️ button after the first matching label/heading
    const TIPS = [
        // Step 0 — Collection
        ['label[for="collection-name"]', 'The name for your group of NFTs — like an album name for music, a series for art, or a project title. Buyers browse and search by collection name.'],
        ['label[for="collection-taxon"]', 'A unique number on the XRP Ledger that groups your NFTs together on-chain. Pick any number — it just needs to be different from your other collections.'],
        ['label[for="collection-description"]', 'Tell potential buyers what this collection is about. This appears on the collection browse page and helps with discoverability.'],
        ['#collection-image-upload', 'The main image shown when browsing collections. Square (1:1) works best. This is the collection thumbnail, not the individual NFT artwork.', 'before'],

        // Step 2 — NFT Info
        ['label[for="nft-title"]', 'The name of this specific NFT. For music, try: "Song Title" Access Pass. This is what appears on all XRPL marketplaces and in wallets.'],
        ['label[for="nft-description"]', 'Describe what the buyer gets — mention streaming access, exclusivity, and what makes this NFT special. Shown on marketplaces and wallet apps.'],
        ['.streaming-access-flag .content-flag-label', 'Permanently embedded in your NFT metadata. Tells IMUTV, IMUP3, and all IMU apps that this NFT grants access to the attached master file.'],
        ['.explicit-flag .content-flag-label', 'Check if your content has explicit language, themes, or imagery. This flag is shown on marketplaces and used for content filtering.'],
        ['.custom-traits-section > h3', 'Traits are key-value pairs shown on marketplaces (Bithomp, xrp.cafe). Genre, mood, rarity — anything that describes your NFT.'],
        ['.ai-section > h3', 'Required: transparency about AI usage protects both creators and buyers. Specify which elements were AI-generated vs human-created.'],
        ['.licensed-toggle-section.music-only-section > h3', 'If your music is registered with a P.R.O (ASCAP, BMI, PRS), select Yes to unlock full industry metadata. If not, skip straight to upload.'],
        ['.licensed-toggle-section.film-only-section > h3', 'If your film is registered, licensed, or has distribution rights, select Yes to unlock full metadata fields. If not, skip to upload.'],
        ['.art-subtype-section > h3', 'Is this born-digital artwork (created on a computer) or a digitised version of a physical piece? Helps buyers understand the provenance.'],
        ['.art-copyright-section > h3', 'If your artwork is formally registered with a copyright office, enter those details here. Permanently embedded in the NFT metadata.'],

        // Step 3 — Track Info (Music)
        ['label[for="nft-genre"]', 'Primary music genre — used for categorisation on streaming platforms and marketplace discovery.'],
        ['label[for="nft-subgenre"]', 'More specific genre classification. Helps with discovery and playlist placement.'],
        ['label[for="nft-language"]', 'Primary language of vocals. Select Instrumental if there are no vocals.'],
        ['label[for="nft-bpm"]', 'Beats per minute — the tempo of your track. Used by DJs and playlist curators.'],
        ['label[for="nft-key"]', 'The musical key signature. Useful for DJs and producers who want to mix your track.'],
        ['label[for="nft-duration"]', 'Track length in seconds. Embedded in the NFT metadata.'],
        ['label[for="nft-lyrics"]', 'Full song lyrics stored in the NFT metadata. Can be displayed by compatible players.'],
        ['label[for="nft-video-type"]', 'Official music video, lyric video, live performance, etc. Categorises the video content.'],
        // Step 3 — Film Descriptive
        ['label[for="film-logline"]', 'One-sentence summary — the elevator pitch that hooks potential viewers.'],
        ['label[for="film-classification"]', 'Feature, short, documentary, series episode, etc. Categorises your content for discovery.'],
        ['label[for="film-rating"]', 'G, PG, PG-13, R, etc. Used for content filtering and parental controls on IMUTV.'],

        // Step 4 — Credits (Music)
        ['#step3-music + .form-grid + .credits-section h3', 'Main performing artist(s) credited on this release. At least one required.'],
        // Step 4 — Film Admin
        ['label[for="film-production-company"]', 'The company that produced the film. Required for proper rights attribution.'],
        ['label[for="film-isan"]', 'International Standard Audiovisual Number — the global unique identifier for audiovisual works.'],
        ['label[for="film-territories"]', 'Geographic regions where you hold distribution rights. Important for streaming rights management.'],
        ['label[for="film-license-type"]', 'What streaming rights you are granting with this NFT. Permanently embedded in the metadata.'],

        // Step 5 — Publishing
        ['.imc-tabpane[data-tab="2"] h3:first-of-type', 'Music publishers manage the business side of your compositions. If you self-publish, you are your own publisher.'],

        // Step 6 — Rights
        ['.imc-tabpane[data-tab="3"] h3:first-of-type', 'What rights come with this NFT? Personal use, commercial license, sync rights — declare it here for buyer clarity.'],

        // Step 7 — IDs
        ['label[for="nft-isrc"]', 'International Standard Recording Code — unique identifier for your audio recording. Get one from your distributor or national ISRC agency.'],
        ['label[for="nft-iswc"]', 'International Standard Musical Work Code — identifies the composition (lyrics + melody). Assigned by your P.R.O.'],
        ['label[for="nft-upc"]', 'Universal Product Code — the barcode number for your release. Assigned by your distributor.'],

        // Step 9 — Review & Mint
        ['.wizard-step[data-step="6"] h3:first-of-type', 'Review your collection settings. Go back to Step 2 to edit if needed.'],
        ['#oe-toggle-fixed', 'Fixed editions = a set number of copies that can never be increased after minting.'],
        ['#oe-toggle-open', 'Open Edition = unlimited minting within a time window. After the window closes, no more can ever be minted.'],
        ['label[for="nft-editions"]', 'How many copies of this NFT can exist. Cannot be increased after minting. Lower supply = higher scarcity.'],
        ['label[for="nft-royalty"]', 'Percentage you earn on every secondary market resale (0-50%). Enforced on-chain by the XRPL — automatic royalties forever.'],
    ];

    // ── Guide content: keyed by step number ────────────────────────────────
    // Some steps have content-type variants
    const GUIDES = {
        0: {
            title: 'Setting Up Your Collection',
            body: '<p><strong>Every NFT belongs to a collection</strong> — think of it like an album for music, a gallery for art, or a series for film. Even single releases get their own collection.</p><p>Choose a descriptive name buyers can search for, pick a unique taxon number (any number works), and add a cover image. You can always add more NFTs to this collection later.</p><p><strong>Adding to Existing:</strong> If you\'ve already created a collection, switch to the "Add to Existing" tab to add NFTs to it.</p>'
        },
        1: {
            title: 'Choose Your Content Type',
            body: '<p>This determines what metadata fields you\'ll fill in, what files you\'ll upload, and how your NFT works across the IMU ecosystem.</p><p><strong><svg class="imc-ic" aria-hidden="true"><use href="#ic-music"></use></svg> Music Access</strong> — Single tracks/songs. Streams on IMUTV + IMUP3. Full P.R.O metadata support.</p><p><strong><svg class="imc-ic" aria-hidden="true"><use href="#ic-clapper"></use></svg> Music Video</strong> — Music videos. Includes both audio + video metadata. Plays on IMUTV, audio on IMUP3.</p><p><strong><svg class="imc-ic" aria-hidden="true"><use href="#ic-palette"></use></svg> Art Access</strong> — Digital or physical art. Auto-watermark preview generation. No complex metadata needed.</p><p><strong><svg class="imc-ic" aria-hidden="true"><use href="#ic-video"></use></svg> Film Access</strong> — Short films, documentaries, features. Full film metadata (ISAN, cue sheets, territories).</p>'
        },
        2: {
            title: 'NFT Details',
            body: '<p>This is the core information buyers see. Give your NFT a <strong>clear title</strong> and <strong>compelling description</strong>.</p><p><strong>Content Flags:</strong> Streaming Access is always on (it\'s what makes your NFT work on IMUTV/IMUP3). Mark explicit content if applicable.</p><p><strong>Display Traits:</strong> These appear on XRPL marketplaces. Add genre, mood, rarity, or any custom attributes.</p><p><strong>AI Disclosure:</strong> Required for all content. Be transparent about any AI tools used in creation.</p><p><strong>Registration Status:</strong> If your content is registered/licensed/copyrighted, select Yes to unlock industry-standard metadata fields in the next steps.</p>'
        },
        3: {
            title: 'Track & Content Details',
            body_music: '<p><strong>Track Metadata</strong> follows industry standards used by Spotify, Apple Music, and all major DSPs.</p><p>Fill in what you know — <strong>genre and language are most important</strong>. BPM, key, and lyrics are valuable but optional.</p><p>If your track is registered with a P.R.O, this metadata enables proper royalty reporting across platforms.</p>',
            body_film: '<p><strong>Film Details</strong> use industry-standard metadata for film and TV distribution.</p><p>The <strong>logline and synopsis</strong> help viewers decide to watch. Classification and rating enable proper content filtering on IMUTV.</p><p>Key credits (directors, producers, writers, cast) are embedded permanently in the NFT metadata.</p>'
        },
        4: {
            title: 'Credits & Administration',
            body_music: '<p>Properly crediting everyone ensures <strong>fair royalty distribution</strong> and professional metadata.</p><p><strong>Writer information</strong> with IPI numbers and P.R.O affiliations is critical — this is what your P.R.O uses to track and pay royalties.</p><p>Producers, engineers, and session musicians are optional but recommended for complete credits.</p>',
            body_film: '<p>Enter your film\'s <strong>production details, rights information, and identifiers</strong>.</p><p>ISAN and EIDR are global identifiers for audiovisual works — include them if you have them.</p><p><strong>Territories and license type</strong> define where and how your content can be streamed. This is permanently embedded in the NFT.</p>'
        },
        5: {
            title: 'Publishing & Master Ownership',
            body: '<p>This step separates <strong>composition rights</strong> (who wrote it) from <strong>master rights</strong> (who owns the recording).</p><p>If you\'re independent, you likely own both. If you have a label deal, your label may own the master.</p><p><strong>Ownership percentages must total 100%</strong> — this enables proper royalty splits.</p>'
        },
        6: {
            title: 'Rights & Compliance',
            body: '<p>Declare what <strong>rights come with your NFT</strong>, what license applies, and whether any samples are used.</p><p><strong>Sample Disclosure</strong> is required — undisclosed samples can create legal issues. If you\'ve cleared samples, provide the documentation.</p><p>Full transparency here protects you legally and builds trust with buyers.</p>'
        },
        7: {
            title: 'Industry Identifiers',
            body: '<p>These codes link your NFT to <strong>global music databases</strong>.</p><p><strong>ISRC</strong> identifies the recording. <strong>ISWC</strong> identifies the composition. <strong>UPC</strong> identifies the release.</p><p>If you don\'t have these yet, leave them blank — but they significantly improve your NFT\'s professional credibility and enable platform integration.</p>'
        },
        8: {
            title: 'Upload Your Media',
            body_music: '<p>Upload a <strong>preview clip</strong> (the free sample buyers hear on the marketplace), your <strong>full master audio</strong> (protected behind NFT ownership), and <strong>cover artwork</strong>.</p><p>The preview and cover are pinned publicly to IPFS. The master file is stored securely and only accessible via IMUTV/IMUP3.</p>',
            body_art: '<p>Upload your <strong>full-resolution master artwork</strong>. We automatically generate a <strong>watermarked preview</strong> — no extra work needed.</p><p>Supported formats: PNG, JPG, GIF, WebP, BMP, TIFF. The master is protected behind NFT ownership.</p>',
            body_film: '<p>Upload a <strong>trailer/preview clip</strong>, your <strong>full film file</strong>, and a <strong>poster image</strong>.</p><p>The preview and poster are public. The master film is protected behind NFT ownership and streams exclusively on IMUTV.</p>'
        },
        9: {
            title: 'Review & Mint',
            body: '<p>Final check before your NFT goes live.</p><p><strong>Editions:</strong> Fixed = set supply. Open = unlimited for a time window.</p><p><strong>Pricing:</strong> Static (fixed XRP) or Dynamic (USD-pegged, auto-calculated).</p><p><strong>Royalties:</strong> Set your transfer fee (0-50%) — you earn this on every secondary market resale, enforced on-chain.</p><p><strong>Allowlist:</strong> Optionally restrict minting to specific wallets.</p><p><strong>Schedule:</strong> Go live immediately or set a future launch date.</p><p>Once you click Mint, your metadata is pinned to IPFS and your listing goes live.</p>'
        }
    };

    // ── Inject ℹ️ tips on DOM ready ────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function() {
        TIPS.forEach(function(entry) {
            var selector = entry[0], text = entry[1], mode = entry[2] || 'after';
            var target = document.querySelector(selector);
            if (!target) return;
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'imc-tip';
            btn.tabIndex = 0;
            btn.setAttribute('aria-label', 'Info');
            btn.innerHTML = 'ℹ️<span class="imc-tip-text">' + text + '</span>';
            if (mode === 'before') {
                target.parentNode.insertBefore(btn, target);
            } else {
                target.appendChild(btn);
            }
        });

        // Click outside to dismiss any open tip
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.imc-tip')) {
                document.querySelectorAll('.imc-tip').forEach(function(t) { t.blur(); });
            }
        });
    });

    // ── 📖 Step Guide show/hide ────────────────────────────────────────────
    window.imcShowGuide = function(stepNum) {
        var panel = document.getElementById('imc-guide-panel');
        var overlay = document.getElementById('imc-guide-overlay');
        var titleEl = document.getElementById('imc-guide-title');
        var badgeEl = document.getElementById('imc-guide-badge');
        var bodyEl = document.getElementById('imc-guide-body');
        if (!panel || !overlay || !titleEl || !bodyEl) return;

        var GK = {0:1, 1:0, 2:2, 3:3, 4:8, 5:9, 6:9};

        var lookupKey = (stepNum in GK) ? GK[stepNum] : stepNum;

        var guide = GUIDES[lookupKey];

        if (stepNum === 3) {

            var lt = window.__imcLaneTabs || [0,1,2,3,4];

            guide = { title: 'Industry Metadata', body: (function(){ var ct=''; try { var tc=document.querySelector('.content-type-card.selected'); if (tc) ct=tc.dataset.type||''; } catch(e){} return lt.map(function(t){ var g = GUIDES[t+3]; if (!g) return ''; var b = (ct==='film' ? (g.body_film||g.body_music) : g.body_music) || g.body || ''; return '<h4>' + g.title + '</h4>' + b; }).join(''); })() };

        }
        if (!guide) return;

        // Determine content type for step-specific variants
        var contentType = '';
        try {
            var typeCard = document.querySelector('.content-type-card.selected');
            if (typeCard) contentType = typeCard.dataset.type || '';
        } catch(e) {}

        titleEl.textContent = guide.title;
        badgeEl.textContent = 'Step ' + stepNum;

        // Pick body variant based on content type
        var body = guide.body || '';
        if (stepNum === 4) {
            if (contentType === 'art') body = guide.body_art || body;
            else if (contentType === 'film') body = guide.body_film || body;
            else body = guide.body_music || body;
        }

        bodyEl.innerHTML = body;
        panel.classList.add('active');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    };

    window.imcCloseGuide = function() {
        var panel = document.getElementById('imc-guide-panel');
        var overlay = document.getElementById('imc-guide-overlay');
        if (panel) panel.classList.remove('active');
        if (overlay) overlay.classList.remove('active');
        document.body.style.overflow = '';
    };

})();
/* ============================================================================
   v409 Phase 8: MINT PREP OVERLAY
   Self-contained — no existing functions or state modified.
   Shows a preparation checklist for first-time creators.
   Hides automatically if: draft exists OR user dismissed previously.
   ============================================================================ */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        var overlay = document.getElementById('imc-mint-prep');
        if (!overlay) return;

        // Don't show if user previously dismissed with "Don't show again"
        try {
            if (localStorage.getItem('imc_mint_prep_dismissed') === '1') {
                overlay.style.display = 'none';
                return;
            }
        } catch(e) { /* localStorage unavailable — show overlay */ }

        // Don't show if a saved draft exists (returning creator)
        try {
            if (localStorage.getItem('imc_mint_draft')) {
                overlay.style.display = 'none';
                return;
            }
        } catch(e) { /* localStorage unavailable — show overlay */ }

        // Bind dismiss button
        var dismissBtn = document.getElementById('mint-prep-dismiss');
        if (dismissBtn) {
            dismissBtn.addEventListener('click', function() {
                overlay.classList.remove('imc-open');
                overlay.style.display = 'none';

                // Save "don't show again" preference if checked
                var dontShow = document.getElementById('mint-prep-dont-show');
                if (dontShow && dontShow.checked) {
                    try {
                        localStorage.setItem('imc_mint_prep_dismissed', '1');
                    } catch(e) { /* silent — will show again next visit */ }
                }

                // Smooth scroll to auth section so user sees the next step
                var authSection = document.getElementById('auth-minter-section');
                if (authSection) {
                    authSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        }

        // Click outside to dismiss tips (reuse Phase 1+9 pattern)
        overlay.addEventListener('click', function(e) {
            if (!e.target.closest('.imc-tip')) {
                overlay.querySelectorAll('.imc-tip').forEach(function(t) { t.blur(); });
            }
        });
    });
})();

/* ============================================================================
   v409 Phase 2: COLLECTION PREVIEW — Save draft then open preview tab
   Self-contained — calls only window.saveMintDraft (already exposed by IIFE).
   ============================================================================ */
(function() {
    'use strict';

    window.imcOpenCollectionPreview = async function() {
        // 1. Auto-save draft (safety net — protects user's work)
        try {
            if (typeof window.saveMintDraft === 'function') {
                window.saveMintDraft();
            }
        } catch(e) {
            console.warn('[IMC] Draft save before preview failed:', e.message);
        }

        // 2. v412: Await IndexedDB file storage completion before opening preview.
        //    Previously used a fixed 600ms timeout which was insufficient for
        //    multiple tier cover files — they hadn't finished writing to IDB before
        //    the preview tab tried to read them. Now we await the actual Promise.
        try {
            if (window._imcDraftFilesReady) {
                await window._imcDraftFilesReady;
            }
        } catch(e) {
            console.warn('[IMC] IDB wait failed (proceeding anyway):', e.message);
        }

        // 3. Open preview tab (IDB writes guaranteed complete)
        window.open('/collection-preview/?mode=draft', '_blank');
    };
})();

/* ═══ PHASE B-b (v830): chrome-diet gate controller ═══
   Mirrors the EXISTING auth-card states (checkAuthorizationStatus is untouched)
   into wizard/bar visibility via a MutationObserver. Zero auth-logic changes. */
(function () {
    function el(id) { return document.getElementById(id); }
    function vis(e) { return !!e && e.style.display !== 'none' && e.offsetParent !== null; }
    function gateSync() {
        var ok = vis(el('auth-success'));
        var needs = vis(el('auth-required')) || vis(el('auth-conflict'));
        var wiz = el('mint-wizard'), sec = el('auth-minter-section'), chip = el('msb-auth-chip');
        if (ok) {
            document.body.classList.add('wizard-active');
            if (wiz) wiz.style.display = '';
            if (chip) chip.style.display = '';
            barStepSync();
        } else if (needs) {
            document.body.classList.remove('wizard-active');
            if (wiz) wiz.style.display = 'none';
            if (sec) sec.style.display = '';
            if (chip) chip.style.display = 'none';
        }
    }
    window.imcShowPrep = function () {
        var o = document.getElementById('imc-mint-prep');
        if (o) { o.style.display = ''; o.classList.add('imc-open'); }
        var gp = document.getElementById('imc-guide-panel');
        if (gp) gp.classList.remove('active');
    };
    window.imcHidePrep = function () {
        var o = document.getElementById('imc-mint-prep');
        if (o) { o.classList.remove('imc-open'); o.style.display = 'none'; }
    };
    /* B-b2: bar step text on load + whenever the wizard activates (reads the ACTIVE progress step) */
    function barStepSync() {
        try {
            var ps = document.querySelector('.progress-step.active') || document.querySelector('.progress-step[data-step="0"]');
            var bs = document.getElementById('msb-step');
            if (ps && bs) {
                var n = ps.querySelector('.step-number');
                var l = ps.querySelector('.step-label');
                bs.textContent = (n ? ('Step ' + n.textContent.trim() + ' \u2014 ') : '') + (l ? l.textContent.trim() : '');
            }
            var bk = document.getElementById('msb-book');
            if (bk && ps && ps.dataset.step) bk.dataset.step = ps.dataset.step;
        } catch (e) {}
    }
    /* B-b2: Esc closes the guide modal */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var gp = document.getElementById('imc-guide-panel');
            if (gp && gp.classList.contains('active')) {
                if (typeof window.imcCloseGuide === 'function') window.imcCloseGuide();
                else gp.classList.remove('active');
            }
            var pv = document.getElementById('imc-mint-prep');
            if (pv && pv.classList.contains('imc-open')) window.imcHidePrep();
        }
    });
    function boot() {
        /* B-b5: PORTAL — .site-content creates a z-index:10 stacking context (custom.css),
           capping any modal inside it BELOW the fixed nav (z 99999+) and footer. Moving the
           modals to <body> escapes every ancestor trap (stacking, overflow, filters). */
        ['imc-mint-prep', 'imc-guide-panel', 'wizard-nav'].forEach(function (id) {
            var m = document.getElementById(id);
            if (m && m.parentElement !== document.body) document.body.appendChild(m);
        });
        var obs = new MutationObserver(gateSync);
        ['auth-checking', 'auth-success', 'auth-conflict', 'auth-required'].forEach(function (id) {
            var e = el(id); if (e) obs.observe(e, { attributes: true, attributeFilter: ['style', 'class'] });
        });

        /* B-c: the action bar yields to the in-page flow overlays (processing / success),
           which live inside the .site-content z-context and cannot out-stack a body-level bar. */
        function busySync() {
            var busy = vis(el('mint-loading-overlay')) || vis(el('mint-success-modal'));
            document.body.classList.toggle('imc-flow-busy', busy);
        }
        var busyObs = new MutationObserver(busySync);
        ['mint-loading-overlay', 'mint-success-modal'].forEach(function (id) {
            var e = el(id); if (e) busyObs.observe(e, { attributes: true, attributeFilter: ['style', 'class'] });
        });
        busySync();
        gateSync();
        barStepSync();
        /* B-b3: first-run setup guide (same conditions the legacy in-flow prep used) */
        try {
            if (localStorage.getItem('imc_mint_prep_dismissed') !== '1' && !localStorage.getItem('imc_mint_draft')) {
                window.imcShowPrep();
            }
        } catch (e) {}
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();


/* ═══ PHASE B-d (v837): imcToast — retires all 103 native alert() popups ═══
   Uniform styled notices. Messages kept byte-identical to their alert()
   originals (copy polish is a later pass). Container lives on <body>,
   above the fee-modal stack. */
(function () {
    var box = null;
    function ensureBox() {
        if (box && document.body.contains(box)) return box;
        box = document.createElement('div');
        box.id = 'imc-toasts';
        document.body.appendChild(box);
        return box;
    }
    window.imcToast = function (msg, type) {
        try {
            type = type || 'error';
            var b = ensureBox();
            while (b.children.length >= 4) b.removeChild(b.firstChild);
            var t = document.createElement('div');
            t.className = 'imc-toast imc-toast--' + type;
            var ic = type === 'error' ? 'x-circle' : (type === 'warn' ? 'warning' : 'check-circle');
            t.innerHTML = '<svg class="imc-ic" aria-hidden="true"><use href="#ic-' + ic + '"></use></svg><span class="imc-toast-msg"></span>';
            t.querySelector('.imc-toast-msg').textContent = String(msg);
            t.addEventListener('click', function () { if (t.parentElement) t.parentElement.removeChild(t); });
            b.appendChild(t);
            requestAnimationFrame(function () { t.classList.add('show'); });
            setTimeout(function () {
                t.classList.remove('show');
                setTimeout(function () { if (t.parentElement) t.parentElement.removeChild(t); }, 250);
            }, type === 'error' ? 7000 : 5000);
        } catch (e) {
            try { window.alert(msg); } catch (e2) {}
        }
    };
})();


/* ═══ PHASE B-d2 (v838): inline field errors + styled retry modal ═══ */
(function () {
    function wrapOf(el) { return el.closest('.form-group') || el.parentElement; }
    window.imcFieldError = function (sel, msg) {
        try {
            var el = document.querySelector(sel);
            if (!el) { window.imcToast(msg); return false; }
            var col = el.closest('.imc-collapsed');
            if (col) col.classList.remove('imc-collapsed');
            var pane = el.closest('.imc-tabpane');
            if (pane && pane.style.display === 'none') {
                var tb = document.querySelector('#industry-tabs .imc-tab[data-tab="' + pane.dataset.tab + '"]');
                if (tb) tb.click();
            }
            var w = wrapOf(el);
            w.classList.add('imc-field-err');
            var m = w.querySelector('.imc-err-msg');
            if (!m) { m = document.createElement('div'); m.className = 'imc-err-msg'; w.appendChild(m); }
            m.textContent = msg;
            el.scrollIntoView({ block: 'center', behavior: 'smooth' });
            try { el.focus({ preventScroll: true }); } catch (e) {}
            var clear = function () {
                w.classList.remove('imc-field-err');
                el.removeEventListener('input', clear); el.removeEventListener('change', clear);
            };
            el.addEventListener('input', clear); el.addEventListener('change', clear);
        } catch (e) { try { window.imcToast(msg); } catch (e2) {} }
        return false;
    };
    window.imcClearFieldErrors = function () {
        document.querySelectorAll('.imc-field-err').forEach(function (w) { w.classList.remove('imc-field-err'); });
    };
    window.imcRetry = function (msg, onRetry) {
        var old = document.getElementById('imc-retry-modal');
        if (old) old.parentElement.removeChild(old);
        var m = document.createElement('div');
        m.id = 'imc-retry-modal';
        m.innerHTML = '<div class="imc-retry-card">' +
            '<div class="imc-retry-h"><svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> Something went wrong</div>' +
            '<div class="imc-retry-msg"></div>' +
            '<div class="imc-retry-actions">' +
            '<button type="button" class="btn-secondary" data-act="cancel">Cancel</button>' +
            '<button type="button" class="btn-primary" data-act="retry">Retry Upload</button>' +
            '</div></div>';
        m.querySelector('.imc-retry-msg').textContent = String(msg);
        m.addEventListener('click', function (e) {
            var act = e.target && e.target.getAttribute && e.target.getAttribute('data-act');
            if (act === 'retry') { m.parentElement.removeChild(m); try { onRetry(); } catch (err) { console.error(err); } }
            else if (act === 'cancel') { m.parentElement.removeChild(m); }
        });
        document.body.appendChild(m);
    };
})();


/* ═══ PHASE B-e (v839): progressive disclosure — optional groups collapse ═══
   Traits + Art Copyright/Provenance start collapsed (they are optional and the
   biggest contributors to step-2 overwhelm). Click the heading to toggle.
   A saved draft disables auto-collapse (returning creators see their values).
   Zero markup changes: behaviour attaches to the EXISTING section wrappers. */
(function () {
    function boot() {
        var hasDraft = false;
        try { hasDraft = !!localStorage.getItem('imc_mint_draft'); } catch (e) {}
        ['.custom-traits-section', '.art-copyright-section'].forEach(function (sel) {
            var sec = document.querySelector(sel);
            if (!sec) return;
            sec.classList.add('imc-collapsible');
            if (!hasDraft) sec.classList.add('imc-collapsed');
            var h = sec.querySelector('h3');
            if (h) h.addEventListener('click', function () { sec.classList.toggle('imc-collapsed'); });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();


/* ═══ PHASE C-α: Industry tab engine ═══ */
(function () {
    var FILM_LABELS = { 0: 'Film Details', 1: 'Admin & Rights' };
    var DEFAULTS = ['Track Info', 'Credits', 'Publishing', 'Rights', 'Identifiers'];
    window.applyIndustryTabs = function (tabs, type) {
        var bar = document.getElementById('industry-tabs');
        if (!bar) return;
        var first = null;
        for (var i = 0; i < 5; i++) {
            var tb = bar.querySelector('.imc-tab[data-tab="' + i + '"]');
            var pn = document.querySelector('.imc-tabpane[data-tab="' + i + '"]');
            var on = !!(tabs && tabs.indexOf(i) !== -1);
            if (tb) {
                tb.style.display = on ? '' : 'none';
                tb.textContent = (type === 'film' && FILM_LABELS[i]) ? FILM_LABELS[i] : DEFAULTS[i];
            }
            if (pn) pn.style.display = 'none';
            if (on && first === null) first = i;
        }
        if (first !== null) activate(first);
    };
    function activate(i) {
        var bar = document.getElementById('industry-tabs');
        if (!bar) return;
        bar.querySelectorAll('.imc-tab').forEach(function (t) { t.classList.toggle('imc-tab-active', t.dataset.tab === String(i)); });
        document.querySelectorAll('.imc-tabpane').forEach(function (p) {
            var on = p.dataset.tab === String(i);
            var allowed = !window.__imcLaneTabs || window.__imcLaneTabs.indexOf(parseInt(p.dataset.tab, 10)) !== -1;
            p.style.display = (on && allowed) ? '' : 'none';
        });
    }
    document.addEventListener('click', function (e) {
        var t = e.target && e.target.closest && e.target.closest('#industry-tabs .imc-tab');
        if (t) activate(parseInt(t.dataset.tab, 10));
    });
})();