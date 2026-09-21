<?php
/**
 * OneSignal Push Notification System for IMProtectors
 * Version: 1.0.0
 * 
 * Handles:
 * - User subscription management
 * - Daily claim ready notifications
 * - Event notifications (live, new)
 * - Gaming rewards ready notifications
 * - Chat message notifications (batched)
 */

if (!defined('ABSPATH')) {
    exit;
}

class IMProtectors_OneSignal {
    
    private static $instance = null;
    private $app_id;
    private $api_key;
    private $log_file;
    
    // Notification types
    const TYPE_CLAIM_READY = 'claim_ready';
    const TYPE_EVENT_LIVE = 'event_live';
    const TYPE_EVENT_NEW = 'event_new';
    const TYPE_GAME_READY = 'game_ready';
    const TYPE_CHAT_MESSAGE = 'chat_message';
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->app_id = defined('ONESIGNAL_APP_ID') ? ONESIGNAL_APP_ID : '';
        $this->api_key = defined('ONESIGNAL_API_KEY') ? ONESIGNAL_API_KEY : '';
        $this->log_file = WP_CONTENT_DIR . '/onesignal-notifications.log';
        
        // Initialize hooks
        add_action('init', [$this, 'init']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        
        // AJAX handlers
        add_action('wp_ajax_onesignal_register', [$this, 'ajax_register_subscription']);
        add_action('wp_ajax_nopriv_onesignal_register', [$this, 'ajax_register_subscription']);
        add_action('wp_ajax_onesignal_update_prefs', [$this, 'ajax_update_preferences']);
        add_action('wp_ajax_onesignal_unsubscribe', [$this, 'ajax_unsubscribe']);
        add_action('wp_ajax_onesignal_get_status', [$this, 'ajax_get_status']);
        
        // Event hooks
        add_action('imu_event_status_changed', [$this, 'on_event_status_change'], 10, 3);
        add_action('imu_event_created', [$this, 'on_event_created'], 10, 1);
        
        // Chat message hook
        add_action('imu_chat_message_sent', [$this, 'on_chat_message'], 10, 3);
        
        // Cron schedules
        add_action('onesignal_check_claim_cooldowns', [$this, 'check_claim_cooldowns']);
        add_action('onesignal_check_game_cooldowns', [$this, 'check_game_cooldowns']);
        add_action('onesignal_send_batched_chat', [$this, 'send_batched_chat_notifications']);
        
        // Schedule cron events
        $this->schedule_cron_events();
    }
    
    public function init() {
        $this->create_tables();
    }
    
    /**
     * Create necessary database tables
     */
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'onesignal_subscriptions';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            $sql = "CREATE TABLE $table_name (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                xrpl_account VARCHAR(50) NOT NULL,
                onesignal_player_id VARCHAR(100) DEFAULT NULL,
                subscription_id VARCHAR(100) DEFAULT NULL,
                device_type VARCHAR(20) DEFAULT 'web',
                notify_claims TINYINT(1) DEFAULT 1,
                notify_events TINYINT(1) DEFAULT 1,
                notify_chat TINYINT(1) DEFAULT 1,
                notify_games TINYINT(1) DEFAULT 1,
                last_claim_notified DATETIME DEFAULT NULL,
                last_game_notified DATETIME DEFAULT NULL,
                pending_chat_count INT DEFAULT 0,
                last_chat_batch DATETIME DEFAULT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY xrpl_account (xrpl_account),
                KEY onesignal_player_id (onesignal_player_id),
                KEY is_active (is_active)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            
            $this->log("Created onesignal_subscriptions table");
        }
        
        // Chat notifications queue table
        $queue_table = $wpdb->prefix . 'onesignal_chat_queue';
        if ($wpdb->get_var("SHOW TABLES LIKE '$queue_table'") !== $queue_table) {
            $sql = "CREATE TABLE $queue_table (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                recipient_account VARCHAR(50) NOT NULL,
                sender_account VARCHAR(50) NOT NULL,
                sender_name VARCHAR(100) DEFAULT NULL,
                message_preview VARCHAR(100) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                processed TINYINT(1) DEFAULT 0,
                PRIMARY KEY (id),
                KEY recipient_account (recipient_account),
                KEY processed (processed),
                KEY created_at (created_at)
            ) $charset_collate;";
            
            dbDelta($sql);
            $this->log("Created onesignal_chat_queue table");
        }
    }
    
    /**
     * Schedule cron events
     */
    private function schedule_cron_events() {
        // Check claim cooldowns every 15 minutes
        if (!wp_next_scheduled('onesignal_check_claim_cooldowns')) {
            wp_schedule_event(time(), 'fifteen_minutes', 'onesignal_check_claim_cooldowns');
        }
        
        // Check game cooldowns every 15 minutes
        if (!wp_next_scheduled('onesignal_check_game_cooldowns')) {
            wp_schedule_event(time(), 'fifteen_minutes', 'onesignal_check_game_cooldowns');
        }
        
        // Send batched chat notifications every 5 minutes
        if (!wp_next_scheduled('onesignal_send_batched_chat')) {
            wp_schedule_event(time(), 'five_minutes', 'onesignal_send_batched_chat');
        }
    }
    
    /**
     * Enqueue frontend scripts
     */
    public function enqueue_scripts() {
        wp_enqueue_script(
            'onesignal-notifications',
            get_template_directory_uri() . '/js/onesignal-notifications.js',
            ['jquery'],
            '1.0.0',
            true
        );
        
        wp_localize_script('onesignal-notifications', 'oneSignalConfig', [
            'appId' => $this->app_id,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('onesignal_nonce'),
            'userAccount' => function_exists('imc_session_wallet') ? imc_session_wallet() : '',
            'siteUrl' => home_url(),
            'safariWebId' => defined('ONESIGNAL_SAFARI_WEB_ID') ? ONESIGNAL_SAFARI_WEB_ID : '',
        ]);
    }
    
    /**
     * Register subscription via AJAX
     */
    public function ajax_register_subscription() {
        check_ajax_referer('onesignal_nonce', 'nonce');
        
        $xrpl_account = sanitize_text_field($_POST['xrpl_account'] ?? '');
        $player_id = sanitize_text_field($_POST['player_id'] ?? '');
        $subscription_id = sanitize_text_field($_POST['subscription_id'] ?? '');
        
        if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $xrpl_account)) {
            wp_send_json_error(['message' => 'Invalid XRPL account']);
        }
        // Phase 1 Batch 2 (Aug 2026): identity from the session token; the
        // posted account must match the session wallet or the call is rejected.
        $os_auth = function_exists('imc_session_require_wallet')
            ? imc_session_require_wallet($xrpl_account)
            : array('ok' => false, 'wallet' => '', 'error' => 'auth');
        if (empty($os_auth['ok'])) {
            $this->log("onesignal_register rejected: " . ((($os_auth['error'] ?? '') === 'mismatch') ? "account mismatch (posted != session)" : "not authenticated"));
            wp_send_json_error(['message' => (($os_auth['error'] ?? '') === 'mismatch') ? 'Account mismatch' : 'Not authenticated'], 403);
        }
        $xrpl_account = $os_auth['wallet'];
        
        if (empty($player_id) && empty($subscription_id)) {
            wp_send_json_error(['message' => 'Player ID or Subscription ID required']);
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'onesignal_subscriptions';
        
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE xrpl_account = %s",
            $xrpl_account
        ));
        
        $data = [
            'xrpl_account' => $xrpl_account,
            'onesignal_player_id' => $player_id,
            'subscription_id' => $subscription_id,
            'is_active' => 1,
            'updated_at' => current_time('mysql')
        ];
        
        if ($existing) {
            $wpdb->update($table, $data, ['xrpl_account' => $xrpl_account]);
            $this->log("Updated subscription for $xrpl_account: player_id=$player_id");
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($table, $data);
            $this->log("Created subscription for $xrpl_account: player_id=$player_id");
        }
        
        // Tag user in OneSignal
        $this->tag_user($player_id ?: $subscription_id, $xrpl_account);
        
        wp_send_json_success(['message' => 'Subscription registered']);
    }
    
    /**
     * Update notification preferences
     */
    public function ajax_update_preferences() {
        check_ajax_referer('onesignal_nonce', 'nonce');
        
        $xrpl_account = sanitize_text_field($_POST['xrpl_account'] ?? '');
        
        if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $xrpl_account)) {
            wp_send_json_error(['message' => 'Invalid XRPL account']);
        }
        // Phase 1 Batch 2 (Aug 2026): identity from the session token; the
        // posted account must match the session wallet or the call is rejected.
        $os_auth = function_exists('imc_session_require_wallet')
            ? imc_session_require_wallet($xrpl_account)
            : array('ok' => false, 'wallet' => '', 'error' => 'auth');
        if (empty($os_auth['ok'])) {
            $this->log("onesignal_update_prefs rejected: " . ((($os_auth['error'] ?? '') === 'mismatch') ? "account mismatch (posted != session)" : "not authenticated"));
            wp_send_json_error(['message' => (($os_auth['error'] ?? '') === 'mismatch') ? 'Account mismatch' : 'Not authenticated'], 403);
        }
        $xrpl_account = $os_auth['wallet'];
        
        global $wpdb;
        $table = $wpdb->prefix . 'onesignal_subscriptions';
        
        $data = [
            'notify_claims' => isset($_POST['notify_claims']) ? 1 : 0,
            'notify_events' => isset($_POST['notify_events']) ? 1 : 0,
            'notify_chat' => isset($_POST['notify_chat']) ? 1 : 0,
            'notify_games' => isset($_POST['notify_games']) ? 1 : 0,
            'updated_at' => current_time('mysql')
        ];
        
        $result = $wpdb->update($table, $data, ['xrpl_account' => $xrpl_account]);
        
        if ($result !== false) {
            $this->log("Updated preferences for $xrpl_account: " . json_encode($data));
            wp_send_json_success(['message' => 'Preferences updated', 'preferences' => $data]);
        } else {
            wp_send_json_error(['message' => 'Failed to update preferences']);
        }
    }
    
    /**
     * Get subscription status
     */
    public function ajax_get_status() {
        check_ajax_referer('onesignal_nonce', 'nonce');
        
        $xrpl_account = sanitize_text_field($_POST['xrpl_account'] ?? '');
        
        if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $xrpl_account)) {
            wp_send_json_error(['message' => 'Invalid XRPL account']);
        }
        // Phase 1 Batch 2 (Aug 2026): identity from the session token; the
        // posted account must match the session wallet or the call is rejected.
        $os_auth = function_exists('imc_session_require_wallet')
            ? imc_session_require_wallet($xrpl_account)
            : array('ok' => false, 'wallet' => '', 'error' => 'auth');
        if (empty($os_auth['ok'])) {
            $this->log("onesignal_get_status rejected: " . ((($os_auth['error'] ?? '') === 'mismatch') ? "account mismatch (posted != session)" : "not authenticated"));
            wp_send_json_error(['message' => (($os_auth['error'] ?? '') === 'mismatch') ? 'Account mismatch' : 'Not authenticated'], 403);
        }
        $xrpl_account = $os_auth['wallet'];
        
        global $wpdb;
        $table = $wpdb->prefix . 'onesignal_subscriptions';
        
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE xrpl_account = %s",
            $xrpl_account
        ), ARRAY_A);
        
        if ($subscription) {
            wp_send_json_success([
                'subscribed' => (bool)$subscription['is_active'],
                'preferences' => [
                    'notify_claims' => (bool)$subscription['notify_claims'],
                    'notify_events' => (bool)$subscription['notify_events'],
                    'notify_chat' => (bool)$subscription['notify_chat'],
                    'notify_games' => (bool)$subscription['notify_games'],
                ]
            ]);
        } else {
            wp_send_json_success(['subscribed' => false, 'preferences' => null]);
        }
    }
    
    /**
     * Unsubscribe user
     */
    public function ajax_unsubscribe() {
        check_ajax_referer('onesignal_nonce', 'nonce');
        
        $xrpl_account = sanitize_text_field($_POST['xrpl_account'] ?? '');
        // Phase 1 Batch 2 (Aug 2026): identity from the session token; the
        // posted account must match the session wallet or the call is rejected.
        $os_auth = function_exists('imc_session_require_wallet')
            ? imc_session_require_wallet($xrpl_account)
            : array('ok' => false, 'wallet' => '', 'error' => 'auth');
        if (empty($os_auth['ok'])) {
            $this->log("onesignal_unsubscribe rejected: " . ((($os_auth['error'] ?? '') === 'mismatch') ? "account mismatch (posted != session)" : "not authenticated"));
            wp_send_json_error(['message' => (($os_auth['error'] ?? '') === 'mismatch') ? 'Account mismatch' : 'Not authenticated'], 403);
        }
        $xrpl_account = $os_auth['wallet'];
        
        global $wpdb;
        $table = $wpdb->prefix . 'onesignal_subscriptions';
        
        $wpdb->update($table, ['is_active' => 0], ['xrpl_account' => $xrpl_account]);
        
        $this->log("Unsubscribed $xrpl_account");
        wp_send_json_success(['message' => 'Unsubscribed']);
    }
    
    /**
     * Tag user in OneSignal with their XRPL account
     */
    private function tag_user($player_id, $xrpl_account) {
        if (empty($this->api_key) || empty($player_id)) {
            return false;
        }
        
        $response = wp_remote_request("https://onesignal.com/api/v1/players/$player_id", [
            'method' => 'PUT',
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . $this->api_key
            ],
            'body' => json_encode([
                'app_id' => $this->app_id,
                'external_user_id' => $xrpl_account,
                'tags' => [
                    'xrpl_account' => $xrpl_account
                ]
            ])
        ]);
        
        if (is_wp_error($response)) {
            $this->log("Failed to tag user $xrpl_account: " . $response->get_error_message(), 'ERROR');
            return false;
        }
        
        $this->log("Tagged user $xrpl_account with player_id $player_id");
        return true;
    }
    
    /**
     * Send push notification
     */
    public function send_notification($params) {
        if (empty($this->api_key) || empty($this->app_id)) {
            $this->log("OneSignal not configured", 'ERROR');
            return false;
        }
        
        $defaults = [
            'app_id' => $this->app_id,
            'chrome_web_icon' => home_url('/wp-content/uploads/icon-192x192.png'),
            'firefox_icon' => home_url('/wp-content/uploads/icon-192x192.png'),
            'url' => home_url('/profile/')
        ];
        
        $payload = array_merge($defaults, $params);
        
        $response = wp_remote_post('https://onesignal.com/api/v1/notifications', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . $this->api_key
            ],
            'body' => json_encode($payload),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            $this->log("Send notification failed: " . $response->get_error_message(), 'ERROR');
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['id'])) {
            $this->log("Notification sent: " . $body['id']);
            return $body['id'];
        }
        
        $this->log("Notification failed: " . json_encode($body), 'ERROR');
        return false;
    }
    
    /**
     * Send notification to specific user by XRPL account
     */
    public function send_to_user($xrpl_account, $heading, $content, $url = null, $data = []) {
        return $this->send_notification([
            'include_external_user_ids' => [$xrpl_account],
            'headings' => ['en' => $heading],
            'contents' => ['en' => $content],
            'url' => $url ?: home_url('/profile/'),
            'data' => $data
        ]);
    }
    
    /**
     * Send notification to users with specific tag
     */
    public function send_to_tagged_users($tag_key, $tag_value, $heading, $content, $url = null) {
        return $this->send_notification([
            'filters' => [
                ['field' => 'tag', 'key' => $tag_key, 'relation' => '=', 'value' => $tag_value]
            ],
            'headings' => ['en' => $heading],
            'contents' => ['en' => $content],
            'url' => $url ?: home_url('/')
        ]);
    }
    
    /**
     * Send to all subscribed users
     */
    public function send_to_all($heading, $content, $url = null) {
        return $this->send_notification([
            'included_segments' => ['Subscribed Users'],
            'headings' => ['en' => $heading],
            'contents' => ['en' => $content],
            'url' => $url ?: home_url('/')
        ]);
    }
    
    // ===========================================
    // EVENT HANDLERS
    // ===========================================
    
    /**
     * Handle event status change (live notification)
     */
    public function on_event_status_change($event_id, $old_status, $new_status) {
        if ($new_status !== 'live') {
            return;
        }
        
        $event = get_post($event_id);
        if (!$event) {
            return;
        }
        
        $event_title = $event->post_title;
        $event_url = get_permalink($event_id);
        
        // Get users who want event notifications
        global $wpdb;
        $table = $wpdb->prefix . 'onesignal_subscriptions';
        $subscribers = $wpdb->get_col(
            "SELECT xrpl_account FROM $table WHERE is_active = 1 AND notify_events = 1"
        );
        
        if (empty($subscribers)) {
            return;
        }
        
        $this->send_notification([
            'include_external_user_ids' => $subscribers,
            'headings' => ['en' => '🔴 Event is LIVE!'],
            'contents' => ['en' => "$event_title is now live! Join now."],
            'url' => $event_url,
            'data' => [
                'type' => self::TYPE_EVENT_LIVE,
                'event_id' => $event_id
            ]
        ]);
        
        $this->log("Sent event live notification for event $event_id to " . count($subscribers) . " users");
    }
    
    /**
     * Handle new event created
     */
    public function on_event_created($event_id) {
        $event = get_post($event_id);
        if (!$event || $event->post_status !== 'publish') {
            return;
        }
        
        $event_title = $event->post_title;
        $event_url = get_permalink($event_id);
        $event_date = get_post_meta($event_id, 'event_date', true);
        
        // Get users who want event notifications
        global $wpdb;
        $table = $wpdb->prefix . 'onesignal_subscriptions';
        $subscribers = $wpdb->get_col(
            "SELECT xrpl_account FROM $table WHERE is_active = 1 AND notify_events = 1"
        );
        
        if (empty($subscribers)) {
            return;
        }
        
        $date_str = $event_date ? " on " . date('M j', strtotime($event_date)) : '';
        
        $this->send_notification([
            'include_external_user_ids' => $subscribers,
            'headings' => ['en' => '🎫 New Event Scheduled!'],
            'contents' => ['en' => "$event_title$date_str - Don't miss it!"],
            'url' => $event_url,
            'data' => [
                'type' => self::TYPE_EVENT_NEW,
                'event_id' => $event_id
            ]
        ]);
        
        $this->log("Sent new event notification for event $event_id to " . count($subscribers) . " users");
    }
    
    /**
     * Handle chat message (queue for batching)
     */
    public function on_chat_message($sender_account, $recipient_account, $message) {
        global $wpdb;
        
        // Check if recipient wants chat notifications
        $sub_table = $wpdb->prefix . 'onesignal_subscriptions';
        $wants_chat = $wpdb->get_var($wpdb->prepare(
            "SELECT notify_chat FROM $sub_table WHERE xrpl_account = %s AND is_active = 1",
            $recipient_account
        ));
        
        if (!$wants_chat) {
            return;
        }
        
        // Get sender name
        $profile_table = $wpdb->prefix . 'xaman_profiles';
        $sender_name = $wpdb->get_var($wpdb->prepare(
            "SELECT name FROM $profile_table WHERE xrpl_account = %s",
            $sender_account
        )) ?: substr($sender_account, 0, 8) . '...';
        
        // Queue the message
        $queue_table = $wpdb->prefix . 'onesignal_chat_queue';
        $wpdb->insert($queue_table, [
            'recipient_account' => $recipient_account,
            'sender_account' => $sender_account,
            'sender_name' => $sender_name,
            'message_preview' => substr($message, 0, 50),
            'created_at' => current_time('mysql')
        ]);
        
        $this->log("Queued chat notification for $recipient_account from $sender_account");
    }
    
    /**
     * Send batched chat notifications (called by cron every 5 minutes)
     */
    public function send_batched_chat_notifications() {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'onesignal_chat_queue';
        
        // Get unprocessed messages grouped by recipient
        $recipients = $wpdb->get_results(
            "SELECT recipient_account, COUNT(*) as msg_count, 
                    GROUP_CONCAT(DISTINCT sender_name ORDER BY created_at DESC SEPARATOR ', ') as senders
             FROM $queue_table 
             WHERE processed = 0 
             GROUP BY recipient_account",
            ARRAY_A
        );
        
        foreach ($recipients as $recipient) {
            $account = $recipient['recipient_account'];
            $count = intval($recipient['msg_count']);
            $senders = $recipient['senders'];
            
            // Truncate senders list
            if (strlen($senders) > 50) {
                $senders = substr($senders, 0, 47) . '...';
            }
            
            $content = $count === 1 
                ? "New message from $senders"
                : "You have $count new messages from $senders";
            
            $this->send_to_user(
                $account,
                '💬 New Messages',
                $content,
                home_url('/profile/#chat'),
                ['type' => self::TYPE_CHAT_MESSAGE, 'count' => $count]
            );
            
            $this->log("Sent batched chat notification to $account: $count messages");
        }
        
        // Mark as processed
        if (!empty($recipients)) {
            $wpdb->query("UPDATE $queue_table SET processed = 1 WHERE processed = 0");
        }
    }
    
    /**
     * Check claim cooldowns and send notifications (called by cron)
     */
    public function check_claim_cooldowns() {
        // Call VPS to get users whose cooldown has expired
        $response = wp_remote_post(defined('IMC_NOTIFY_CHECK_URL') ? IMC_NOTIFY_CHECK_URL : '', [
            'body' => [
                'action' => 'check_claim_cooldowns',
                'secret' => defined('NOTIFICATION_SECRET') ? NOTIFICATION_SECRET : ''
            ],
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            $this->log("Failed to check claim cooldowns: " . $response->get_error_message(), 'ERROR');
            return;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($body['accounts']) || empty($body['accounts'])) {
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'onesignal_subscriptions';
        
        foreach ($body['accounts'] as $account) {
            // Check if user wants claim notifications and hasn't been notified recently
            $sub = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE xrpl_account = %s AND is_active = 1 AND notify_claims = 1",
                $account
            ));
            
            if (!$sub) {
                continue;
            }
            
            // Don't notify if we've already notified in the last 23 hours
            if ($sub->last_claim_notified) {
                $last = strtotime($sub->last_claim_notified);
                if (time() - $last < 82800) { // 23 hours
                    continue;
                }
            }
            
            $this->send_to_user(
                $account,
                '⛲ Daily Rewards Ready!',
                'Your XFT rewards are ready to claim. Visit the Frequency Fountain now!',
                home_url('/profile/'),
                ['type' => self::TYPE_CLAIM_READY]
            );
            
            // Update last notified time
            $wpdb->update($table, 
                ['last_claim_notified' => current_time('mysql')],
                ['xrpl_account' => $account]
            );
            
            $this->log("Sent claim ready notification to $account");
        }
    }
    
    /**
     * Check game cooldowns and send notifications (called by cron)
     */
    public function check_game_cooldowns() {
        // Call VPS to get users whose game cooldown has expired
        $response = wp_remote_post(defined('IMC_NOTIFY_CHECK_URL') ? IMC_NOTIFY_CHECK_URL : '', [
            'body' => [
                'action' => 'check_game_cooldowns',
                'secret' => defined('NOTIFICATION_SECRET') ? NOTIFICATION_SECRET : ''
            ],
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            $this->log("Failed to check game cooldowns: " . $response->get_error_message(), 'ERROR');
            return;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($body['accounts']) || empty($body['accounts'])) {
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'onesignal_subscriptions';
        
        foreach ($body['accounts'] as $account) {
            $sub = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE xrpl_account = %s AND is_active = 1 AND notify_games = 1",
                $account
            ));
            
            if (!$sub) {
                continue;
            }
            
            // Don't notify if we've already notified in the last 23 hours
            if ($sub->last_game_notified) {
                $last = strtotime($sub->last_game_notified);
                if (time() - $last < 82800) {
                    continue;
                }
            }
            
            $this->send_to_user(
                $account,
                '🎮 Game Rewards Ready!',
                'Your Champion of Frequencies rewards are ready to claim!',
                home_url('/champion-of-frequencies/'),
                ['type' => self::TYPE_GAME_READY]
            );
            
            $wpdb->update($table,
                ['last_game_notified' => current_time('mysql')],
                ['xrpl_account' => $account]
            );
            
            $this->log("Sent game ready notification to $account");
        }
    }
    
    /**
     * Log message
     */
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[$timestamp] [$level] $message\n";
        file_put_contents($this->log_file, $log_message, FILE_APPEND);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[OneSignal] $message");
        }
    }
}

// Initialize
IMProtectors_OneSignal::get_instance();

// Add custom cron schedules
add_filter('cron_schedules', function($schedules) {
    $schedules['five_minutes'] = [
        'interval' => 300,
        'display' => __('Every 5 Minutes')
    ];
    $schedules['fifteen_minutes'] = [
        'interval' => 900,
        'display' => __('Every 15 Minutes')
    ];
    return $schedules;
});