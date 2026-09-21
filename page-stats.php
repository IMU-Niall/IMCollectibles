<?php
/**
 * Template Name: Stats
 * File: page-stats.php
 * @version v255 — platform-specific stats, zero external APIs
 */
get_header();
$nonce = wp_create_nonce('xrpl_marketplace_nonce');
$h     = get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/imc-stats-handler.php';
?>
<style>
:root{--sg:var(--imu-gold, #d6ba66);--sgl:#e8d38a;--sgd:rgba(var(--imu-gold-rgb), .12);--sc:#12121a;--sch:#1a1a26;--sb:rgba(var(--imu-gold-rgb), .15);--st:#e8e6e3;--sm:#7a7a8a;--ss:#4ade80;--sbl:#60a5fa;--sr:14px}
.sw{max-width:1300px;margin:0 auto;padding:2rem 1.5rem 5rem;min-height:80vh}
.s-back{display:inline-flex;align-items:center;gap:.5rem;color:var(--sm);text-decoration:none;font-size:.88rem;margin-bottom:1.75rem;transition:color .18s}
.s-back:hover{color:var(--sg)}
.s-hdr{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:1rem;margin-bottom:2rem}
.s-hdr h1{font-size:1.9rem;color:var(--sg);margin:0 0 .2rem;display:flex;align-items:center;gap:.6rem}
.s-hdr p{color:var(--sm);font-size:.88rem;margin:0}
.s-tabs{display:flex;gap:.4rem;background:var(--sc);border:1px solid var(--sb);border-radius:10px;padding:.3rem}
.s-tab{padding:.5rem 1.25rem;border:none;background:transparent;color:var(--sm);cursor:pointer;border-radius:7px;font-weight:600;font-size:.85rem;transition:all .18s}
.s-tab:hover{color:var(--sg);background:var(--sgd)}
.s-tab.active{background:var(--sg);color:#000}
.s-ov{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:1rem;margin-bottom:2.5rem}
.s-card{background:var(--sc);border:1px solid var(--sb);border-radius:var(--sr);padding:1.25rem 1.5rem;transition:border-color .18s}
.s-card:hover{border-color:rgba(var(--imu-gold-rgb), .35)}
.s-card .ci{font-size:1.4rem;margin-bottom:.5rem;display:block}
.s-card .cl{color:var(--sm);font-size:.76rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.4rem}
.s-card .cv{color:var(--sg);font-size:1.5rem;font-weight:700;line-height:1.1}
.s-card .cv.load{background:var(--sgd);border-radius:6px;width:70%;height:1.75rem;animation:shim 1.4s infinite}
@keyframes shim{0%,100%{opacity:.4}50%{opacity:.9}}
.s-2col{display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.75rem;align-items:start}
.s-st{font-size:1.05rem;font-weight:700;color:var(--st);margin:0 0 1rem;display:flex;align-items:center;gap:.5rem}
.s-tbox{background:var(--sc);border:1px solid var(--sb);border-radius:var(--sr);overflow:hidden}
.s-tbl{width:100%;border-collapse:collapse;font-size:.875rem}
.s-tbl th{background:rgba(var(--imu-gold-rgb), .07);color:var(--sm);font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;padding:.8rem 1rem;border-bottom:1px solid var(--sb);text-align:left}
.s-tbl th.r,.s-tbl td.r{text-align:right}
.s-tbl td{padding:.75rem 1rem;border-bottom:1px solid rgba(var(--imu-gold-rgb), .07);color:var(--st);vertical-align:middle}
.s-tbl tbody tr:last-child td{border-bottom:none}
.s-tbl tbody tr{transition:background .15s}
.s-tbl tbody tr:hover{background:var(--sch)}
.sr{width:36px;text-align:center;font-weight:700;font-size:.8rem;color:var(--sm)}
.sr1{color:#ffd700;font-size:1rem}.sr2{color:#c0c0c0}.sr3{color:#cd7f32}
.s-cc{display:flex;align-items:center;gap:.75rem}
.s-cc img{width:40px;height:40px;border-radius:8px;object-fit:cover;background:#0a0a0f;flex-shrink:0}
.s-cn{font-weight:600;color:var(--st);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:170px}
.s-cs{font-size:.74rem;color:var(--sm);margin-top:.1rem}
.s-vol{color:var(--sg);font-weight:600}.s-mints{color:var(--ss);font-weight:600}.s-blue{color:var(--sbl)}
.s-state{padding:3rem 1.5rem;text-align:center;color:var(--sm)}
.s-spin{width:32px;height:32px;border:3px solid rgba(var(--imu-gold-rgb), .18);border-top-color:var(--sg);border-radius:50%;margin:0 auto 1rem;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.s-state.err{color:#f87171}
.s-retry{margin-top:.6rem;padding:.45rem 1.1rem;background:var(--sg);color:#000;border:none;border-radius:7px;cursor:pointer;font-weight:600;font-size:.83rem}
.s-retry:hover{background:var(--sgl)}
.s-empty{text-align:center;padding:3rem 1.5rem;color:var(--sm)}
.s-empty .ei{font-size:2rem;display:block;margin-bottom:.6rem}
.s-empty p{font-size:.88rem;line-height:1.6;max-width:360px;margin:0 auto}
.s-rbox{background:var(--sc);border:1px solid var(--sb);border-radius:var(--sr);overflow:hidden;margin-bottom:1.5rem}
.s-sale{display:grid;grid-template-columns:48px 1fr auto;gap:.9rem;align-items:center;padding:.8rem 1.1rem;border-bottom:1px solid rgba(var(--imu-gold-rgb), .07);transition:background .15s}
.s-sale:last-child{border-bottom:none}
.s-sale:hover{background:var(--sch)}
.s-simg{width:48px;height:48px;border-radius:8px;object-fit:cover;background:#0a0a0f}
.s-sn{font-weight:600;font-size:.88rem;color:var(--st);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:280px}
.s-ss{font-size:.76rem;color:var(--sm);margin-top:.15rem}
.s-srr{text-align:right;flex-shrink:0}
.s-sp{font-weight:700;font-size:.9rem;color:var(--sg)}.s-st2{font-size:.74rem;color:var(--sm);margin-top:.15rem}
@media(max-width:900px){.s-2col{grid-template-columns:1fr}}
@media(max-width:768px){
.sw{padding:1rem 1rem 3rem}.s-hdr{flex-direction:column;align-items:flex-start}
.s-hdr h1{font-size:1.4rem}.s-ov{grid-template-columns:repeat(2,1fr)}
.s-tbl th.hm,.s-tbl td.hm{display:none}
.s-cn{max-width:110px}.s-sn{max-width:150px}}
</style>
<div class="sw marketplace-page">
<a href="<?php echo esc_url(home_url('/')); ?>" class="s-back">← Back to Marketplace</a>
<div class="s-hdr">
  <div>
    <h1><span>📊</span> IMCollectibles Stats</h1>
    <p>Primary mint activity across all collections on this platform</p>
  </div>
  <div class="s-tabs">
    <button class="s-tab active" data-period="all">All Time</button>
    <button class="s-tab" data-period="30d">30D</button>
    <button class="s-tab" data-period="7d">7D</button>
    <button class="s-tab" data-period="24h">24H</button>
  </div>
</div>

<div class="s-ov">
  <div class="s-card"><span class="ci">🎵</span><div class="cl">Total Mints</div><div class="cv load" id="ov-mints">&nbsp;</div></div>
  <div class="s-card"><span class="ci">💧</span><div class="cl">XRP Volume</div><div class="cv load" id="ov-xrp">&nbsp;</div></div>
  <div class="s-card"><span class="ci">🟢</span><div class="cl">Active Listings</div><div class="cv load" id="ov-active">&nbsp;</div></div>
  <div class="s-card"><span class="ci">👥</span><div class="cl">Collectors</div><div class="cv load" id="ov-buyers">&nbsp;</div></div>
  <div class="s-card"><span class="ci">🎤</span><div class="cl">Artists</div><div class="cv load" id="ov-artists">&nbsp;</div></div>
</div>

<div class="s-2col">
  <div>
    <div class="s-st">🏆 Top Collections</div>
    <div class="s-tbox"><table class="s-tbl">
      <thead><tr><th class="sr">#</th><th>Collection</th><th class="r">Mints</th><th class="r hm">XRP Vol</th><th class="r hm">Buyers</th></tr></thead>
      <tbody id="col-body"><tr><td colspan="5"><div class="s-state"><div class="s-spin"></div>Loading…</div></td></tr></tbody>
    </table></div>
  </div>
  <div>
    <div class="s-st">🎤 Top Artists</div>
    <div class="s-tbox"><table class="s-tbl">
      <thead><tr><th class="sr">#</th><th>Artist</th><th class="r">Mints</th><th class="r hm">XRP Vol</th><th class="r hm">Buyers</th></tr></thead>
      <tbody id="art-body"><tr><td colspan="5"><div class="s-state"><div class="s-spin"></div>Loading…</div></td></tr></tbody>
    </table></div>
  </div>
</div>

<div class="s-st">🕐 Recent Sales</div>
<div class="s-rbox" id="recent-box"><div class="s-state"><div class="s-spin"></div>Loading…</div></div>
</div>

<script>
(function(){
var H='<?php echo esc_js($h); ?>', N='<?php echo esc_js($nonce); ?>', period='all';
function e(s){if(!s)return'';var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
function u(a,x){var p='action='+a+'&nonce='+encodeURIComponent(N)+'&period='+period;if(x)Object.keys(x).forEach(function(k){p+='&'+k+'='+encodeURIComponent(x[k]);});return H+'?'+p;}
function rc(r){if(r===1)return'sr sr1';if(r===2)return'sr sr2';if(r===3)return'sr sr3';return'sr';}
function spin(id,n){var el=document.getElementById(id);if(el)el.innerHTML='<tr><td colspan="'+n+'"><div class="s-state"><div class="s-spin"></div>Loading…</div></td></tr>';}
function empty(id,icon,t,s,n){var el=document.getElementById(id);if(el)el.innerHTML='<tr><td colspan="'+n+'"><div class="s-empty"><span class="ei">'+icon+'</span><p><strong>'+e(t)+'</strong><br>'+e(s)+'</p></div></td></tr>';}
function fail(id,fn,n){var el=document.getElementById(id);if(el)el.innerHTML='<tr><td colspan="'+n+'"><div class="s-state err">Failed to load.<br><button class="s-retry" onclick="'+fn+'()">Retry</button></div></td></tr>';}

function loadOv(){
  ['ov-mints','ov-xrp','ov-active','ov-buyers','ov-artists'].forEach(function(id){var el=document.getElementById(id);if(el){el.className='cv load';el.innerHTML='&nbsp;';}});
  fetch(u('overview')).then(function(r){return r.json();}).then(function(d){
    if(!d.success)throw d.error;
    var v=d.data,ps=[['ov-mints',v.total_mints.toLocaleString()],['ov-xrp',v.xrp_volume_fmt],['ov-active',v.active_listings.toLocaleString()],['ov-buyers',v.unique_buyers.toLocaleString()],['ov-artists',v.unique_artists.toLocaleString()]];
    ps.forEach(function(p){var el=document.getElementById(p[0]);if(el){el.className='cv';el.textContent=p[1];}});
  }).catch(function(){['ov-mints','ov-xrp','ov-active','ov-buyers','ov-artists'].forEach(function(id){var el=document.getElementById(id);if(el){el.className='cv';el.textContent='—';}});});
}

window.loadCols=function(){
  spin('col-body',5);
  fetch(u('top_collections',{limit:10})).then(function(r){return r.json();}).then(function(d){
    if(!d.success)throw d.error;
    var cols=d.collections||[];
    if(!cols.length){empty('col-body','🎵','No mints yet','Collections appear here once minting begins.',5);return;}
    document.getElementById('col-body').innerHTML=cols.map(function(c){
      return'<tr onclick="window.location.href=\'/collections/?issuer='+encodeURIComponent(c.artist_account)+'&taxon='+encodeURIComponent(c.taxon)+'\'" style="cursor:pointer"><td class="'+rc(c.rank)+'">'+c.rank+'</td><td><div class="s-cc"><img src="'+e(c.image)+'" onerror="this.src=\'\/wp-content\/uploads\/fallback-nft.svg\'"><div><div class="s-cn">'+e(c.name)+'</div><div class="s-cs">'+c.type_icon+' '+e(c.artist_display)+'</div></div></div></td><td class="r s-mints">'+c.total_mints.toLocaleString()+'</td><td class="r s-vol hm">'+e(c.xrp_vol_fmt)+'</td><td class="r s-blue hm">'+c.unique_buyers.toLocaleString()+'</td></tr>';
    }).join('');
  }).catch(function(){fail('col-body','loadCols',5);});
};

window.loadArts=function(){
  spin('art-body',5);
  fetch(u('top_artists',{limit:10})).then(function(r){return r.json();}).then(function(d){
    if(!d.success)throw d.error;
    var arts=d.artists||[];
    if(!arts.length){empty('art-body','🎤','No activity yet','Artists appear here once sales are recorded.',5);return;}
    document.getElementById('art-body').innerHTML=arts.map(function(a){
      return'<tr'+(a.profile_slug?' onclick="window.location.href=\'/artists/'+encodeURIComponent(a.profile_slug)+'\'" style="cursor:pointer"':'')+'><td class="'+rc(a.rank)+'">'+a.rank+'</td><td><div class="s-cc"><img src="'+e(a.sample_cover)+'" onerror="this.src=\'\/wp-content\/uploads\/fallback-nft.svg\'"><div><div class="s-cn">'+e(a.artist_display)+'</div><div class="s-cs">'+a.listings+' listing'+(a.listings!==1?'s':'')+'</div></div></div></td><td class="r s-mints">'+a.total_mints.toLocaleString()+'</td><td class="r s-vol hm">'+e(a.xrp_vol_fmt)+'</td><td class="r s-blue hm">'+a.unique_buyers.toLocaleString()+'</td></tr>';
    }).join('');
  }).catch(function(){fail('art-body','loadArts',5);});
};

window.loadRecent=function(){
  var box=document.getElementById('recent-box');
  if(box)box.innerHTML='<div class="s-state"><div class="s-spin"></div>Loading…</div>';
  fetch(H+'?action=recent_sales&limit=15&nonce='+encodeURIComponent(N))
    .then(function(r){return r.json();}).then(function(d){
      if(!d.success)throw d.error;
      var sales=d.sales||[];
      if(!sales.length){box.innerHTML='<div class="s-empty"><span class="ei">🛒</span><p><strong>No sales yet</strong><br>Recent mints appear here in real time.</p></div>';return;}
      box.innerHTML=sales.map(function(s){
        var tier=s.tier_name?'<span style="color:var(--sm);font-size:.8em"> '+e(s.tier_name)+'</span>':'';
        return'<div class="s-sale"><img class="s-simg" src="'+e(s.cover_url)+'" onerror="this.src=\'\/wp-content\/uploads\/fallback-nft.svg\'"><div><div class="s-sn">'+s.type_icon+' '+e(s.collection_name)+tier+'</div><div class="s-ss">→ '+e(s.buyer_short)+'</div></div><div class="s-srr"><div class="s-sp">'+e(s.price_fmt)+'</div><div class="s-st2">'+e(s.time_ago)+'</div></div></div>';
      }).join('');
    }).catch(function(){if(box)box.innerHTML='<div class="s-state err">Failed.<br><button class="s-retry" onclick="loadRecent()">Retry</button></div>';});
};

function loadAll(){loadOv();loadCols();loadArts();}

document.querySelectorAll('.s-tab').forEach(function(btn){
  btn.addEventListener('click',function(){
    document.querySelectorAll('.s-tab').forEach(function(t){t.classList.remove('active');});
    this.classList.add('active');period=this.dataset.period;loadAll();
  });
});

loadAll(); loadRecent();
})();
</script>
<?php get_footer(); ?>