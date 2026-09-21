<?php
/*File: /wp-content/themes/astra/chat-handler.php
Handles chat-related AJAX requests
 */
require_once('../../../wp-load.php');

// Ensure JSON output
header('Content-Type: application/json; charset=UTF-8');
ob_start(); // Buffer output to prevent stray HTML// Enable error logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', WP_CONTENT_DIR . '/chat-handler.log');
// Keep diagnostic logging to the action name. Request bodies carry private
// message content and must not be written to disk.
error_log("chat-handler.php called at " . date('Y-m-d H:i:s') . " with action: " . ($_GET['action'] ?? $_POST['action'] ?? 'none'));// Authentication check
// Phase 1 (Aug 2026): token-first identity (imc_session_require_wallet);
// legacy cookie fallback stays gated behind IMC_SESSION_TOKEN_ONLY.
$imc_chat_auth = function_exists('imc_session_require_wallet')
    ? imc_session_require_wallet('')
    : array('ok' => false, 'wallet' => '', 'error' => 'auth');
if (empty($imc_chat_auth['ok']) || !preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', (string) $imc_chat_auth['wallet'])) {
    error_log('Chat: Authentication failed (no valid session token or legacy cookie)');
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    ob_end_flush();
    exit;
}// Verify CSRF or nonce for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitize_text_field($_POST['csrf_token'] ?? '');
    $nonce = sanitize_text_field($_POST['nonce'] ?? '');
    $is_mobile = isset($_POST['is_mobile']) && $_POST['is_mobile'] === '1' ? 'yes' : 'no';
    error_log("Chat: POST request received (Mobile: $is_mobile): action=" . ($_POST['action'] ?? 'none') . ", payload: " . json_encode($_POST));
    if (!check_ajax_referer('chat_nonce', 'csrf_token', false) && !wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        error_log("Chat: CSRF or nonce verification failed (Mobile: $is_mobile). CSRF: $csrf_token, Nonce: $nonce");
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token or nonce']);
        ob_end_flush();
        exit;
    }
}global $wpdb;
$current = $imc_chat_auth['wallet']; // Phase 1: session-derived, not the raw cookie

// ============================================================================
// P3-E: MINT the WebSocket auth token. GET ?action=chat_token.
// The relay (imc-dm-chat) now requires a token whose bound account matches the
// account sent in `init` - this closes the spoof (a client could previously
// register as ANY wallet with no proof). We bind the token to $current, the
// SESSION-VERIFIED wallet (imc_session_require_wallet above), never client input.
// Stateless HMAC over VPS_SHARED_SECRET so the relay verifies with NO DB read.
// Format: base64url(payload) . "." . base64url(HMAC). The relay shares only the secret.
// ============================================================================
if (($_GET['action'] ?? '') === 'chat_token') {
    $secret = defined('VPS_SHARED_SECRET') ? VPS_SHARED_SECRET : '';
    if ($secret === '' || $current === '') {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'unavailable']);
        ob_end_flush();
        exit;
    }
    $expiry  = time() + 86400;                 // 24h TTL (D: P3-E)
    $payload = $current . '.' . $expiry;
    // base64url helper (no padding, +/ -> -_) to match Node's 'base64url'.
    $b64url = function ($bin) {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    };
    $sig   = $b64url(hash_hmac('sha256', $payload, $secret, true));
    $token = $b64url($payload) . '.' . $sig;
    ob_clean();
    echo json_encode(['success' => true, 'token' => $token, 'expires' => $expiry]);
    ob_end_flush();
    exit;
}
$search = sanitize_text_field($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 10;// Verify nonce for GET requests with search, history, or chats
if (($search !== '' || isset($_GET['history_with']) || isset($_GET['chats'])) && (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'xrpl_marketplace_nonce'))) {
    error_log('Chat: Invalid nonce for search/history/chats: ' . ($_GET['nonce'] ?? 'none'));
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Invalid nonce']);
    ob_end_flush();
    exit;
}// Check if tables exist
$message_table = $wpdb->prefix . 'xrpl_messages';
$profile_table = $wpdb->prefix . 'xaman_profiles';
if (!$wpdb->get_var("SHOW TABLES LIKE '$message_table'") || !$wpdb->get_var("SHOW TABLES LIKE '$profile_table'")) {
    error_log('Chat: Missing tables - wp_xrpl_messages or wp_xaman_profiles');
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Database tables missing']);
    ob_end_flush();
    exit;
}// Prevent duplicate messages
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    $recipient = sanitize_text_field($_POST['recipient']);
    $message = wp_kses_post($_POST['message']);
    $is_mobile = isset($_POST['is_mobile']) && $_POST['is_mobile'] === '1' ? 'yes' : 'no';
    $timestamp = current_time('mysql');
    
    error_log("Chat: Attempting to send message (Mobile: $is_mobile): sender=$current, recipient=$recipient, message=" . substr($message, 0, 100));

    // Validate recipient
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $recipient) || empty($message)) {
        error_log("Chat: Invalid send_message input (Mobile: $is_mobile): recipient=$recipient, message=" . substr($message, 0, 100));
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Invalid recipient or message']);
        ob_end_flush();
        exit;
    }

    // Check for recent identical message (duplicate prevention)
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $message_table 
         WHERE sender = %s AND recipient = %s AND message = %s 
         AND timestamp > DATE_SUB(%s, INTERVAL 10 SECOND)",
        $current, $recipient, $message, $timestamp
    ));
    
    if ($exists) {
        error_log("Chat: Duplicate message detected (Mobile: $is_mobile): sender=$current, recipient=$recipient, message=" . substr($message, 0, 100));
        ob_clean();
        echo json_encode([
            'success' => true,
            'message_id' => $exists,
            'timestamp' => $timestamp,
            'content' => $message,
            'from' => $current,
            'to' => $recipient
        ]);
        ob_end_flush();
        exit;
    }
    
    // Insert new message
    $result = $wpdb->insert(
        $message_table,
        [
            'sender' => $current,
            'recipient' => $recipient,
            'message' => $message,
            'is_unread' => 1,
            'timestamp' => $timestamp
        ],
        ['%s', '%s', '%s', '%d', '%s']
    );
    
    if ($result === false) {
        error_log("Chat: Insert message error (Mobile: $is_mobile): " . $wpdb->last_error);
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Failed to save message: ' . $wpdb->last_error]);
        ob_end_flush();
        exit;
    }
    
    $message_id = $wpdb->insert_id;
    error_log("Chat: Message saved (Mobile: $is_mobile): id=$message_id, sender=$current, recipient=$recipient");
    
    ob_clean();
    echo json_encode([
        'success' => true,
        'message_id' => $message_id,
        'timestamp' => $timestamp,
        'content' => $message,
        'from' => $current,
        'to' => $recipient
    ]);
    ob_end_flush();
    exit;
}

// Fetch open chats
if (isset($_GET['chats'])) {
    $results = $wpdb->get_results(
        $wpdb->prepare("
            SELECT DISTINCT 
                CASE WHEN m.sender = %s THEN m.recipient ELSE m.sender END AS xrpl_account,
                p.name, p.profile_pic_url,
                MAX(m.timestamp) AS last_message_time,
                SUM(CASE WHEN m.is_unread = 1 AND m.recipient = %s THEN 1 ELSE 0 END) AS unread_count
            FROM $message_table m
            JOIN $profile_table p ON 
                (m.sender = p.xrpl_account AND m.sender != %s) OR 
                (m.recipient = p.xrpl_account AND m.recipient != %s)
            WHERE (m.sender = %s OR m.recipient = %s)
            GROUP BY xrpl_account, p.name, p.profile_pic_url
            ORDER BY last_message_time DESC
            LIMIT %d OFFSET %d
        ", $current, $current, $current, $current, $current, $current, $limit, ($page - 1) * $limit),
        ARRAY_A
    );if ($wpdb->last_error) {
    error_log('Chat: Fetch chats error: ' . $wpdb->last_error . ' for query: ' . $wpdb->last_query);
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $wpdb->last_error]);
    ob_end_flush();
    exit;
}

error_log('Chat: Fetched chats for user ' . $current . ': ' . json_encode($results));
ob_clean();
echo json_encode(['success' => true, 'chats' => $results]);
ob_end_flush();
exit;}// User search
if ($search !== '') {
    $like = '%' . $wpdb->esc_like($search) . '%';
    $results = $wpdb->get_results(
        $wpdb->prepare("
            SELECT xrpl_account, name, profile_pic_url
            FROM $profile_table
            WHERE (xrpl_account LIKE %s OR name LIKE %s) AND xrpl_account != %s
            LIMIT %d
        ", $like, $like, $current, $limit),
        ARRAY_A
    );if ($wpdb->last_error) {
    error_log('Chat: Search users error: ' . $wpdb->last_error . ' for query: ' . $wpdb->last_query);
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $wpdb->last_error]);
    ob_end_flush();
    exit;
}

error_log('Chat: Search users returned ' . count($results) . ' results for query: ' . $search);
ob_clean();
echo json_encode(['success' => true, 'users' => $results]);
ob_end_flush();
exit;}// Message history
if (isset($_GET['history_with'])) {
    $other = sanitize_text_field($_GET['history_with']);
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $other)) {
        error_log('Chat: Invalid recipient address: ' . $other);
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Invalid recipient address']);
        ob_end_flush();
        exit;
    }$history = $wpdb->get_results(
    $wpdb->prepare("
        SELECT id, sender, recipient, message, timestamp, reactions
        FROM $message_table
        WHERE (sender = %s AND recipient = %s) OR (sender = %s AND recipient = %s)
        ORDER BY timestamp DESC
        LIMIT 100
    ", $current, $other, $other, $current),
    ARRAY_A
);

if ($wpdb->last_error) {
    error_log('Chat: Fetch history error: ' . $wpdb->last_error . ' for query: ' . $wpdb->last_query);
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $wpdb->last_error]);
    ob_end_flush();
    exit;
}

error_log('Chat: Fetched history for ' . $other . ': ' . json_encode($history));
$wpdb->update(
    $message_table,
    ['is_unread' => 0, 'read_at' => current_time('mysql')],
    ['recipient' => $current, 'sender' => $other, 'is_unread' => 1],
    ['%d', '%s'],
    ['%s', '%s', '%d']
);

if ($wpdb->last_error) {
    error_log('Chat: Update read status error: ' . $wpdb->last_error);
}

ob_clean();
echo json_encode(['success' => true, 'messages' => array_reverse($history)]);
ob_end_flush();
exit;}// WordPress AJAX actions
add_action('wp_ajax_send_message', 'handle_send_message');
function handle_send_message() {
    global $wpdb;
    // Phase 1 (Aug 2026): session-derived identity (token-first).
    $imc_aj_auth = function_exists('imc_session_require_wallet') ? imc_session_require_wallet('') : array('ok' => false, 'wallet' => '');
    if (empty($imc_aj_auth['ok'])) { ob_clean(); echo json_encode(['success' => false, 'error' => 'Not authenticated']); ob_end_flush(); exit; }
    $current = $imc_aj_auth['wallet'];
    $recipient = sanitize_text_field($_POST['recipient']);
    $message = wp_kses_post($_POST['message']);
    $is_mobile = isset($_POST['is_mobile']) && $_POST['is_mobile'] === '1' ? 'yes' : 'no';
    $message_table = $wpdb->prefix . 'xrpl_messages';error_log("Chat: Processing send_message (Mobile: $is_mobile): sender=$current, recipient=$recipient, message=" . substr($message, 0, 100));

if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $recipient) || empty($message)) {
    error_log("Chat: Invalid send_message input (Mobile: $is_mobile): recipient=$recipient, message=" . substr($message, 0, 100));
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Invalid recipient or message']);
    ob_end_flush();
    exit;
}

$result = $wpdb->insert(
    $message_table,
    [
        'sender' => $current,
        'recipient' => $recipient,
        'message' => $message,
        'is_unread' => 1,
        'timestamp' => current_time('mysql')
    ],
    ['%s', '%s', '%s', '%d', '%s']
);

if ($result === false) {
    error_log("Chat: Insert message error (Mobile: $is_mobile): " . $wpdb->last_error);
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Failed to save message: ' . $wpdb->last_error]);
    ob_end_flush();
    exit;
}

$message_id = $wpdb->insert_id;
error_log("Chat: Message saved (Mobile: $is_mobile): id=$message_id, sender=$current, recipient=$recipient, message=" . substr($message, 0, 100));

ob_clean();
echo json_encode([
    'success' => true,
    'message_id' => $message_id,
    'timestamp' => current_time('mysql'),
    'content' => $message,
    'from' => $current,
    'to' => $recipient
]);
ob_end_flush();
exit;}add_action('wp_ajax_add_reaction', 'handle_add_reaction');
function handle_add_reaction() {
    global $wpdb;
    // Phase 1 (Aug 2026): session-derived identity (token-first).
    $imc_aj_auth = function_exists('imc_session_require_wallet') ? imc_session_require_wallet('') : array('ok' => false, 'wallet' => '');
    if (empty($imc_aj_auth['ok'])) { ob_clean(); echo json_encode(['success' => false, 'error' => 'Not authenticated']); ob_end_flush(); exit; }
    $current = $imc_aj_auth['wallet'];
    $message_id = intval($_POST['message_id']);
    $emoji = sanitize_text_field($_POST['emoji']);
    $is_mobile = isset($_POST['is_mobile']) && $_POST['is_mobile'] === '1' ? 'yes' : 'no';
    $message_table = $wpdb->prefix . 'xrpl_messages';error_log("Chat: Attempting to add reaction (Mobile: $is_mobile): message_id=$message_id, emoji=$emoji, user=$current");

$valid_emojis = ['', '', ''];
if (!in_array($emoji, $valid_emojis)) {
    error_log("Chat: Invalid emoji (Mobile: $is_mobile): $emoji");
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Invalid emoji']);
    ob_end_flush();
    exit;
}

$reactions = $wpdb->get_var($wpdb->prepare("SELECT reactions FROM $message_table WHERE id = %d", $message_id));
$reactions = json_decode($reactions ?: '[]', true);
$reactions[] = ['user' => $current, 'emoji' => $emoji];
$result = $wpdb->update($message_table, ['reactions' => json_encode($reactions)], ['id' => $message_id]);

if ($result === false) {
    error_log("Chat: Update reaction error (Mobile: $is_mobile): " . $wpdb->last_error);
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Failed to add reaction: ' . $wpdb->last_error]);
    ob_end_flush();
    exit;
}

error_log("Chat: Reaction added (Mobile: $is_mobile): message_id=$message_id, emoji=$emoji, user=$current");
ob_clean();
echo json_encode(['success' => true]);
ob_end_flush();
exit;}// Ensure no stray output
ob_end_flush();