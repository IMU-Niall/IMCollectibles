document.addEventListener('DOMContentLoaded', function() {
    const { ajaxUrl, nonceTip, nonceTrustline, nonceLiveCheck, nonceReaction, nonceViewer, nonceChat, eventId, xrplAccount, hasXftTrustline, isYouTube, isTwitch, isDacastIframe, wsUrl, homeUrl } = eventReplayData;
    const chatForm = document.getElementById('event-chat-form');
    const chatMessages = document.getElementById('event-chat-messages');
    const reactionButtons = document.querySelectorAll('.reaction-btn');
    const reactionCounts = document.getElementById('reaction-counts');
    const reactionOverlay = document.getElementById('reaction-overlay');
    const audienceList = document.getElementById('audience-list');
    const audienceCount = document.getElementById('audience-count');
    const emojiToggle = document.querySelector('.emoji-toggle');
    const emojiPicker = document.getElementById('emoji-picker');
    const emojiButtons = document.querySelectorAll('.emoji-btn');
    const tipForm = document.getElementById('tip-form');
    const chatContainer = document.getElementById('chat-container');
    const chatToggleButton = document.getElementById('chat-toggle');
    const messageInput = document.getElementById('event-chat-message');
    let ws = null;

    // Debug: Log initialization and element presence
    console.log('IMU Events JS initialized:', { eventId, xrplAccount, isYouTube, isTwitch, isDacastIframe, wsUrl });
    console.log('Chat elements:', { chatForm: !!chatForm, chatMessages: !!chatMessages, messageInput: !!messageInput, chatContainer: !!chatContainer });
    if (!chatForm || !messageInput) {
        console.error('Chat form or input missing, chat functionality disabled');
        showToast('Chat input unavailable. Please refresh the page.', 'error');
    }

    // Fix WebSocket URL
    const correctedWsUrl = 'wss://events-chat.imcollectibles.io';
    
    

        // Initialize video player
    if (!isYouTube && !isTwitch && !isDacastIframe) {
        const video = document.getElementById('live-stream') || document.getElementById('replay-video');
        const streamUrl = video?.querySelector('source')?.src;
        if (video && streamUrl) {
            console.log(`Initializing video player for ${video.id}:`, streamUrl);
            if (Hls.isSupported()) {
                const hls = new Hls();
                hls.loadSource(streamUrl);
                hls.attachMedia(video);
                hls.on(Hls.Events.ERROR, function(event, data) {
                    console.error('HLS error:', data);
                    if (data.fatal) {
                        video.pause();
                        showToast(`Failed to load ${video.id === 'live-stream' ? 'live stream' : 'replay video'}. Please try again later.`);
                    }
                });
                                const player = videojs(video, {
                    controls: true,  // Explicitly enable controls
                    experimentalSvgIcons: true,  // Use SVG icons to fix font loading issues
                    html5: { hls: { overrideNative: true } },
                    controlBar: {
                        downloadButton: false,
                        pictureInPictureToggle: true  // Enable PIP
                    }
                });
                player.hlsQualitySelector();  // Add plugin after player init
                video.addEventListener('contextmenu', (e) => e.preventDefault());

                // Override fullscreen to full screen the .video-container (includes chat overlay)
                const videoContainer = document.querySelector('.video-container');
                const buttonContainer = document.querySelector('.button-container');
                player.requestFullscreen = function() {
                    if (videoContainer.requestFullscreen) {
                        videoContainer.requestFullscreen();
                    } else if (videoContainer.mozRequestFullScreen) {
                        videoContainer.mozRequestFullScreen();
                    } else if (videoContainer.webkitRequestFullscreen) {
                        videoContainer.webkitRequestFullscreen(Element.ALLOW_KEYBOARD_INPUT);
                    } else if (videoContainer.msRequestFullscreen) {
                        videoContainer.msRequestFullscreen();
                    }
                    return true;  // Indicate success
                };
                player.exitFullscreen = function() {
                    if (document.exitFullscreen) {
                        document.exitFullscreen();
                    } else if (document.mozCancelFullScreen) {
                        document.mozCancelFullScreen();
                    } else if (document.webkitExitFullscreen) {
                        document.webkitExitFullscreen();
                    } else if (document.msExitFullscreen) {
                        document.msExitFullscreen();
                    }
                    return true;
                };
                player.isFullscreen = function() {
                    return document.fullscreenElement === videoContainer ||
                           document.mozFullScreenElement === videoContainer ||
                           document.webkitFullscreenElement === videoContainer ||
                           document.msFullscreenElement === videoContainer;
                };

                // Update classes and move chat elements on fullscreen change
                const updateFullscreen = () => {
                    if (player.isFullscreen()) {
                        player.addClass('vjs-fullscreen');
                        // Move chat toggle and container inside videoContainer
                        if (chatToggleButton && videoContainer) {
                            videoContainer.appendChild(chatToggleButton);
                            chatToggleButton.style.position = 'absolute';
                            chatToggleButton.style.top = '10px';
                            chatToggleButton.style.right = '10px';
                            chatToggleButton.style.zIndex = '1000';
                        }
                        if (chatContainer && videoContainer) {
                            videoContainer.appendChild(chatContainer);
                            chatContainer.style.position = 'absolute';
                            chatContainer.style.top = '60px';
                            chatContainer.style.right = '10px';
                            chatContainer.style.left = 'auto';
                            chatContainer.style.width = '300px';  // Smaller for full screen
                            chatContainer.style.height = '400px';
                            chatContainer.style.zIndex = '2147483647';
                            // Disable dragging/resizing in full screen
                            chatHeader.style.cursor = 'default';
                            const resizeHandles = chatContainer.querySelectorAll('.resize-tl, .resize-tr, .resize-bl, .resize-br');
                            resizeHandles.forEach(handle => handle.style.display = 'none');
                        }
                    } else {
                        player.removeClass('vjs-fullscreen');
                        // Restore chat elements
                        if (chatToggleButton && buttonContainer) {
                            buttonContainer.appendChild(chatToggleButton);
                            chatToggleButton.style.position = '';
                            chatToggleButton.style.top = '';
                            chatToggleButton.style.right = '';
                            chatToggleButton.style.zIndex = '';
                        }
                        if (chatContainer) {
                            document.body.appendChild(chatContainer);
                            chatContainer.style.position = 'fixed';
                            chatContainer.style.top = '60px';
                            chatContainer.style.right = '';
                            chatContainer.style.left = 'calc(50% + 340px + 10px)';
                            chatContainer.style.width = '';
                            chatContainer.style.height = '';
                            // Re-enable dragging/resizing
                            chatHeader.style.cursor = 'move';
                            const resizeHandles = chatContainer.querySelectorAll('.resize-tl, .resize-tr, .resize-bl, .resize-br');
                            resizeHandles.forEach(handle => handle.style.display = 'block');
                        }
                    }
                };
                document.addEventListener('fullscreenchange', updateFullscreen);
                document.addEventListener('mozfullscreenchange', updateFullscreen);
                document.addEventListener('webkitfullscreenchange', updateFullscreen);
                document.addEventListener('MSFullscreenChange', updateFullscreen);
            } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                video.src = streamUrl;
                video.addEventListener('error', function() {
                    console.error(`Video error for ${video.id}`);
                    showToast(`Failed to load ${video.id === 'live-stream' ? 'live stream' : 'replay video'}. Please try again later.`);
                });
            } else {
                console.error('Video playback not supported');
                showToast('Your browser does not support video playback.');
            }
        } else {
            console.error('Video element or stream URL missing:', { video, streamUrl });
            showToast('Video player initialization failed.');
        }
    }
    
    

    // Show toast notification
    function showToast(message, type = 'error') {
        console.log(`Toast: ${message} (${type})`);
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        toast.style.position = 'fixed';
        toast.style.top = '20px';
        toast.style.right = '20px';
        toast.style.background = type === 'success' ? '#28a745' : '#333';
        toast.style.color = '#fff';
        toast.style.padding = '10px 20px';
        toast.style.borderRadius = '5px';
        toast.style.zIndex = '10000';
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.5s';
            setTimeout(() => toast.remove(), 500);
        }, 3000);
    }

    // Show toast with link for live event
    function showLiveEventToast(eventId) {
        const toast = document.createElement('div');
        toast.className = 'toast toast-success';
        toast.innerHTML = `A live event is happening now! <a href="${homeUrl}${eventId}" style="color: #d6ba66; text-decoration: underline;">Join now</a>`;
        toast.style.position = 'fixed';
        toast.style.top = '20px';
        toast.style.right = '20px';
        toast.style.background = '#28a745';
        toast.style.color = '#fff';
        toast.style.padding = '10px 20px';
        toast.style.borderRadius = '5px';
        toast.style.zIndex = '10000';
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.5s';
            setTimeout(() => toast.remove(), 500);
        }, 5000); // Longer duration for user to notice
        console.log(`Live event toast shown for event ID: ${eventId}`);
    }

        // Scroll to bottom of messages
    function scrollToBottom(force = false) {
        if (chatMessages) {
            const isNearBottom = chatMessages.scrollHeight - chatMessages.scrollTop - chatMessages.clientHeight < 100; // Increased tolerance for better UX
            if (force || isNearBottom) {
                requestAnimationFrame(() => {
                    chatMessages.scrollTop = chatMessages.scrollHeight;
                    console.log('Auto-scrolled to bottom (forced:', force, ')');
                });
            } else {
                console.log('User scrolled up; not auto-scrolling');
            }
        }
    }

    // Observe chat messages for new additions
    if (chatMessages) {
        const observer = new MutationObserver(() => {
            console.log('Chat messages mutated, scrolling to bottom');
            scrollToBottom();
        });
        observer.observe(chatMessages, { childList: true, subtree: true });
    }

    // WebSocket setup
    function connectWebSocket() {
        if (!eventId || eventId === '0' || !xrplAccount) {
            console.error('Invalid event ID or XRPL account for WebSocket:', { eventId, xrplAccount });
            showToast('Cannot connect to comments. Invalid event or account.');
            return;
        }
        console.log('Connecting to WebSocket:', `${correctedWsUrl}?event_id=${eventId}&xrpl_account=${encodeURIComponent(xrplAccount)}`);
        ws = new WebSocket(`${correctedWsUrl}?event_id=${eventId}&xrpl_account=${encodeURIComponent(xrplAccount)}`);
        ws.onopen = function() {
            console.log('Connected to WebSocket server');
            ws.send(JSON.stringify({ type: 'get_comments', event_id: eventId }));
            ws.send(JSON.stringify({ type: 'get_reactions', event_id: eventId }));
            ws.send(JSON.stringify({ type: 'get_audience', event_id: eventId }));
            ws.send(JSON.stringify({ type: 'heartbeat', event_id: eventId, xrpl_account: xrplAccount }));
            showToast(`Connected to ${isYouTube ? 'replay comments' : 'live chat'}`, 'success');
        };
        ws.onmessage = function(event) {
            try {
                const data = JSON.parse(event.data);
                console.log('WebSocket message received:', data);
                switch (data.type) {
                    case 'welcome':
                        console.log('WebSocket welcome message:', data.message);
                        break;
                    case 'comment':
                        if (chatMessages) {
                            const msg = document.createElement('p');
                            msg.innerHTML = `<span class="username">${data.user}</span>: ${data.comment}`;
                            chatMessages.appendChild(msg);
                            scrollToBottom();
                        }
                        break;
                    case 'comments':
                        if (chatMessages) {
                            chatMessages.innerHTML = '';
                            // Reverse comments to display oldest first
                            data.comments.reverse().forEach(m => {
                                const msg = document.createElement('p');
                                msg.innerHTML = `<span class="username">${m.user}</span>: ${m.comment}`;
                                chatMessages.appendChild(msg);
                            });
                            scrollToBottom();
                            console.log(`Loaded ${data.comments.length} comments`);
                        }
                        break;
                    case 'reaction':
                        if (reactionCounts && reactionOverlay) {
                            reactionCounts.innerHTML = Object.entries(data.counts)
                                .filter(([_, count]) => count > 0)
                                .map(([r, c]) => `${r}: ${c}`)
                                .join(' | ') || 'No reactions yet';
                            const emoji = document.createElement('div');
                            emoji.className = 'reaction-emoji';
                            emoji.textContent = data.reaction;
                            emoji.style.right = `${Math.random() * 50}px`;
                            reactionOverlay.appendChild(emoji);
                            setTimeout(() => emoji.remove(), 3000);
                        }
                        break;
                    case 'reactions':
                        if (reactionCounts) {
                            reactionCounts.innerHTML = Object.entries(data.counts)
                                .filter(([_, count]) => count > 0)
                                .map(([r, c]) => `${r}: ${c}`)
                                .join(' | ') || 'No reactions yet';
                        }
                        if (data.recent && data.recent.length > 0 && reactionOverlay) {
                            data.recent.forEach(reaction => {
                                const emoji = document.createElement('div');
                                emoji.className = 'reaction-emoji';
                                emoji.textContent = reaction.reaction;
                                emoji.style.right = `${Math.random() * 50}px`;
                                reactionOverlay.appendChild(emoji);
                                setTimeout(() => emoji.remove(), 3000);
                            });
                        }
                        break;
                    case 'audience':
                        if (audienceList && audienceCount) {
                            audienceCount.textContent = data.viewers.length;
                            audienceList.innerHTML = data.viewers.map(v => `
                                <div class="audience-member">
                                    <img src="${v.profile_pic_url || 'https://imcollectibles.io/wp-content/uploads/2023/12/default_profile.png'}" alt="${v.name}">
                                    <span>${v.name}</span>
                                </div>
                            `).join('');
                            console.log(`Updated audience: ${data.viewers.length} viewers`);
                        }
                        break;
                    case 'error':
                        console.error('WebSocket error:', data.error);
                        showToast(`Error: ${data.error}`);
                        if (data.type === 'reaction' || data.type === 'reactions') {
                            sendReactionFallback(data.reaction || '');
                        } else if (data.type === 'audience') {
                            fetchAudienceFallback();
                        }
                        break;
                }
            } catch (err) {
                console.error('WebSocket message error:', err);
                showToast('Failed to process server message');
            }
        };
        ws.onclose = function() {
            console.log('WebSocket closed, reconnecting...');
            showToast(`${isYouTube ? 'Comments' : 'Chat'} temporarily unavailable. Reconnecting...`);
            setTimeout(connectWebSocket, 5000);
        };
        ws.onerror = function(error) {
            console.error('WebSocket error:', error);
            showToast(`${isYouTube ? 'Comment' : 'Chat'} server connection error`);
        };
    }
    if (eventId && xrplAccount) connectWebSocket();

    // Heartbeat
    function sendHeartbeat() {
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({ type: 'heartbeat', event_id: eventId, xrpl_account: xrplAccount }));
            console.log('Sent heartbeat:', { eventId, xrplAccount });
        } else {
            showToast('Cannot update audience. Reconnecting...');
            setTimeout(connectWebSocket, 5000);
        }
        setTimeout(sendHeartbeat, 120000);
    }
    if (eventId && xrplAccount) setTimeout(sendHeartbeat, 120000);

    // Fallback AJAX for reactions
    function sendReactionFallback(reaction) {
        if (!reaction) return;
        console.log('Sending reaction via fallback:', { reaction, eventId, xrplAccount });
        const formData = new FormData();
        formData.append('action', 'handle_event_reaction');
        formData.append('reaction', reaction);
        formData.append('event_id', eventId);
        formData.append('xrpl_account', xrplAccount);
        formData.append('_wpnonce', nonceReaction);
        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'X-WP-Nonce': nonceReaction },
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(data => { throw new Error(data.error || 'Network response not ok'); });
            }
            return response.json();
        })
        .then(data => {
            console.log('Reaction fallback response:', data);
            if (data.success && reactionCounts && reactionOverlay) {
                reactionCounts.innerHTML = Object.entries(data.counts)
                    .filter(([_, count]) => count > 0)
                    .map(([r, c]) => `${r}: ${c}`)
                    .join(' | ') || 'No reactions yet';
                const emoji = document.createElement('div');
                emoji.className = 'reaction-emoji';
                emoji.textContent = reaction;
                emoji.style.right = `${Math.random() * 50}px`;
                reactionOverlay.appendChild(emoji);
                setTimeout(() => emoji.remove(), 3000);
            } else {
                showToast(`Failed to send reaction: ${data.error || 'Unknown error'}`);
            }
        })
        .catch(error => {
            console.error('Reaction fallback error:', error);
            showToast(`Error sending reaction: ${error.message}`);
        });
    }

    // Fallback AJAX for audience
    function fetchAudienceFallback() {
        console.log('Fetching audience via fallback:', { eventId });
        const formData = new FormData();
        formData.append('action', 'get_event_viewers');
        formData.append('event_id', eventId);
        formData.append('_wpnonce', nonceViewer);
        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'X-WP-Nonce': nonceViewer },
            body: formData
        })
        .then(response => {
            if (!response.ok) throw new Error('Network response not ok');
            return response.json();
        })
        .then(data => {
            console.log('Audience fallback response:', data);
            if (data.success && audienceList && audienceCount) {
                audienceCount.textContent = data.viewers.length;
                audienceList.innerHTML = data.viewers.map(v => `
                    <div class="audience-member">
                        <img src="${v.profile_pic_url || 'https://imcollectibles.io/wp-content/uploads/2023/12/default_profile.png'}" alt="${v.name}">
                        <span>${v.name}</span>
                    </div>
                `).join('');
            }
        })
        .catch(error => {
            console.error('Audience fetch error:', error);
            showToast('Failed to fetch audience data');
        });
    }

    // Chat form submission
    if (chatForm && chatMessages && messageInput) {
        chatForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const comment = messageInput.value.trim();
            if (!comment) {
                console.log('Empty comment, submission ignored');
                return;
            }
            console.log('Event chat form submitted:', { comment, eventId, xrplAccount });
            if (ws && ws.readyState === WebSocket.OPEN) {
                ws.send(JSON.stringify({
                    type: 'comment',
                    event_id: eventId,
                    xrpl_account: xrplAccount,
                    comment: comment
                }));
                messageInput.value = '';
                console.log('Comment sent via WebSocket');
            } else {
                showToast(`${isYouTube ? 'Comment' : 'Chat'} server not connected. Using fallback mode.`);
                console.log('Sending comment via AJAX fallback');
                const formData = new FormData();
                formData.append('action', 'handle_event_chat');
                formData.append('event_id', eventId);
                formData.append('xrpl_account', xrplAccount);
                formData.append('message', comment);
                formData.append('_wpnonce', nonceChat);
                fetch(ajaxUrl, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    console.log('Chat AJAX response:', data);
                    if (data.success) {
                        const msg = document.createElement('p');
                        msg.innerHTML = `<span class="username">${data.user}</span>: ${data.message}`;
                        chatMessages.appendChild(msg);
                        scrollToBottom();
                        messageInput.value = '';
                    } else {
                        showToast(`Failed to send ${isYouTube ? 'comment' : 'message'}: ${data.error || 'Unknown error'}`);
                    }
                })
                .catch(error => {
                    console.error('Chat AJAX error:', error);
                    showToast(`Error sending ${isYouTube ? 'comment' : 'message'}: ${error.message}`);
                });
            }
        });
    } else {
        console.error('Chat elements missing:', { chatForm, chatMessages, messageInput });
        showToast('Chat functionality unavailable due to missing elements.');
    }

    // Emoji picker toggle
    if (emojiToggle && emojiPicker) {
        emojiToggle.addEventListener('click', function() {
            emojiPicker.style.display = emojiPicker.style.display === 'none' || emojiPicker.style.display === '' ? 'flex' : 'none';
            console.log('Emoji picker toggled:', emojiPicker.style.display);
        });
        emojiButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                const emoji = this.getAttribute('data-emoji');
                if (messageInput) {
                    messageInput.value += emoji;
                    messageInput.focus();
                    console.log('Emoji added to input:', emoji);
                }
                emojiPicker.style.display = 'none';
            });
        });
    }

    // Reaction handling
    if (reactionButtons && reactionCounts && reactionOverlay) {
        reactionButtons.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const reaction = this.getAttribute('data-reaction');
                console.log('Reaction button clicked:', { reaction, eventId, xrplAccount });
                if (ws && ws.readyState === WebSocket.OPEN) {
                    ws.send(JSON.stringify({
                        type: 'reaction',
                        event_id: eventId,
                        xrpl_account: xrplAccount,
                        reaction: reaction
                    }));
                } else {
                    sendReactionFallback(reaction);
                }
            });
        });
    }

// v92: Dynamic currency loading based on recipient trustlines
const tipAccountSelect = document.getElementById('tip_account_id');
const currencySelect = document.getElementById('currency');
let availableTipCurrencies = null;

async function loadRecipientCurrencies() {
    if (!tipAccountSelect || !currencySelect) return;
    
    const selectedOption = tipAccountSelect.selectedOptions[0];
    if (!selectedOption || !selectedOption.dataset.xrpAddress) {
        // No recipient selected, show default options
        currencySelect.innerHTML = '<option value="XRP">💧 XRP</option>';
        return;
    }
    
    const recipientAddress = selectedOption.dataset.xrpAddress;
    currencySelect.innerHTML = '<option value="">Loading currencies...</option>';
    currencySelect.disabled = true;
    
    try {
        const url = new URL(ajaxUrl);
        url.searchParams.set('action', 'get_recipient_tip_currencies');
        url.searchParams.set('recipient', recipientAddress);
        url.searchParams.set('sender', xrplAccount);
        
        const response = await fetch(url.toString());
        const data = await response.json();
        
        if (data.success && data.currencies) {
            availableTipCurrencies = data.currencies;
            currencySelect.innerHTML = '';
            
            let hasAvailable = false;
            data.currencies.forEach(currency => {
                const option = document.createElement('option');
                option.value = currency.ticker;
                option.dataset.issuer = currency.issuer || '';
                
                // Check availability
                if (currency.ticker === 'XRP') {
                    option.textContent = `${currency.icon} ${currency.ticker}`;
                    hasAvailable = true;
                } else if (currency.available) {
                    // Both sender and recipient have trustline
                    option.textContent = `${currency.icon} ${currency.ticker}`;
                    hasAvailable = true;
                } else if (!currency.recipient_can_receive) {
                    // Recipient missing trustline
                    option.textContent = `${currency.icon} ${currency.ticker} (Artist can't receive)`;
                    option.disabled = true;
                } else if (!currency.sender_can_send) {
                    // Sender missing trustline
                    option.textContent = `${currency.icon} ${currency.ticker} (You need trustline)`;
                    option.disabled = true;
                }
                
                currencySelect.appendChild(option);
            });
            
            if (!hasAvailable) {
                currencySelect.innerHTML = '<option value="XRP">💧 XRP</option>';
            }
        } else {
            // Fallback to XRP only
            currencySelect.innerHTML = '<option value="XRP">💧 XRP</option>';
        }
    } catch (error) {
        console.error('Error loading recipient currencies:', error);
        currencySelect.innerHTML = '<option value="XRP">💧 XRP</option>';
    }
    
    currencySelect.disabled = false;
}

// Load currencies when recipient changes
if (tipAccountSelect) {
    tipAccountSelect.addEventListener('change', loadRecipientCurrencies);
    // Also load on initial page load if recipient is pre-selected
    if (tipAccountSelect.value) {
        loadRecipientCurrencies();
    }
}

// Replace the tipForm event listener (lines ~446-508)
// Replace the tipForm event listener (lines ~446-508)
if (tipForm) {
    tipForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const tipAccountId = document.getElementById('tip_account_id').value;
        const xrpAddress = document.getElementById('tip_account_id').selectedOptions[0].dataset.xrpAddress;
        const currency = document.getElementById('currency').value;
        const currencyOption = document.getElementById('currency').selectedOptions[0];
        const issuer = currencyOption?.dataset?.issuer || '';
        const amount = parseFloat(document.getElementById('amount').value);
        const memo = document.getElementById('memo').value.trim();
        console.log('Tip form submitted:', { tipAccountId, xrpAddress, currency, issuer, amount, memo });
        if (!tipAccountId || !xrpAddress || !currency || isNaN(amount) || amount < 0.01) {
            showToast('Please fill all required fields with valid values.');
            return;
        }
        if (memo.length > 255) {
            showToast('Message must be 255 characters or less.');
            return;
        }
        // v92: Check if selected currency is actually available
        if (currencyOption?.disabled) {
            showToast('Selected currency is not available for this recipient.');
            return;
        }
        const submitButton = tipForm.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        submitButton.textContent = 'Sending...';
        const formData = new FormData();
        formData.append('action', 'send_tip');
        formData.append('event_id', eventId);
        formData.append('xrpl_account', xrplAccount);
        formData.append('tip_account_id', tipAccountId);
        formData.append('xrp_address', xrpAddress);
        formData.append('currency', currency);
        formData.append('issuer', issuer); // v92: Pass issuer for multi-currency
        formData.append('amount', amount);
        formData.append('memo', memo);
        formData.append('_wpnonce', nonceTip);
        console.log('Sending tip request to:', ajaxUrl, { formData: Object.fromEntries(formData) });
        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'X-WP-Nonce': nonceTip },
            body: formData
        })
        .then(response => {
            console.log('Tip submission response status:', response.status);
            submitButton.disabled = false;
            submitButton.textContent = 'Send Tip';
            if (!response.ok) {
                return response.json().then(data => {
                    console.error('Tip submission failed with response:', data);
                    throw new Error(data.error || `Network response not ok (HTTP ${response.status})`);
                });
            }
            return response.json();
        })
        .then(data => {
            console.log('Tip response:', data);
            if (data.success) {
                const qrDiv = document.createElement('div');
                qrDiv.innerHTML = `
                    <p>Scan the QR code with your Xaman wallet to approve the tip:</p>
                    <img src="${data.qr}" alt="Xumm QR Code" class="tip-qr-image" />
                    <p>Or <a href="${data.deeplink}" class="xaman-link" target="_blank">open in Xaman</a>.</p>
                    <p>Waiting for confirmation...</p>
                `;
                tipForm.innerHTML = qrDiv.outerHTML;
                autoCheckTipStatus(data.uuid);
            } else {
                console.error('Tip submission failed:', data);
                showToast(`Failed to send tip: ${data.error || 'Unknown error'}. <a href="#" onclick="location.reload();">Retry</a>`, 'error');
            }
        })
        .catch(error => {
            submitButton.disabled = false;
            submitButton.textContent = 'Send Tip';
            console.error('Tip submission error:', error);
            showToast(`Error sending tip: ${error.message}. <a href="#" onclick="location.reload();">Retry</a>`, 'error');
        });
    });
}

// Replace autoCheckTipStatus (lines ~446-478)
function autoCheckTipStatus(uuid) {
    let attempts = 0;
    const maxAttempts = 120;
    console.log('Starting tip status check:', { uuid });
    const interval = setInterval(() => {
        const formData = new FormData();
        formData.append('action', 'check_tip_status');
        formData.append('uuid', uuid);
        formData.append('_wpnonce', nonceTip);
        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'X-WP-Nonce': nonceTip },
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(data => { throw new Error(data.error || 'Network response not ok'); });
            }
            return response.json();
        })
        .then(data => {
            console.log('Tip status check:', { uuid, attempts, data });
            attempts++;
            const trustlineQrDiv = document.getElementById('trustline-qr');
            if (data.success) {
                clearInterval(interval);
                tipForm.innerHTML = `
                    <div class="tip-success">
                        <h3>Tip Sent!</h3>
                        <div class="checkmark">✅</div>
                        <p>Your tip has been sent successfully.</p>
                    </div>
                `;
                setTimeout(() => location.reload(), 2000);
            } else if (attempts >= maxAttempts) {
                clearInterval(interval);
                tipForm.innerHTML = `<p>Tip timed out. <a href="#" onclick="location.reload();">Try again</a>.</p>`;
            }
        })
        .catch(error => {
            console.error('Tip status check error:', error);
            attempts++;
            if (attempts >= maxAttempts) {
                clearInterval(interval);
                tipForm.innerHTML = `<p>Error checking tip status: ${error.message}. <a href="#" onclick="location.reload();">Try again</a>.</p>`;
            }
        });
    }, 5000);
}

    // Trustline QR generation
    window.generateTrustlineQR = function() {
        const trustlineQrDiv = document.getElementById('trustline-qr');
        trustlineQrDiv.innerHTML = '<p>Generating trustline QR code...</p>';
        trustlineQrDiv.style.display = 'block';
        console.log('Generating trustline QR:', { xrplAccount });
        const formData = new FormData();
        formData.append('action', 'send_trustline');
        formData.append('xrpl_account', xrplAccount);
        formData.append('_wpnonce', nonceTrustline);
        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'X-WP-Nonce': nonceTrustline },
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(data => { throw new Error(data.error || 'Network response not ok'); });
            }
            return response.json();
        })
        .then(data => {
            console.log('Trustline QR response:', data);
            if (data.success) {
                trustlineQrDiv.innerHTML = `
                    <p>Scan the QR code with your Xaman wallet to set the XFT trustline:</p>
                    <img src="${data.qr}" alt="Xumm Trustline QR Code" class="trustline-qr-image" />
                    <p>Or <a href="${data.deeplink}" class="xaman-link" target="_blank">open in Xaman</a>.</p>
                    <p>Waiting for confirmation...</p>
                `;
                window.autoCheckTrustlineStatus(data.uuid);
            } else {
                trustlineQrDiv.innerHTML = `<p>Failed to generate trustline: ${data.error || 'Unknown error'}.</p>`;
            }
        })
        .catch(error => {
            console.error('Trustline QR error:', error);
            trustlineQrDiv.innerHTML = `<p>Error generating trustline: ${error.message}.</p>`;
        });
    };

    // Auto-check trustline status (REVISED - Handle updated response, close/reload on success)
window.autoCheckTrustlineStatus = function(uuid) {
    let attempts = 0;
    const maxAttempts = 120;
    console.log('Starting trustline status check:', { uuid });
    const interval = setInterval(() => {
        const formData = new FormData();
        formData.append('action', 'check_trustline_status');
        formData.append('uuid', uuid);
        formData.append('_wpnonce', nonceTrustline);
        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'X-WP-Nonce': nonceTrustline },
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(data => { throw new Error(data.error || 'Network response not ok'); });
            }
            return response.json();
        })
        .then(data => {
            console.log('Trustline status check:', { uuid, attempts, data });
            attempts++;
            const trustlineQrDiv = document.getElementById('trustline-qr');
            if (data.success) { // REVISED: Check data.success (true if signed and tx_hash present)
                clearInterval(interval);
                trustlineQrDiv.innerHTML = `
                    <div class="tip-success">
                        <h3>Trustline Set!</h3>
                        <div class="checkmark">✅</div>
                        <p>XFT trustline set successfully.</p>
                    </div>
                `;
                setTimeout(() => {
                    trustlineQrDiv.style.display = 'none';
                    location.reload(); // REVISED: Reload to update UI (e.g., enable XFT option)
                }, 2000);
            } else if (attempts >= maxAttempts) {
                clearInterval(interval);
                trustlineQrDiv.innerHTML = `<p>Trustline setup timed out. <a href="#" onclick="window.generateTrustlineQR()">Try again</a>.</p>`;
            }
        })
        .catch(error => {
            console.error('Trustline status check error:', error);
            attempts++;
            if (attempts >= maxAttempts) {
                clearInterval(interval);
                trustlineQrDiv.innerHTML = `<p>Trustline setup timed out. <a href="#" onclick="window.generateTrustlineQR()">Try again</a>.</p>`;
            }
        });
    }, 5000);
};

    // In imu-events.js, inside toggleChat()
    function toggleChat() {
        if (chatContainer) {
            const isOpening = chatContainer.style.display === 'none' || chatContainer.style.display === '';
            chatContainer.style.display = isOpening ? 'flex' : 'none';
            if (isOpening) {
                // Force reflow and z-index priority
                chatContainer.style.zIndex = '2147483647'; // Reaffirm high z-index
                const videoContainer = document.querySelector('.video-container');
                const isFullscreen = document.fullscreenElement || document.mozFullScreenElement || document.webkitFullscreenElement || document.msFullscreenElement;
                if (isFullscreen && videoContainer) {
                    videoContainer.appendChild(chatContainer); // Append to videoContainer in full screen
                    chatContainer.style.position = 'absolute';
                    chatContainer.style.top = '60px';
                    chatContainer.style.right = '10px';
                    chatContainer.style.left = 'auto';
                    chatContainer.style.width = '300px';
                    chatContainer.style.height = '400px';
                    // Disable dragging/resizing in full screen
                    const chatHeader = document.querySelector('.chat-header');
                    if (chatHeader) chatHeader.style.cursor = 'default';
                    const resizeHandles = chatContainer.querySelectorAll('.resize-tl, .resize-tr, .resize-bl, .resize-br');
                    resizeHandles.forEach(handle => handle.style.display = 'none');
                } else {
                    document.body.appendChild(chatContainer); // Re-parent to body otherwise
                    chatContainer.style.position = 'fixed';
                    // Re-enable dragging/resizing if not full screen
                    const chatHeader = document.querySelector('.chat-header');
                    if (chatHeader) chatHeader.style.cursor = 'move';
                    const resizeHandles = chatContainer.querySelectorAll('.resize-tl, .resize-tr, .resize-bl, .resize-br');
                    resizeHandles.forEach(handle => handle.style.display = 'block');
                }
                // Force reflow to fix rendering/underlay issues
                chatContainer.style.opacity = '0';
                setTimeout(() => {
                    chatContainer.style.opacity = '1';
                    scrollToBottom(true); // Force initial scroll
                    console.log('Chat opened, forced reflow and z-index');
                }, 10);
            }
        }
    }

    if (chatToggleButton) {
        chatToggleButton.addEventListener('click', toggleChat);
        console.log('Chat toggle button initialized');
    }

    // Make chat draggable and resizable (desktop only)
    const chatHeader = document.querySelector('.chat-header');
    if (chatContainer && chatHeader && window.innerWidth > 768) {
        let isDragging = false;
        let currentX = (window.innerWidth / 2) + 340 + 10;
        let currentY = isYouTube ? 60 : 100;
        let initialX, initialY;
        chatContainer.style.left = `${currentX}px`;
        chatContainer.style.top = `${currentY}px`;
        chatContainer.style.right = 'auto';
        chatHeader.addEventListener('mousedown', (e) => {
            initialX = e.clientX - currentX;
            initialY = e.clientY - currentY;
            isDragging = true;
            chatContainer.style.transition = 'none';
            console.log('Started dragging chat container:', { initialX, initialY });
        });
        document.addEventListener('mousemove', (e) => {
            if (isDragging) {
                e.preventDefault();
                currentX = e.clientX - initialX;
                currentY = e.clientY - initialY;
                chatContainer.style.left = `${currentX}px`;
                chatContainer.style.top = `${currentY}px`;
                chatContainer.style.right = 'auto';
            }
        });
        document.addEventListener('mouseup', () => {
            isDragging = false;
            chatContainer.style.transition = 'all 0.3s ease';
            console.log('Stopped dragging chat container:', { currentX, currentY });
        });
        const resizeHandles = chatContainer.querySelectorAll('.resize-tl, .resize-tr, .resize-bl, .resize-br');
        resizeHandles.forEach(handle => {
            handle.addEventListener('mousedown', (e) => {
                e.preventDefault();
                const startX = e.clientX;
                const startY = e.clientY;
                const startWidth = chatContainer.offsetWidth;
                const startHeight = chatContainer.offsetHeight;
                const startLeft = chatContainer.offsetLeft;
                const startTop = chatContainer.offsetTop;
                const isTopLeft = handle.classList.contains('resize-tl');
                const isTopRight = handle.classList.contains('resize-tr');
                const isBottomLeft = handle.classList.contains('resize-bl');
                const isBottomRight = handle.classList.contains('resize-br');
                console.log('Started resizing chat container:', { handle: handle.className });
                const onMouseMove = (moveEvent) => {
                    let newWidth, newHeight, newLeft, newTop;
                    if (isTopLeft) {
                        newWidth = startWidth - (moveEvent.clientX - startX);
                        newHeight = startHeight - (moveEvent.clientY - startY);
                        newLeft = startLeft + (moveEvent.clientX - startX);
                        newTop = startTop + (moveEvent.clientY - startY);
                    } else if (isTopRight) {
                        newWidth = startWidth + (moveEvent.clientX - startX);
                        newHeight = startHeight - (moveEvent.clientY - startY);
                        newLeft = startLeft;
                        newTop = startTop + (moveEvent.clientY - startY);
                    } else if (isBottomLeft) {
                        newWidth = startWidth - (moveEvent.clientX - startX);
                        newHeight = startHeight + (moveEvent.clientY - startY);
                        newLeft = startLeft + (moveEvent.clientX - startX);
                        newTop = startTop;
                    } else if (isBottomRight) {
                        newWidth = startWidth + (moveEvent.clientX - startX);
                        newHeight = startHeight + (moveEvent.clientY - startY);
                        newLeft = startLeft;
                        newTop = startTop;
                    }
                    if (newWidth >= 200) {
                        chatContainer.style.width = `${newWidth}px`;
                        chatContainer.style.left = `${newLeft}px`;
                    }
                    if (newHeight >= 300) {
                        chatContainer.style.height = `${newHeight}px`;
                        chatContainer.style.top = `${newTop}px`;
                    }
                };
                const onMouseUp = () => {
                    document.removeEventListener('mousemove', onMouseMove);
                    document.removeEventListener('mouseup', onMouseUp);
                    console.log('Stopped resizing chat container:', { width: chatContainer.offsetWidth, height: chatContainer.offsetHeight });
                };
                document.addEventListener('mousemove', onMouseMove);
                document.addEventListener('mouseup', onMouseUp);
            });
        });
    }

    // Check for live events (only for replay pages)
    let isPolling = false;
    if (isYouTube) {
        function checkLiveEvents() {
            if (isPolling) return;
            isPolling = true;
            console.log('Checking for live events');
            const formData = new FormData();
            formData.append('action', 'check_live_events');
            formData.append('_wpnonce', nonceLiveCheck);
            fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(data => { throw new Error(data.message || 'Network response not ok'); });
                }
                return response.json();
            })
            .then(data => {
                console.log('Live events check response:', data);
                if (data.success && data.data.live_event_ids && data.data.live_event_ids.length > 0) {
                    const newEventId = data.data.live_event_ids[0];
                    if (newEventId != eventId) {
                        showLiveEventToast(newEventId);
                    }
                } else if (data.success && data.data.live_event_ids.length === 0 && eventId !== '0') {
                    showToast('No live events available. Staying on replay.', 'success');
                }
            })
            .catch(error => console.error('Live events check error:', error))
            .finally(() => {
                isPolling = false;
                setTimeout(checkLiveEvents, 60000);
            });
        }
        if (eventId) checkLiveEvents();
    }
});