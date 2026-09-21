<?php
/*
 * Template Name: Event Replay
 */
// Enqueue styles and scripts
function enqueue_event_replay_assets() {
    wp_enqueue_style('imu-events', get_template_directory_uri() . '/css/imu-events.css', [], '1.0');
    wp_enqueue_style('video-js', 'https://vjs.zencdn.net/7.21.0/video-js.css', [], '7.21.0');
    wp_enqueue_script('video-js', 'https://vjs.zencdn.net/7.21.0/video.min.js', [], '7.21.0', true);
    wp_enqueue_script('hls-js', 'https://cdn.jsdelivr.net/npm/hls.js@latest', [], null, true);
    wp_enqueue_script('videojs-hls-quality-selector', 'https://cdn.jsdelivr.net/npm/videojs-hls-quality-selector@1.1.1/dist/videojs-hls-quality-selector.min.js', ['video-js'], '1.1.1', true);
    wp_enqueue_script('imu-events-js', get_template_directory_uri() . '/js/imu-events.js', ['hls-js', 'video-js', 'videojs-hls-quality-selector'], '1.0.0', true);
    global $wpdb;
    $event_id = absint($_GET['event_id'] ?? 0);
    $events_table = $wpdb->prefix . 'events';
    $event = $event_id ? $wpdb->get_row($wpdb->prepare("SELECT id, recording_url FROM $events_table WHERE id = %d LIMIT 1", $event_id)) : null;
    $is_youtube = $event && preg_match('/(youtube\.com\/watch\?v=|youtu\.be\/)/i', $event->recording_url) ? true : false;
    wp_localize_script('imu-events-js', 'eventReplayData', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonceTip' => wp_create_nonce('xrpl_marketplace_nonce'), // Fixed: Use xrpl_marketplace_nonce
        'nonceTrustline' => wp_create_nonce('send_trustline_nonce'),
        'nonceLiveCheck' => wp_create_nonce('check_live_events'),
        'eventId' => $event ? absint($event->id) : 0,
        'xrplAccount' => function_exists('imc_session_wallet') ? imc_session_wallet() : '',
        'hasXftTrustline' => get_transient('xaman_xft_' . md5(function_exists('imc_session_wallet') ? imc_session_wallet() : '') . '_trustline') ? true : false,
        'isYouTube' => $is_youtube,
        'wsUrl' => 'wss://events-chat.imcollectibles.io'
    ]);
}
add_action('wp_enqueue_scripts', 'enqueue_event_replay_assets');

get_header();

global $wpdb;

// Fallback xaman_log
if (!function_exists('xaman_log')) {
    function xaman_log($message) {
        // Logs are written under wp-content, not beside this file. Ensure the
        // directory is denied by the web server.
        $log_file = WP_CONTENT_DIR . '/imc-logs/xaman-profile-new.log';
        if (!is_dir(dirname($log_file))) { @mkdir(dirname($log_file), 0755, true); }
        $timestamp = gmdate('Y-m-d H:i:s');
        file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
    }
}

xaman_log("Event Replay page accessed: URL=" . esc_url_raw($_SERVER['REQUEST_URI']) . ", event_id=" . ($_GET['event_id'] ?? 'not set'));

// Check XRPL account and NFT ownership
$account = function_exists('imc_session_wallet') ? imc_session_wallet() : '';
$event_id = absint($_GET['event_id'] ?? 0);
$has_nft = false;

if (!$account) {
    xaman_log("No XRPL account cookie set");
    echo '<div class="section"><p>Please log in with Xaman Wallet to view this replay.</p>' . do_shortcode('[xaman_login]') . '</div>';
    get_footer();
    exit;
}

if (!$event_id) {
    xaman_log("Invalid event_id: 0");
    echo '<div class="section"><p>Invalid event ID.</p></div>';
    get_footer();
    exit;
}

xaman_log("Checking NFT for account=$account, event_id=$event_id");

// Fetch NFT data
$transient_key = 'xaman_nft_' . md5($account);
$nfts = get_transient($transient_key);
if ($nfts === false) {
    $url = "https://imcollectibles.io/xumm-proxy.php?account=$account&t=" . time();
    xaman_log("Fetching NFT data for replay: $url");
    $response = wp_remote_get($url, ['timeout' => 10]);
    if (is_wp_error($response)) {
        xaman_log("Failed to fetch NFT data for $account: " . $response->get_error_message());
        echo '<p>Error fetching wallet data. Please try again later.</p>';
        get_footer();
        exit;
    }
    $nfts = json_decode(wp_remote_retrieve_body($response), true);
    set_transient($transient_key, $nfts, 300);
    xaman_log("Cached NFT data for $account");
}

if ($nfts && isset($nfts['result']['account_nfts'])) {
    foreach ($nfts['result']['account_nfts'] as $nft) {
        if (
            ($nft['Issuer'] === 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR' && $nft['NFTokenTaxon'] == 0) ||
            ($nft['Issuer'] === 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga' && $nft['NFTokenTaxon'] == 717825) ||
            ($nft['Issuer'] === 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt' && $nft['NFTokenTaxon'] == 1056369418)
        ) {
            $has_nft = true;
            xaman_log("Qualifying NFT found for $account: Issuer={$nft['Issuer']}, Taxon={$nft['NFTokenTaxon']}");
            break;
        }
    }
}

if (!$has_nft) {
    xaman_log("No qualifying NFT for $account");
    echo '<div class="section"><p>Become a Protector or Guardian to view this replay!</p><a href="' . esc_url(home_url('/mint/')) . '" class="btn">Mint Now</a></div>';
    get_footer();
    exit;
}

// Fetch event details
$events_table = $wpdb->prefix . 'events';
$event = $wpdb->get_row($wpdb->prepare(
    "SELECT id, title, recording_url, left_banner_url, right_banner_url, top_banner_url, top_banner_desktop_url, bottom_banner_url
     FROM $events_table
     WHERE id = %d",
    $event_id
));
if (!$event) {
    xaman_log("Event not found: ID=$event_id");
    echo '<div class="section"><p>Event not found (ID: ' . esc_html($event_id) . ').</p></div>';
    get_footer();
    exit;
}
if (empty(trim($event->recording_url))) {
    xaman_log("No recording_url for Event: ID=$event_id, Title={$event->title}");
    echo '<div class="section"><p>No recording available for event (ID: ' . esc_html($event_id) . ', Title: ' . esc_html($event->title) . ').</p></div>';
    get_footer();
    exit;
}
xaman_log("Event loaded: ID=$event->id, Title={$event->title}, Recording URL={$event->recording_url}");

// Determine if recording_url is a YouTube link
$is_youtube = preg_match('/(youtube\.com\/watch\?v=|youtu\.be\/)/i', $event->recording_url);
$youtube_id = '';
if ($is_youtube) {
    if (preg_match('/youtube\.com\/watch\?v=([^\&\?\/]+)/i', $event->recording_url, $match) ||
        preg_match('/youtu\.be\/([^\&\?\/]+)/i', $event->recording_url, $match)) {
        $youtube_id = $match[1];
    }
    if (!$youtube_id) {
        xaman_log("Invalid YouTube URL for Event: ID=$event_id, URL={$event->recording_url}");
        echo '<div class="section"><p>Invalid video URL for event (ID: ' . esc_html($event_id) . ').</p></div>';
        get_footer();
        exit;
    }
}

// Fetch tip accounts
$tip_accounts_table = $wpdb->prefix . 'tip_accounts';
$tip_accounts = $wpdb->get_results("SELECT id, name, xrp_address FROM $tip_accounts_table WHERE status = 'active'");
xaman_log("Fetched " . count($tip_accounts) . " active tip accounts");

// Check XFT trustline
$xft_issuer = 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt';
$xft_transient_key = 'xaman_xft_' . md5($account);
$has_xft_trustline = get_transient($xft_transient_key . '_trustline');

if ($has_xft_trustline === false) {
    $xrpl_api_url = "https://s1.ripple.com:51234/";
    $request = ['method' => 'account_lines', 'params' => [['account' => $account, 'ledger_index' => 'current']]];
    $response = wp_remote_post($xrpl_api_url, [
        'body' => json_encode($request),
        'headers' => ['Content-Type' => 'application/json'],
        'timeout' => 10
    ]);
    $has_xft_trustline = false;
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['result']['lines'])) {
            foreach ($body['result']['lines'] as $line) {
                if ($line['account'] === $xft_issuer && $line['currency'] === 'XFT') {
                    $has_xft_trustline = true;
                    break;
                }
            }
        }
    } else {
        xaman_log("XRPL API error for $account: " . (is_wp_error($response) ? $response->get_error_message() : "HTTP {$response['response']['code']}"));
    }
    set_transient($xft_transient_key . '_trustline', $has_xft_trustline, 300);
    xaman_log("Checked XFT trustline for $account: " . ($has_xft_trustline ? 'Yes' : 'No'));
}

?>

<div id="page-replay-page" class="page-content">
    <video autoplay muted loop playsinline class="page-replay-background-video">
        <source src="https://videos.pexels.com/video-files/3129902/3129902-uhd_2560_1440_25fps.mp4" type="video/mp4">
        Your browser does not support the video tag.
    </video>
    <div class="event-replay">
        <h2><?php echo esc_html($event->title); ?> - Replay</h2>
        <div class="button-container">
            <?php if ($tip_accounts): ?>
                <button class="tip-button" onclick="document.getElementById('tip-popup').style.display='block';">Send Tip</button>
            <?php endif; ?>
            <button class="help-button" onclick="document.getElementById('help-popup').style.display='block';">Help</button>
            <button class="chat-toggle" id="chat-toggle">Chat</button>
        </div>
        <?php if ($tip_accounts): ?>
            <div class="tip-popup" id="tip-popup">
                <h3>Send a Tip</h3>
                <form id="tip-form">
                    <input type="hidden" name="event_id" value="<?php echo esc_attr($event->id); ?>">
                    <input type="hidden" name="xrpl_account" value="<?php echo esc_attr($account); ?>">
                    <label for="tip_account_id">Recipient:</label>
                    <select id="tip_account_id" name="tip_account_id" required>
                        <option value="">Select Recipient</option>
                        <?php foreach ($tip_accounts as $tip_account): ?>
                            <option value="<?php echo esc_attr($tip_account->id); ?>" data-xrp-address="<?php echo esc_attr($tip_account->xrp_address); ?>">
                                <?php echo esc_html($tip_account->name); ?> (<?php echo esc_html($tip_account->xrp_address); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label for="currency">Currency:</label>
                    <select id="currency" name="currency" required>
                        <option value="XRP">XRP</option>
                        <option value="XFT" <?php echo !$has_xft_trustline ? 'disabled' : ''; ?>>XFT<?php echo !$has_xft_trustline ? ' (Trustline required)' : ''; ?></option>
                    </select>
                    <label for="amount">Amount:</label>
                    <input type="number" id="amount" name="amount" step="0.01" min="0.01" required placeholder="Enter amount (min 0.01)">
                    <label for="memo">Message (Optional):</label>
                    <textarea id="memo" name="memo" maxlength="255" placeholder="Add a message with your tip (max 255 characters)"></textarea>
                    <button type="submit">Send Tip</button>
                    <button type="button" class="close-popup" onclick="document.getElementById('tip-popup').style.display='none';">Close</button>
                </form>
            </div>
        <?php endif; ?>
        <div class="help-popup" id="help-popup">
            <h3>Maximise Your Experiences at IMU Events</h3>
            <h5>Turn up the volume!</h5><p>Hit the unmute button in the bottom right of the media player to turn on the sound!</p>
            <h5>Show your love</h5><p>Send a tip in $XRP or $XFT by hitting the "Send Tip" button above the media player</p>
            <h5>Need the $XFT Trustline?</h5><p>Scroll to the bottom of the page and hit "Set Trustline"</p>
            <h5>Seeing Clearly</h5><p>Drag any corner of the chat box to resize that to your hearts desire!</p>
            <h5>Get out of the way</h5><p>Move the chat box by holding and dragging the gold "Event Replay Chat" bar!</p>
            <button type="button" onclick="document.getElementById('help-popup').style.display='none';">Close</button>
        </div>
        <div class="media-container">
            <?php if (!empty($event->left_banner_url)): ?>
                <img src="<?php echo esc_url($event->left_banner_url); ?>" alt="Left Banner" class="banner left">
            <?php endif; ?>
            <div class="video-container">
                <?php if ($is_youtube && $youtube_id): ?>
                    <iframe src="https://www.youtube.com/embed/<?php echo esc_attr($youtube_id); ?>" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                <?php else: ?>
                    <video id="replay-video" class="video-js vjs-default-skin" controls preload="auto" data-setup='{"fluid": true, "controlBar": {"downloadButton": false}}'>
                        <source src="<?php echo esc_url($event->recording_url); ?>" type="application/x-mpegURL">
                        Your browser does not support the video tag.
                    </video>
                <?php endif; ?>
                <div class="reaction-overlay" id="reaction-overlay"></div>
            </div>
            <?php if (!empty($event->right_banner_url)): ?>
                <img src="<?php echo esc_url($event->right_banner_url); ?>" alt="Right Banner" class="banner right">
            <?php endif; ?>
        </div>
        <div class="chat-container" id="chat-container">
            <div class="chat-header">Event Replay Chat</div>
            <div id="event-chat-messages"></div>
            <form id="event-chat-form">
                <input type="hidden" name="event_id" value="<?php echo esc_attr($event->id); ?>">
                <input type="hidden" name="xrpl_account" value="<?php echo esc_attr($account); ?>">
                <input type="text" name="message" id="event-chat-message" placeholder="Type your message...">
                <button type="button" class="emoji-toggle" title="Toggle Emoji Picker">👽</button>
                <div class="emoji-picker" id="emoji-picker">
                    <?php
                    $reactions = ['👍', '👏', '🤘', '🚀', '👽', '🕺', '💥', '❤️‍🔥', '💃', '🎤', '🎸', '🎷', '🎉', '❤️'];
                    foreach ($reactions as $reaction) {
                        echo '<button type="button" class="emoji-btn" data-emoji="' . esc_attr($reaction) . '">' . $reaction . '</button>';
                    }
                    ?>
                </div>
                <button type="submit">Send</button>
            </form>
            <div class="resize-tl"></div>
            <div class="resize-tr"></div>
            <div class="resize-bl"></div>
            <div class="resize-br"></div>
        </div>
        <div class="reactions-container">
            <h3>Reactions</h3>
            <div id="reactions">
                <?php
                foreach ($reactions as $reaction) {
                    echo '<button class="reaction-btn" data-reaction="' . esc_attr($reaction) . '">' . $reaction . '</button>';
                }
                ?>
            </div>
            <div id="reaction-counts"></div>
        </div>
        <div class="audience-container">
            <h3>Audience (<span id="audience-count">0</span>)</h3>
            <div class="audience-list" id="audience-list"></div>
        </div>
        <?php if ($tip_accounts): ?>
            <div class="tip-instructions">
                <h3>Tip Instructions</h3>
                <p>Show your support by sending a tip in XRP or XFT to an artist or IMU! Click the "Send Tip" button above, select a recipient, choose your currency, and enter an amount (minimum 0.01). You'll need to approve the transaction in your Xaman wallet.</p>
                <?php if (!$has_xft_trustline): ?>
                    <button class="trustline-button" onclick="generateTrustlineQR()">Set Trustline</button>
                    <div class="trustline-qr" id="trustline-qr"></div>
                <?php else: ?>
                    <p class="trustline-set">XFT trustline already set!</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php
        $top_banner_url = esc_url($event->top_banner_url ?? '');
        $top_banner_desktop_url = esc_url($event->top_banner_desktop_url ?? '');
        $bottom_banner_url = esc_url($event->bottom_banner_url ?? '');
        $is_mobile = wp_is_mobile();
        $top_banner_final_url = $is_mobile || empty($top_banner_desktop_url) ? $top_banner_url : $top_banner_desktop_url;
        $top_banner_placeholder = $is_mobile ? 'https://via.placeholder.com/320x100' : 'https://via.placeholder.com/1000x213';
        ?>
        <?php if (!empty($top_banner_final_url)): ?>
            <img src="<?php echo $top_banner_final_url; ?>" alt="Top Banner" class="bottom-top-banner" onerror="this.src='<?php echo $top_banner_placeholder; ?>';">
        <?php endif; ?>
        <?php if (!empty($bottom_banner_url)): ?>
            <img src="<?php echo $bottom_banner_url; ?>" alt="Bottom Banner" class="bottom-banner" onerror="this.src='https://via.placeholder.com/1000x100';">
        <?php endif; ?>
    </div>
</div>
<?php get_footer(); ?>