if (window.__rewardsInit) { /* already bound */ }
else { window.__rewardsInit = true; }




// JavaScript for rewards box: Handles postMessage from iframe, updates display/breakdown table, claim logic.
// Uses xrplMarketplace.nonce for secure AJAX (from header.php).
document.addEventListener('DOMContentLoaded', function() {
  const rewardsBox = document.getElementById('rewards-box');
  const sessionXft = document.getElementById('session-xft');
  const totalXft = document.getElementById('total-xft');
  const expiryText = document.getElementById('expiry-text');
  const claimButton = document.getElementById('claim-button');
  const qrCodeDiv = document.getElementById('qr-code');
  const tableBody = document.getElementById('rewards-table-body');
const REWARDS_UI_VERSION = 'r-1.4';
const storedRewardsVersion = localStorage.getItem('rewardsUiVersion');
if (storedRewardsVersion !== REWARDS_UI_VERSION) {
  localStorage.setItem('rewardsUiVersion', REWARDS_UI_VERSION);
  // Do NOT wipe 'userData' here; that belongs to the game state.
  console.log('Rewards UI version updated.');
}

  let unclaimedRewards = []; // Local cache of pending rewards
  let claimedRewards = [];
  let lifetimeTotal = 0;
  let recentRewards = [];
  let missedCount = 0;
  let cooldowns = { XFT: 0, XMEME: 0 }; // NEW: Store cooldowns for each currency
  const pieChartCanvas = document.createElement('canvas');
  pieChartCanvas.id = 'rewards-pie-chart';
  pieChartCanvas.style.width = '100%';
  pieChartCanvas.style.maxWidth = '300px';
  pieChartCanvas.style.margin = '15px auto';
  claimButton.after(pieChartCanvas);
  let pieChart;
  const expiryProgress = document.createElement('div');
  expiryProgress.id = 'expiry-progress';
  expiryProgress.style.width = '100%';
  expiryProgress.style.height = '10px';
  expiryProgress.style.background = '#ddd';
  expiryProgress.style.marginTop = '5px';
  expiryProgress.style.borderRadius = '5px';
  expiryProgress.style.overflow = 'hidden';
  const progressFill = document.createElement('div');
  progressFill.style.height = '100%';
  progressFill.style.background = 'green';
  progressFill.style.width = '100%';
  expiryProgress.appendChild(progressFill);
  expiryText.after(expiryProgress);
  const warningBadge = document.createElement('div');
  warningBadge.id = 'expiry-warning';
  warningBadge.style.position = 'absolute';
  warningBadge.style.top = '10px';
  warningBadge.style.right = '10px';
  warningBadge.style.background = 'red';
  warningBadge.style.color = 'white';
  warningBadge.style.padding = '5px 10px';
  warningBadge.style.borderRadius = '5px';
  warningBadge.style.display = 'none';
  warningBadge.textContent = 'Expiring Soon!';
  rewardsBox.appendChild(warningBadge);
  const statsDiv = document.createElement('div');
  statsDiv.id = 'rewards-stats';
  statsDiv.style.marginTop = '20px';
  statsDiv.style.textAlign = 'left';
  statsDiv.innerHTML = `
    <h3 style="text-align: center;">Lifetime Stats</h3>
    <p>Total XFT Earned: <span id="lifetime-xft">0</span></p>
    <p>Total XMEME Earned: <span id="lifetime-xmeme">0</span></p>
    <p>Missed (Expired): <span id="missed-count">0</span></p>
    <h3 style="text-align: center;">Recent Rewards (Last 10)</h3>
    <ul id="recent-list"></ul>
    <h3 style="text-align: center;">Claim History</h3>
    <ul id="claim-history"></ul>
  `;
  pieChartCanvas.after(statsDiv);
  const style = document.createElement('style');
  style.innerHTML = `
    @keyframes flyIn {
      0% { opacity: 1; transform: translateY(0); }
      100% { opacity: 0; transform: translateY(-50px); }
    }
    .fly-xft {
      position: absolute;
      color: #FFD700;
      font-weight: bold;
      animation: flyIn 1s ease-out forwards;
    }
    @keyframes flashWarning {
      0% { opacity: 1; }
      50% { opacity: 0.5; }
      100% { opacity: 1; }
    }
    .flashing { animation: flashWarning 1s infinite; }
  `;
  document.head.appendChild(style);
  const confettiDiv = document.createElement('div');
  confettiDiv.id = 'confetti-container';
  confettiDiv.style.position = 'absolute';
  confettiDiv.style.width = '100%';
  confettiDiv.style.height = '100%';
  confettiDiv.style.top = '0';
  confettiDiv.style.left = '0';
  confettiDiv.style.pointerEvents = 'none';
  confettiDiv.style.display = 'none';
  rewardsBox.appendChild(confettiDiv);
  // Cooldown timer element (below claim button)
  const cooldownTimer = document.createElement('p');
  cooldownTimer.id = 'cooldown-timer';
  cooldownTimer.style.marginTop = '5px';
  cooldownTimer.style.fontSize = '0.9em';
  cooldownTimer.style.color = '#ccc';
  cooldownTimer.style.display = 'none';
  claimButton.after(cooldownTimer);
  // Add session and total for XMEME
  const sessionXmeme = document.createElement('p');
  sessionXmeme.id = 'session-xmeme';
  sessionXmeme.textContent = 'Session XMEME: 0';
  sessionXft.after(sessionXmeme);
  const totalXmeme = document.createElement('p');
  totalXmeme.id = 'total-xmeme';
  totalXmeme.textContent = 'Total Unclaimed XMEME: 0';
  totalXft.after(totalXmeme);


  // Replace your getCookie() with this:
function getCookieLatest(name) {
  const matches = document.cookie.split('; ').filter(c => c.startsWith(name + '='));
  if (!matches.length) return null;
  // pick the last occurrence (most recently set, least likely to be the empty/old one)
  return decodeURIComponent(matches[matches.length - 1].split('=')[1] || '');
}

// Initialize xrplAccount with multiple fallbacks
let xrplAccount =
  getCookieLatest('xrpl_account') ||
  (window.xrplMarketplace && window.xrplMarketplace.user_account) ||
  (typeof window.xrpl_account !== 'undefined' ? window.xrpl_account : '') ||
  '';
  
  // Keep global in sync so the game can also pick this up if framed/shared
if (xrplAccount && (!window.xrpl_account || window.xrpl_account !== xrplAccount)) {
  window.xrpl_account = xrplAccount;
}

  
  
  function refreshAccountIfChanged() {
  const latest = getCookieLatest('xrpl_account') ||
                 (window.xrplMarketplace && window.xrplMarketplace.user_account) ||
                 window.xrpl_account || '';
  if (latest && latest !== xrplAccount) {
    console.log('xrpl_account changed → resyncing', { from: xrplAccount, to: latest });
    xrplAccount = latest;
    // Re-fetch everything that depends on login
    fetchNftEligibility();
    fetchCooldownsFromServer();
    fetchPendingFromServer();
    fetchClaimedFromServer();
    updateRewardsDisplay();
  }
}

// run every few seconds + before any claim attempt
setInterval(refreshAccountIfChanged, 5000);

function normalizeTxHashes(data, rewards = []) {
  // Return a consistent shape: { XFT: string|null, XMEME: string|null }
  const result = { XFT: null, XMEME: null };

  if (data && data.tx_hashes && typeof data.tx_hashes === 'object') {
    // New/expected shape
    result.XFT = data.tx_hashes.XFT || null;
    result.XMEME = data.tx_hashes.XMEME || null;
    return result;
  }

  // Fallbacks:
  // - Single tx_hash for a single-currency claim
  // - Or an array/other shape you might add later
  if (data && typeof data.tx_hash === 'string') {
    // Decide which currency this was for.
    // If rewards list is available, infer from it; else default to XFT.
    const hasXmeme = Array.isArray(rewards) && rewards.some(r => r.currency === 'XMEME');
    const hasXft   = Array.isArray(rewards) && rewards.some(r => !r.currency || r.currency === 'XFT');

    if (hasXmeme && !hasXft) result.XMEME = data.tx_hash;
    else result.XFT = data.tx_hash; // default
  }

  return result;
}


  // Filter expired rewards (>7 days)
  function filterExpired(rewards) {
    const sevenDaysAgo = Date.now() - (7 * 24 * 60 * 60 * 1000);
    return rewards.filter(reward => reward.timestamp > sevenDaysAgo);
  }

  function cleanExpired() {
    let userData = JSON.parse(localStorage.getItem('userData')) || {};
    userData.pendingRewards = filterExpired(userData.pendingRewards || []);
    try {
      localStorage.setItem('userData', JSON.stringify(userData));
      console.log('Cleaned expired from localStorage');
    } catch (err) {
      console.error('localStorage clean failed:', err);
      alert('Storage error: Clear browser data to fix rewards saving.');
    }
  }

     // NEW: Fetch cooldown times from server
  async function fetchCooldownsFromServer() {
    if (!xrplAccount) return;
    try {
      const response = await fetch(`/xumm-proxy.php?action=fetch-cooldowns&account=${xrplAccount}&_wpnonce=${xrplMarketplace.nonce}`);
      const data = await response.json();
      if (data.success && data.cooldowns) {
        const now = Date.now();
        const oneDayMs = 86400000;
        // Sanitize: ensure timestamps are not unreasonably far in future
        cooldowns = {
          XFT: Math.min((data.cooldowns.XFT || 0) * 1000, now + oneDayMs),
          XMEME: Math.min((data.cooldowns.XMEME || 0) * 1000, now + oneDayMs)
        };
        localStorage.setItem('cooldowns', JSON.stringify(cooldowns));
        console.log('Fetched and sanitized cooldowns (ms):', cooldowns);
      } else {
        console.error('Cooldown fetch failed:', data.error || 'No error message');
      }
    } catch (err) {
      console.error('Cooldown fetch error:', err);
    }
  }

  function fetchClaimedFromServer() {
    if (!xrplAccount) return;
    fetch('/xumm-proxy.php?action=fetch-claimed&account=' + xrplAccount + '&_wpnonce=' + xrplMarketplace.nonce)
      .then(response => response.json())
      .then(data => {
        if (data.success && data.claimed) {
          claimedRewards = data.claimed;
          const lifetimeXft = claimedRewards.filter(r => r.currency === 'XFT' || !r.currency).reduce((sum, r) => sum + parseFloat(r.amount), 0);
          const lifetimeXmeme = claimedRewards.filter(r => r.currency === 'XMEME').reduce((sum, r) => sum + parseFloat(r.amount), 0);
          document.getElementById('lifetime-xft').textContent = lifetimeXft;
          document.getElementById('lifetime-xmeme').textContent = lifetimeXmeme;
          missedCount = claimedRewards.filter(r => r.status === 'expired').length;
          document.getElementById('missed-count').textContent = missedCount;
          recentRewards = [...unclaimedRewards, ...claimedRewards]
            .sort((a, b) => b.timestamp - a.timestamp)
            .slice(0, 10);
          const recentList = document.getElementById('recent-list');
          recentList.innerHTML = '';
          recentRewards.forEach(r => {
            const li = document.createElement('li');
            li.textContent = `${parseFloat(r.amount)} ${r.currency || 'XFT'} (${new Date(r.timestamp).toLocaleDateString()})`;
            recentList.appendChild(li);
          });
          const historyList = document.getElementById('claim-history');
          historyList.innerHTML = '';
          claimedRewards.filter(r => r.status === 'completed').slice(0, 10).forEach(r => {
            const li = document.createElement('li');
            li.innerHTML = `${parseFloat(r.amount)} ${r.currency || 'XFT'} claimed on ${new Date(r.timestamp).toLocaleDateString()} <a href="https://xrpl.explorer/tx/${r.tx_hash}" target="_blank" style="color: #007bff;">(View TX)</a>`;
            historyList.appendChild(li);
          });
        }
      })
      .catch(err => console.error('Fetch claimed error:', err));
  }

  let hasOriginalNft = false;
  let hasTrackRNft = false;
  let lastNftFetch = 0;
  const nftCacheDuration = 300000; // 5 minutes

  async function fetchNftEligibility() {
    if (!xrplAccount) return;
    const now = Date.now();
    if (now - lastNftFetch < nftCacheDuration) {
      console.log('Skipping NFT fetch: cached data still valid');
      updateRewardsDisplay();
      return;
    }
    try {
      const response = await fetch(
        '/xumm-proxy.php?account=' + xrplAccount +
        '&t=' + Date.now() + '&force_check=true&_wpnonce=' + xrplMarketplace.nonce
      );
      const data = await response.json();
      console.log('NFT fetch raw data:', data);

      if (data.result && data.result.status === 'success') {
        const r = data.result || {};
        const num = (v) => Number.isFinite(+v) ? +v : 0;
        const normalized = {
          guardians: num(r.guardians),
protectors_freq: num(r.protectors_freq ?? r.frequencies),
protectors_ledger: num(r.protectors_ledger ?? r.ledger),
protectors_lasVegas: num(r.protectors_lasVegas ?? r.lasVegas ?? r.lasvegas),
protectors_firepit: num(r.protectors_firepit ?? r.firepit),
trackr_classic: num(r.trackr_classic),
trackr_885: num(r.trackr_885),
trackr_jgg1: num(r.trackr_jgg1),
trackr_jgg2: num(r.trackr_jgg2),
trackr_jgg3: num(r.trackr_jgg3)
        };

        let inferredTrackr = 0;
        if (!normalized.trackr_classic && !normalized.trackr_885 && !normalized.trackr_jgg1 &&
            !normalized.trackr_jgg2 && !normalized.trackr_jgg3 && Array.isArray(r.account_nfts)) {
          try {
            inferredTrackr = r.account_nfts.reduce((acc, nft) => {
              const txt = JSON.stringify(nft || {}).toUpperCase();
              return acc + (/(TRACKR|TRCKR|JGG|885)/.test(txt) ? 1 : 0);
            }, 0);
          } catch (e) {}
        }

        hasOriginalNft = (normalized.guardians + normalized.protectors_freq +
                  normalized.protectors_ledger + normalized.protectors_lasVegas +
                  normalized.protectors_firepit) > 0;
        const trackrTotal = (normalized.trackr_classic + normalized.trackr_885 +
                             normalized.trackr_jgg1 + normalized.trackr_jgg2 +
                             normalized.trackr_jgg3) || inferredTrackr;
        hasTrackRNft = trackrTotal > 0 || normalized.protectors_ledger >= 5 ||
                       normalized.protectors_freq >= 5 || normalized.protectors_lasVegas >= 2 ||
                       normalized.guardians > 0;

        console.log('NFT normalized counts:', normalized, 'inferredTrackr:', inferredTrackr,
                    'hasOriginalNft:', hasOriginalNft, 'hasTrackRNft:', hasTrackRNft);
        lastNftFetch = now;
        updateRewardsDisplay();
      } else {
        console.error('NFT fetch failed:', data);
      }
    } catch (err) {
      console.error('NFT fetch error:', err);
    }
  }

  window.addEventListener('message', function(event) {
    if (event.origin !== 'https://imcollectibles.io') return;
    const data = event.data;
    console.log('Debug: Message received from iframe:', data);
    if (data.type === 'rewards_update') {
      cleanExpired();
      let userData = JSON.parse(localStorage.getItem('userData')) || {};
      let existing = userData.pendingRewards || [];
      data.rewards.forEach(newReward => {
        if (!existing.some(r => r.session_id === newReward.session_id)) {
          existing.push(newReward);
          const flyText = document.createElement('div');
          flyText.className = 'fly-xft';
          flyText.textContent = `+${newReward.amount} $${newReward.currency || 'XFT'}`;
          flyText.style.left = `${rewardsBox.offsetLeft + rewardsBox.offsetWidth / 2}px`;
          flyText.style.top = `${rewardsBox.offsetTop + rewardsBox.offsetHeight}px`;
          document.body.appendChild(flyText);
          setTimeout(() => flyText.remove(), 1000);
        } else {
          console.log('Duplicate reward skipped:', newReward.session_id);
        }
      });
      userData.pendingRewards = filterExpired(existing);
      try {
        localStorage.setItem('userData', JSON.stringify(userData));
        console.log('Stored rewards in userData.pendingRewards:', userData.pendingRewards);
      } catch (err) {
        console.error('localStorage save failed:', err);
        alert('Storage full—clear browser data or claim rewards to continue.');
      }
      unclaimedRewards = userData.pendingRewards;
      if (xrplAccount) storePendingOnServer(data.rewards);
      updateRewardsDisplay();
      console.log('Received rewards update:', data.rewards);
    } else if (data.type === 'fullscreen_change') {
      const video = document.querySelector('.champion-background-video');
      if (data.isFullscreen) {
        rewardsBox.style.display = 'none';
        if (video) video.style.display = 'none';
      } else {
        rewardsBox.style.display = 'block';
        if (video) video.style.display = 'block';
      }
      console.log('Fullscreen change:', data.isFullscreen);
    }
  });

  try {
    let userData = JSON.parse(localStorage.getItem('userData')) || {};
    unclaimedRewards = filterExpired(userData.pendingRewards || []);
    console.log('Loaded and filtered rewards from userData:', unclaimedRewards);
  } catch (err) {
    console.error('localStorage load failed:', err);
    alert('Storage error on load—clear browser data.');
  }

  function storePendingOnServer(rewards) {
    fetch('/xumm-proxy.php?action=store-pending&_wpnonce=' + xrplMarketplace.nonce, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ rewards, account: xrplAccount })
    })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          console.log('Stored pending on server successfully');
        } else {
          console.error('Store pending failed:', data.error || 'No error message');
        }
      })
      .catch(err => console.error('Store pending error:', err));
  }
  
  // After storing to server, re-fetch to ensure sync (prevents local desync)
fetchPendingFromServer();

  function fetchPendingFromServer() {
  fetch('/xumm-proxy.php?action=fetch-pending&account=' + xrplAccount + '&_wpnonce=' + xrplMarketplace.nonce)
    .then(response => response.json())
    .then(data => {
      if (data.success && data.rewards && data.rewards.length > 0) {
        let userData = JSON.parse(localStorage.getItem('userData')) || {};
        // Make server authoritative: overwrite local with server data
        userData.pendingRewards = filterExpired(data.rewards);
        try {
          localStorage.setItem('userData', JSON.stringify(userData));
        } catch (err) {
          console.error('localStorage update failed after server fetch:', err);
          alert('Storage full after sync—clear browser data.');
        }
        unclaimedRewards = userData.pendingRewards;
        updateRewardsDisplay();
        console.log('Overwrote local pendingRewards with server data');
      } else {
        console.log('Server fetch empty or failed, using local data as backup');
      }
    })
    .catch(err => console.error('Fetch pending error:', err));
}

  let lastRewardsState = null;

  function updateRewardsDisplay() {
        let sessionXftTotal = 0;
    let sessionXmemeTotal = 0;
    let grandXftTotal = 0;
    let grandXmemeTotal = 0;
    let earliestExpiry = null;
    let sourcesAggregate = {};
    const oneDayMs = 24 * 60 * 60 * 1000;

    unclaimedRewards.forEach(reward => {
      const amount = parseFloat(reward.amount);
      if (reward.currency === 'XMEME') {
        sessionXmemeTotal += amount;
        grandXmemeTotal += amount;
      } else {
        sessionXftTotal += amount;
        grandXftTotal += amount;
      }
      const expiryTime = reward.timestamp + (7 * 24 * 60 * 60 * 1000);
      if (!earliestExpiry || expiryTime < earliestExpiry) earliestExpiry = expiryTime;
      reward.sources.forEach(source => {
        const key = `${source.source} (${reward.currency || 'XFT'})`;
        sourcesAggregate[key] = (sourcesAggregate[key] || 0) + parseFloat(source.amount);
      });
    });

    sessionXft.textContent = `Session XFT: ${sessionXftTotal}`;
    sessionXmeme.textContent = `Session XMEME: ${sessionXmemeTotal}`;
    totalXft.textContent = `Total Unclaimed XFT: ${grandXftTotal}`;
    totalXmeme.textContent = `Total Unclaimed XMEME: ${grandXmemeTotal}`;

    if (earliestExpiry) {
      const remaining = Math.max(0, earliestExpiry - Date.now());
      const days = Math.floor(remaining / oneDayMs);
      const hours = Math.floor((remaining % oneDayMs) / (60 * 60 * 1000));
      expiryText.textContent = `Earliest Reward Expires In: ${days}d ${hours}h`;
      const timeLeftPercent = (remaining / (7 * oneDayMs)) * 100;
      progressFill.style.width = `${timeLeftPercent}%`;
      progressFill.style.background = timeLeftPercent > 50 ? 'green' : timeLeftPercent > 25 ? 'yellow' : 'red';
      if (remaining < oneDayMs) {
        warningBadge.style.display = 'block';
        warningBadge.classList.add('flashing');
      } else {
        warningBadge.style.display = 'none';
        warningBadge.classList.remove('flashing');
      }
    } else {
      expiryText.textContent = 'Earliest Reward Expires In: N/A';
      progressFill.style.width = '100%';
      progressFill.style.background = 'green';
      warningBadge.style.display = 'none';
    }

    tableBody.innerHTML = '';
    Object.entries(sourcesAggregate).forEach(([source, amount]) => {
      const row = document.createElement('tr');
      let icon = '';
      if (source.includes('Win')) icon = '🏆';
      else if (source.includes('Combo')) icon = '🔥';
      else if (source.includes('Perfect Run')) icon = '✨';
      else if (source.includes('Final Stage')) icon = '🎉';
      else if (source.includes('Quest')) icon = '✅';
      else if (source.includes('Achievement')) icon = '⭐';
      row.innerHTML = `<td style="text-align: left;">${icon} ${source}</td><td style="text-align: right;">${amount}</td>`;
      tableBody.appendChild(row);
    });

    // Update pie chart data without recreating (stable, no refresh)
    if (pieChart) {
      pieChart.data.labels = Object.keys(sourcesAggregate);
      pieChart.data.datasets[0].data = Object.values(sourcesAggregate);
      pieChart.update(); // Smooth update without flicker
    } else {
      const ctx = document.getElementById('rewards-pie-chart').getContext('2d');
      pieChart = new Chart(ctx, {
        type: 'pie',
        data: {
          labels: Object.keys(sourcesAggregate),
          datasets: [{
            data: Object.values(sourcesAggregate),
            backgroundColor: ['#FFD700', '#FF4500', '#00FF00', '#1E90FF', '#FF69B4', '#8A2BE2']
          }]
        },
        options: {
          responsive: true,
          plugins: { legend: { position: 'top' } }
        }
      });
    }

    // Check cooldowns for XFT and XMEME
    const now = Date.now();
    let xftCooldownRemaining = cooldowns.XFT ? Math.max(0, cooldowns.XFT - now) : 0;
    let xmemeCooldownRemaining = cooldowns.XMEME ? Math.max(0, cooldowns.XMEME - now) : 0;
    xftCooldownRemaining = Math.min(xftCooldownRemaining, 86400000);
    xmemeCooldownRemaining = Math.min(xmemeCooldownRemaining, 86400000);
    const isXftCooldown = xftCooldownRemaining > 0;
    const isXmemeCooldown = xmemeCooldownRemaining > 0;

    // Determine claim eligibility
    const hasXftRewards = grandXftTotal > 0;
    const hasXmemeRewards = grandXmemeTotal > 0;
    const canClaimXft = hasXftRewards && hasOriginalNft && !isXftCooldown;
    const canClaimXmeme = hasXmemeRewards && hasTrackRNft && !isXmemeCooldown;
    const canClaim = canClaimXft || canClaimXmeme;

    // Check if state changed (exclude cooldown times for pie/chart stability)
    const currentState = JSON.stringify({
      unclaimedRewardsLength: unclaimedRewards.length,
      canClaim,
      hasXftRewards,
      hasXmemeRewards,
      hasOriginalNft,
      hasTrackRNft
    });
    if (currentState === lastRewardsState) {
      return; // Skip UI updates if core state unchanged
    }
    lastRewardsState = currentState;

    claimButton.disabled = true;
    claimButton.style.backgroundColor = '#007bff';
    cooldownTimer.style.display = 'none';
    if (!xrplAccount) {
      claimButton.innerHTML = 'Log in to Claim <a href="#xaman-login-container" style="color: #007bff;">(Xaman)</a>';
    } else if (!hasOriginalNft && !hasTrackRNft) {
      claimButton.innerHTML = 'Become a Protector to Claim <a href="https://imcollectibles.io/nft-marketplace/" style="color: #007bff;">(Buy NFT)</a>';
    } else if (!canClaim) {
      claimButton.style.backgroundColor = '#6c757d';
      claimButton.style.cursor = 'not-allowed';
      claimButton.textContent = 'Claim Rewards';
      let cooldownText = '';
      if (isXftCooldown && hasXftRewards) {
        const hours = Math.floor(xftCooldownRemaining / 3600000);
        const minutes = Math.floor((xftCooldownRemaining % 3600000) / 60000);
        const seconds = Math.floor((xftCooldownRemaining % 60000) / 1000);
        cooldownText += `XFT: ${hours}h ${minutes}m ${seconds}s`;
      }
      if (isXmemeCooldown && hasXmemeRewards) {
        const hours = Math.floor(xmemeCooldownRemaining / 3600000);
        const minutes = Math.floor((xmemeCooldownRemaining % 3600000) / 60000);
        const seconds = Math.floor((xmemeCooldownRemaining % 60000) / 1000);
        cooldownText += (cooldownText ? ' | ' : '') + `XMEME: ${hours}h ${minutes}m ${seconds}s`;
      }
      if (cooldownText) {
        cooldownTimer.textContent = `Next claim: ${cooldownText}`;
        cooldownTimer.style.display = 'block';
      } else {
        claimButton.textContent = 'No Rewards';
      }
    } else {
      claimButton.disabled = false;
      claimButton.textContent = 'Claim Rewards';
    }
    console.log('Debug: Updated display with unclaimedRewards:', unclaimedRewards, 'canClaim:', canClaim,
                'cooldowns:', cooldowns, 'xftCooldownRemaining:', xftCooldownRemaining,
                'xmemeCooldownRemaining:', xmemeCooldownRemaining);
  }

  // Update cooldown timer every second for real-time countdown
  function startCooldownTimer() {
    setInterval(() => {
      if (cooldowns.XFT || cooldowns.XMEME) {
        updateRewardsDisplay();
      }
    }, 1000);
  }

  // Call on load
  (async () => {
    if (xrplAccount) {
      await Promise.all([fetchNftEligibility(), fetchCooldownsFromServer()]);
      fetchPendingFromServer();
      fetchClaimedFromServer();
      if (unclaimedRewards.length > 0) {
        storePendingOnServer(unclaimedRewards);
        console.log('Resynced local pending to server on load.');
      }
    }
    updateRewardsDisplay();
    startCooldownTimer();
    // If logged in, force server sync on load
if (xrplAccount) {
  fetchPendingFromServer();
}
  })();

  claimButton.addEventListener('click', async () => {
    refreshAccountIfChanged();
    if (!xrplAccount) {
      alert('Please log in with Xaman first.');
      return;
    }
    if (claimButton.disabled) return;
    claimButton.disabled = true;
    claimButton.textContent = 'Processing...';
    console.log('Claim started - Nonce:', xrplMarketplace.nonce, 'Account:', xrplAccount, 'Rewards:', unclaimedRewards);
    try {
      const response = await fetch('/xumm-proxy.php?action=verify-rewards', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          rewards: unclaimedRewards,
          account: xrplAccount,
          _wpnonce: xrplMarketplace.nonce
        })
      });
      console.log('Fetch response status:', response.status, 'OK:', response.ok);
      if (!response.ok) {
        const text = await response.text();
        throw new Error(`Server error: ${response.status} - ${text}`);
      }
      const data = await response.json();
      console.log('Claim response data:', data);
      if (data.success) {
        const hashes = normalizeTxHashes(data, unclaimedRewards);
        unclaimedRewards = [];
        let userData = JSON.parse(localStorage.getItem('userData')) || {};
        userData.pendingRewards = [];
        localStorage.setItem('userData', JSON.stringify(userData));
        const now = Date.now();
        if (hashes.XFT) cooldowns.XFT = now + 86400000;
        if (hashes.XMEME) cooldowns.XMEME = now + 86400000;
        localStorage.setItem('cooldowns', JSON.stringify(cooldowns));

        const successMessage = document.createElement('div');
        successMessage.textContent = 'Claim Successful!';
        successMessage.style.position = 'absolute';
        successMessage.style.top = '50%';
        successMessage.style.left = '50%';
        successMessage.style.transform = 'translate(-50%, -50%)';
        successMessage.style.fontSize = '30px';
        successMessage.style.color = 'green';
        document.body.appendChild(successMessage);
        setTimeout(() => successMessage.remove(), 2000);

        confettiDiv.style.display = 'block';
        if (window.particlesJS) {
          particlesJS('confetti-container', {
            particles: {
              number: { value: 100 },
              color: { value: ['#FFD700', '#FF4500', '#00FF00'] },
              shape: { type: 'circle' },
              opacity: { value: 1 },
              size: { value: 5, random: true },
              move: { enable: true, speed: 10, direction: 'bottom', out_mode: 'out' }
            }
          });
        }
        setTimeout(() => {
          confettiDiv.style.display = 'none';
          if (window.pJSDom && pJSDom[0] && pJSDom[0].pJS && pJSDom[0].pJS.fn && pJSDom[0].pJS.fn.vendors) {
            pJSDom[0].pJS.fn.vendors.destroypJS();
          }
        }, 2000);

        updateRewardsDisplay();
        fetchClaimedFromServer();
        fetchCooldownsFromServer();

        const links = [];
        if (hashes.XFT) links.push(`XFT TX: https://xrpl.explorer/tx/${hashes.XFT}`);
        if (hashes.XMEME) links.push(`XMEME TX: https://xrpl.explorer/tx/${hashes.XMEME}`);
        if (links.length === 0 && data.tx_hash) links.push(`TX: https://xrpl.explorer/tx/${data.tx_hash}`);
        if (links.length) alert('Transaction(s) successful!\n' + links.join('\n'));

        claimButton.disabled = false;
        claimButton.textContent = 'Claim Rewards';
      } else {
        console.error('Claim failed data:', data);
        alert(data.error || 'Claim failed. Please try again.');
        if (data.error && data.error.includes('Cooldown active')) {
          const match = data.error.match(/Cooldown active for (\w+). Try again in (\d+) seconds/);
          if (match) {
            const currency = match[1];
            const seconds = parseInt(match[2]);
            cooldowns[currency] = Date.now() + (seconds * 1000);
            localStorage.setItem('cooldowns', JSON.stringify(cooldowns));
            fetchCooldownsFromServer();
          }
        }
        updateRewardsDisplay();
        claimButton.disabled = false;
        claimButton.textContent = 'Claim Rewards';
      }
    } catch (err) {
      console.error('Claim network error:', err);
      alert('Network error during claim: ' + err.message);
      if (err.message.includes('Cooldown active')) {
        const match = err.message.match(/Cooldown active for (\w+). Try again in (\d+) seconds/);
        if (match) {
          const currency = match[1];
          const seconds = parseInt(match[2]);
          cooldowns[currency] = Date.now() + (seconds * 1000);
          localStorage.setItem('cooldowns', JSON.stringify(cooldowns));
          fetchCooldownsFromServer();
        }
      }
      updateRewardsDisplay();
      claimButton.disabled = false;
      claimButton.textContent = 'Claim Rewards';
    }
  });
});