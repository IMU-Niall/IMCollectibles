/**
 * Frequency Fountain v2.0 - Enhanced XFT Claim Animation
 * IMProtectors.com
 * 
 * Features:
 * - Premium ornate fountain with cascading water
 * - Coins that fall and stack into a pile
 * - Pile size scales with claim amount
 * - Fountain persists on completion
 * - Claim streak display
 */

(function() {
    'use strict';

    // ============================================
    // CONFIGURATION
    // ============================================
    const CONFIG = {
        // Coin animation
        coinSpawnInterval: 120,      // ms between coin spawns
        coinFallDuration: 1800,      // ms for coin to fall
        coinFallVariance: 200,       // random variance in fall time
        minCoins: 8,                 // minimum coins to spawn
        maxCoins: 60,                // maximum coins to spawn
        rewardThreshold: 3000,       // reward amount for max coins
        
        // Pile configuration
        minPileCoins: 5,             // minimum coins in pile
        maxPileCoins: 35,            // maximum coins in pile
        pileThreshold: 3000,         // reward for max pile
        
        // Progress simulation
        progressInterval: 400,
        progressMessages: [
            { threshold: 0, message: "Activating the Frequency Fountain..." },
            { threshold: 15, message: "Tuning into your frequency..." },
            { threshold: 30, message: "Scanning your NFT vault..." },
            { threshold: 50, message: "Calculating your resonance..." },
            { threshold: 70, message: "Channeling your rewards..." },
            { threshold: 85, message: "Finalizing transaction..." },
            { threshold: 95, message: "Almost there..." }
        ],
        
        // Counter animation
        counterUpdateInterval: 50,
        counterAnimationDuration: 300
    };

    // ============================================
    // STATE
    // ============================================
    const state = {
        isOpen: false,
        isProcessing: false,
        currentProgress: 0,
        rewardAmount: 0,
        coinsSpawned: 0,
        targetCoinCount: 0,
        counterValue: 0,
        coinInterval: null,
        progressInterval: null,
        counterInterval: null,
        abortController: null,
        pileCoins: [],
        claimStreak: 1,
        claimSuccessful: false,  // Track if claim succeeded
        nextClaimTime: 0         // Seconds until next claim
    };

    // ============================================
    // DOM ELEMENTS (populated on init)
    // ============================================
    let elements = {};

    // ============================================
    // PREMIUM FOUNTAIN SVG
    // ============================================
    const FOUNTAIN_SVG = `
    <svg viewBox="0 0 320 380" xmlns="http://www.w3.org/2000/svg" class="ff-fountain-svg">
        <defs>
            <!-- Gradients -->
            <linearGradient id="stoneGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" style="stop-color:#8b8680"/>
                <stop offset="30%" style="stop-color:#6b6560"/>
                <stop offset="70%" style="stop-color:#4a4540"/>
                <stop offset="100%" style="stop-color:#3a3530"/>
            </linearGradient>
            <linearGradient id="stoneHighlight" x1="0%" y1="0%" x2="0%" y2="100%">
                <stop offset="0%" style="stop-color:#9a9590"/>
                <stop offset="100%" style="stop-color:#5a5550"/>
            </linearGradient>
            <linearGradient id="goldAccent" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" style="stop-color:#f4d03f"/>
                <stop offset="50%" style="stop-color:#d4af37"/>
                <stop offset="100%" style="stop-color:#996515"/>
            </linearGradient>
            <linearGradient id="goldShine" x1="0%" y1="0%" x2="100%" y2="0%">
                <stop offset="0%" style="stop-color:#ffe066"/>
                <stop offset="50%" style="stop-color:#d4af37"/>
                <stop offset="100%" style="stop-color:#ffe066"/>
            </linearGradient>
            <linearGradient id="waterGradient" x1="0%" y1="0%" x2="0%" y2="100%">
                <stop offset="0%" style="stop-color:#7ec8e3"/>
                <stop offset="50%" style="stop-color:#5ba3c0"/>
                <stop offset="100%" style="stop-color:#3d7a94"/>
            </linearGradient>
            
            <!-- Filters -->
            <filter id="stoneShadow" x="-20%" y="-20%" width="140%" height="140%">
                <feDropShadow dx="0" dy="4" stdDeviation="6" flood-opacity="0.4"/>
            </filter>
            <filter id="goldGlow" x="-50%" y="-50%" width="200%" height="200%">
                <feGaussianBlur stdDeviation="3" result="blur"/>
                <feMerge>
                    <feMergeNode in="blur"/>
                    <feMergeNode in="SourceGraphic"/>
                </feMerge>
            </filter>
            <filter id="innerShadow">
                <feOffset dx="0" dy="2"/>
                <feGaussianBlur stdDeviation="2" result="offset-blur"/>
                <feComposite operator="out" in="SourceGraphic" in2="offset-blur" result="inverse"/>
                <feFlood flood-color="black" flood-opacity="0.3" result="color"/>
                <feComposite operator="in" in="color" in2="inverse" result="shadow"/>
                <feComposite operator="over" in="shadow" in2="SourceGraphic"/>
            </filter>
        </defs>
        
        <!-- Base Platform -->
        <ellipse cx="160" cy="355" rx="140" ry="20" fill="url(#stoneGradient)" filter="url(#stoneShadow)"/>
        <ellipse cx="160" cy="352" rx="135" ry="18" fill="url(#stoneHighlight)"/>
        
        <!-- Bottom Basin -->
        <path d="M40 340 Q40 310 60 300 L260 300 Q280 310 280 340 Q280 360 160 365 Q40 360 40 340Z" 
              fill="url(#stoneGradient)" filter="url(#stoneShadow)"/>
        <ellipse cx="160" cy="305" rx="100" ry="12" fill="url(#stoneHighlight)"/>
        <ellipse cx="160" cy="308" rx="85" ry="8" fill="#2a2520" opacity="0.6"/>
        
        <!-- Gold rim on bottom basin -->
        <ellipse cx="160" cy="300" rx="102" ry="10" fill="none" stroke="url(#goldAccent)" stroke-width="3" filter="url(#goldGlow)"/>
        
        <!-- Middle Column -->
        <rect x="140" y="180" width="40" height="120" fill="url(#stoneGradient)" filter="url(#stoneShadow)"/>
        <rect x="142" y="182" width="36" height="116" fill="url(#stoneHighlight)"/>
        
        <!-- Decorative rings on column -->
        <ellipse cx="160" cy="200" rx="24" ry="4" fill="url(#goldAccent)" filter="url(#goldGlow)"/>
        <ellipse cx="160" cy="250" rx="24" ry="4" fill="url(#goldAccent)" filter="url(#goldGlow)"/>
        
        <!-- Middle Tier Bowl -->
        <path d="M100 175 Q100 155 120 145 L200 145 Q220 155 220 175 Q220 185 160 190 Q100 185 100 175Z" 
              fill="url(#stoneGradient)" filter="url(#stoneShadow)"/>
        <ellipse cx="160" cy="150" rx="50" ry="8" fill="url(#stoneHighlight)"/>
        <ellipse cx="160" cy="153" rx="40" ry="5" fill="#2a2520" opacity="0.5"/>
        
        <!-- Gold rim on middle tier -->
        <ellipse cx="160" cy="145" rx="52" ry="7" fill="none" stroke="url(#goldAccent)" stroke-width="2.5" filter="url(#goldGlow)"/>
        
        <!-- Upper Column -->
        <rect x="150" y="80" width="20" height="65" fill="url(#stoneGradient)"/>
        <rect x="151" y="82" width="18" height="61" fill="url(#stoneHighlight)"/>
        
        <!-- Top Tier Bowl -->
        <path d="M125 78 Q125 62 140 55 L180 55 Q195 62 195 78 Q195 88 160 92 Q125 88 125 78Z" 
              fill="url(#stoneGradient)" filter="url(#stoneShadow)"/>
        <ellipse cx="160" cy="60" rx="28" ry="5" fill="url(#stoneHighlight)"/>
        <ellipse cx="160" cy="63" rx="22" ry="3" fill="#2a2520" opacity="0.5"/>
        
        <!-- Gold rim on top tier -->
        <ellipse cx="160" cy="55" rx="30" ry="5" fill="none" stroke="url(#goldAccent)" stroke-width="2" filter="url(#goldGlow)"/>
        
        <!-- Spout -->
        <rect x="155" y="35" width="10" height="22" fill="url(#goldAccent)" filter="url(#goldGlow)"/>
        <ellipse cx="160" cy="35" rx="7" ry="3" fill="url(#goldShine)"/>
        
        <!-- Decorative XFT Emblem -->
        <circle cx="160" cy="230" r="15" fill="url(#goldAccent)" filter="url(#goldGlow)"/>
        <text x="160" y="235" text-anchor="middle" font-family="Cinzel, serif" font-size="12" font-weight="bold" fill="#1a1510">X</text>
        
        <!-- Small decorative gems -->
        <circle cx="120" cy="172" r="4" fill="url(#goldAccent)" filter="url(#goldGlow)"/>
        <circle cx="200" cy="172" r="4" fill="url(#goldAccent)" filter="url(#goldGlow)"/>
        <circle cx="80" cy="325" r="5" fill="url(#goldAccent)" filter="url(#goldGlow)"/>
        <circle cx="240" cy="325" r="5" fill="url(#goldAccent)" filter="url(#goldGlow)"/>
    </svg>`;

    // ============================================
    // INITIALIZATION
    // ============================================
    function init() {
        // Only initialize on pages with the claim form
        const claimForm = document.getElementById('claim-xft-form');
        if (!claimForm) {
            console.log('[Frequency Fountain] No claim form found - skipping init');
            return;
        }

        console.log('[Frequency Fountain] ✅ Initialized');
        
        // Inject HTML structure
        injectFountainHTML();
        console.log('[Frequency Fountain] HTML injected');
        
        // Cache DOM elements
        cacheElements();
        console.log('[Frequency Fountain] Elements cached:', {
            overlay: !!elements.overlay,
            fountain: !!elements.fountain,
            claimForm: !!elements.claimForm,
            claimButton: !!elements.claimButton
        });
        
        // Attach event listeners
        attachEventListeners();
        
        // Generate background particles
        generateBackgroundParticles();
    }

    // ============================================
    // HTML INJECTION
    // ============================================
    function injectFountainHTML() {
        const html = `
        <div class="frequency-fountain-overlay" id="ff-overlay">
            <button class="ff-close-btn" id="ff-close-btn" aria-label="Close">&times;</button>
            
            <div class="frequency-fountain-container">
                
                <div class="ff-background-particles" id="ff-particles"></div>
                
                <div class="ff-header">
                    <h2 class="ff-title">Frequency Fountain</h2>
                    <p class="ff-subtitle">Claim Your Daily Rewards</p>
                </div>
                
                <div class="ff-fountain-wrapper" id="ff-fountain-wrapper">
                    <div class="ff-fountain" id="ff-fountain">
                        ${FOUNTAIN_SVG}
                        
                        <!-- Water System -->
                        <div class="ff-water-system">
                            <div class="ff-water-spout"></div>
                            <div class="ff-water-cascade-1"></div>
                            <div class="ff-water-cascade-2"></div>
                            <div class="ff-water-basin"></div>
                            <div class="ff-water-droplets">
                                <div class="ff-droplet"></div>
                                <div class="ff-droplet"></div>
                                <div class="ff-droplet"></div>
                                <div class="ff-droplet"></div>
                                <div class="ff-droplet"></div>
                                <div class="ff-droplet"></div>
                            </div>
                        </div>
                        
                        <!-- Falling Coins Container -->
                        <div class="ff-coins-container" id="ff-coins"></div>
                        
                        <!-- Coin Pile -->
                        <div class="ff-coin-pile" id="ff-pile"></div>
                        <div class="ff-pile-glow"></div>
                    </div>
                    
                    <!-- Live Counter (during animation) -->
                    <div class="ff-live-counter-wrapper">
                        <div class="ff-live-counter" id="ff-counter">0</div>
                        <div class="ff-live-counter-label">XFT Collecting</div>
                    </div>
                </div>
                
                <div class="ff-progress-section" id="ff-progress-section">
                    <div class="ff-progress-message" id="ff-progress-message">Preparing fountain...</div>
                    <div class="ff-progress-bar-container">
                        <div class="ff-progress-bar" id="ff-progress-bar"></div>
                    </div>
                </div>
                
                <!-- Completion State -->
                <div class="ff-completion" id="ff-completion">
                    <div class="ff-completion-amount" id="ff-completion-amount">0</div>
                    <div class="ff-completion-label">XFT Claimed</div>
                    <div class="ff-completion-streak" id="ff-completion-streak">
                        <span class="ff-streak-fire">🔥</span>
                        <span class="ff-streak-text"><span class="ff-streak-count" id="ff-streak-count">1</span> Day Streak</span>
                    </div>
                    <div class="ff-completion-message">Rewards deposited to your wallet!</div>
                    <div class="ff-next-claim">
                        <div class="ff-next-claim-label">Next Claim Available In</div>
                        <div class="ff-next-claim-timer" id="ff-next-timer">23:59:59</div>
                    </div>
                    <div class="ff-actions">
                        <button class="ff-btn ff-btn-secondary" id="ff-share-btn">
                            <span>Share</span>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/>
                                <polyline points="16 6 12 2 8 6"/>
                                <line x1="12" y1="2" x2="12" y2="15"/>
                            </svg>
                        </button>
                        <button class="ff-btn ff-btn-primary" id="ff-done-btn">Done</button>
                    </div>
                </div>
                
                <!-- Error State -->
                <div class="ff-error" id="ff-error">
                    <div class="ff-error-icon">⚠️</div>
                    <h3 class="ff-error-title">Fountain Malfunction</h3>
                    <p class="ff-error-message" id="ff-error-message">Something went wrong. Please try again.</p>
                    <div class="ff-actions">
                        <button class="ff-btn ff-btn-secondary" id="ff-error-close">Close</button>
                        <button class="ff-btn ff-btn-primary" id="ff-retry-btn">Try Again</button>
                    </div>
                </div>
                
                <!-- Recharging State -->
                <div class="ff-recharging" id="ff-recharging">
                    <div class="ff-recharging-icon">⛲</div>
                    <h3 class="ff-recharging-title">Fountain Recharging</h3>
                    <p class="ff-recharging-message">Your rewards are regenerating...</p>
                    <div class="ff-recharging-timer" id="ff-recharging-timer">00:00:00</div>
                    <button class="ff-btn ff-btn-primary" id="ff-recharging-close">Got It</button>
                </div>
            </div>
            
            <!-- Navigation Warning Modal -->
            <div class="ff-nav-warning" id="ff-nav-warning">
                <h3 class="ff-nav-warning-title">⚠️ Claim In Progress</h3>
                <p class="ff-nav-warning-message">Your rewards are being processed. Leaving now may interrupt the transaction.</p>
                <div class="ff-nav-warning-actions">
                    <button class="ff-btn ff-btn-secondary" id="ff-nav-leave">Leave Anyway</button>
                    <button class="ff-btn ff-btn-primary" id="ff-nav-stay">Stay Here</button>
                </div>
            </div>
        </div>
        
        <canvas class="ff-confetti-canvas" id="ff-confetti"></canvas>`;
        
        document.body.insertAdjacentHTML('beforeend', html);
    }

    // ============================================
    // ELEMENT CACHING
    // ============================================
    function cacheElements() {
        elements = {
            overlay: document.getElementById('ff-overlay'),
            container: document.querySelector('.frequency-fountain-container'),
            fountain: document.getElementById('ff-fountain'),
            fountainWrapper: document.getElementById('ff-fountain-wrapper'),
            coinsContainer: document.getElementById('ff-coins'),
            coinPile: document.getElementById('ff-pile'),
            counter: document.getElementById('ff-counter'),
            progressSection: document.getElementById('ff-progress-section'),
            progressBar: document.getElementById('ff-progress-bar'),
            progressMessage: document.getElementById('ff-progress-message'),
            completion: document.getElementById('ff-completion'),
            completionAmount: document.getElementById('ff-completion-amount'),
            streakCount: document.getElementById('ff-streak-count'),
            completionStreak: document.getElementById('ff-completion-streak'),
            nextTimer: document.getElementById('ff-next-timer'),
            error: document.getElementById('ff-error'),
            errorMessage: document.getElementById('ff-error-message'),
            recharging: document.getElementById('ff-recharging'),
            rechargingTimer: document.getElementById('ff-recharging-timer'),
            navWarning: document.getElementById('ff-nav-warning'),
            closeBtn: document.getElementById('ff-close-btn'),
            doneBtn: document.getElementById('ff-done-btn'),
            shareBtn: document.getElementById('ff-share-btn'),
            retryBtn: document.getElementById('ff-retry-btn'),
            errorClose: document.getElementById('ff-error-close'),
            rechargingClose: document.getElementById('ff-recharging-close'),
            navStay: document.getElementById('ff-nav-stay'),
            navLeave: document.getElementById('ff-nav-leave'),
            confettiCanvas: document.getElementById('ff-confetti'),
            claimForm: document.getElementById('claim-xft-form'),
            claimButton: document.getElementById('claim-xft-btn')
        };
    }

    // ============================================
    // EVENT LISTENERS
    // ============================================
    function attachEventListeners() {
        // Intercept claim form submission
        if (elements.claimForm) {
            elements.claimForm.addEventListener('submit', handleClaimSubmit);
            console.log('[Frequency Fountain] ✅ Form submit listener attached');
        } else {
            console.warn('[Frequency Fountain] ⚠️ Claim form not found - cannot attach listener');
        }

        // Close buttons
        elements.closeBtn?.addEventListener('click', handleCloseAttempt);
        elements.doneBtn?.addEventListener('click', closeFountain);
        elements.errorClose?.addEventListener('click', closeFountain);
        elements.rechargingClose?.addEventListener('click', closeFountain);

        // Retry button
        elements.retryBtn?.addEventListener('click', () => {
            hideAllStates();
            startClaim();
        });

        // Share button
        elements.shareBtn?.addEventListener('click', shareResults);

        // Navigation warning
        elements.navStay?.addEventListener('click', () => {
            elements.navWarning?.classList.remove('visible');
        });
        
        elements.navLeave?.addEventListener('click', () => {
            state.isProcessing = false;
            closeFountain();
        });

        // Prevent accidental navigation during claim
        window.addEventListener('beforeunload', handleBeforeUnload);
    }

    // ============================================
    // CLAIM SUBMISSION HANDLER
    // ============================================
    function handleClaimSubmit(e) {
        e.preventDefault();
        console.log('[Frequency Fountain] 🚀 Form submitted - starting visualizer');
        
        // Prevent double submission
        if (elements.claimButton?.disabled) {
            console.log('[Frequency Fountain] Button already disabled, ignoring');
            return;
        }
        
        // Update button UI immediately for visual feedback
        if (elements.claimButton) {
            elements.claimButton.disabled = true;
            elements.claimButton.innerHTML = '<span class="ffp-spinner"></span> Claiming...';
            elements.claimButton.classList.remove('ffp-btn-glow');
        }
        
        // Extract expected reward amount from page
        const rewardElement = document.querySelector('.ffp-rewards-amount, .daily-reward-value, .xft-reward-amount, [data-reward-amount]');
        let rewardAmount = 0;
        
        if (rewardElement) {
            const text = rewardElement.textContent || rewardElement.dataset.rewardAmount;
            rewardAmount = parseFloat(text.replace(/[^0-9.]/g, '')) || 0;
        }
        
        // Fallback: try to find in page content
        if (rewardAmount === 0) {
            const pageText = document.body.innerText;
            const match = pageText.match(/Daily Reward[:\s]*([0-9,]+(?:\.[0-9]+)?)\s*XFT/i);
            if (match) {
                rewardAmount = parseFloat(match[1].replace(/,/g, ''));
            }
        }

        // Default if still not found
        if (rewardAmount === 0) rewardAmount = 1000;

        console.log('[Frequency Fountain] Expected reward amount:', rewardAmount);
        
        state.rewardAmount = rewardAmount;
        state.targetCoinCount = calculateCoinCount(rewardAmount);

        // Open fountain and start animation
        console.log('[Frequency Fountain] 🎉 Opening fountain overlay...');
        openFountain();
        startClaim();
    }

    // ============================================
    // FOUNTAIN OPEN/CLOSE
    // ============================================
    function openFountain() {
        state.isOpen = true;
        elements.overlay?.classList.add('active');
        document.body.style.overflow = 'hidden';
        console.log('[Frequency Fountain] Overlay activated:', elements.overlay?.classList.contains('active'));
    }

    function closeFountain() {
        if (state.isProcessing && !state.abortController?.signal.aborted) {
            handleCloseAttempt();
            return;
        }

        state.isOpen = false;
        elements.overlay?.classList.add('closing');
        
        setTimeout(() => {
            elements.overlay?.classList.remove('active', 'closing');
            document.body.style.overflow = '';
            resetFountainUI();
            
            // Update button state after successful claim
            if (state.claimSuccessful && elements.claimButton) {
                updateButtonToRecharging();
            }
        }, 500);
    }
    
    // Update claim button to show recharging state
    function updateButtonToRecharging() {
        if (!elements.claimButton) return;
        
        // Keep button disabled
        elements.claimButton.disabled = true;
        elements.claimButton.classList.remove('ffp-btn-glow');
        
        // Restore button HTML with recharging badge
        elements.claimButton.innerHTML = `
            <span class="ffp-btn-icon">💰</span>
            <span class="ffp-btn-text">Claim $XFT Rewards</span>
            <span class="ffp-btn-badge">Recharging</span>
        `;
        
        // Also update the page timer if it exists
        const timerSection = document.querySelector('.ffp-timer-section');
        const timerContainer = document.getElementById('claim-timer');
        
        if (timerContainer && state.nextClaimTime > 0) {
            // Show the timer section
            timerContainer.style.display = 'block';
            timerContainer.dataset.timeLeft = state.nextClaimTime;
            
            // Start the countdown timer on the page
            startPageTimer(state.nextClaimTime);
        }
        
        console.log('[Frequency Fountain] Button updated to recharging state');
    }
    
    // Start countdown timer on the main page
    function startPageTimer(timeLeft) {
        const hoursEl = document.getElementById('timer-hours');
        const minutesEl = document.getElementById('timer-minutes');
        const secondsEl = document.getElementById('timer-seconds');
        
        if (!hoursEl || !minutesEl || !secondsEl) return;
        
        let remaining = timeLeft;
        
        function updateTimer() {
            if (remaining <= 0) {
                window.location.reload();
                return;
            }
            
            const hours = Math.floor(remaining / 3600);
            const minutes = Math.floor((remaining % 3600) / 60);
            const seconds = remaining % 60;
            
            hoursEl.textContent = String(hours).padStart(2, '0');
            minutesEl.textContent = String(minutes).padStart(2, '0');
            secondsEl.textContent = String(seconds).padStart(2, '0');
            
            remaining--;
            setTimeout(updateTimer, 1000);
        }
        
        updateTimer();
        
        // Also show the timer container if hidden
        const timerWrapper = document.querySelector('.ffp-timer-container');
        if (timerWrapper) {
            timerWrapper.style.display = 'flex';
        }
    }

    function handleCloseAttempt() {
        if (state.isProcessing) {
            elements.navWarning?.classList.add('visible');
        } else {
            closeFountain();
        }
    }

    function handleBeforeUnload(e) {
        if (state.isProcessing) {
            e.preventDefault();
            e.returnValue = 'Your claim is being processed. Are you sure you want to leave?';
            return e.returnValue;
        }
    }

    // ============================================
    // CLAIM PROCESS
    // ============================================
    async function startClaim() {
        console.log('[Frequency Fountain] 🎬 startClaim() called');
        
        state.isProcessing = true;
        state.currentProgress = 0;
        state.coinsSpawned = 0;
        state.counterValue = 0;
        state.pileCoins = [];

        // Reset UI
        resetFountainUI();
        hideAllStates();
        
        // Show progress section
        elements.progressSection.style.display = 'block';
        elements.fountainWrapper.style.display = 'flex';
        console.log('[Frequency Fountain] Progress section and fountain wrapper displayed');

        // Activate fountain animation
        elements.fountain?.classList.add('active');
        console.log('[Frequency Fountain] Fountain activated');

        // Start progress simulation
        startProgressSimulation();

        // Start coin spawning
        startCoinAnimation();

        try {
            // Create abort controller for timeout
            state.abortController = new AbortController();

            // ═══════════════════════════════════════════════════════════════
            // NONCE REFRESH (v111): Refresh the WordPress nonce before
            // claiming to prevent "Security verification failed" on
            // cached/stale pages where the WP nonce has expired.
            // This is a best-effort operation — if it fails, we proceed
            // with the existing nonce (it may still be valid).
            // ═══════════════════════════════════════════════════════════════
            try {
                const ajaxUrl = (typeof ajaxurl !== 'undefined') 
                    ? ajaxurl 
                    : '/wp-admin/admin-ajax.php';
                
                const nonceResp = await fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=refresh_xft_nonce',
                    credentials: 'same-origin'
                });
                
                if (nonceResp.ok) {
                    const nonceData = await nonceResp.json();
                    if (nonceData.success && nonceData.data && nonceData.data.nonce) {
                        const nonceField = elements.claimForm.querySelector('[name="_wpnonce"]');
                        if (nonceField) {
                            nonceField.value = nonceData.data.nonce;
                            console.log('[Frequency Fountain] ✅ WP nonce refreshed');
                        }
                    }
                }
            } catch (nonceErr) {
                console.warn('[Frequency Fountain] Nonce refresh failed, using existing:', nonceErr);
                // Non-fatal — proceed with existing nonce
            }

            // Get form data (with refreshed nonce if available)
            const formData = new FormData(elements.claimForm);
            formData.append('ajax_claim', '1');

            // Get the form action URL properly
            const formActionUrl = elements.claimForm.getAttribute('action');
            console.log('[Frequency Fountain] Submitting to:', formActionUrl);

            // Send AJAX request
            const response = await fetch(formActionUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                signal: state.abortController.signal
            });

            // Check if response is JSON
            const contentType = response.headers.get('content-type');
            
            if (contentType && contentType.includes('application/json')) {
                const result = await response.json();

                if (result.success) {
                    state.claimStreak = result.streak || 1;
                    await completeAnimation(result.amount || state.rewardAmount, result.streak || 1);
                    showCompletion(result.amount || state.rewardAmount, result.streak || 1, result.next_claim || 86400);
                } else if (result.cooldown) {
                    showRecharging(result.time_left || 0);
                } else {
                    showError(result.error || 'Failed to claim rewards');
                }
            } else {
                // Response is HTML - check for success/error in URL
                const redirectUrl = response.url;
                if (redirectUrl) {
                    const urlParams = new URL(redirectUrl).searchParams;
                    
                    if (urlParams.has('success')) {
                        await completeAnimation(state.rewardAmount, 1);
                        showCompletion(state.rewardAmount, 1, 86400);
                    } else if (urlParams.has('error')) {
                        const errorMsg = decodeURIComponent(urlParams.get('error'));
                        
                        if (errorMsg.includes('wait') || errorMsg.includes('cooldown')) {
                            const timeMatch = errorMsg.match(/(\d+):(\d+):(\d+)/);
                            let timeLeft = 86400;
                            if (timeMatch) {
                                timeLeft = parseInt(timeMatch[1]) * 3600 + parseInt(timeMatch[2]) * 60 + parseInt(timeMatch[3]);
                            }
                            showRecharging(timeLeft);
                        } else {
                            showError(errorMsg);
                        }
                    } else {
                        showError('Unexpected response from server. Please try again.');
                    }
                }
            }

        } catch (error) {
            if (error.name === 'AbortError') {
                console.log('[Frequency Fountain] Request aborted');
            } else {
                console.error('[Frequency Fountain] Error:', error);
                showError('Network error. Please check your connection and try again.');
            }
        } finally {
            state.isProcessing = false;
            stopAnimations();
        }
    }

    // ============================================
    // COIN ANIMATION SYSTEM
    // ============================================
    function startCoinAnimation() {
        state.coinInterval = setInterval(() => {
            if (state.coinsSpawned < state.targetCoinCount && state.isProcessing) {
                spawnCoin();
                state.coinsSpawned++;
            }
        }, CONFIG.coinSpawnInterval);
    }

    function spawnCoin() {
        const coin = document.createElement('div');
        coin.className = 'ff-coin';
        
        // Random horizontal position
        const xPos = Math.random() * 140 + 20; // 20-160px range
        coin.style.left = `${xPos}px`;
        coin.style.top = '0px';
        
        // Random fall duration with variance
        const fallDuration = CONFIG.coinFallDuration + (Math.random() - 0.5) * CONFIG.coinFallVariance;
        coin.style.setProperty('--fall-duration', `${fallDuration}ms`);
        
        elements.coinsContainer.appendChild(coin);
        
        // Trigger falling animation
        requestAnimationFrame(() => {
            coin.classList.add('falling');
        });
        
        // Add to pile when fall completes
        setTimeout(() => {
            addToPile(xPos);
            coin.remove();
        }, fallDuration);
    }

    function addToPile(xPos) {
        const pileCount = calculatePileCount(state.rewardAmount);
        
        // Only add visible pile coins up to the calculated amount
        if (state.pileCoins.length >= pileCount) return;
        
        const pileCoin = document.createElement('div');
        pileCoin.className = 'ff-pile-coin';
        
        // Calculate pile position (layered stack effect)
        const layer = Math.floor(state.pileCoins.length / 6);
        const posInLayer = state.pileCoins.length % 6;
        
        // Wider spread at bottom, narrower at top
        const layerWidth = Math.max(120 - layer * 20, 40);
        const startX = (180 - layerWidth) / 2;
        const coinSpacing = layerWidth / 6;
        
        const finalX = startX + posInLayer * coinSpacing + (Math.random() - 0.5) * 10;
        const finalY = 55 - layer * 18; // Stack upward
        
        pileCoin.style.left = `${finalX}px`;
        pileCoin.style.bottom = `${finalY}px`;
        pileCoin.style.zIndex = layer + 1;
        
        // Slight rotation for natural look
        pileCoin.style.transform = `rotate(${(Math.random() - 0.5) * 15}deg)`;
        
        elements.coinPile.appendChild(pileCoin);
        
        // Animate in
        requestAnimationFrame(() => {
            pileCoin.classList.add('visible');
        });
        
        state.pileCoins.push(pileCoin);
    }

    // ============================================
    // PROGRESS SIMULATION
    // ============================================
    function startProgressSimulation() {
        state.progressInterval = setInterval(() => {
            if (state.currentProgress < 95 && state.isProcessing) {
                // Slow down as we approach 95%
                const increment = state.currentProgress < 50 ? 3 : 
                                 state.currentProgress < 80 ? 2 : 1;
                state.currentProgress = Math.min(95, state.currentProgress + increment);
                updateProgress(state.currentProgress);
            }
        }, CONFIG.progressInterval);
    }

    function updateProgress(progress) {
        if (elements.progressBar) {
            elements.progressBar.style.width = `${progress}%`;
        }
        
        // Update message based on progress
        for (let i = CONFIG.progressMessages.length - 1; i >= 0; i--) {
            if (progress >= CONFIG.progressMessages[i].threshold) {
                if (elements.progressMessage) {
                    const newMessage = CONFIG.progressMessages[i].message;
                    if (elements.progressMessage.textContent !== newMessage) {
                        elements.progressMessage.classList.add('changing');
                        setTimeout(() => {
                            elements.progressMessage.textContent = newMessage;
                            elements.progressMessage.classList.remove('changing');
                        }, 150);
                    }
                }
                break;
            }
        }
    }

    // ============================================
    // COUNTER ANIMATION
    // ============================================
    function animateCounter(targetValue, duration = CONFIG.counterAnimationDuration) {
        const startValue = state.counterValue;
        const startTime = performance.now();
        
        function update() {
            const elapsed = performance.now() - startTime;
            const progress = Math.min(elapsed / duration, 1);
            
            // Ease out quad
            const eased = 1 - (1 - progress) * (1 - progress);
            const currentValue = startValue + (targetValue - startValue) * eased;
            
            state.counterValue = currentValue;
            if (elements.counter) {
                elements.counter.textContent = Math.floor(currentValue).toLocaleString();
            }
            
            if (progress < 1) {
                requestAnimationFrame(update);
            }
        }
        
        requestAnimationFrame(update);
    }

    // ============================================
    // COMPLETION
    // ============================================
    async function completeAnimation(amount, streak) {
        // Complete progress to 100%
        state.currentProgress = 100;
        updateProgress(100);
        
        // Final counter animation
        animateCounter(amount, 500);
        
        // Wait for animations
        await new Promise(resolve => setTimeout(resolve, 800));
        
        // Stop coin spawning but keep fountain active
        clearInterval(state.coinInterval);
        
        // Mark fountain as completed (keeps water flowing, pile stays)
        elements.fountain?.classList.add('completed');
    }

    function showCompletion(amount, streak, nextClaim) {
        hideAllStates();
        
        // Mark claim as successful and store next claim time
        state.claimSuccessful = true;
        state.nextClaimTime = nextClaim;
        
        // Keep fountain wrapper visible with pile, but make it compact
        elements.fountainWrapper.style.display = 'flex';
        elements.fountainWrapper.classList.add('compact');
        elements.progressSection.style.display = 'none';
        
        // Show completion overlay
        elements.completion?.classList.add('visible');
        
        // Animate final amount
        animateFinalAmount(amount);
        
        // Show streak
        if (elements.streakCount) {
            elements.streakCount.textContent = streak;
        }
        
        // Handle streak display (hide if streak is 1)
        if (elements.completionStreak) {
            if (streak <= 1) {
                elements.completionStreak.style.display = 'none';
            } else {
                elements.completionStreak.style.display = 'flex';
            }
        }
        
        // Start next claim countdown
        startNextClaimCountdown(nextClaim);
        
        // Trigger confetti
        triggerConfetti();
        
        // Scroll to show completion content
        setTimeout(() => {
            elements.overlay?.scrollTo({ top: elements.overlay.scrollHeight, behavior: 'smooth' });
        }, 300);
    }

    function animateFinalAmount(targetAmount) {
        let currentAmount = 0;
        const duration = 1500;
        const startTime = performance.now();
        
        function update() {
            const elapsed = performance.now() - startTime;
            const progress = Math.min(elapsed / duration, 1);
            
            // Ease out
            const eased = 1 - Math.pow(1 - progress, 3);
            currentAmount = targetAmount * eased;
            
            if (elements.completionAmount) {
                elements.completionAmount.textContent = Math.floor(currentAmount).toLocaleString();
            }
            
            if (progress < 1) {
                requestAnimationFrame(update);
            }
        }
        
        requestAnimationFrame(update);
    }

    function startNextClaimCountdown(seconds) {
        let remaining = seconds;
        
        function updateTimer() {
            const hours = Math.floor(remaining / 3600);
            const mins = Math.floor((remaining % 3600) / 60);
            const secs = remaining % 60;
            
            if (elements.nextTimer) {
                elements.nextTimer.textContent = 
                    `${String(hours).padStart(2, '0')}:${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
            }
            
            if (remaining > 0) {
                remaining--;
                setTimeout(updateTimer, 1000);
            }
        }
        
        updateTimer();
    }

    // ============================================
    // ERROR & RECHARGING STATES
    // ============================================
    function showError(message) {
        hideAllStates();
        stopAnimations();
        
        elements.fountain?.classList.remove('active', 'completed');
        elements.fountainWrapper.style.display = 'none';
        elements.progressSection.style.display = 'none';
        
        if (elements.errorMessage) {
            elements.errorMessage.textContent = message;
        }
        elements.error?.classList.add('visible');
    }

    function showRecharging(timeLeft) {
        hideAllStates();
        stopAnimations();
        
        elements.fountain?.classList.remove('active', 'completed');
        elements.fountainWrapper.style.display = 'none';
        elements.progressSection.style.display = 'none';
        
        elements.recharging?.classList.add('visible');
        
        // Start countdown
        let remaining = timeLeft;
        function updateTimer() {
            const hours = Math.floor(remaining / 3600);
            const mins = Math.floor((remaining % 3600) / 60);
            const secs = remaining % 60;
            
            if (elements.rechargingTimer) {
                elements.rechargingTimer.textContent = 
                    `${String(hours).padStart(2, '0')}:${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
            }
            
            if (remaining > 0 && state.isOpen) {
                remaining--;
                setTimeout(updateTimer, 1000);
            }
        }
        updateTimer();
    }

    // ============================================
    // UI HELPERS
    // ============================================
    function hideAllStates() {
        elements.completion?.classList.remove('visible');
        elements.error?.classList.remove('visible');
        elements.recharging?.classList.remove('visible');
        elements.navWarning?.classList.remove('visible');
    }

    function resetFountainUI() {
        // Clear coins
        if (elements.coinsContainer) {
            elements.coinsContainer.innerHTML = '';
        }
        
        // Clear pile
        if (elements.coinPile) {
            elements.coinPile.innerHTML = '';
        }
        state.pileCoins = [];
        
        // Reset fountain state
        elements.fountain?.classList.remove('active', 'completed');
        elements.fountainWrapper?.classList.remove('compact');
        
        // Reset progress
        if (elements.progressBar) {
            elements.progressBar.style.width = '0%';
        }
        if (elements.progressMessage) {
            elements.progressMessage.textContent = 'Preparing fountain...';
        }
        
        // Reset counter
        if (elements.counter) {
            elements.counter.textContent = '0';
        }
        state.counterValue = 0;
        
        // Reset completion amount
        if (elements.completionAmount) {
            elements.completionAmount.textContent = '0';
        }
        
        hideAllStates();
    }

    function stopAnimations() {
        clearInterval(state.coinInterval);
        clearInterval(state.progressInterval);
        clearInterval(state.counterInterval);
    }

    // ============================================
    // CONFETTI
    // ============================================
    function triggerConfetti() {
        if (typeof confetti === 'function') {
            // Gold confetti burst
            confetti({
                particleCount: 100,
                spread: 70,
                origin: { y: 0.6 },
                colors: ['#d4af37', '#f4d03f', '#996515', '#ffe066', '#ffffff']
            });
            
            // Side bursts
            setTimeout(() => {
                confetti({
                    particleCount: 50,
                    angle: 60,
                    spread: 55,
                    origin: { x: 0 },
                    colors: ['#d4af37', '#f4d03f', '#996515']
                });
                confetti({
                    particleCount: 50,
                    angle: 120,
                    spread: 55,
                    origin: { x: 1 },
                    colors: ['#d4af37', '#f4d03f', '#996515']
                });
            }, 200);
        }
    }

    // ============================================
    // SHARE
    // ============================================
    function shareResults() {
        const amount = elements.completionAmount?.textContent || '0';
        const streak = elements.streakCount?.textContent || '1';
        
        const text = `🎉 I just claimed ${amount} XFT from the Frequency Fountain! 💰${streak > 1 ? ` (${streak} day streak 🔥)` : ''}\n\nClaim yours at imcollectibles.io/profile`;
        
        const twitterUrl = `https://twitter.com/intent/tweet?text=${encodeURIComponent(text)}`;
        window.open(twitterUrl, '_blank', 'width=550,height=420');
    }

    // ============================================
    // UTILITY FUNCTIONS
    // ============================================
    function calculateCoinCount(reward) {
        const normalized = Math.min(reward / CONFIG.rewardThreshold, 1);
        return Math.floor(CONFIG.minCoins + (CONFIG.maxCoins - CONFIG.minCoins) * normalized);
    }
    
    function calculatePileCount(reward) {
        const normalized = Math.min(reward / CONFIG.pileThreshold, 1);
        return Math.floor(CONFIG.minPileCoins + (CONFIG.maxPileCoins - CONFIG.minPileCoins) * normalized);
    }

    function generateBackgroundParticles() {
        const container = document.getElementById('ff-particles');
        if (!container) return;
        
        for (let i = 0; i < 20; i++) {
            const particle = document.createElement('div');
            particle.className = 'ff-particle';
            particle.style.left = `${Math.random() * 100}%`;
            particle.style.top = `${Math.random() * 100}%`;
            particle.style.animationDelay = `${Math.random() * 6}s`;
            particle.style.animationDuration = `${4 + Math.random() * 4}s`;
            container.appendChild(particle);
        }
    }

    // ============================================
    // INITIALIZE ON DOM READY
    // ============================================
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();