<?php
/**
 * Template Name: Collection Preview
 * File: page-collection-preview.php
 * Path: /wp-content/themes/astra/page-templates/page-collection-preview.php
 *
 * v409 Phase 2+3: Dual-mode collection preview page.
 *   Draft mode (?mode=draft): Reads from localStorage + IndexedDB (mint wizard data)
 *   Live mode  (?issuer=X&taxon=Y): Fetches from listings API (real on-chain data)
 *
 * Opens in a new tab from Step 9 (Create NFT) or Creator Dashboard.
 * Read-only — never writes to draft storage or listing data.
 */
get_header();

$listings_endpoint = get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/listings-handler.php';
$pinata_gw = 'https://<your-pinata-gateway>/ipfs/';
?>
<style>
/* ── Self-contained CSS — prefixed to avoid collisions ────────────────── */
:root{--cp-gold:var(--imu-gold, #d6ba66);--cp-gold-light:#e8d38a;--cp-gold-dim:rgba(var(--imu-gold-rgb), .12);--cp-gold-border:rgba(var(--imu-gold-rgb), .18);--cp-bg:#0a0a12;--cp-card:#12121a;--cp-card-solid:#14151c;--cp-text:#e8e6e3;--cp-muted:#7a7a8a;--cp-radius:14px;--cp-success:#4ade80;--cp-warning:#fbbf24}
.cp-wrap{max-width:1100px;margin:0 auto;padding:2rem 1.5rem 5rem;min-height:80vh}
.cp-back{display:inline-flex;align-items:center;gap:.5rem;color:var(--cp-muted);text-decoration:none;font-size:.88rem;margin-bottom:1.75rem;cursor:pointer;transition:color .18s;background:none;border:none}
.cp-back:hover{color:var(--cp-gold)}

/* Draft banner */
.cp-draft-banner{background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.25);border-radius:10px;padding:.75rem 1.25rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:.75rem;font-family:'Montserrat',sans-serif;font-size:.88rem;color:var(--cp-warning)}
.cp-draft-banner strong{color:var(--cp-warning)}

/* Collection hero */
.cp-hero{display:flex;gap:2rem;align-items:flex-start;margin-bottom:2rem;padding:2rem;background:rgba(18,19,26,.9);border:1px solid var(--cp-gold-border);border-radius:var(--cp-radius)}
.cp-hero-img{width:180px;height:180px;flex-shrink:0;border-radius:12px;overflow:hidden;border:2px solid var(--cp-gold);background:var(--cp-card)}
.cp-hero-img img{width:100%;height:100%;object-fit:cover}
.cp-hero-img .cp-placeholder{width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--cp-muted);font-size:2rem;background:var(--cp-card)}
.cp-hero-info{flex:1;min-width:0}
.cp-hero-info h1{font-family:'Lora',serif;font-size:1.8rem;color:var(--cp-gold);margin:0 0 .5rem;text-shadow:0 0 30px rgba(var(--imu-gold-rgb), .15)}
.cp-hero-info .cp-desc{color:rgba(255,255,255,.7);font-family:'Montserrat',sans-serif;font-size:.9rem;line-height:1.6;margin:0 0 1rem;max-width:550px}
.cp-stats{display:flex;gap:.6rem;flex-wrap:wrap}
.cp-stat{background:var(--cp-gold-dim);border:1px solid var(--cp-gold-border);border-radius:10px;padding:.6rem 1rem;text-align:center;min-width:80px}
.cp-stat-val{display:block;font-family:'Lora',serif;font-size:1.1rem;color:var(--cp-gold-light);font-weight:700}
.cp-stat-lbl{display:block;font-family:'Montserrat',sans-serif;font-size:.72rem;color:var(--cp-muted);margin-top:2px;text-transform:uppercase;letter-spacing:.5px}

/* Tier section */
.cp-tier-header{display:flex;align-items:center;gap:.75rem;margin:1.5rem 0 .75rem;padding:.6rem 0;border-bottom:1px solid var(--cp-gold-dim)}
.cp-tier-header h2{font-family:'Lora',serif;font-size:1.15rem;color:var(--cp-gold-light);margin:0}
.cp-tier-editions{font-family:'Montserrat',sans-serif;font-size:.82rem;color:var(--cp-muted)}
.cp-tier-progress{flex:1;max-width:160px;height:6px;background:rgba(255,255,255,.06);border-radius:3px;overflow:hidden;margin-left:auto}
.cp-tier-progress-fill{height:100%;background:linear-gradient(90deg,var(--cp-gold),var(--cp-gold-light));border-radius:3px;transition:width .3s}

/* NFT card */
.cp-cards-grid{display:grid;grid-template-columns:1fr;gap:1rem}
.cp-card{background:var(--cp-card-solid);border:1px solid var(--cp-gold-border);border-radius:var(--cp-radius);overflow:hidden;transition:border-color .2s,transform .2s;cursor:pointer}
.cp-card:hover{border-color:rgba(var(--imu-gold-rgb), .35);transform:translateY(-3px);box-shadow:0 8px 24px rgba(var(--imu-gold-rgb), .1)}
.cp-card-main{display:flex;gap:1rem;padding:1rem}
.cp-card-cover{width:100px;height:100px;flex-shrink:0;border-radius:10px;overflow:hidden;background:var(--cp-bg);border:1px solid rgba(var(--imu-gold-rgb), .1)}
.cp-card-cover img{width:100%;height:100%;object-fit:cover}
.cp-card-cover .cp-placeholder{width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--cp-muted);font-size:1.5rem}
.cp-card-info{flex:1;min-width:0;display:flex;flex-direction:column;justify-content:center;gap:.25rem}
.cp-card-title{font-family:'Lora',serif;font-size:1.05rem;color:var(--cp-text);margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cp-card-tier-name{font-family:'Montserrat',sans-serif;font-size:.78rem;color:var(--cp-gold);font-weight:600}
.cp-card-meta{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}
.cp-card-badge{font-family:'Montserrat',sans-serif;font-size:.75rem;padding:2px 8px;border-radius:4px;font-weight:600}
.cp-badge-type{background:rgba(var(--imu-gold-rgb), .1);color:var(--cp-gold);border:1px solid rgba(var(--imu-gold-rgb), .2)}
.cp-badge-editions{background:rgba(255,255,255,.05);color:var(--cp-muted);border:1px solid rgba(255,255,255,.08)}
.cp-badge-price{color:var(--cp-gold-light);font-family:'Montserrat',sans-serif;font-size:.85rem;font-weight:600}
.cp-badge-status{padding:2px 8px;border-radius:4px;font-size:.72rem;font-weight:700;text-transform:uppercase}
.cp-status-active{background:rgba(74,222,128,.12);color:var(--cp-success);border:1px solid rgba(74,222,128,.25)}
.cp-status-draft{background:rgba(251,191,36,.1);color:var(--cp-warning);border:1px solid rgba(251,191,36,.25)}
.cp-status-paused{background:rgba(156,163,175,.1);color:#9ca3af;border:1px solid rgba(156,163,175,.2)}
.cp-status-sold_out{background:rgba(248,113,113,.1);color:#f87171;border:1px solid rgba(248,113,113,.2)}
.cp-card-toggle{font-family:'Montserrat',sans-serif;font-size:.78rem;color:var(--cp-muted);margin-left:auto;transition:color .18s}
.cp-card:hover .cp-card-toggle{color:var(--cp-gold)}

/* Expanded detail */
.cp-card-detail{display:none;padding:0 1rem 1.25rem;border-top:1px solid rgba(var(--imu-gold-rgb), .08);margin-top:0}
.cp-card.expanded .cp-card-detail{display:block}
.cp-card.expanded .cp-card-toggle::after{content:'▲'}
.cp-card:not(.expanded) .cp-card-toggle::after{content:'▼'}
.cp-detail-desc{font-family:'Montserrat',sans-serif;font-size:.88rem;color:rgba(255,255,255,.7);line-height:1.6;margin:.75rem 0}
.cp-traits-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:.5rem;margin:.75rem 0}
.cp-trait{background:rgba(var(--imu-gold-rgb), .05);border:1px solid var(--cp-gold-border);border-radius:8px;padding:.5rem .6rem;text-align:center}
.cp-trait-type{display:block;font-family:'Montserrat',sans-serif;font-size:.68rem;color:var(--cp-muted);text-transform:uppercase;letter-spacing:.3px;margin-bottom:2px}
.cp-trait-value{display:block;font-family:'Montserrat',sans-serif;font-size:.82rem;color:var(--cp-text);font-weight:600}

/* Metadata JSON */
.cp-meta-toggle{margin-top:.75rem}
.cp-meta-toggle summary{font-family:'Montserrat',sans-serif;font-size:.82rem;color:var(--cp-muted);cursor:pointer;padding:.4rem 0;transition:color .18s}
.cp-meta-toggle summary:hover{color:var(--cp-gold)}
.cp-meta-toggle pre{background:rgba(0,0,0,.4);border:1px solid rgba(var(--imu-gold-rgb), .08);border-radius:8px;padding:1rem;margin:.5rem 0 0;font-size:.75rem;color:rgba(255,255,255,.6);overflow-x:auto;max-height:400px;overflow-y:auto;white-space:pre-wrap;word-break:break-all}

/* Actions bar */
.cp-actions{display:flex;gap:1rem;margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--cp-gold-dim);flex-wrap:wrap;justify-content:center}
.cp-btn{font-family:'Montserrat',sans-serif;font-size:.9rem;font-weight:600;padding:.7rem 1.5rem;border-radius:10px;border:none;cursor:pointer;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;gap:.4rem}
.cp-btn-primary{background:linear-gradient(135deg,var(--cp-gold),#d4af37);color:#0a0a12}
.cp-btn-primary:hover{transform:translateY(-2px);box-shadow:0 4px 16px rgba(var(--imu-gold-rgb), .3)}
.cp-btn-secondary{background:rgba(var(--imu-gold-rgb), .08);color:var(--cp-gold);border:1px solid var(--cp-gold-border)}
.cp-btn-secondary:hover{background:rgba(var(--imu-gold-rgb), .15);border-color:var(--cp-gold)}

/* Loading */
.cp-loading{text-align:center;padding:4rem 2rem;color:var(--cp-muted);font-family:'Montserrat',sans-serif}
.cp-loading-spinner{width:32px;height:32px;border:3px solid rgba(var(--imu-gold-rgb), .15);border-top-color:var(--cp-gold);border-radius:50%;animation:cp-spin .8s linear infinite;margin:0 auto 1rem}
@keyframes cp-spin{to{transform:rotate(360deg)}}

/* Empty state */
.cp-empty{text-align:center;padding:3rem;color:var(--cp-muted);font-family:'Montserrat',sans-serif}

/* Responsive */
@media(max-width:640px){
    .cp-hero{flex-direction:column;align-items:center;text-align:center}
    .cp-hero-img{width:140px;height:140px}
    .cp-hero-info .cp-desc{max-width:100%}
    .cp-stats{justify-content:center}
    .cp-card-main{flex-direction:column;align-items:center;text-align:center}
    .cp-card-cover{width:120px;height:120px}
    .cp-card-meta{justify-content:center}
    .cp-traits-grid{grid-template-columns:repeat(auto-fill,minmax(100px,1fr))}
}
</style>

<div class="cp-wrap">
    <button class="cp-back" onclick="window.close()">← Close Preview</button>

    <div id="cp-draft-banner" class="cp-draft-banner" style="display:none">
        ⚠️ <strong>DRAFT PREVIEW</strong> — This collection has not been minted yet. IPFS URLs and NFT IDs will be assigned after minting.
    </div>

    <div id="cp-hero" class="cp-hero" style="display:none"></div>
    <div id="cp-content"></div>
    <div id="cp-loading" class="cp-loading">
        <div class="cp-loading-spinner"></div>
        <p>Loading collection preview…</p>
    </div>

    <div id="cp-actions" class="cp-actions" style="display:none">
        <button class="cp-btn cp-btn-primary" onclick="window.close()">✅ Looks Good — Close Preview</button>
        <button class="cp-btn cp-btn-secondary" id="cp-back-btn" onclick="window.close()" style="display:none">✏️ Back to Editor</button>
        <a class="cp-btn cp-btn-secondary" id="cp-live-link" href="#" target="_blank" style="display:none">🔗 View Live Page</a>
    </div>
</div>

<script>
(function() {
    'use strict';

    const PINATA = '<?php echo esc_js($pinata_gw); ?>';
    const LISTINGS_EP = '<?php echo esc_js($listings_endpoint); ?>';
    const params = new URLSearchParams(window.location.search);
    const mode = params.get('mode');
    const issuer = params.get('issuer');
    const taxon = params.get('taxon');

    const TYPE_ICONS = { music: '🎵', musicvideo: '🎬', musicVideo: '🎬', art: '🎨', film: '🎥' };
    const TYPE_LABELS = { music: 'Music Access', musicvideo: 'Music Video Access', musicVideo: 'Music Video Access', art: 'Art Access', film: 'Film Access' };

    // ── Utilities ──
    function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
    function ipfsUrl(cid) { if (!cid) return ''; return cid.startsWith('ipfs://') ? PINATA + cid.replace('ipfs://', '') : (cid.startsWith('http') ? cid : PINATA + cid); }

    // ── IndexedDB reader (reads existing draft file store) ──
    function openDraftDB() {
        return new Promise(function(resolve, reject) {
            try {
                var req = indexedDB.open('imc_draft_files', 1);
                req.onupgradeneeded = function(e) { var db = e.target.result; if (!db.objectStoreNames.contains('files')) db.createObjectStore('files'); };
                req.onsuccess = function(e) { resolve(e.target.result); };
                req.onerror = function() { reject(req.error); };
            } catch(e) { reject(e); }
        });
    }

    function getDraftFile(db, key) {
        return new Promise(function(resolve) {
            try {
                var tx = db.transaction('files', 'readonly');
                var req = tx.objectStore('files').get(key);
                req.onsuccess = function() {
                    var r = req.result;
                    if (!r || !r.buffer) { resolve(null); return; }
                    var blob = new Blob([r.buffer], { type: r.type });
                    resolve(URL.createObjectURL(blob));
                };
                req.onerror = function() { resolve(null); };
            } catch(e) { resolve(null); }
        });
    }

    // ── Render helpers ──
    function renderHero(data) {
        var heroEl = document.getElementById('cp-hero');
        heroEl.innerHTML = `
            <div class="cp-hero-img">
                ${data.coverUrl ? `<img src="${esc(data.coverUrl)}" alt="${esc(data.name)}" onerror="this.parentNode.innerHTML='<div class=cp-placeholder>📁</div>'">` : '<div class="cp-placeholder">📁</div>'}
            </div>
            <div class="cp-hero-info">
                <h1>${esc(data.name)}</h1>
                ${data.description ? `<p class="cp-desc">${esc(data.description)}</p>` : ''}
                <div class="cp-stats">
                    <div class="cp-stat"><span class="cp-stat-val">${esc(data.taxon)}</span><span class="cp-stat-lbl">Taxon</span></div>
                    <div class="cp-stat"><span class="cp-stat-val">${esc(TYPE_LABELS[data.type] || data.type || '—')}</span><span class="cp-stat-lbl">Type</span></div>
                    <div class="cp-stat"><span class="cp-stat-val">${data.totalEditions || '—'}</span><span class="cp-stat-lbl">Editions</span></div>
                    ${data.price ? `<div class="cp-stat"><span class="cp-stat-val">${esc(data.price)}</span><span class="cp-stat-lbl">Price</span></div>` : ''}
                    ${data.royalty ? `<div class="cp-stat"><span class="cp-stat-val">${data.royalty}%</span><span class="cp-stat-lbl">Royalty</span></div>` : ''}
                </div>
            </div>`;
        heroEl.style.display = 'flex';
    }

    function renderCard(card) {
        var traitsHtml = '';
        if (card.traits && card.traits.length) {
            traitsHtml = '<div class="cp-traits-grid">' + card.traits.map(function(t) {
                return `<div class="cp-trait"><span class="cp-trait-type">${esc(t.trait_type)}</span><span class="cp-trait-value">${esc(String(t.value))}</span></div>`;
            }).join('') + '</div>';
        }
        var metaJson = card.metadata ? JSON.stringify(card.metadata, null, 2) : '';
        var statusBadge = card.status ? `<span class="cp-card-badge cp-badge-status cp-status-${card.status}">${card.status.replace('_', ' ')}</span>` : '';
        var mintProgress = '';
        if (typeof card.minted === 'number' && typeof card.total === 'number') {
            var pct = card.total > 0 ? Math.round((card.minted / card.total) * 100) : 0;
            mintProgress = `<span class="cp-card-badge cp-badge-editions">${card.minted}/${card.total} minted</span>`;
        }

        return `
        <div class="cp-card" onclick="this.classList.toggle('expanded')">
            <div class="cp-card-main">
                <div class="cp-card-cover">
                    ${card.coverUrl ? `<img src="${esc(card.coverUrl)}" alt="" onerror="this.parentNode.innerHTML='<div class=cp-placeholder>🖼️</div>'">` : '<div class="cp-placeholder">🖼️</div>'}
                </div>
                <div class="cp-card-info">
                    <h3 class="cp-card-title">${esc(card.title)}</h3>
                    ${card.tierName ? `<span class="cp-card-tier-name">${esc(card.tierName)}</span>` : ''}
                    <div class="cp-card-meta">
                        <span class="cp-card-badge cp-badge-type">${TYPE_ICONS[card.type] || '📦'} ${esc(TYPE_LABELS[card.type] || card.type || '')}</span>
                        <span class="cp-card-badge cp-badge-editions">×${card.editions || '?'} editions</span>
                        ${card.price ? `<span class="cp-badge-price">${esc(card.price)}</span>` : ''}
                        ${statusBadge}
                        ${mintProgress}
                    </div>
                </div>
                <span class="cp-card-toggle"></span>
            </div>
            <div class="cp-card-detail">
                ${card.description ? `<p class="cp-detail-desc">${esc(card.description)}</p>` : ''}
                ${traitsHtml}
                ${metaJson ? `<details class="cp-meta-toggle"><summary>📋 View Metadata JSON</summary><pre>${esc(metaJson)}</pre></details>` : ''}
            </div>
        </div>`;
    }

    function renderTierSection(tierName, editions, minted, total, cardsHtml) {
        var pct = total > 0 ? Math.round((minted / total) * 100) : 0;
        return `
        <div class="cp-tier-header">
            <h2>🎲 ${esc(tierName)}</h2>
            <span class="cp-tier-editions">×${editions} editions</span>
            ${typeof minted === 'number' ? `
            <div class="cp-tier-progress"><div class="cp-tier-progress-fill" style="width:${pct}%"></div></div>
            <span class="cp-tier-editions">${minted}/${total} minted</span>` : ''}
        </div>
        <div class="cp-cards-grid">${cardsHtml}</div>`;
    }

    // ══════════════════════════════════════════════════════════════════════
    // DRAFT MODE
    // ══════════════════════════════════════════════════════════════════════
    async function loadDraftMode() {
        document.getElementById('cp-draft-banner').style.display = 'flex';
        document.getElementById('cp-back-btn').style.display = '';

        var raw;
        try { raw = localStorage.getItem('imc_mint_draft'); } catch(e) {}
        if (!raw) {
            document.getElementById('cp-loading').innerHTML = '<div class="cp-empty"><p>No draft found. Please save your progress in the Create NFT wizard first.</p></div>';
            return;
        }

        var draft;
        try { draft = JSON.parse(raw); } catch(e) {
            document.getElementById('cp-loading').innerHTML = '<div class="cp-empty"><p>Could not read draft data.</p></div>';
            return;
        }

        // Open IndexedDB for cover images
        var db = null;
        try { db = await openDraftDB(); } catch(e) { console.warn('[CP] IndexedDB unavailable:', e); }

        var collCoverUrl = null;
        if (db) { try { collCoverUrl = await getDraftFile(db, 'collectionCover'); } catch(e) {} }
        if (!collCoverUrl && db) { try { collCoverUrl = await getDraftFile(db, 'coverFile'); } catch(e) {} }

        var fd = draft.formData || {};
        var coll = draft.collection || {};
        var tiers = draft.tiers || {};
        var type = draft.contentType || 'music';
        var price = fd.pricing_mode === 'free' ? 'Free' : (fd.pricing_mode === 'pwyw' ? ('Pay What You Want (min ' + (fd.price || fd.price_xrp || '0') + ' XRP)') : (fd.pricing_mode === 'dynamic' ? ('$' + (fd.price_usd || fd.dynamic_usd_price || '?') + ' USD') : ((fd.price || '0') + ' XRP')));
        var totalEditions = 0;

        // Calculate total editions
        if (tiers.enabled && tiers.items && tiers.items.length > 0) {
            tiers.items.forEach(function(t) { totalEditions += (Number(t.editions) || 0); });
        } else {
            totalEditions = Number(fd.editions) || 1;
        }

        // Render hero
        renderHero({
            name: coll.name || fd.collection_name || 'Untitled Collection',
            description: coll.description || fd.collection_description || '',
            taxon: coll.taxon || '—',
            type: type,
            totalEditions: totalEditions,
            price: price,
            royalty: fd.royalty || '5',
            coverUrl: collCoverUrl
        });

        // Build metadata from draft
        var draftMeta = null;
        try {
            var metaEl = null; // Not available in preview tab — build from draft
            draftMeta = {
                name: fd.title || 'Untitled',
                description: fd.description || '',
                image: 'ipfs://PENDING',
                collection: { name: coll.name || '', family: 'IMCollectibles' },
                attributes: (draft.customTraits || [])
            };
        } catch(e) {}

        // Render cards
        var contentEl = document.getElementById('cp-content');
        var html = '';

        if (tiers.enabled && tiers.items && tiers.items.length > 0) {
            for (var i = 0; i < tiers.items.length; i++) {
                var tier = tiers.items[i];
                var tierCoverUrl = null;
                if (db) { try { tierCoverUrl = await getDraftFile(db, 'tier_' + i + '_coverFile'); } catch(e) {} }

                // Merge shared traits + tier traits
                var allTraits = (draft.customTraits || []).concat(tier.traits || []);

                var cardHtml = renderCard({
                    title: fd.title || 'Untitled NFT',
                    tierName: tier.name || ('Tier ' + (i + 1)),
                    type: type,
                    editions: tier.editions || 0,
                    price: price,
                    description: fd.description || '',
                    coverUrl: tierCoverUrl,
                    traits: allTraits,
                    metadata: draftMeta ? Object.assign({}, draftMeta, { attributes: allTraits }) : null
                });

                html += renderTierSection(tier.name || ('Tier ' + (i + 1)), tier.editions || 0, null, null, cardHtml);
            }
        } else {
            // Single (non-tiered) card
            var singleCoverUrl = null;
            if (db) { try { singleCoverUrl = await getDraftFile(db, 'coverFile'); } catch(e) {} }

            html += '<div class="cp-cards-grid">' + renderCard({
                title: fd.title || 'Untitled NFT',
                type: type,
                editions: totalEditions,
                price: price,
                description: fd.description || '',
                coverUrl: singleCoverUrl,
                traits: draft.customTraits || [],
                metadata: draftMeta
            }) + '</div>';
        }

        contentEl.innerHTML = html;
        document.getElementById('cp-loading').style.display = 'none';
        document.getElementById('cp-actions').style.display = 'flex';
    }

    // ══════════════════════════════════════════════════════════════════════
    // LIVE MODE
    // ══════════════════════════════════════════════════════════════════════
    async function loadLiveMode() {
        if (!issuer || !taxon) {
            document.getElementById('cp-loading').innerHTML = '<div class="cp-empty"><p>Missing issuer or taxon parameter.</p></div>';
            return;
        }

        // Show live link
        var liveLink = document.getElementById('cp-live-link');
        liveLink.href = '/collections/?taxon=' + encodeURIComponent(taxon) + '&issuer=' + encodeURIComponent(issuer);
        liveLink.style.display = '';

        try {
            var resp = await fetch(LISTINGS_EP + '?action=get_by_artist&account=' + encodeURIComponent(issuer) + '&limit=100');
            var data = await resp.json();
            if (!data.success || !data.data || !data.data.listings) throw new Error(data.error || 'API error');

            var listings = data.data.listings.filter(function(l) { return String(l.collection_taxon) === String(taxon); });
            if (listings.length === 0) {
                document.getElementById('cp-loading').innerHTML = '<div class="cp-empty"><p>No listings found for this collection.</p></div>';
                return;
            }

            var first = listings[0];
            var totalEd = 0, totalMinted = 0;
            listings.forEach(function(l) { totalEd += l.total_editions; totalMinted += l.minted_count; });

            // v665: token-agnostic price — mirrors imc_primary_display_price() in functions.php.
            // A listing priced only in XRPL tokens shows its real token price, not "0 XRP".
            var cpFirstTok = function (l) {
                var cs = l && l.accepted_currencies;
                try { if (typeof cs === 'string') cs = JSON.parse(cs); } catch (e) { cs = null; }
                if (!Array.isArray(cs)) return null;
                for (var i = 0; i < cs.length; i++) {
                    var c = cs[i];
                    if (c.enabled === false) continue;
                    var code = String(c.currency || '').toUpperCase();
                    if (!code || code === 'XRP') continue;
                    if (parseFloat(c.price || 0) > 0) return { currency: c.currency, price: parseFloat(c.price) };
                }
                return null;
            };
            var cpFmtTok = function (v) { return v === Math.floor(v) ? v.toLocaleString('en-US') : String(v); };
            var cpXrp = parseFloat(first.price_xrp) || 0;
            var cpTok = cpFirstTok(first);
            var priceDisplay;
            if (first.pricing_mode === 'free') {
                priceDisplay = 'Free';
            } else if (first.pricing_mode === 'pwyw') {
                priceDisplay = cpXrp > 0
                    ? ('Pay What You Want (min ' + cpXrp + ' XRP)')
                    : (cpTok ? ('Pay What You Want (min ' + cpFmtTok(cpTok.price) + ' ' + cpTok.currency + ')')
                             : 'Pay What You Want (any amount)');
            } else if (first.pricing_mode === 'dynamic') {
                priceDisplay = '$' + (first.price_usd || '?') + ' USD';
            } else if (cpXrp > 0) {
                priceDisplay = cpXrp + ' XRP';
            } else if (cpTok) {
                priceDisplay = cpFmtTok(cpTok.price) + ' ' + cpTok.currency;
            } else {
                priceDisplay = 'Free';
            }

            renderHero({
                name: first.collection_name || 'Collection',
                description: first.description || '',
                taxon: first.collection_taxon,
                type: first.nft_type,
                totalEditions: totalEd,
                price: priceDisplay,
                royalty: first.transfer_fee ? (first.transfer_fee / 1000).toFixed(1) : '0',
                coverUrl: ipfsUrl(first.cover_ipfs)
            });

            var contentEl = document.getElementById('cp-content');
            var html = '';

            listings.forEach(function(l) {
                var meta = l.metadata || null;
                var sharedTraits = (meta && meta.attributes) ? meta.attributes : [];

                if (l.has_tiers && l.tiers && l.tiers.length > 0) {
                    l.tiers.forEach(function(tier) {
                        var tierTraits = sharedTraits.concat(tier.tier_traits || []);
                        var cardHtml = renderCard({
                            title: l.nft_name,
                            tierName: tier.tier_name,
                            type: l.nft_type,
                            editions: tier.total_editions,
                            minted: tier.minted_count,
                            total: tier.total_editions,
                            price: priceDisplay,
                            status: l.status,
                            description: l.description || (meta ? meta.description : ''),
                            coverUrl: ipfsUrl(tier.cover_ipfs),
                            traits: tierTraits,
                            metadata: meta
                        });
                        html += renderTierSection(tier.tier_name, tier.total_editions, tier.minted_count, tier.total_editions, cardHtml);
                    });
                } else {
                    html += '<div class="cp-cards-grid">' + renderCard({
                        title: l.nft_name,
                        type: l.nft_type,
                        editions: l.total_editions,
                        minted: l.minted_count,
                        total: l.total_editions,
                        price: priceDisplay,
                        status: l.status,
                        description: l.description || (meta ? meta.description : ''),
                        coverUrl: ipfsUrl(l.cover_ipfs),
                        traits: sharedTraits,
                        metadata: meta
                    }) + '</div>';
                }
            });

            contentEl.innerHTML = html;
            document.getElementById('cp-loading').style.display = 'none';
            document.getElementById('cp-actions').style.display = 'flex';

        } catch(e) {
            console.error('[CP] Live mode error:', e);
            document.getElementById('cp-loading').innerHTML = '<div class="cp-empty"><p>Error loading collection: ' + esc(e.message) + '</p></div>';
        }
    }

    // ── Init ──
    if (mode === 'draft') { loadDraftMode(); }
    else if (issuer && taxon) { loadLiveMode(); }
    else { document.getElementById('cp-loading').innerHTML = '<div class="cp-empty"><p>Invalid preview URL. Please use the preview button from the Create NFT wizard or Creator Dashboard.</p></div>'; }

})();
</script>

<?php get_footer(); ?>