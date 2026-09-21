/**
 * OneSignal Push Notifications - IMProtectors
 * Version: 1.0.0
 * 
 * Handles:
 * - OneSignal SDK initialization
 * - User subscription management
 * - Preference management UI
 * - Permission prompts
 */

(function() {
    'use strict';

    // Configuration from WordPress
    const config = window.oneSignalConfig || {
        appId: '',
        ajaxUrl: '/wp-admin/admin-ajax.php',
        nonce: '',
        userAccount: '',
        siteUrl: 'https://imcollectibles.io',
        safariWebId: ''
    };

    // State
    const state = {
        initialized: false,
        subscribed: false,
        playerId: null,
        subscriptionId: null,
        preferences: {
            notify_claims: true,
            notify_events: true,
            notify_chat: true,
            notify_games: true
        }
    };

    /**
     * Get XRPL account from cookie
     */
    function getXrplAccount() {
        const match = document.cookie.match(/xrpl_account=([^;]+)/);
        return match ? match[1] : null;
    }

    /**
     * Attach to the OneSignal SDK initialized by header.php.
     * header.php already calls OneSignal.init() via OneSignalDeferred.
     * This module must NOT call init() again — doing so throws in v16.
     * Instead we push a second deferred callback which runs after init completes.
     */
    async function initOneSignal() {
        if (state.initialized || !config.appId) {
            return;
        }

        try {
            window.OneSignalDeferred = window.OneSignalDeferred || [];

            await new Promise((resolve, reject) => {
                window.OneSignalDeferred.push(async function (OneSignal) {
                    try {
                        // SDK already initialized by header.php — just mark ready
                        state.initialized = true;

                        // Attach subscription/permission event listeners
                        setupEventListeners();

                        // Sync current subscription state into local state object
                        await checkSubscriptionState();

                        // Register wallet account with server if already subscribed
                        const userAccount = getXrplAccount() || config.userAccount;
                        if (userAccount && state.subscribed) {
                            await registerWithServer(userAccount);
                        }

                        resolve();
                    } catch (err) {
                        reject(err);
                    }
                });
            });

        } catch (error) {
            console.error('[OneSignal] Post-init setup failed:', error);
        }
    }

    /**
     * SDK is loaded via <script> tag in header.php — no dynamic loading needed.
     */
    function loadOneSignalSDK() {
        return Promise.resolve();
    }

    /**
     * Set up OneSignal event listeners
     */
    function setupEventListeners() {
        // Subscription change
        OneSignal.Notifications.addEventListener('permissionChange', (permission) => {
            console.log('[OneSignal] Permission changed:', permission);
            updateUI();
        });

        OneSignal.User.PushSubscription.addEventListener('change', (event) => {
            console.log('[OneSignal] Subscription changed:', event);
            state.subscribed = event.current.optedIn;
            state.subscriptionId = event.current.id;
            
            const userAccount = getXrplAccount() || config.userAccount;
            if (userAccount && state.subscribed) {
                registerWithServer(userAccount);
            }
            
            updateUI();
        });
    }

    /**
     * Check current subscription state
     */
    async function checkSubscriptionState() {
        try {
            const permission = await OneSignal.Notifications.permission;
            const pushSubscription = OneSignal.User.PushSubscription;
            
            state.subscribed = pushSubscription.optedIn;
            state.subscriptionId = pushSubscription.id;
            
            console.log('[OneSignal] Subscription state:', {
                permission,
                subscribed: state.subscribed,
                subscriptionId: state.subscriptionId
            });

            updateUI();
        } catch (error) {
            console.error('[OneSignal] Failed to check subscription:', error);
        }
    }

    /**
     * Request notification permission and subscribe
     */
    async function subscribe() {
        const userAccount = getXrplAccount() || config.userAccount;
        
        if (!userAccount) {
            showMessage('Please connect your wallet first to enable notifications.', 'warning');
            return false;
        }

        try {
            // Show slidedown prompt
            await OneSignal.Slidedown.promptPush();
            
            // Check if subscribed after prompt
            const pushSubscription = OneSignal.User.PushSubscription;
            
            if (pushSubscription.optedIn) {
                state.subscribed = true;
                state.subscriptionId = pushSubscription.id;
                
                // Register with our server
                await registerWithServer(userAccount);
                
                // Set external user ID
                await OneSignal.login(userAccount);
                
                showMessage('Push notifications enabled! 🎉', 'success');
                updateUI();
                return true;
            }
            
            return false;
        } catch (error) {
            console.error('[OneSignal] Subscribe failed:', error);
            showMessage('Failed to enable notifications. Please try again.', 'error');
            return false;
        }
    }

    /**
     * Unsubscribe from notifications
     */
    async function unsubscribe() {
        const userAccount = getXrplAccount() || config.userAccount;
        
        try {
            // Opt out of push
            await OneSignal.User.PushSubscription.optOut();
            
            state.subscribed = false;
            
            // Update server
            if (userAccount) {
                await fetch(config.ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'onesignal_unsubscribe',
                        nonce: config.nonce,
                        xrpl_account: userAccount
                    })
                });
            }
            
            showMessage('Push notifications disabled.', 'info');
            updateUI();
            return true;
        } catch (error) {
            console.error('[OneSignal] Unsubscribe failed:', error);
            return false;
        }
    }

    /**
     * Register subscription with our server
     */
    async function registerWithServer(userAccount) {
        try {
            const response = await fetch(config.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'onesignal_register',
                    nonce: config.nonce,
                    xrpl_account: userAccount,
                    player_id: state.playerId || '',
                    subscription_id: state.subscriptionId || ''
                })
            });

            const data = await response.json();
            
            if (data.success) {
                console.log('[OneSignal] Registered with server');
            } else {
                console.error('[OneSignal] Server registration failed:', data);
            }
        } catch (error) {
            console.error('[OneSignal] Server registration error:', error);
        }
    }

    /**
     * Update notification preferences
     */
    async function updatePreferences(preferences) {
        const userAccount = getXrplAccount() || config.userAccount;
        
        if (!userAccount) {
            showMessage('Please connect your wallet first.', 'warning');
            return false;
        }

        try {
            const formData = new URLSearchParams({
                action: 'onesignal_update_prefs',
                nonce: config.nonce,
                xrpl_account: userAccount
            });

            // Add each preference
            if (preferences.notify_claims) formData.append('notify_claims', '1');
            if (preferences.notify_events) formData.append('notify_events', '1');
            if (preferences.notify_chat) formData.append('notify_chat', '1');
            if (preferences.notify_games) formData.append('notify_games', '1');

            const response = await fetch(config.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            });

            const data = await response.json();
            
            if (data.success) {
                state.preferences = preferences;
                showMessage('Preferences saved!', 'success');
                return true;
            }
            
            showMessage('Failed to save preferences.', 'error');
            return false;
        } catch (error) {
            console.error('[OneSignal] Update preferences failed:', error);
            showMessage('Error saving preferences.', 'error');
            return false;
        }
    }

    /**
     * Get subscription status from server
     */
    async function getServerStatus() {
        const userAccount = getXrplAccount() || config.userAccount;
        
        if (!userAccount) {
            return null;
        }

        try {
            const response = await fetch(config.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'onesignal_get_status',
                    nonce: config.nonce,
                    xrpl_account: userAccount
                })
            });

            const data = await response.json();
            
            if (data.success && data.data.preferences) {
                state.preferences = data.data.preferences;
            }
            
            return data.data;
        } catch (error) {
            console.error('[OneSignal] Get status failed:', error);
            return null;
        }
    }

    /**
     * Update UI elements
     */
    function updateUI() {
        // Update toggle switches
        const mainToggle = document.getElementById('push-notifications-toggle');
        if (mainToggle) {
            mainToggle.checked = state.subscribed;
            mainToggle.closest('.push-toggle-container')?.classList.toggle('subscribed', state.subscribed);
        }

        // Update status text
        const statusEl = document.getElementById('push-status');
        if (statusEl) {
            if (state.subscribed) {
                statusEl.textContent = 'Push notifications are enabled';
                statusEl.className = 'push-status enabled';
            } else {
                statusEl.textContent = 'Push notifications are disabled';
                statusEl.className = 'push-status disabled';
            }
        }

        // Update preference checkboxes
        document.querySelectorAll('[data-pref]').forEach(el => {
            const pref = el.dataset.pref;
            if (state.preferences[pref] !== undefined) {
                el.checked = state.preferences[pref];
            }
        });

        // Enable/disable preference controls based on subscription state
        document.querySelectorAll('.notification-pref-control').forEach(el => {
            el.disabled = !state.subscribed;
        });
    }

    /**
     * Show message to user
     */
    function showMessage(message, type = 'info') {
        const container = document.getElementById('notification-messages') || document.body;
        
        const el = document.createElement('div');
        el.className = `onesignal-message onesignal-message-${type}`;
        el.textContent = message;
        el.style.cssText = `
            position: fixed;
            top: 100px;
            right: 20px;
            padding: 12px 20px;
            border-radius: 8px;
            font-family: system-ui, -apple-system, sans-serif;
            font-size: 14px;
            z-index: 999999;
            animation: slideIn 0.3s ease;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            max-width: 300px;
        `;
        
        // Color based on type
        const colors = {
            success: { bg: '#28a745', text: '#fff' },
            error: { bg: '#dc3545', text: '#fff' },
            warning: { bg: '#ffc107', text: '#000' },
            info: { bg: '#17a2b8', text: '#fff' }
        };
        
        const color = colors[type] || colors.info;
        el.style.backgroundColor = color.bg;
        el.style.color = color.text;

        container.appendChild(el);

        setTimeout(() => {
            el.style.animation = 'slideOut 0.3s ease forwards';
            setTimeout(() => el.remove(), 300);
        }, 4000);
    }

    /**
     * Initialize UI handlers
     */
    function initUIHandlers() {
        // Main toggle
        const mainToggle = document.getElementById('push-notifications-toggle');
        if (mainToggle) {
            mainToggle.addEventListener('change', async (e) => {
                mainToggle.disabled = true;
                
                if (e.target.checked) {
                    const success = await subscribe();
                    if (!success) {
                        e.target.checked = false;
                    }
                } else {
                    await unsubscribe();
                }
                
                mainToggle.disabled = false;
            });
        }

        // Subscribe button
        const subscribeBtn = document.getElementById('push-subscribe-btn');
        if (subscribeBtn) {
            subscribeBtn.addEventListener('click', async () => {
                subscribeBtn.disabled = true;
                subscribeBtn.textContent = 'Enabling...';
                
                await subscribe();
                
                subscribeBtn.disabled = false;
                subscribeBtn.textContent = state.subscribed ? 'Notifications Enabled' : 'Enable Notifications';
            });
        }

        // Preference checkboxes
        document.querySelectorAll('[data-pref]').forEach(el => {
            el.addEventListener('change', async () => {
                const newPrefs = { ...state.preferences };
                document.querySelectorAll('[data-pref]').forEach(checkbox => {
                    newPrefs[checkbox.dataset.pref] = checkbox.checked;
                });
                await updatePreferences(newPrefs);
            });
        });

        // Save preferences button (if using form)
        const savePrefsBtn = document.getElementById('save-notification-prefs');
        if (savePrefsBtn) {
            savePrefsBtn.addEventListener('click', async (e) => {
                e.preventDefault();
                
                const newPrefs = {};
                document.querySelectorAll('[data-pref]').forEach(checkbox => {
                    newPrefs[checkbox.dataset.pref] = checkbox.checked;
                });
                
                savePrefsBtn.disabled = true;
                savePrefsBtn.textContent = 'Saving...';
                
                await updatePreferences(newPrefs);
                
                savePrefsBtn.disabled = false;
                savePrefsBtn.textContent = 'Save Preferences';
            });
        }
    }

    /**
     * Add CSS for messages
     */
    function addStyles() {
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
            
            .push-toggle-container {
                background: #1a1a2e;
                border: 1px solid #333;
                border-radius: 12px;
                padding: 20px;
                margin: 15px 0;
            }
            
            .push-toggle-container h3 {
                color: #d4af37;
                margin: 0 0 15px 0;
                font-size: 18px;
            }
            
            .push-toggle-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 10px 0;
                border-bottom: 1px solid #333;
            }
            
            .push-toggle-row:last-child {
                border-bottom: none;
            }
            
            .push-toggle-label {
                color: #fff;
                font-size: 14px;
            }
            
            .push-toggle-label small {
                display: block;
                color: #888;
                font-size: 12px;
                margin-top: 4px;
            }
            
            .push-switch {
                position: relative;
                width: 50px;
                height: 26px;
            }
            
            .push-switch input {
                opacity: 0;
                width: 0;
                height: 0;
            }
            
            .push-slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: #444;
                transition: 0.3s;
                border-radius: 26px;
            }
            
            .push-slider:before {
                position: absolute;
                content: "";
                height: 20px;
                width: 20px;
                left: 3px;
                bottom: 3px;
                background-color: white;
                transition: 0.3s;
                border-radius: 50%;
            }
            
            input:checked + .push-slider {
                background-color: #28a745;
            }
            
            input:checked + .push-slider:before {
                transform: translateX(24px);
            }
            
            input:disabled + .push-slider {
                opacity: 0.5;
                cursor: not-allowed;
            }
            
            .push-status {
                text-align: center;
                padding: 10px;
                border-radius: 8px;
                margin-top: 15px;
                font-size: 13px;
            }
            
            .push-status.enabled {
                background: rgba(40, 167, 69, 0.2);
                color: #28a745;
            }
            
            .push-status.disabled {
                background: rgba(220, 53, 69, 0.2);
                color: #dc3545;
            }
            
            #push-subscribe-btn {
                width: 100%;
                padding: 12px 20px;
                background: linear-gradient(135deg, #d4af37 0%, #b8860b 100%);
                color: #000;
                border: none;
                border-radius: 8px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                margin-top: 15px;
            }
            
            #push-subscribe-btn:hover:not(:disabled) {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(212, 175, 55, 0.4);
            }
            
            #push-subscribe-btn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
        `;
        document.head.appendChild(style);
    }

    // ===========================================
    // PUBLIC API
    // ===========================================
    window.IMPNotifications = {
        init: initOneSignal,
        subscribe,
        unsubscribe,
        updatePreferences,
        getStatus: getServerStatus,
        isSubscribed: () => state.subscribed,
        getState: () => ({ ...state })
    };

    // ===========================================
    // AUTO-INITIALIZE
    // ===========================================
    document.addEventListener('DOMContentLoaded', () => {
        console.log('[OneSignal] DOM ready, initializing...');
        addStyles();
        initUIHandlers();
        
        // Only auto-init if we have an app ID
        if (config.appId) {
            initOneSignal();
        } else {
            console.warn('[OneSignal] No App ID configured');
        }
    });

})();