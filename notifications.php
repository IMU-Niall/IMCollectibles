<?php
if (!defined('ABSPATH')) {
    exit;
}

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

if (!function_exists('xaman_log')) {
    function xaman_log($message, $type = 'INFO') {
        error_log("[$type] $message");
        file_put_contents(WP_CONTENT_DIR . '/xaman-notifications.log', "[$type] " . date('Y-m-d H:i:s') . " $message\n", FILE_APPEND);
    }
}

function send_push_notification($account, $title, $message, $url = '') {
    if (!defined('PUSHENGAGE_API_KEY') || !defined('PUSHENGAGE_APP_ID')) {
        xaman_log("PushEngage API key or App ID not defined for account: $account", 'ERROR');
        return false;
    }

    global $wpdb;
    $profiles_table = $wpdb->prefix . 'xaman_profiles';
    $subscriptions_table = $wpdb->prefix . 'xaman_subscriptions';
    $failed_table = $wpdb->prefix . 'failed_notifications';

    $user = $wpdb->get_row($wpdb->prepare(
        "SELECT notify_events, notify_claims, notify_claims_received, notify_messages FROM $profiles_table WHERE xrpl_account = %s",
        $account
    ));

    if ($wpdb->last_error) {
        xaman_log("Database error in send_push_notification for $account: {$wpdb->last_error}", 'ERROR');
        return false;
    }

    if (!$user) {
        xaman_log("No user found for push notification: $account", 'ERROR');
        return false;
    }

    $should_send = false;
    if ($title === 'XFT Claim Successful' && $user->notify_claims_received) $should_send = true;
    elseif ($title === 'Protector Rewards are Waiting! Come + Claim $XFT!' && $user->notify_claims) $should_send = true;
    elseif (strpos($title, 'RSVP Confirmed') !== false && $user->notify_events) $should_send = true;
    elseif ($title === 'IMU Update' && $user->notify_messages) $should_send = true;

    if (!$should_send) {
        xaman_log("Push notification not sent for $account: preferences not enabled for '$title'", 'WARNING');
        return false;
    }

    // Check PushEngage with retry
    $pushengage_status = false;
    $attempts = 0;
    $max_attempts = 3;
    $delay = 2;
    while ($attempts < $max_attempts) {
        $response = wp_remote_get("https://api.pushengage.com/v4.0/subscribers?external_user_id=$account", [
            'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . PUSHENGAGE_API_KEY],
            'timeout' => 15
        ]);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $pushengage_status = !empty($body['subscribers']);
            xaman_log("PushEngage check attempt " . ($attempts + 1) . " for $account: " . ($pushengage_status ? 'Subscribed' : 'Not subscribed') . ", Response: " . json_encode($body));
            break;
        } else {
            $err = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response);
            xaman_log("PushEngage check failed attempt " . ($attempts + 1) . " for $account: $err", 'ERROR');
            $attempts++;
            if ($attempts < $max_attempts) sleep($delay *= 2);
        }
    }

    $payload = ['title' => $title, 'message' => $message, 'url' => $url ?: home_url('/profile/'), 'external_user_id' => $account];

    // Try PushEngage
    if ($pushengage_status) {
        $attempts = 0;
        $delay = 2;
        while ($attempts < $max_attempts) {
            $response = wp_remote_post('https://api.pushengage.com/v4.0/notifications', [
                'body' => json_encode($payload),
                'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . PUSHENGAGE_API_KEY],
                'timeout' => 15
            ]);
            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);
            xaman_log("PushEngage send attempt " . ($attempts + 1) . " for $account - Code: $code, Body: " . json_encode($body));
            if (!is_wp_error($response) && $code === 200 && ($body['success'] ?? false)) {
                xaman_log("PushEngage sent to $account: $title");
                return true;
            }
            $err = is_wp_error($response) ? $response->get_error_message() : ($body['error'] ?? 'Unknown');
            xaman_log("PushEngage send failed attempt " . ($attempts + 1) . " for $account: $err", 'WARNING');
            $attempts++;
            if ($attempts < $max_attempts) sleep($delay *= 2);
        }
        xaman_log("PushEngage failed after $max_attempts attempts for $account", 'ERROR');
    } else {
        xaman_log("No PushEngage subscription for $account, falling back to native", 'INFO');
    }

    // Native fallback
    $native_sub = $wpdb->get_var($wpdb->prepare("SELECT subscription FROM $subscriptions_table WHERE xrpl_account = %s", $account));
    if ($native_sub) {
        try {
            $auth = [
                'VAPID' => [
                    'subject' => 'mailto:' . (defined('IMC_VAPID_CONTACT') ? IMC_VAPID_CONTACT : 'admin@imcollectibles.io'),
                    'publicKey' => VAPID_PUBLIC_KEY,
                    'privateKey' => VAPID_PRIVATE_KEY,
                ]
            ];
            $webPush = new WebPush($auth);
            $sub_data = json_decode($native_sub, true);
            $subscription = Subscription::create($sub_data);
            $native_payload = json_encode([
                'title' => $title,
                'body' => $message,
                'icon' => '/wp-content/uploads/notification-icon.png',
                'data' => ['url' => $payload['url']]
            ]);
            $report = $webPush->sendOneNotification($subscription, $native_payload);
            if ($report->isSuccess()) {
                xaman_log("Native sent to $account: $title");
                return true;
            } else {
                xaman_log("Native failed for $account: " . $report->getReason(), 'ERROR');
            }
        } catch (Exception $e) {
            xaman_log("Native exception for $account: " . $e->getMessage(), 'ERROR');
        }
    } else {
        xaman_log("No native subscription for $account", 'WARNING');
    }

    // Log failure
    $wpdb->insert($failed_table, [
        'xrpl_account' => $account,
        'type' => 'push',
        'data' => json_encode($payload),
        'created_at' => current_time('mysql', 1),
        'attempts' => 1,
        'last_error' => 'Both failed'
    ]);
    xaman_log("Logged failed notification for $account", 'ERROR');
    return false;
}

function send_email_notification($email, $subject, $message) {
    if (empty($email)) {
        xaman_log("No email for notification: $subject", 'WARNING');
        return false;
    }
    xaman_log("Email sent to $email: $subject");
    return wp_mail($email, $subject, $message, ['Content-Type: text/html; charset=UTF-8']);
}

function send_notification($account, $type, $data) {
    global $wpdb;
    $profiles_table = $wpdb->prefix . 'xaman_profiles';
    $failed_table = $wpdb->prefix . 'failed_notifications';
    $user = $wpdb->get_row($wpdb->prepare(
        "SELECT email, notify_claims, notify_claims_received, notify_events, notify_messages FROM $profiles_table WHERE xrpl_account = %s",
        $account
    ));

    if ($wpdb->last_error) {
        xaman_log("DB error in send_notification for $account: {$wpdb->last_error}", 'ERROR');
        return false;
    }

    if (!$user) {
        xaman_log("No user for notification: $account", 'ERROR');
        return false;
    }

    $sent = false;
    if ($type === 'claim_success' && $user->notify_claims_received) {
        $sent = send_push_notification($account, "XFT Claim Successful", "You’ve claimed {$data['rewards']} XFT! Check wallet.", home_url('/profile/'));
        send_email_notification($user->email, "XFT Claim Successful", "You’ve claimed {$data['rewards']} XFT! <a href='" . home_url('/profile/') . "'>Profile</a>");
        xaman_log("Claim success attempt for $account, email: {$user->email}, amount: {$data['rewards']}");
    } elseif ($type === 'claim_reset' && $user->notify_claims) {
        $sent = send_push_notification($account, "Rewards Waiting! Claim XFT!", "Timer reset. Claim now!", home_url('/profile/'));
        send_email_notification($user->email, "Rewards Waiting! Claim XFT!", "Timer reset. <a href='" . home_url('/profile/') . "'>Profile</a>");
        xaman_log("Claim reset attempt for $account, email: {$user->email}");
    } elseif ($type === 'rsvp' && $user->notify_events) {
        $sent = send_push_notification($account, "RSVP Confirmed: {$data['title']}", "RSVP’d for {$data['title']} on {$data['date']}. Join!", home_url('/live-events/'));
        send_email_notification($user->email, "RSVP Confirmed: {$data['title']}", "RSVP’d for {$data['title']} on {$data['date']}. <a href='" . home_url('/live-events/') . "'>Events</a>");
        xaman_log("RSVP attempt for $account, title: {$data['title']}, email: {$user->email}");
    }

    if (!$sent && in_array($type, ['claim_reset', 'claim_success'])) {
        $wpdb->insert($failed_table, [
            'xrpl_account' => $account,
            'type' => $type,
            'data' => json_encode($data),
            'created_at' => current_time('mysql', 1),
            'attempts' => 1,
            'last_error' => 'Push failed'
        ]);
        xaman_log("Logged failed $type for $account", 'ERROR');
    }

    return $sent;
}

function improtectors_notification_check() {
    global $wpdb;
    $start = microtime(true);
    $claim_table = $wpdb->prefix . 'xft_claims';
    $profiles_table = $wpdb->prefix . 'xaman_profiles';

    $users = $wpdb->get_results($wpdb->prepare("
        SELECT c.xrpl_account, c.last_claim, p.notify_claims
        FROM $claim_table c
        JOIN $profiles_table p ON c.xrpl_account = p.xrpl_account
        WHERE p.notify_claims = 1
        AND (c.last_claim IS NULL OR TIMESTAMPDIFF(SECOND, c.last_claim, UTC_TIMESTAMP()) >= %d)
    ", 86400));

    if ($wpdb->last_error) {
        xaman_log("DB error in notification_check: {$wpdb->last_error}", 'ERROR');
        return;
    }

    $processed = 0;
    foreach ($users as $user) {
        $account = $user->xrpl_account;
        $transient_key = 'xft_reset_notified_' . md5($account);
        if (get_transient($transient_key)) continue;

        $time_diff = $user->last_claim ? (time() - strtotime($user->last_claim . ' UTC')) : PHP_INT_MAX;
        if ($time_diff < 86400) continue;

        $sent = send_notification($account, 'claim_reset', [
            'title' => 'Rewards Waiting! Claim $XFT!',
            'message' => 'Timer reset. Claim now!',
            'url' => home_url('/profile/')
        ]);

        if ($sent) {
            set_transient($transient_key, true, 86400 + 300);
            xaman_log("Sent reset notification for $account");
        } else {
            xaman_log("Failed reset notification for $account", 'ERROR');
        }
        $processed++;
    }

    $time = microtime(true) - $start;
    xaman_log("Notification check cron: checked " . count($users) . " users, processed $processed, took $time s");
}
add_action('improtectors_notification_check', 'improtectors_notification_check');

function improtectors_retry_notifications() {
    global $wpdb;
    $start = microtime(true);
    $failed_table = $wpdb->prefix . 'failed_notifications';

    $notifications = $wpdb->get_results("SELECT * FROM $failed_table WHERE attempts < 5");
    if ($wpdb->last_error) {
        xaman_log("DB error in retry_notifications: {$wpdb->last_error}", 'ERROR');
        return;
    }

    xaman_log("Retry cron: found " . count($notifications) . " failed");
    foreach ($notifications as $notification) {
        $data = json_decode($notification->data, true);
        $success = send_push_notification(
            $notification->xrpl_account,
            $data['title'] ?? 'Rewards Waiting! Claim $XFT!',
            $data['message'] ?? 'Timer reset. Claim now!',
            $data['url'] ?? home_url('/profile/')
        );

        if ($success) {
            $wpdb->delete($failed_table, ['id' => $notification->id]);
            xaman_log("Retried and sent for {$notification->xrpl_account}, type: {$notification->type}");
        } else {
            $wpdb->update($failed_table, [
                'attempts' => $notification->attempts + 1,
                'last_error' => 'Retry failed',
                'created_at' => current_time('mysql', 1)
            ], ['id' => $notification->id]);
            xaman_log("Retry failed for {$notification->xrpl_account}, type: {$notification->type}, attempt: " . ($notification->attempts + 1));
        }
    }

    $time = microtime(true) - $start;
    xaman_log("Retry cron complete, took $time s");
}
add_action('improtectors_retry_notifications', 'improtectors_retry_notifications');

add_action('wp_ajax_check_notification_status', 'check_notification_status_callback');
add_action('wp_ajax_nopriv_check_notification_status', 'check_notification_status_callback');
function check_notification_status_callback() {
    $raw = file_get_contents('php://input');
    $headers = getallheaders();
    // SEC (20 Sep 2026): POST + HEADERS dropped -- headers can carry the session Bearer.
    xaman_log("check_notification_status: called");

    $nonce = sanitize_text_field($_POST['nonce'] ?? '');
    $account = sanitize_text_field($_POST['account'] ?? '');

    if (!wp_verify_nonce($nonce, 'xaman_notifications_nonce')) {
        xaman_log("Invalid nonce for check_notification_status: $nonce", 'ERROR');
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
    }

    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        xaman_log("Invalid account for check_notification_status: $account", 'ERROR');
        wp_send_json_error(['message' => 'Invalid account'], 400);
    }

    // Phase 1 Batch 2 (Aug 2026): identity from the session token; the
    // posted account must match the session wallet or the call is rejected.
    $nt_auth = function_exists('imc_session_require_wallet')
        ? imc_session_require_wallet($account)
        : array('ok' => false, 'wallet' => '', 'error' => 'auth');
    if (empty($nt_auth['ok'])) {
        xaman_log("check_notification_status rejected: " . ((($nt_auth['error'] ?? '') === 'mismatch') ? "account mismatch (posted != session)" : "not authenticated"), 'ERROR');
        wp_send_json_error(['message' => (($nt_auth['error'] ?? '') === 'mismatch') ? 'Account mismatch' : 'Not authenticated'], 403);
    }
    $account = $nt_auth['wallet'];

    global $wpdb;
    $profiles_table = $wpdb->prefix . 'xaman_profiles';
    $user = $wpdb->get_row($wpdb->prepare(
        "SELECT notify_events, notify_claims, notify_claims_received, notify_messages, email FROM $profiles_table WHERE xrpl_account = %s",
        $account
    ));

    if ($wpdb->last_error) {
        xaman_log("DB error in check_notification_status for $account: {$wpdb->last_error}", 'ERROR');
        wp_send_json_error(['message' => 'DB error'], 500);
    }

    if (!$user) {
        $wpdb->insert($profiles_table, [
            'xrpl_account' => $account,
            'notify_events' => 0,
            'notify_claims' => 0,
            'notify_claims_received' => 0,
            'notify_messages' => 0,
            'email' => '',
            'created_at' => current_time('mysql', 1),
            'name' => $account,
            'profile_pic_url' => '/wp-content/uploads/fallback-nft.svg'
        ]);
        if ($wpdb->last_error) {
            xaman_log("Failed to create user for $account: {$wpdb->last_error}", 'ERROR');
            wp_send_json_error(['message' => 'Failed to create user'], 500);
        }
        xaman_log("Created new user for $account");
        $user = (object) ['notify_events' => 0, 'notify_claims' => 0, 'notify_claims_received' => 0, 'notify_messages' => 0, 'email' => ''];
    }

    $sub_status = false;
    if (defined('PUSHENGAGE_API_KEY')) {
        $response = wp_remote_get("https://api.pushengage.com/v4.0/subscribers?external_user_id=$account", [
            'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . PUSHENGAGE_API_KEY],
            'timeout' => 15
        ]);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $sub_status = !empty($body['subscribers']);
            xaman_log("PushEngage status for $account: " . ($sub_status ? 'Subscribed' : 'Not subscribed') . ", Response: " . json_encode($body));
        } else {
            $err = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response);
            xaman_log("PushEngage check failed for $account: $err", 'ERROR');
        }
    }

    wp_send_json_success([
        'notify_events' => (int)$user->notify_events,
        'notify_claims' => (int)$user->notify_claims,
        'notify_claims_received' => (int)$user->notify_claims_received,
        'notify_messages' => (int)$user->notify_messages,
        'email' => $user->email,
        'is_subscribed' => $sub_status
    ]);
}

add_action('wp_ajax_save_native_subscription', 'save_native_subscription_callback');
add_action('wp_ajax_nopriv_save_native_subscription', 'save_native_subscription_callback');
function save_native_subscription_callback() {
    global $wpdb;
    $nonce = sanitize_text_field($_POST['nonce'] ?? '');
    $account = sanitize_text_field($_POST['account'] ?? '');
    $subscription = $_POST['subscription'] ?? '';

    xaman_log("save_native_subscription: account=$account, nonce=$nonce, sub=" . substr($subscription, 0, 50) . "...");

    if (!wp_verify_nonce($nonce, 'xaman_notifications_nonce')) {
        xaman_log("Invalid nonce: $nonce", 'ERROR');
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
    }

    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        xaman_log("Invalid account: $account", 'ERROR');
        wp_send_json_error(['message' => 'Invalid account'], 400);
    }

    // Phase 1 Batch 2 (Aug 2026): identity from the session token; the
    // posted account must match the session wallet or the call is rejected.
    $nt_auth = function_exists('imc_session_require_wallet')
        ? imc_session_require_wallet($account)
        : array('ok' => false, 'wallet' => '', 'error' => 'auth');
    if (empty($nt_auth['ok'])) {
        xaman_log("save_native_subscription rejected: " . ((($nt_auth['error'] ?? '') === 'mismatch') ? "account mismatch (posted != session)" : "not authenticated"), 'ERROR');
        wp_send_json_error(['message' => (($nt_auth['error'] ?? '') === 'mismatch') ? 'Account mismatch' : 'Not authenticated'], 403);
    }
    $account = $nt_auth['wallet'];

    if (empty($subscription)) {
        xaman_log("No sub data for $account", 'ERROR');
        wp_send_json_error(['message' => 'No sub data'], 400);
    }

    $table = $wpdb->prefix . 'xaman_subscriptions';
    $existing = $wpdb->get_row($wpdb->prepare("SELECT id FROM $table WHERE xrpl_account = %s", $account));

    if ($existing) {
        $result = $wpdb->update($table, ['subscription' => $subscription, 'updated_at' => current_time('mysql', 1)], ['xrpl_account' => $account]);
        xaman_log("Updated sub for $account: " . ($result !== false ? "success, rows: $result" : "failed: {$wpdb->last_error}"));
    } else {
        $result = $wpdb->insert($table, [
            'xrpl_account' => $account,
            'subscription' => $subscription,
            'created_at' => current_time('mysql', 1),
            'updated_at' => current_time('mysql', 1)
        ]);
        xaman_log("Inserted sub for $account: " . ($result !== false ? "success, id: {$wpdb->insert_id}" : "failed: {$wpdb->last_error}"));
    }

    if ($wpdb->last_error) {
        xaman_log("DB error for $account: {$wpdb->last_error}", 'ERROR');
        wp_send_json_error(['message' => 'DB error: ' . $wpdb->last_error], 500);
    } else {
        xaman_log("Saved sub for $account");
        wp_send_json_success(['message' => 'Native sub saved']);
    }
}

add_action('wp_ajax_check_subscription_status', 'check_subscription_status_callback');
add_action('wp_ajax_nopriv_check_subscription_status', 'check_subscription_status_callback');
function check_subscription_status_callback() {
    $raw = file_get_contents('php://input');
    $headers = getallheaders();
    // SEC (20 Sep 2026): POST + HEADERS dropped -- headers can carry the session Bearer.
    xaman_log("check_subscription_status: called");

    $nonce = sanitize_text_field($_POST['nonce'] ?? '');
    $account = sanitize_text_field($_POST['account'] ?? '');

    if (!wp_verify_nonce($nonce, 'xaman_notifications_nonce')) {
        xaman_log("Invalid nonce: $nonce", 'ERROR');
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
    }

    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        xaman_log("Invalid account: $account", 'ERROR');
        wp_send_json_error(['message' => 'Invalid account'], 400);
    }

    // Phase 1 Batch 2 (Aug 2026): identity from the session token; the
    // posted account must match the session wallet or the call is rejected.
    $nt_auth = function_exists('imc_session_require_wallet')
        ? imc_session_require_wallet($account)
        : array('ok' => false, 'wallet' => '', 'error' => 'auth');
    if (empty($nt_auth['ok'])) {
        xaman_log("check_subscription_status rejected: " . ((($nt_auth['error'] ?? '') === 'mismatch') ? "account mismatch (posted != session)" : "not authenticated"), 'ERROR');
        wp_send_json_error(['message' => (($nt_auth['error'] ?? '') === 'mismatch') ? 'Account mismatch' : 'Not authenticated'], 403);
    }
    $account = $nt_auth['wallet'];

    global $wpdb;
    $profiles_table = $wpdb->prefix . 'xaman_profiles';
    $subs_table = $wpdb->prefix . 'xaman_subscriptions';

    $user = $wpdb->get_row($wpdb->prepare("SELECT 1 FROM $profiles_table WHERE xrpl_account = %s", $account));
    if (!$user) {
        $wpdb->insert($profiles_table, [
            'xrpl_account' => $account,
            'notify_events' => 0,
            'notify_claims' => 0,
            'notify_claims_received' => 0,
            'notify_messages' => 0,
            'email' => '',
            'created_at' => current_time('mysql', 1),
            'name' => $account,
            'profile_pic_url' => '/wp-content/uploads/fallback-nft.svg'
        ]);
        if ($wpdb->last_error) {
            xaman_log("Failed create user for $account: {$wpdb->last_error}", 'ERROR');
            wp_send_json_error(['message' => 'Failed create user'], 500);
        }
        xaman_log("Created user for $account");
    }

    $sub_status = false;
    if (defined('PUSHENGAGE_API_KEY')) {
        $response = wp_remote_get("https://api.pushengage.com/v4.0/subscribers?external_user_id=$account", [
            'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . PUSHENGAGE_API_KEY],
            'timeout' => 15
        ]);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $sub_status = !empty($body['subscribers']);
            xaman_log("PushEngage status for $account: " . ($sub_status ? 'Subscribed' : 'Not subscribed') . ", Response: " . json_encode($body));
        } else {
            $err = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response);
            xaman_log("PushEngage check failed for $account: $err", 'ERROR');
        }
    }

    if (!$sub_status) {
        $native_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $subs_table WHERE xrpl_account = %s", $account));
        $sub_status = $native_count > 0;
        xaman_log("Native status for $account: " . ($sub_status ? 'Subscribed' : 'Not subscribed'));
    }

    wp_send_json_success(['is_subscribed' => $sub_status]);
}