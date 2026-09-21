// chat.js - Fix Real-Time Updates and NFT Offer Message Visibility
document.addEventListener('DOMContentLoaded', () => {
    const toggleBtn = document.getElementById('open-chat-btn');
    const chatContainer = document.getElementById('sitewide-chat-container');
    const sidebar = document.querySelector('.chat-sidebar');
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const searchInput = document.getElementById('chat-user-search');
    const userList = document.getElementById('chat-user-list');
    const messageArea = document.getElementById('chat-messages');
    const chatHeader = document.getElementById('chat-header');
    const chatForm = document.getElementById('chat-form');
    const input = document.getElementById('chat-input');
    const typingIndicator = document.getElementById('typing-indicator');
    const emojiPickerBtn = document.getElementById('emoji-picker-btn');
    const emojiPicker = document.getElementById('emoji-picker');
    const globalUnreadBadge = document.getElementById('global-unread-badge');
    const chatMain = document.querySelector('.chat-main');

    let socket = null;
    let currentUser = '';
    let selectedUser = null;
    let typingTimeout = null;
    let chatCache = JSON.parse(localStorage.getItem('chatCache')) || {};
    let isSidebarMinimized = false;
    let currentUserProfilePic = 'default-avatar.png';
    let isLoadingMessages = false;
    let sentMessages = new Set(); // Track sent message IDs to prevent duplicates
    let pendingMessages = new Map(); // Track pending messages by temporary ID

    // Validate DOM elements
    if (!toggleBtn) console.warn('⚠️ Chat toggle button (#open-chat-btn) not found in DOM');
    if (!chatContainer) console.warn('⚠️ Chat container (#sitewide-chat-container) not found in DOM');
    if (!chatForm) console.warn('⚠️ Chat form (#chat-form) not found in DOM');

    // Validate ajaxUrl
    const ajaxUrl = chatConfig?.ajaxUrl || '/wp-admin/admin-ajax.php';
    // Chat module loaded

    // Get current user
    if (chatConfig?.xrplMarketplace?.user_account) {
        currentUser = chatConfig.xrplMarketplace.user_account;
    } else if (typeof xrpl_account !== 'undefined' && xrpl_account) {
        currentUser = xrpl_account;
    } else if (document.cookie.includes('xrpl_account=')) {
        currentUser = document.cookie.match(/xrpl_account=([^;]+)/)[1];
    }
    // Chat initialized

    // Fetch current user's profile picture
    async function fetchCurrentUserProfile() {
        try {
            const res = await fetch(`/wp-content/themes/astra/chat-handler.php?search=${encodeURIComponent(currentUser)}&nonce=${encodeURIComponent(chatConfig?.xrplMarketplace?.nonce || '')}`);
            const text = await res.text();
            console.log('Raw profile response:', text);
            const data = JSON.parse(text);
            if (data.success && data.users.length) {
                currentUserProfilePic = data.users[0].profile_pic_url || 'default-avatar.png';
                console.log('✅ Current user profile pic:', currentUserProfilePic);
            }
        } catch (err) {
            console.error('❌ Failed to fetch current user profile:', err);
            showToast('Failed to load profile picture', 'warning');
        }
    }

    // Show toast notification
    function showToast(message, type = 'error') {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 5000);
    }

    // Scroll to bottom of messages
    function scrollToBottom() {
        setTimeout(() => {
            if (messageArea) {
                messageArea.scrollTop = messageArea.scrollHeight;
                console.log('📜 Scrolled to bottom');
            }
        }, 500); // Increased delay for rendering
    }

    // Debounce utility
    function debounce(func, wait) {
        let timeout;
        return function (...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }

    // Clean WordPress-escaped message content
    // WP's wp_kses_post() double-escapes JSON: {\"type\":\"nft_card\"} and apostrophes: I\'m
    function cleanWpMessage(raw) {
        if (typeof raw !== 'string') return raw;
        // Strip escaped quotes and apostrophes added by WordPress
        let cleaned = raw.replace(/\\"/g, '"').replace(/\\'/g, "'");
        // Try parsing as JSON object if it looks like one
        if (cleaned.startsWith('{') && cleaned.endsWith('}')) {
            try {
                return JSON.parse(cleaned);
            } catch (e) {
                // Not valid JSON even after cleaning, return as text
            }
        }
        return cleaned;
    }

    // Fetch messages for a specific user
    const fetchMessages = debounce(async function(user, append = false) {
        if (!user?.xrpl_account) {
            console.error('❌ No user selected for fetching messages');
            return;
        }
        try {
            const res = await fetch(`/wp-content/themes/astra/chat-handler.php?history_with=${encodeURIComponent(user.xrpl_account)}&nonce=${encodeURIComponent(chatConfig?.xrplMarketplace?.nonce || '')}`);
            const text = await res.text();
            console.log('Raw history response:', text);
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('❌ History response not JSON:', text, e);
                showToast('Failed to load messages: Invalid server response');
                return;
            }
            if (data.success) {
                if (!append) messageArea.innerHTML = ''; // Clear unless appending
                data.messages.forEach(msg => {
                    let content = cleanWpMessage(msg.message);
                    displayMessage(
                        msg.sender,
                        content,
                        msg.timestamp,
                        msg.sender === currentUser,
                        JSON.parse(msg.reactions || '[]'),
                        msg.id
                    );
                });
                scrollToBottom();
            } else {
                console.error('❌ Fetch messages failed:', data);
                showToast('Failed to load messages: ' + (data.error || 'Unknown error'));
            }
        } catch (err) {
            console.error('❌ Fetch messages error:', err);
            showToast('Error loading messages');
        }
    }, 500);

    // Toggle Chat Popup
    if (toggleBtn) {
        toggleBtn.addEventListener('click', () => {
            if (chatContainer) {
                chatContainer.style.display = chatContainer.style.display === 'flex' ? 'none' : 'flex';
                if (chatContainer.style.display === 'flex') {
                    isSidebarMinimized = false;
                    sidebar.classList.remove('minimized');
                    sidebar.style.display = 'flex';
                    sidebar.style.width = '100%';
                    chatMain.style.display = 'none';
                    sidebarToggle.style.display = 'block';
                    fetchChats();
                    if (selectedUser && input) input.focus();
                }
            }
        });
    }

    // Toggle Sidebar
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', () => {
            isSidebarMinimized = !isSidebarMinimized;
            sidebar.classList.toggle('minimized', isSidebarMinimized);
            sidebarToggle.textContent = isSidebarMinimized ? '▶' : '☰';
            sidebarToggle.setAttribute('aria-label', isSidebarMinimized ? 'Expand chat list' : 'Collapse chat list');
            if (isSidebarMinimized) {
                sidebar.style.width = '60px';
                chatMain.style.display = 'flex';
            } else {
                sidebar.style.display = 'flex';
                sidebar.style.width = '100%';
                chatMain.style.display = 'none';
            }
        });
    }

    // Back to chat list
    function backToChatList() {
        selectedUser = null;
        sidebar.style.display = 'flex';
        sidebar.style.width = '100%';
        chatMain.style.display = 'none';
        chatHeader.innerHTML = 'Select a conversation';
        messageArea.innerHTML = '';
        fetchChats();
    }

    // WebSocket Setup
    let wsPingInterval = null;
    let wsReconnectDelay = 1000;
    const WS_RECONNECT_MAX = 30000;

    // P3-E: fetch a fresh HMAC auth token before each connect (24h TTL, so a
    // long-lived reconnect always re-mints). Bound server-side to the session
    // wallet, so the relay can verify we are who we claim in `init`.
    async function fetchChatToken() {
        try {
            const res = await fetch('/wp-content/themes/astra/chat-handler.php?action=chat_token', { credentials: 'include' });
            const data = await res.json();
            return (data && data.success) ? data.token : null;
        } catch (e) {
            console.error('❌ chat token fetch failed', e);
            return null;
        }
    }

    async function connectWebSocket() {
        if (!currentUser) {
            console.error('❌ No current user for WebSocket');
            showToast('Please log in to use chat');
            return;
        }

        // Clear any leaked ping interval from previous connection
        if (wsPingInterval) {
            clearInterval(wsPingInterval);
            wsPingInterval = null;
        }

        // P3-E: obtain the auth token BEFORE opening the socket.
        const chatToken = await fetchChatToken();
        if (!chatToken) {
            showToast('Chat auth failed — please refresh');
            return;
        }

        socket = new WebSocket('wss://chat.imcollectibles.io');
        socket.addEventListener('open', () => {
            console.log('✅ WebSocket connected for:', currentUser);
            wsReconnectDelay = 1000; // Reset backoff on successful connect
            socket.send(JSON.stringify({ type: 'init', account: currentUser, token: chatToken }));
            wsPingInterval = setInterval(() => {
                if (socket && socket.readyState === WebSocket.OPEN) {
                    socket.send(JSON.stringify({ type: 'ping' }));
                }
            }, 30000);
        });

        socket.addEventListener('message', (event) => {
            try {
                const data = JSON.parse(event.data);
                const isMobile = /Mobi|Android|iPhone|iPad|iPod/.test(navigator.userAgent);
                console.log(`📥 WebSocket message received (Mobile: ${isMobile}):`, data);
                if (data.type === 'message') {
                    // Check if message involves currentUser or is in their chat list
                    const isRelevantChat = chatCache.chats?.some(chat => chat.xrpl_account === data.from || chat.xrpl_account === data.to) || data.from === currentUser || data.to === currentUser;
                    if (!sentMessages.has(data.message_id) && isRelevantChat) {
                        let content = data.content;
                        if (typeof content === 'string' && content.startsWith('{') && content.endsWith('}')) {
                            try {
                                content = JSON.parse(content);
                            } catch (e) {
                                console.error('❌ Failed to parse WebSocket message content:', content, e);
                            }
                        }
                        // Determine recipient if 'to' is missing
                        let recipient = data.to;
                        if (!recipient && data.from !== currentUser) {
                            recipient = currentUser; // Assume currentUser is the recipient
                            console.warn(`⚠️ Missing 'to' field, assuming recipient: ${recipient}`);
                        }
                        // Display message if it matches the current chat
                        if (selectedUser && (data.from === selectedUser.xrpl_account || recipient === selectedUser.xrpl_account)) {
                            displayMessage(data.from, content, data.timestamp, data.from === currentUser, [], data.message_id);
                            scrollToBottom();
                        } else {
                            console.log(`ℹ️ Message for another chat: from=${data.from}, to=${recipient}`);
                            showToast(`New message from ${data.from}`, 'info');
                        }
                        updateChatList(data.from);
                        // Refresh open chat if relevant
                        if (selectedUser && (data.from === selectedUser.xrpl_account || recipient === selectedUser.xrpl_account)) {
                            fetchMessages(selectedUser);
                        }
                    } else {
                        console.log(`🔄 Skipping message: already processed or irrelevant (ID: ${data.message_id}, to: ${data.to}, from: ${data.from}, currentUser: ${currentUser})`);
                    }
                } else if (data.type === 'message_confirmed') {
                    if (!sentMessages.has(data.message_id)) {
                        sentMessages.add(data.message_id);
                        let content = data.content;
                        if (typeof content === 'string' && content.startsWith('{') && content.endsWith('}')) {
                            try {
                                content = JSON.parse(content);
                            } catch (e) {
                                console.error('❌ Failed to parse confirmed message content:', content, e);
                            }
                        }
                        const tempId = [...pendingMessages.entries()].find(([_, msg]) => msg.content === content && msg.to === data.to)?.[0];
                        if (tempId) {
                            const localMessage = messageArea.querySelector(`[data-message-id="${tempId}"]`);
                            if (localMessage) {
                                localMessage.dataset.messageId = data.message_id;
                                pendingMessages.delete(tempId);
                            }
                        } else if (data.from === currentUser && selectedUser && (data.to === selectedUser.xrpl_account || data.from === selectedUser.xrpl_account)) {
                            displayMessage(currentUser, content, data.timestamp, true, [], data.message_id);
                            scrollToBottom();
                        }
                        updateChatList(data.from);
                        // Refresh open chat if relevant
                        if (selectedUser && (data.from === selectedUser.xrpl_account || data.to === selectedUser.xrpl_account)) {
                            fetchMessages(selectedUser);
                        }
                    }
                } else if (data.type === 'presence') {
                    updatePresence(data.account, data.status);
                } else if (data.type === 'typing') {
                    showTypingIndicator(data.from);
                } else if (data.type === 'reaction') {
                    updateReaction(data.message_id, data.emoji, data.from);
                } else if (data.type === 'pong') {
                    console.log('🏓 WebSocket pong received');
                } else if (data.type === 'error') {
                    showToast(data.message);
                    console.error('❌ Server error:', data);
                }
            } catch (err) {
                showToast('Failed to process server message');
                console.error('❌ WebSocket message error:', err);
            }
        });

        socket.addEventListener('close', () => {
            // Clear ping interval to prevent leak on reconnect
            if (wsPingInterval) {
                clearInterval(wsPingInterval);
                wsPingInterval = null;
            }
            console.warn('🔌 WebSocket disconnected. Reconnecting in ' + (wsReconnectDelay / 1000) + 's...');
            showToast('Lost connection. Reconnecting...');
            setTimeout(connectWebSocket, wsReconnectDelay);
            // Exponential backoff: 1s → 2s → 4s → 8s → 16s → 30s max
            wsReconnectDelay = Math.min(wsReconnectDelay * 2, WS_RECONNECT_MAX);
        });

        socket.addEventListener('error', (err) => {
            console.error('❌ WebSocket error:', err);
            showToast('WebSocket connection error');
        });
    }

    // Display message with NFT card support (no description)
    function displayMessage(from, content, timestamp, isSent, reactions = [], message_id) {
        if (!message_id || sentMessages.has(message_id) || messageArea.querySelector(`[data-message-id="${message_id}"]`)) {
            console.log('🔄 Skipping duplicate message:', message_id);
            return;
        }
        sentMessages.add(message_id);
        const div = document.createElement('div');
        div.classList.add('message', isSent ? 'sent' : 'incoming');
        div.dataset.sender = from;
        div.dataset.timestamp = timestamp;
        div.dataset.messageId = message_id;
        const date = timestamp ? new Date(timestamp.replace(' ', 'T') + 'Z') : new Date();
        const time = isNaN(date) ? new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        const profilePic = isSent ? currentUserProfilePic : (selectedUser?.profile_pic_url || 'default-avatar.png');
        
        let contentHtml = '';
        if (typeof content === 'object' && content.type === 'nft_card') {
            contentHtml = `
                <div class="nft-card-message">
                    <img src="${content.image || '/wp-content/uploads/fallback-nft.svg'}" alt="${content.name || 'Unnamed NFT'}" class="nft-card-image">
                    <div class="nft-card-details">
                        <span class="nft-card-name">${content.name || 'Unnamed NFT'}</span>
                    </div>
                </div>`;
        } else {
            contentHtml = `<span class="message-content">${typeof content === 'string' ? content : JSON.stringify(content)}</span>`;
        }

        div.innerHTML = `
            ${isSent ? '' : `<img src="${profilePic}" alt="${selectedUser?.name || from}" class="message-avatar">`}
            <div class="message-body">
                ${contentHtml}
                <span class="timestamp">${time}</span>
                <div class="reactions"></div>
            </div>
        `;
        div.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            showReactionPicker(div, message_id);
        });
        messageArea.appendChild(div);
        scrollToBottom();
    }

    // Show reaction picker
    function showReactionPicker(messageEl, message_id) {
        const picker = document.createElement('div');
        picker.classList.add('reaction-picker');
        ['👍', '❤️', '😂'].forEach(emoji => {
            const btn = document.createElement('button');
            btn.textContent = emoji;
            btn.addEventListener('click', () => {
                addReactionToMessage(message_id, emoji);
                picker.remove();
            });
            picker.appendChild(btn);
        });
        messageEl.appendChild(picker);
        setTimeout(() => picker.remove(), 5000);
    }

    // Add reaction
    function addReaction(messageEl, emoji, user) {
        const reactionsDiv = messageEl.querySelector('.reactions');
        const reaction = document.createElement('span');
        reaction.classList.add('reaction');
        reaction.textContent = emoji;
        reaction.title = `Reacted by ${user}`;
        reactionsDiv.appendChild(reaction);
    }

    // Update reaction
    function updateReaction(message_id, emoji, from) {
        const messageEl = messageArea.querySelector(`[data-message-id="${message_id}"]`);
        if (messageEl) addReaction(messageEl, emoji, from);
    }

    // Show typing indicator
    function showTypingIndicator(from) {
        if (from === selectedUser?.xrpl_account) {
            typingIndicator.style.display = 'block';
            clearTimeout(typingTimeout);
            typingTimeout = setTimeout(() => {
                typingIndicator.style.display = 'none';
            }, 3000);
        }
    }

    // Update presence
    function updatePresence(account, status) {
        const li = userList.querySelector(`[data-xrpl="${account}"]`);
        if (li) {
            const dot = li.querySelector('.status-dot');
            dot.classList.toggle('online', status === 'online');
            dot.classList.toggle('offline', status !== 'online');
            dot.setAttribute('aria-label', status === 'online' ? 'Online' : 'Offline');
        }
    }

    // Fetch open chats
    async function fetchChats(page = 1) {
        try {
            const res = await fetch(`/wp-content/themes/astra/chat-handler.php?chats&page=${page}&nonce=${encodeURIComponent(chatConfig?.xrplMarketplace?.nonce || '')}`);
            const text = await res.text();
            console.log('Raw chats response:', text);
            const data = JSON.parse(text);
            if (data.success) {
                chatCache.chats = data.chats;
                localStorage.setItem('chatCache', JSON.stringify(chatCache));
                renderChatList(data.chats);
                updateGlobalUnreadBadge();
            } else {
                showToast('Failed to load chats: ' + (data.error || 'Unknown error'));
                console.error('❌ Fetch chats failed:', data);
            }
        } catch (err) {
            showToast('Error fetching chats');
            console.error('❌ Fetch chats error:', err);
        }
    }

    // Render chat list
    function renderChatList(chats) {
        userList.innerHTML = '';
        if (!chats.length) {
            userList.innerHTML = '<li>No conversations found.</li>';
            return;
        }

        chats.forEach(chat => {
            const li = document.createElement('li');
            li.dataset.xrpl = chat.xrpl_account;
            li.draggable = true;
            li.innerHTML = `
                <img src="${chat.profile_pic_url || 'default-avatar.png'}" alt="${chat.name || chat.xrpl_account}" class="avatar">
                <span class="chat-name">${chat.name || chat.xrpl_account}</span>
                <span class="status-dot offline" aria-label="Offline"></span>
                <span class="unread-badge" style="display: ${chat.unread_count > 0 ? 'inline' : 'none'};">${chat.unread_count}</span>
            `;
            li.addEventListener('click', () => {
                openChatWith(chat);
            });
            li.addEventListener('dragstart', (e) => e.dataTransfer.setData('text/plain', chat.xrpl_account));
            li.addEventListener('dragover', (e) => e.preventDefault());
            li.addEventListener('drop', (e) => {
                e.preventDefault();
                reorderChats(e.dataTransfer.getData('text/plain'), chat.xrpl_account);
            });
            userList.appendChild(li);
        });
    }

    // Reorder chats
    function reorderChats(draggedId, targetId) {
        const chats = [...chatCache.chats];
        const draggedIndex = chats.findIndex(c => c.xrpl_account === draggedId);
        const targetIndex = chats.findIndex(c => c.xrpl_account === targetId);
        const [dragged] = chats.splice(draggedIndex, 1);
        chats.splice(targetIndex, 0, dragged);
        chatCache.chats = chats;
        localStorage.setItem('chatCache', JSON.stringify(chatCache));
        renderChatList(chats);
    }

    // Update chat list on new message
    function updateChatList(from) {
        fetchChats();
    }

    // Update global unread badge
    function updateGlobalUnreadBadge() {
        const totalUnread = chatCache.chats?.reduce((sum, chat) => sum + (parseInt(chat.unread_count) || 0), 0) || 0;
        globalUnreadBadge.textContent = totalUnread;
        globalUnreadBadge.style.display = totalUnread > 0 ? 'inline' : 'none';
    }

    // Open chat
    async function openChatWith(user) {
        selectedUser = user;
        chatCache[user.xrpl_account] = chatCache[user.xrpl_account] || { page: 1, hasMoreMessages: true };
        chatHeader.innerHTML = `
            <button id="back-to-chats" style="background: none; border: none; color: #d6ba66; font-size: 20px; cursor: pointer; margin-right: 10px;">←</button>
            <span class="chat-title">Chatting with ${user.name || user.xrpl_account}</span>
            <img src="${user.profile_pic_url || 'default-avatar.png'}" alt="${user.name || user.xrpl_account}" class="header-avatar">
        `;
        document.getElementById('back-to-chats').addEventListener('click', backToChatList);
        sidebar.style.display = 'none';
        chatMain.style.display = 'flex';
        messageArea.innerHTML = '<p>Loading messages...</p>';
        input.focus();
        await fetchMessages(user); // Use fetchMessages to load chat
    }

    // Fetch NFT name from database as fallback
    async function fetchNftNameFromDb(nftId) {
        try {
            const res = await fetch(`/xumm-proxy.php?action=get_nft_name&nft_id=${encodeURIComponent(nftId)}&nonce=${encodeURIComponent(chatConfig?.xrplMarketplace?.nonce || '')}`);
            const text = await res.text();
            console.log('Raw DB response:', text);
            const data = JSON.parse(text);
            if (data.success && data.nft_name) {
                return { name: data.nft_name, image: data.image || '/wp-content/uploads/fallback-nft.svg' };
            }
            return { name: 'Unnamed NFT', image: '/wp-content/uploads/fallback-nft.svg' };
        } catch (err) {
            console.error('❌ Failed to fetch NFT name from DB:', err);
            return { name: 'Unnamed NFT', image: '/wp-content/uploads/fallback-nft.svg' };
        }
    }

    // Start a chat from trading.js, fetching user data and NFT metadata
    window.startChat = async function(recipient, nftId) {
        if (!recipient || !currentUser) {
            console.error('Invalid recipient or no current user:', { recipient, currentUser });
            showToast('Please log in to message the owner.', 'error');
            return;
        }
        try {
            // Fetch user data for the recipient
            const userRes = await fetch(`/wp-content/themes/astra/chat-handler.php?search=${encodeURIComponent(recipient)}&nonce=${encodeURIComponent(chatConfig?.xrplMarketplace?.nonce || '')}`);
            const text = await userRes.text();
            console.log('Raw user response:', text);
            const userData = JSON.parse(text);
            if (!userData.success || !userData.users.length) {
                console.error('Failed to fetch recipient data:', userData);
                showToast('User not found.', 'error');
                return;
            }
            const user = userData.users[0];

            // Check for existing chat
            let existingChat = null;
            try {
                const chatRes = await fetch(`/wp-content/themes/astra/chat-handler.php?chats&nonce=${encodeURIComponent(chatConfig?.xrplMarketplace?.nonce || '')}`);
                const text = await chatRes.text();
                console.log('Raw chats response:', text);
                const chatData = JSON.parse(text);
                if (chatData.success && Array.isArray(chatData.chats)) {
                    existingChat = chatData.chats.find(chat => chat.xrpl_account === recipient);
                }
            } catch (err) {
                console.error('Failed to fetch chats:', err);
                showToast('Error checking existing chats', 'warning');
            }

            // Fetch NFT metadata
            let nftName = 'Unnamed NFT';
            let nftImage = '/wp-content/uploads/fallback-nft.svg';
            try {
                const nftRes = await fetch(`/xumm-proxy.php?ids=${encodeURIComponent(nftId)}&nonce=${encodeURIComponent(chatConfig?.xrplMarketplace?.nonce || '')}`);
                const text = await nftRes.text();
                console.log('Raw NFT metadata response:', text);
                const nftData = JSON.parse(text);
                if (nftData.success && nftData.nfts.length > 0) {
                    nftName = nftData.nfts[0].metadata.name || 'Unnamed NFT';
                    nftImage = nftData.nfts[0].metadata.image || nftImage;
                } else {
                    console.warn('Failed to fetch NFT metadata, trying DB:', nftData);
                    const dbData = await fetchNftNameFromDb(nftId);
                    nftName = dbData.name;
                    nftImage = dbData.image;
                }
            } catch (err) {
                console.error('NFT metadata fetch error:', err);
                const dbData = await fetchNftNameFromDb(nftId);
                nftName = dbData.name;
                nftImage = dbData.image;
                showToast('Failed to fetch NFT details, using fallback.', 'warning');
            }

            // Store NFT ID in cache for context
            chatCache[recipient] = chatCache[recipient] || {};
            chatCache[recipient].contextNftId = nftId;
            localStorage.setItem('chatCache', JSON.stringify(chatCache));

            // Open or reuse chat
            selectedUser = user;
            await openChatWith(user);

            // Ensure chat container is open and main chat area is visible
            chatContainer.style.display = 'flex';
            sidebar.style.display = 'none';
            chatMain.style.display = 'flex';
            sidebarToggle.style.display = 'block';

            // Send pre-filled message
            const textMessage = "I'm interested in your NFT";
            await sendMessage(recipient, textMessage);

            // Send NFT card message
            const cardContent = {
                type: 'nft_card',
                nftId: nftId,
                name: nftName,
                image: nftImage
            };
            await sendMessage(recipient, cardContent);

            // Update chat list
            await fetchChats();
            console.log('Started chat with:', recipient, 'for NFT:', nftId, 'name:', nftName);
        } catch (err) {
            console.error('startChat error:', err);
            showToast(`Failed to start chat: ${err.message}`, 'error');
        }
    };

    // Send message via WebSocket with retry
    async function sendMessage(to, content, retries = 3) {
        if (!to || !content) {
            showToast('Invalid message or recipient', 'error');
            console.error('❌ Invalid sendMessage input:', { to, content });
            return false;
        }
        const isMobile = /Mobi|Android|iPhone|iPad|iPod/.test(navigator.userAgent);
        const messageId = Math.random().toString(36).substring(2, 15);
        const payload = {
            type: 'message',
            from: currentUser,
            to,
            content: typeof content === 'string' ? content : JSON.stringify(content),
            timestamp: new Date().toISOString(),
            message_id: messageId
        };
        pendingMessages.set(messageId, { to, content: payload.content });
        displayMessage(currentUser, content, payload.timestamp, true, [], messageId);
        console.log(`📤 Attempting to send message (Mobile: ${isMobile}):`, payload);

        if (socket && socket.readyState === WebSocket.OPEN) {
            socket.send(JSON.stringify(payload));
            console.log(`📤 WebSocket message sent (Mobile: ${isMobile}):`, payload);
        } else {
            console.warn(`⚠️ WebSocket not ready (Mobile: ${isMobile}), falling back to AJAX`);
        }

        // Save to server via AJAX
        try {
            const formData = new URLSearchParams({
                action: 'send_message',
                recipient: to,
                message: typeof content === 'string' ? content : JSON.stringify(content),
                csrf_token: chatConfig?.csrfToken || '',
                nonce: chatConfig?.xrplMarketplace?.nonce || '',
                is_mobile: isMobile ? '1' : '0'
            });
            const res = await fetch('/wp-content/themes/astra/chat-handler.php', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData,
                credentials: 'same-origin'
            });
            const text = await res.text();
            console.log(`📩 AJAX response (Mobile: ${isMobile}, Status: ${res.status}):`, text);
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error(`❌ AJAX response not JSON (Mobile: ${isMobile}):`, text, e);
                showToast('Server error: Message saved locally', 'warning');
                return await sendMessageToServer(to, content);
            }
            if (!data.success) {
                console.error(`❌ AJAX send failed (Mobile: ${isMobile}):`, data);
                showToast(data.error || 'Failed to save message.', 'error');
                return await sendMessageToServer(to, content);
            }
            console.log(`✅ Message confirmed (Mobile: ${isMobile}):`, data.message_id);
            sentMessages.add(data.message_id);
            const localMessage = messageArea.querySelector(`[data-message-id="${messageId}"]`);
            if (localMessage) {
                localMessage.dataset.messageId = data.message_id;
                pendingMessages.delete(messageId);
            }
            scrollToBottom();
            updateChatList(to);
            return true;
        } catch (err) {
            console.error(`❌ AJAX send failed (Mobile: ${isMobile}):`, err);
            showToast('Failed to save message, trying fallback.', 'warning');
            return await sendMessageToServer(to, content);
        }
    }

    // Fallback server-side message sending
    async function sendMessageToServer(to, content) {
        const isMobile = /Mobi|Android|iPhone|iPad|iPod/.test(navigator.userAgent);
        const messageId = Math.random().toString(36).substring(2, 15);
        pendingMessages.set(messageId, { to, content: typeof content === 'string' ? content : JSON.stringify(content) });
        console.log(`📤 Fallback AJAX send (Mobile: ${isMobile}):`, { to, content });
        try {
            const formData = new URLSearchParams({
                action: 'send_message',
                recipient: to,
                message: typeof content === 'string' ? content : JSON.stringify(content),
                csrf_token: chatConfig?.csrfToken || '',
                nonce: chatConfig?.xrplMarketplace?.nonce || '',
                is_mobile: isMobile ? '1' : '0'
            });
            const res = await fetch('/wp-content/themes/astra/chat-handler.php', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData,
                credentials: 'same-origin'
            });
            const text = await res.text();
            console.log(`📩 Fallback AJAX response (Mobile: ${isMobile}, Status: ${res.status}):`, text);
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error(`❌ Fallback AJAX response not JSON (Mobile: ${isMobile}):`, text, e);
                showToast('Server error: Message saved locally', 'warning');
                return true;
            }
            if (!data.success) {
                console.error(`❌ Fallback AJAX send failed (Mobile: ${isMobile}):`, data);
                showToast(data.error || 'Failed to save message.', 'error');
                return true;
            }
            console.log(`✅ Fallback message confirmed (Mobile: ${isMobile}):`, data.message_id);
            sentMessages.add(data.message_id);
            const localMessage = messageArea.querySelector(`[data-message-id="${messageId}"]`);
            if (localMessage) {
                localMessage.dataset.messageId = data.message_id;
                pendingMessages.delete(messageId);
            }
            scrollToBottom();
            updateChatList(to);
            return true;
        } catch (err) {
            console.error(`❌ Fallback AJAX send failed (Mobile: ${isMobile}):`, err);
            showToast('Failed to save message, saved locally.', 'error');
            return true;
        }
    }

    // Message form submission with debouncing and enhanced mobile support
    let isSubmitting = false;
    async function handleMessageSubmit(e) {
        e.preventDefault();
        if (isSubmitting) {
            console.log('🔄 Submission debounced');
            return;
        }
        isSubmitting = true;
        const isMobile = /Mobi|Android|iPhone|iPad|iPod/.test(navigator.userAgent);
        console.log(`📤 Handling message submit (Mobile: ${isMobile}, Event: ${e.type})`);
        const content = input.value.trim();
        if (content && selectedUser) {
            try {
                await sendMessage(selectedUser.xrpl_account, content);
                input.value = '';
            } catch (err) {
                console.error(`❌ Message send error (Mobile: ${isMobile}):`, err);
                showToast('Failed to send message', 'error');
            }
        } else {
            console.warn(`⚠️ Invalid submission (Mobile: ${isMobile}):`, { content, selectedUser });
        }
        setTimeout(() => { isSubmitting = false; }, 500);
    }

    if (chatForm) {
        chatForm.addEventListener('submit', handleMessageSubmit);
        chatForm.addEventListener('touchstart', (e) => {
            if (document.activeElement === input) {
                console.log('📱 Touchstart triggered');
                handleMessageSubmit(e);
            }
        });
        chatForm.addEventListener('touchend', (e) => {
            if (document.activeElement === input) {
                console.log('📱 Touchend triggered');
                handleMessageSubmit(e);
            }
        });
        chatForm.addEventListener('click', (e) => {
            if (document.activeElement === input) {
                console.log('📱 Click triggered');
                handleMessageSubmit(e);
            }
        });
        input.addEventListener('blur', () => {
            if (input.value.trim() && selectedUser) {
                console.log('📱 Input blur triggered, submitting');
                handleMessageSubmit(new Event('submit'));
            }
        });
    }

    // Send typing event
    function sendTyping() {
        if (!socket || !selectedUser) return;
        clearTimeout(typingTimeout);
        typingTimeout = setTimeout(() => {
            if (socket.readyState === WebSocket.OPEN) {
                socket.send(JSON.stringify({ type: 'typing', to: selectedUser.xrpl_account }));
            }
        }, 500);
    }

    // Add reaction
    async function addReactionToMessage(message_id, emoji) {
        const formData = new FormData();
        formData.append('action', 'add_reaction');
        formData.append('message_id', message_id);
        formData.append('emoji', emoji);
        formData.append('csrf_token', chatConfig?.csrfToken || '');
        formData.append('nonce', chatConfig?.xrplMarketplace?.nonce || '');

        try {
            const res = await fetch(`${ajaxUrl}?action=add_reaction`, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (data.success && socket) {
                socket.send(JSON.stringify({ type: 'reaction', message_id, emoji, from: currentUser, to: selectedUser?.xrpl_account }));
            } else {
                showToast('Failed to add reaction: ' + (data.error || 'Server error'));
                console.error('❌ Reaction failed:', data);
            }
        } catch (err) {
            showToast('Error adding reaction');
            console.error('❌ Reaction error:', err);
        }
    }

    // Emoji picker
    function initEmojiPicker() {
        try {
            const picker = document.createElement('emoji-picker');
            emojiPicker.appendChild(picker);
            picker.addEventListener('emoji-click', (e) => {
                input.value += e.detail.unicode;
                emojiPicker.style.display = 'none';
                input.focus();
            });
            emojiPickerBtn.addEventListener('click', () => {
                emojiPicker.style.display = emojiPicker.style.display === 'block' ? 'none' : 'block';
            });
            console.log('✅ Emoji picker initialized');
        } catch (err) {
            console.error('❌ Emoji picker initialization failed:', err);
            showToast('Failed to load emoji picker');
        }
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', (e) => {
        if (e.ctrlKey && e.key === 'k') {
            e.preventDefault();
            searchInput.focus();
            if (selectedUser) {
                backToChatList();
            }
        }
        if (e.key === 'Enter' && !e.shiftKey && document.activeElement === input) {
            chatForm.dispatchEvent(new Event('submit'));
        }
    });

    // User search
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            const query = searchInput.value.trim();
            if (!query) {
                fetchChats();
                return;
            }
            fetch(`/wp-content/themes/astra/chat-handler.php?search=${encodeURIComponent(query)}&nonce=${encodeURIComponent(chatConfig?.xrplMarketplace?.nonce || '')}`)
                .then(res => res.text())
                .then(text => {
                    console.log('Raw search response:', text);
                    const data = JSON.parse(text);
                    if (data.success) {
                        renderChatList(data.users);
                    } else {
                        showToast('Failed to search users: ' + (data.error || 'Unknown error'));
                        console.error('❌ Search failed:', data);
                    }
                })
                .catch(err => {
                    showToast('Error searching users');
                    console.error('❌ Search error:', err);
                });
        });
    }

    // Typing detection
    if (input) input.addEventListener('input', sendTyping);

    // Lazy-load messages
    messageArea.addEventListener('scroll', () => {
        if (messageArea.scrollTop === 0 && selectedUser && !isLoadingMessages && chatCache[selectedUser.xrpl_account]?.hasMoreMessages) {
            isLoadingMessages = true;
            const page = (chatCache[selectedUser.xrpl_account]?.page || 1) + 1;
            const currentScrollHeight = messageArea.scrollHeight;
            fetch(`/wp-content/themes/astra/chat-handler.php?history_with=${encodeURIComponent(selectedUser.xrpl_account)}&page=${page}&nonce=${encodeURIComponent(chatConfig?.xrplMarketplace?.nonce || '')}`)
                .then(res => res.text())
                .then(text => {
                    console.log('Raw lazy-load response:', text);
                    const data = JSON.parse(text);
                    if (data.success) {
                        if (data.messages.length === 0) {
                            chatCache[selectedUser.xrpl_account].hasMoreMessages = false;
                            localStorage.setItem('chatCache', JSON.stringify(chatCache));
                            console.log('🚫 No more messages to load for:', selectedUser.xrpl_account);
                        } else {
                            chatCache[selectedUser.xrpl_account].page = page;
                            localStorage.setItem('chatCache', JSON.stringify(chatCache));
                            data.messages.reverse().forEach(msg => {
                                if (!messageArea.querySelector(`[data-message-id="${msg.id}"]`)) {
                                    let content = cleanWpMessage(msg.message);
                                    const div = document.createElement('div');
                                    div.classList.add('message', msg.sender === currentUser ? 'sent' : 'incoming');
                                    div.dataset.sender = msg.sender;
                                    div.dataset.timestamp = msg.timestamp;
                                    div.dataset.messageId = msg.id;
                                    const date = msg.timestamp ? new Date(msg.timestamp.replace(' ', 'T') + 'Z') : new Date();
                                    const time = isNaN(date) ? new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                                    const profilePic = msg.sender === currentUser ? currentUserProfilePic : (selectedUser?.profile_pic_url || 'default-avatar.png');
                                    let contentHtml = '';
                                    if (typeof content === 'object' && content.type === 'nft_card') {
                                        contentHtml = `
                                            <div class="nft-card-message">
                                                <img src="${content.image || '/wp-content/uploads/fallback-nft.svg'}" alt="${content.name || 'Unnamed NFT'}" class="nft-card-image">
                                                <div class="nft-card-details">
                                                    <span class="nft-card-name">${content.name || 'Unnamed NFT'}</span>
                                                </div>
                                            </div>`;
                                    } else {
                                        contentHtml = `<span class="message-content">${typeof content === 'string' ? content : JSON.stringify(content)}</span>`;
                                    }
                                    div.innerHTML = `
                                        ${msg.sender === currentUser ? '' : `<img src="${profilePic}" alt="${selectedUser?.name || msg.sender}" class="message-avatar">`}
                                        <div class="message-body">
                                            ${contentHtml}
                                            <span class="timestamp">${time}</span>
                                            <div class="reactions"></div>
                                        </div>
                                    `;
                                    div.addEventListener('contextmenu', (e) => {
                                        e.preventDefault();
                                        showReactionPicker(div, msg.id);
                                    });
                                    messageArea.insertBefore(div, messageArea.firstChild);
                                    JSON.parse(msg.reactions || '[]').forEach(r => addReaction(div, r.emoji, r.user));
                                } else {
                                    console.log('🔄 Skipping duplicate message:', msg.id);
                                }
                            });
                            messageArea.scrollTop = messageArea.scrollHeight - currentScrollHeight;
                        }
                    } else {
                        showToast('Failed to load more messages: ' + (data.error || 'Unknown error'));
                        console.error('❌ Lazy-load failed:', data);
                    }
                    isLoadingMessages = false;
                })
                .catch(err => {
                    showToast('Error loading more messages');
                    console.error('❌ Lazy-load error:', err);
                    isLoadingMessages = false;
                });
        }
    });

    // Initialize
    if (!currentUser) {
        showToast('Please log in to use chat');
        return;
    }
    fetchChats();
    fetchCurrentUserProfile();
    connectWebSocket();
    initEmojiPicker();
});