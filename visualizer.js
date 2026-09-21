(function(){
  function protectorTierBoost(c){
    if(c>=250) return 25;
    if(c>=100) return 20;
    if(c>=50)  return 15;
    if(c>=25)  return 10;
    if(c>=10)  return 5;
    return 0;
  }
  function guardianTierBoost(c){
    if(c>=25) return 25;
    if(c>=20) return 20;
    if(c>=15) return 15;
    if(c>=10) return 10;
    if(c>=5)  return 5;
    return 0;
  }
  function fmtPct(x){
    const r = Math.round(x * 1e6) / 1e6;
    return r.toString() + "%";
  }
  function fmtMoney(symbol,v){
    const n = isFinite(v) ? v : 0;
    return symbol + (Math.round(n * 100) / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function highlightTier(group, count, tierPercent){
    document.querySelectorAll('.tier-line[data-group="'+group+'"]').forEach(el=>{
      el.classList.remove('active');
    });
    let matched = null;
    document.querySelectorAll('.tier-line[data-group="'+group+'"]').forEach(el=>{
      const min = parseInt(el.getAttribute('data-min'),10);
      const maxAttr = el.getAttribute('data-max');
      const max = (maxAttr === "" || maxAttr === null) ? Infinity : parseInt(maxAttr,10);
      if(count >= min && count <= max){ matched = el; }
    });
    if(matched){ matched.classList.add('active'); }
    if(group === 'prot'){
      const out = document.getElementById('prot_tier_value');
      if(out) out.textContent = tierPercent + "%";
    }else{
      const out = document.getElementById('guard_tier_value');
      if(out) out.textContent = tierPercent + "%";
    }
  }

  function calc(){
    const q = id => document.getElementById(id);
    const cnt_firepit   = parseInt(q("cnt_firepit").value   || "0", 10);
    const cnt_guardians = parseInt(q("cnt_guardians").value || "0", 10);
    const cnt_pf        = parseInt(q("cnt_prot_freq").value || "0", 10);
    const cnt_pl        = parseInt(q("cnt_prot_ledger").value || "0", 10);
    const cnt_prot      = cnt_pf + cnt_pl;
    const cnt_lv        = parseInt(q("cnt_lv").value || "0", 10);
    

    const rate_firepit    = parseFloat(q("rate_firepit").value    || "0");
    const rate_guardians  = parseFloat(q("rate_guardians").value  || "0");
    const rate_protectors = parseFloat(q("rate_protectors").value || "0");
    const boost_lv_unit   = parseFloat(q("boost_lv_unit").value   || "0");

    const ad_rev   = parseFloat(q("ad_rev").value || "0");
    const currency = q("currency").value;

    const boost_prot_tier  = protectorTierBoost(cnt_prot);
    // v111: GOTF + Firepit share the same tier — use combined count
    const boost_guard_tier = guardianTierBoost(cnt_guardians + cnt_firepit);
    const boost_lv         = cnt_lv * boost_lv_unit; // applies to PROTECTORS ONLY
    

    // KPI updates
    // LV boost only applies to Protectors, not Guardians/Firepit
    const protTotalBoost  = (boost_prot_tier + boost_lv);
    const guardTotalBoost = boost_guard_tier; // No LV boost for Guardians/Firepit
    q("kpi_boost_prot").textContent  = protTotalBoost + "%";
    q("kpi_boost_guard").textContent = guardTotalBoost + "%";
    q("kpi_boost_lv").textContent    = boost_lv + "%";

    // Highlight tiers (tiers only, LV separate)
    highlightTier('prot',  cnt_prot,     boost_prot_tier);
    // v111: Highlight using combined GOTF + Firepit count
    highlightTier('guard', cnt_guardians + cnt_firepit, boost_guard_tier);

    // Final boosts per collection
    // Firepit shares the guardian tier boost, but NOT the LV boost
    const boost_firepit = guardTotalBoost;
    const boost_guard   = guardTotalBoost;
    const boost_prot    = protTotalBoost;

    // Final per-NFT rates
    const final_firepit    = rate_firepit   * (1 + boost_firepit/100);
    const final_guardians  = rate_guardians * (1 + boost_guard/100);
    const final_protectors = rate_protectors* (1 + boost_prot/100);

    // Collection totals (as %)
    const total_firepit    = cnt_firepit   * final_firepit;
    const total_guardians  = cnt_guardians * final_guardians;
    const total_protectors = cnt_prot      * final_protectors;
    const grand_total_pct  = total_firepit + total_guardians + total_protectors;

    // Estimated returns
    const ret_firepit    = ad_rev * (total_firepit   / 100);
    const ret_guardians  = ad_rev * (total_guardians / 100);
    const ret_protectors = ad_rev * (total_protectors/ 100);
    const ret_total      = ret_firepit + ret_guardians + ret_protectors;

    // Render table
    const tbody = document.getElementById("tbody_rows");
    tbody.innerHTML = "";
    const rows = [
      { name:"Firepit", count:cnt_firepit, base:rate_firepit,    boost:boost_firepit,  per:final_firepit,    total:total_firepit,    ret:ret_firepit },
      { name:"Guardians", count:cnt_guardians, base:rate_guardians, boost:guardTotalBoost, per:final_guardians, total:total_guardians, ret:ret_guardians },
      { name:"Protectors (Freq + Ledger)", count:cnt_pf + cnt_pl, base:rate_protectors, boost:protTotalBoost, per:final_protectors, total:total_protectors, ret:ret_protectors },
    ];
    rows.forEach(r => {
      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td>${r.name}</td>
        <td class="right">${r.count}</td>
        <td class="right">${fmtPct(r.base)}</td>
        <td class="right">${r.boost}%</td>
        <td class="right">${fmtPct(r.per)}</td>
        <td class="right">${fmtPct(r.total)}</td>
        <td class="right">${fmtMoney(currency, r.ret)}</td>
      `;
      tbody.appendChild(tr);
    });

    // Footers
    document.getElementById("ft_total").textContent = fmtPct(grand_total_pct);
    const total_nfts = cnt_firepit + cnt_guardians + (cnt_pf + cnt_pl);
    const wavg = total_nfts ? grand_total_pct / total_nfts : 0;
    document.getElementById("ft_final_pernft").textContent = total_nfts ? fmtPct(wavg) : "–";
    document.getElementById("ft_total_return").textContent = fmtMoney(currency, ret_total);
  }

  function addListeners(){
    const ids = ["cnt_firepit","cnt_guardians","cnt_prot_freq","cnt_prot_ledger","cnt_lv","rate_firepit","rate_guardians","rate_protectors","boost_lv_unit","ad_rev","currency"];
    ids.forEach(id => {
      const el = document.getElementById(id);
      if(el) el.addEventListener("input", calc, { passive:true });
    });
    const reset = document.getElementById("btn_reset");
    if(reset){
      reset.addEventListener("click", function(){
        document.getElementById("cnt_firepit").value = 0;
        document.getElementById("cnt_guardians").value = 0;
        document.getElementById("cnt_prot_freq").value = 0;
        document.getElementById("cnt_prot_ledger").value = 0;
        document.getElementById("cnt_lv").value = 0;
        document.getElementById("rate_firepit").value = 0.0025;
        document.getElementById("rate_guardians").value = 0.00293;
        document.getElementById("rate_protectors").value = 0.00025;
        document.getElementById("boost_lv_unit").value = 1;
        document.getElementById("ad_rev").value = 0;
        document.getElementById("currency").value = "$";
        calc();
      });
    }
  }

  // Prefill holdings using existing nonce (xrpl_marketplace_nonce) and cookie
  function prefillFromServer(){
    try{
      // detect cookie directly so we don’t depend on header.php variables
      var hasCookie = (function(){
        const m = document.cookie.match(/(?:^|; )xrpl_account=([^;]*)/);
        return !!(m && decodeURIComponent(m[1]));
      })();
      if(!hasCookie) return;

      const body = new URLSearchParams();
      body.append('action', 'imu_rv_holdings');
      body.append('nonce',  (window.IMURV && IMURV.nonce) ? IMURV.nonce : '');

      fetch((window.IMURV && IMURV.ajax_url) ? IMURV.ajax_url : '/wp-admin/admin-ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        credentials: 'same-origin',
        body: body.toString()
      })
      .then(r => r.json())
      .then(data => {
        if(!data || !data.ok || !data.counts) return;
        const c = data.counts;
        document.getElementById("cnt_firepit").value     = c.firepit || 0;
        document.getElementById("cnt_guardians").value   = c.guardians || 0;
        document.getElementById("cnt_prot_freq").value   = c.prot_freq || 0;
        document.getElementById("cnt_prot_ledger").value = c.prot_ledger || 0;
        document.getElementById("cnt_lv").value          = c.las_vegas || 0;
        calc();
      })
      .catch(()=>{ /* silent fail */ });
    }catch(e){ /* silent */ }
  }

  document.addEventListener("DOMContentLoaded", function(){
    addListeners();
    prefillFromServer();
    calc();
  });
})();