<?php
// /public_html/burn-to-earn.php
require_once __DIR__ . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

header('Content-Type: application/json');
$log_file = __DIR__ . '/logs/burn-to-earn.log';
if (!file_exists(dirname($log_file))) {
    mkdir(dirname($log_file), 0755, true);
}
if (!is_writable($log_file)) {
    chmod($log_file, 0664);
}

function json_error($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    file_put_contents($GLOBALS['log_file'], date('[Y-m-d H:i:s] ') . "Error: $message\n", FILE_APPEND);
    exit;
}

function create_xumm_payload_with_webhook($txjson, $custom_meta_blob = []) {
    global $log_file;
    $api_key    = defined('XUMM_API_KEY')    ? XUMM_API_KEY    : '';
    $api_secret = defined('XUMM_API_SECRET') ? XUMM_API_SECRET : '';

    // Important: webhook belongs in options.webhook
    // Important: custom_meta is a TOP-LEVEL object, not inside options
    $payload = [
        'txjson'      => $txjson,
        'webhook'     => 'https://imcollectibles.io/xumm-proxy.php',  // Must be root-level, NOT inside options
        'options'     => [
            'expire'     => 5,
            'return_url' => ['web' => 'https://imcollectibles.io/burn-to-earn/'],
        ],
        'custom_meta' => [
            'identifier' => 'nft_burn',
            'blob'       => array_merge(['type' => 'nft_burn'], $custom_meta_blob)
        ]
    ];

    $ch = curl_init('https://xumm.app/api/v1/platform/payload');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'X-API-Key: '    . $api_key,
            'X-API-Secret: ' . $api_secret,
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT        => 15
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Xumm API request: " . json_encode($payload, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Xumm API response (HTTP $status): " . $response . "\n", FILE_APPEND);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['error' => 'Curl error: ' . $error];
    }
    $data = json_decode($response, true);
    curl_close($ch);

    if ($status !== 200 || !isset($data['uuid']) || !isset($data['refs']['qr_png'])) {
        $error_message = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error';
        return ['error' => 'Xumm API error: ' . $error_message];
    }
    return $data;
}


// Log server environment, headers, and account
file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "PHP Version: " . phpversion() . "\n", FILE_APPEND);
file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "PHP Settings: upload_max_filesize=" . ini_get('upload_max_filesize') . ", post_max_size=" . ini_get('post_max_size') . ", memory_limit=" . ini_get('memory_limit') . ", max_input_time=" . ini_get('max_input_time') . ", max_execution_time=" . ini_get('max_execution_time') . "\n", FILE_APPEND);
// SEC (20 Sep 2026): raw getallheaders() dump removed -- can carry the session Bearer.

// Define IMU_BURN_CRON_KEY in wp-config.php for production.
// Fix 1 (security): fail-closed — NO hardcoded fallback. If the constant is not
// defined, $cron_key is '' and the key-gated bypass below can never match
// (empty provided key is rejected), so a missing define means "no bypass",
// never "use a baked-in key".
$cron_key = defined('IMU_BURN_CRON_KEY') ? IMU_BURN_CRON_KEY : '';
// Fix 1: accept the cron key from POST body (preferred, keeps it OUT of the URL /
// access logs) or GET (legacy, transitional). One source of truth used everywhere.
$provided_key = isset($_POST['key']) ? $_POST['key'] : (isset($_GET['key']) ? $_GET['key'] : '');
$has_cron_key = ($cron_key !== '' && hash_equals($cron_key, (string) $provided_key));
// Fix 1: action may now arrive via POST (cron POSTs it) or GET (legacy).
$bt_action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
$bt_auth = function_exists('imc_session_require_wallet')
    ? imc_session_require_wallet('')
    : array('ok' => false, 'wallet' => '');
$account = !empty($bt_auth['ok']) ? $bt_auth['wallet'] : '';
$request_account = isset($_GET['account']) ? sanitize_text_field($_GET['account']) : '';
if ($request_account && $request_account !== $account) {
    file_put_contents($log_file, date('[Y-m-d H:i:s crux: account-mismatch] ') . "Account mismatch: cookie=$account, request=$request_account\n", FILE_APPEND);
    json_error('Account mismatch', 403);
}
file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Request method: {$_SERVER['REQUEST_METHOD']}, Action: " . ($_GET['action'] ?? $_POST['action'] ?? 'none') . ", Account: $account\n", FILE_APPEND);

if ($bt_action && in_array($bt_action, ['cleanup_expired', 'cleanup_submissions', 'manual_update_status', 'simulate_webhook', 'transfer_status', 'burned_nfts'], true) && $has_cron_key) {
    // Skip nonce check for cron and specific actions (key via POST or GET; fail-closed)
} else {
    $nonce = $_POST['_wpnonce'] ?? $_SERVER['HTTP_X_WP_NONCE'] ?? $_GET['_wpnonce'] ?? '';
    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Received nonce: $nonce, POST: " . json_encode($_POST, JSON_PRETTY_PRINT) . ", FILES: " . json_encode($_FILES, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
    if (!$account) {
        json_error('User not logged in', 401);
    }
    if (empty($nonce) || !wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        file_put_contents($log_file, date('[Y-m-d H:i:s crux: nonce-failure] ') . "Invalid nonce: $nonce\n", FILE_APPEND);
        json_error('Invalid nonce', 403);
    }
}

global $wpdb;
$transfers_table = $wpdb->prefix . 'nft_transfers';
$submissions_table = $wpdb->prefix . 'design_submissions';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
    $action = $_POST['action'] ?? '';
    if (stripos($content_type, 'application/json') !== false) {
        $input = file_get_contents('php://input');
        $json_data = json_decode($input, true);
        $action = $json_data['action'] ?? '';
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "JSON input: " . json_encode($json_data, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
    }
    if (!$action && isset($_FILES['design_images'])) {
        $action = 'submit_design';
    }
    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "POST action: $action, Data: " . json_encode($_POST, JSON_PRETTY_PRINT) . ", FILES: " . json_encode($_FILES, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

    if ($action === 'webhook') {
        // Minimal webhook handling (confirmation now in xumm-proxy.php; this is fallback for signed status only)
        $input = file_get_contents('php://input');
        $webhook_data = json_decode($input, true);
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Webhook data: " . json_encode($webhook_data, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
        $uuid = sanitize_text_field($webhook_data['payloadResponse']['payload_uuidv4'] ?? '');
        $tx_hash = sanitize_text_field($webhook_data['payloadResponse']['txid'] ?? '');
        $nft_id = sanitize_text_field($webhook_data['custom_meta']['blob']['nft_id'] ?? '');
        $signed = $webhook_data['meta']['signed'] ?? false;

        if (!$uuid || !$nft_id || !$tx_hash) {
            json_error('Invalid webhook payload', 400);
        }

        if ($signed) {
            $updated = $wpdb->update($transfers_table, [
                'status' => 'pending_confirmation',  // Renamed from 'pending_vps' to reflect direct burn
                'tx_hash' => $tx_hash,
                'updated_at' => current_time('mysql', 1)
            ], ['payload_uuid' => $uuid, 'nft_token_id' => $nft_id], ['%s', '%s', '%s'], ['%s', '%s']);
            if ($updated === false) {
                json_error('Failed to update transfer status', 500);
            }
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Webhook updated transfer to pending_confirmation: UUID=$uuid, NFT=$nft_id, tx_hash=$tx_hash\n", FILE_APPEND);
        }
        echo json_encode(['status' => 'received']);
        exit;
    }

    // Rate limiting applies to user-facing actions only (not webhooks)
    $rate_limit_key = 'rate_limit_' . md5($account);
    $attempts = get_transient($rate_limit_key) ?: 0;
    if ($attempts >= 10) {
        json_error('Too many requests, try again later', 429);
    }
    set_transient($rate_limit_key, $attempts + 1, 60);

    switch ($action) {
        case 'submit_form_data':
            // Store form data in a transient
            $form_data = [
                'email' => sanitize_email($_POST['email'] ?? ''),
                'x_handle' => sanitize_text_field($_POST['x_handle'] ?? ''),
                'description' => sanitize_text_field($_POST['description'] ?? ''),
                'background_id' => (int)($_POST['background_id'] ?? 0)
            ];
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Received form data: " . json_encode($form_data, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

            // Validate form data
            if (empty($form_data['email']) && empty($form_data['x_handle'])) {
                json_error('At least one of Email or X Handle is required', 400);
            }
            if (empty($form_data['description'])) {
                json_error('Missing description', 400);
            }
            if (empty($form_data['background_id'])) {
                json_error('Missing background selection', 400);
            }
            if (strlen($form_data['description']) > 200) {
                json_error('Description exceeds 200 characters', 400);
            }

            // Store in transient with a unique key
            $transient_key = 'design_submission_' . $account . '_' . time();
            set_transient($transient_key, $form_data, 300); // 5 minutes expiry
            echo json_encode(['success' => true, 'transient_key' => $transient_key]);
            exit;

        case 'submit_design':
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Starting submit_design action\n", FILE_APPEND);

            // Verify burn and submission eligibility
            $total_burned = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $transfers_table WHERE xrpl_account = %s AND status = 'confirmed'",
                $account
            ));
            $submission_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $submissions_table WHERE xrpl_account = %s AND status = 'pending'",
                $account
            ));
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Total burned: $total_burned, Submission count: $submission_count\n", FILE_APPEND);
            if ($total_burned < 10 || floor($total_burned / 10) <= $submission_count) {
                json_error('Insufficient confirmed burns or no available submissions', 400);
            }

            // Get form data from transient
            $transient_key = sanitize_text_field($_POST['transient_key'] ?? '');
            $form_data = get_transient($transient_key);
            if ($form_data === false) {
                json_error('Invalid or expired transient key', 400);
            }
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Retrieved form data from transient: " . json_encode($form_data, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

            $email = $form_data['email'];
            $x_handle = $form_data['x_handle'];
            $description = $form_data['description'];
            $background_id = $form_data['background_id'];

            // Process file uploads with server-side validation
            $image_ids = [];
            if (isset($_FILES['design_images']) && !empty($_FILES['design_images']['name'][0])) {
                $files = [];
                if (is_array($_FILES['design_images']['name'])) {
                    foreach ($_FILES['design_images']['name'] as $index => $name) {
                        if (empty($name)) continue;
                        $files[] = [
                            'name' => $name,
                            'type' => $_FILES['design_images']['type'][$index],
                            'tmp_name' => $_FILES['design_images']['tmp_name'][$index],
                            'error' => $_FILES['design_images']['error'][$index],
                            'size' => $_FILES['design_images']['size'][$index]
                        ];
                        // Limit to 1 file server-side (aligned with JS)
                        if (count($files) > 1) break;
                    }
                } else {
                    if (!empty($_FILES['design_images']['name'])) {
                        $files[] = $_FILES['design_images'];
                    }
                }

                $allowed_types = ['image/jpeg', 'image/png'];
                foreach ($files as $file) {
                    $name = sanitize_file_name($file['name']);
                    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Processing file: $name, Size: {$file['size']}, Type: {$file['type']}, Error: {$file['error']}\n", FILE_APPEND);
                    if ($file['error'] !== UPLOAD_ERR_OK) {
                        file_put_contents($log_file, date('[Y-m-d H:i:s crux: file-upload-error] ') . "File upload error for $name: Error code {$file['error']}\n", FILE_APPEND);
                        continue;
                    }
                    if ($file['size'] > 5 * 1024 * 1024) {
                        file_put_contents($log_file, date('[Y-m-d H:i:s crux: file-size-exceeded] ') . "File size exceeds 5MB for $name: Size {$file['size']}\n", FILE_APPEND);
                        continue;
                    }
                    if (!in_array($file['type'], $allowed_types)) {
                        file_put_contents($log_file, date('[Y-m-d H:i:s crux: invalid-file-type] ') . "Invalid file type for $name: {$file['type']}\n", FILE_APPEND);
                        continue;
                    }
                    // Server-side image validation
                    $image_info = getimagesize($file['tmp_name']);
                    if (!$image_info || !in_array($image_info['mime'], $allowed_types)) {
                        file_put_contents($log_file, date('[Y-m-d H:i:s crux: invalid-image] ') . "Invalid image format for $name\n", FILE_APPEND);
                        json_error('Invalid image format', 400);
                    }
                    $upload = wp_handle_upload($file, ['test_form' => false]);
                    if (isset($upload['error'])) {
                        file_put_contents($log_file, date('[Y-m-d H:i:s crux: upload-error] ') . "Upload error for $name: {$upload['error']}\n", FILE_APPEND);
                        continue;
                    }
                    $attachment_id = wp_insert_attachment([
                        'guid' => $upload['url'],
                        'post_mime_type' => $upload['type'],
                        'post_title' => $name,
                        'post_content' => '',
                        'post_status' => 'inherit'
                    ], $upload['file']);
                    if ($attachment_id) {
                        require_once(ABSPATH . 'wp-admin/includes/image.php');
                        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $upload['file']));
                        $image_ids[] = $attachment_id;
                        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Uploaded file $name as attachment ID $attachment_id\n", FILE_APPEND);
                    } else {
                        file_put_contents($log_file, date('[Y-m-d H:i:s crux: attachment-failure] ') . "Failed to insert attachment for $name\n", FILE_APPEND);
                    }
                }
            } else {
                file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "No files uploaded (optional)\n", FILE_APPEND);
            }

            // Get burned NFTs
            $burned_nfts = $wpdb->get_col($wpdb->prepare(
                "SELECT nft_token_id FROM $transfers_table WHERE xrpl_account = %s AND status = 'confirmed' ORDER BY created_at DESC LIMIT 10",
                $account
            ));
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Burned NFTs: " . json_encode($burned_nfts, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

            // Insert submission
            $insert_data = [
                'xrpl_account' => $account,
                'x_handle' => $x_handle,
                'email' => $email,
                'image_ids' => implode(',', $image_ids),
                'description' => $description,
                'background_id' => $background_id,
                'burned_nfts' => implode(',', $burned_nfts),
                'status' => 'pending',
                'created_at' => current_time('mysql', 1),
                'admin_notified' => 0
            ];
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Insert data: " . json_encode($insert_data, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

            $inserted = $wpdb->insert($submissions_table, $insert_data);
            if ($inserted === false) {
                $db_error = $wpdb->last_error;
                file_put_contents($log_file, date('[Y-m-d H:i:s crux: db-error] ') . "Database insert failed: $db_error\n", FILE_APPEND);
                json_error('Failed to store submission: ' . $db_error, 500);
            }

            // Delete transient
            delete_transient($transient_key);

            // Send admin notification
            $message = "New submission from $account.\nX Handle: $x_handle\nEmail: $email\nDescription: $description\nImages: " . count($image_ids);
            wp_mail('admin@imcollectibles.io', 'New Burn-2-Earn Submission', $message);
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Submission inserted successfully, ID: {$wpdb->insert_id}, Admin notified\n", FILE_APPEND);

            echo json_encode(['success' => true, 'image_ids' => $image_ids, 'submission_id' => $wpdb->insert_id]);
            exit;

        case 'update_transfer_status':
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Update transfer status data: " . json_encode($data, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
    $nft_id = strtoupper(sanitize_text_field($data['nft_id'] ?? ''));
    if (empty($nft_id) || !preg_match('/^[0-9A-F]{64}$/', $nft_id)) {
        json_error('Invalid NFT ID format', 400);
    }
    $status = sanitize_text_field($data['status'] ?? '');
    // Whitelist: users may only advance to pending_confirmation (never confirmed — that requires XRPL proof)
    if (!in_array($status, ['pending_confirmation'], true)) {
        json_error('Invalid status value', 400);
    }
    $tx_hash = sanitize_text_field($data['tx_hash'] ?? '');
    if (!$tx_hash) {
        json_error('Missing required fields', 400);
    }
    $updated = $wpdb->update($transfers_table, [
        'status' => $status,
        'tx_hash' => $tx_hash,
        'updated_at' => current_time('mysql', 1)
    ], ['nft_token_id' => $nft_id, 'xrpl_account' => $account], ['%s', '%s', '%s'], ['%s', '%s']);
    if ($updated === false) {
        json_error('Failed to update transfer status', 500);
    }
    echo json_encode(['success' => true]);
    exit;

        case 'burn_single_nft':
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Burn single NFT data: " . json_encode($data, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
            $nft_id = strtoupper(sanitize_text_field($data['nft_id'] ?? ''));
            if (empty($nft_id) || !preg_match('/^[0-9A-F]{64}$/', $nft_id)) {
                json_error('Invalid NFT ID format', 400);
            }

            // Fetch NFT ownership directly from XRPL (no loopback HTTP call)
            $transient_key = 'nft_metadata_' . md5($account);
            $nfts = get_transient($transient_key);
            if ($nfts === false) {
                $xrpl_url = defined('XRP_RPC_URL') ? XRP_RPC_URL : 'https://xrplcluster.com';
                $nfts = [];
                $marker = null;
                $page = 0;
                do {
                    $params = ['account' => $account, 'ledger_index' => 'validated', 'limit' => 200];
                    if ($marker) $params['marker'] = $marker;
                    $rpc_response = wp_remote_post($xrpl_url, [
                        'body'    => json_encode(['method' => 'account_nfts', 'params' => [$params]]),
                        'headers' => ['Content-Type' => 'application/json'],
                        'timeout' => 10,
                    ]);
                    if (is_wp_error($rpc_response)) {
                        json_error('Failed to validate NFT: ' . $rpc_response->get_error_message(), 500);
                    }
                    $rpc_body = json_decode(wp_remote_retrieve_body($rpc_response), true);
                    $page_nfts = $rpc_body['result']['account_nfts'] ?? [];
                    $nfts = array_merge($nfts, $page_nfts);
                    $marker = $rpc_body['result']['marker'] ?? null;
                    $page++;
                } while ($marker && $page < 20);
                set_transient($transient_key, $nfts, HOUR_IN_SECONDS);
                file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Fetched " . count($nfts) . " NFTs via direct XRPL RPC for account: $account\n", FILE_APPEND);
            }
            if (empty($nfts)) {
                json_error('No NFTs found for account', 400);
            }
            $owned_nft_ids = array_map('strtoupper', array_column($nfts, 'NFTokenID'));
            if (!in_array($nft_id, $owned_nft_ids)) {
                json_error('Selected NFT not owned by user', 400);
            }

            // Check if NFT already burned (anti-duplication)
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $transfers_table WHERE nft_token_id = %s",
                $nft_id
            ));
            if ($existing > 0) {
                json_error('NFT already burned or in process', 400);
            }

            $nft = array_filter($nfts, fn($n) => strtoupper($n['NFTokenID'] ?? '') === $nft_id);
            $nft = reset($nft);
            $issuer = $nft['Issuer'] ?? null;
            if (!$issuer) {
                json_error('NFT has no issuer', 400);
            }

            $txjson = [
                'TransactionType' => 'NFTokenBurn',
                'Account' => $account,
                'NFTokenID' => $nft_id,
                'SourceTag' => 2606240013,
                'Memos' => [['Memo' => ['MemoData' => bin2hex('Burning Protector in the Frequency Firepit 🔥')]]]
            ];
            // --- Joey branch (v553, additive): insert the pending row (claims the NFT like the
            // Xaman path), return the NFTokenBurn txjson for local signing. joey_verify_burn
            // confirms on-chain. Xaman path below untouched.
            // v684: the SERVER decides the wallet, matching the v683 change to the other four
            // handlers. This branch was missed by that pass because it reads php://input JSON
            // rather than $_POST, so a $_POST['wallet'] sweep did not surface it. Both this
            // branch and the Xaman path below insert the SAME 'pending' transfers row and bust
            // the same cache, so widening the predicate introduces no new orphan-row class.
            if (($json_data['wallet'] ?? '') === 'joey' || ($_COOKIE['xrpl_wallet_type'] ?? '') === 'joey') {
                $wpdb->insert($transfers_table, [
                    'xrpl_account' => $account,
                    'nft_token_id' => $nft_id,
                    'issuer'       => $issuer,
                    'taxon'        => $nft['NFTokenTaxon'] ?? 0,
                    'memo'         => 'Burning Protector in the Frequency Firepit 🔥',
                    'status'       => 'pending',
                    'created_at'   => current_time('mysql', 1),
                    'payload_uuid' => 'joey'
                ]);
                delete_transient('nft_metadata_' . md5($account));
                echo json_encode(['success' => true, 'wallet' => 'joey', 'txjson' => $txjson, 'nft_id' => $nft_id]);
                exit;
            }

            $payload = create_xumm_payload_with_webhook($txjson, ['nft_id' => $nft_id]);
            if (isset($payload['error'])) {
                json_error('Failed to create payload: ' . $payload['error'], 500);
            }

            $insert_data = [
                'xrpl_account' => $account,
                'nft_token_id' => $nft_id,
                'issuer' => $issuer,
                'taxon' => $nft['NFTokenTaxon'] ?? 0,
                'memo' => 'Burning Protector in the Frequency Firepit 🔥',
                'status' => 'pending',
                'created_at' => current_time('mysql', 1),
                'payload_uuid' => $payload['uuid']
            ];
            $inserted = $wpdb->insert($transfers_table, $insert_data);
            if ($inserted === false) {
                json_error('Failed to store transfer in database: ' . $wpdb->last_error, 500);
            }
            // Bust the NFT ownership cache so a just-burned NFT can't be re-selected
            delete_transient('nft_metadata_' . md5($account));
            echo json_encode([
                'success' => true,
                'uuid' => $payload['uuid'],
                'qr' => $payload['refs']['qr_png'],
                'deeplink' => $payload['next']['always'],
                'nft_id' => $nft_id
            ]);
            exit;


        case 'joey_verify_burn':
            // v553 Joey/WalletConnect burn confirm. Nonce already verified by the top gate;
            // $account is the cookie account. Verify the NFTokenBurn on-chain, then mark confirmed.
            $j_nft = strtoupper(sanitize_text_field($json_data['nft_id'] ?? ''));
            $j_tx  = sanitize_text_field($json_data['tx_hash'] ?? '');
            if (!preg_match('/^[A-F0-9]{64}$/', $j_nft)) json_error('Invalid NFT id', 400);
            if (!preg_match('/^[A-Fa-f0-9]{64}$/', $j_tx)) json_error('Invalid transaction hash', 400);
            $j_url = defined('XRP_RPC_URL') ? XRP_RPC_URL : 'https://xrplcluster.com';
            $j_res = null;
            for ($ji = 0; $ji < 6; $ji++) {
                $j_r = wp_remote_post($j_url, [
                    'body' => json_encode(['method' => 'tx', 'params' => [['transaction' => $j_tx, 'binary' => false]]]),
                    'headers' => ['Content-Type' => 'application/json'],
                    'timeout' => 10
                ]);
                if (!is_wp_error($j_r)) {
                    $j_b = json_decode(wp_remote_retrieve_body($j_r), true);
                    $j_res = $j_b['result'] ?? null;
                    if (!empty($j_res['validated'])) break;
                }
                sleep(2);
            }
            if (empty($j_res) || empty($j_res['validated'])) json_error('Transaction not validated -- please retry', 400);
            if (($j_res['meta']['TransactionResult'] ?? '') !== 'tesSUCCESS') json_error('Burn failed on-chain: ' . ($j_res['meta']['TransactionResult'] ?? 'unknown'), 400);
            if (($j_res['TransactionType'] ?? '') !== 'NFTokenBurn') json_error('Unexpected transaction type', 400);
            if (($j_res['Account'] ?? '') !== $account) json_error('Transaction account mismatch', 403);
            if (strtoupper($j_res['NFTokenID'] ?? '') !== $j_nft) json_error('Burned NFT mismatch', 400);
            $wpdb->update($transfers_table, [
                'status' => 'confirmed', 'tx_hash' => $j_tx, 'updated_at' => current_time('mysql', 1)
            ], ['nft_token_id' => $j_nft, 'xrpl_account' => $account]);
            delete_transient('nft_metadata_' . md5($account));
            echo json_encode(['success' => true, 'nft_id' => $j_nft, 'tx_hash' => $j_tx]);
            exit;
    }

    // Fix 1 (Option B): cron cleanup actions, POST-reachable. Key-gated ($has_cron_key
    // was computed at the top from POST-or-GET). Lets the cron POST the key so it
    // never rides in the URL / access logs. Same queries as the GET-side handlers.
    if ($has_cron_key && $action === 'cleanup_expired') {
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE $transfers_table SET status = 'expired' WHERE status = 'pending' AND created_at < %s",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        ));
        echo json_encode(['success' => true, 'updated' => $updated]);
        exit;
    }
    if ($has_cron_key && $action === 'cleanup_submissions') {
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE $submissions_table SET status = 'expired' WHERE status = 'pending' AND created_at < %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        ));
        echo json_encode(['success' => true, 'updated' => $updated]);
        exit;
    }

    json_error('Invalid request', 400);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    if ($_GET['action'] === 'burned_counts') {
        $freq_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $transfers_table WHERE xrpl_account = %s AND status = 'confirmed' AND issuer IN ('rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga', 'rf1MGf4U8CZzb2NDGa5qXPFm4eM39zKq9U') AND taxon IN (717825, 1)",
            $account
        ));
        $ledger_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $transfers_table WHERE xrpl_account = %s AND status = 'confirmed' AND issuer = 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt' AND taxon = 1056369418",
            $account
        ));
        $submission_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $submissions_table WHERE xrpl_account = %s AND status = 'pending'",
            $account
        ));
        echo json_encode([
            'success' => true,
            'protectors_freq' => (int)$freq_count,
            'protectors_ledger' => (int)$ledger_count,
            'submission_count' => (int)$submission_count
        ]);
        exit;
    }

    if ($_GET['action'] === 'burned_nfts') {
        $burned_nfts = $wpdb->get_col($wpdb->prepare(
            "SELECT nft_token_id FROM $transfers_table WHERE xrpl_account = %s AND status = 'confirmed'",
            $account
        ));
        echo json_encode([
            'success' => true,
            'burned_nfts' => $burned_nfts
        ]);
        exit;
    }

    if ($_GET['action'] === 'transfer_status') {
    $uuid = sanitize_text_field($_GET['uuid'] ?? '');
    $type = sanitize_text_field($_GET['type'] ?? '');
    $account_for_query = $account;

    if ($uuid && !$has_cron_key) {
        $query = $wpdb->prepare(
            "SELECT * FROM $transfers_table WHERE payload_uuid = %s LIMIT 1",
            $uuid
        );
    } else {
        if ($has_cron_key) {
            $account_for_query = '%';
        }
        $query = $wpdb->prepare(
            "SELECT * FROM $transfers_table WHERE xrpl_account LIKE %s ORDER BY created_at DESC",
            $account_for_query
        );
    }

    $transfers = $wpdb->get_results($query, ARRAY_A);
    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Transfer_status query: $query\nResults: " . json_encode($transfers, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

    $submissions = $wpdb->get_results($wpdb->prepare(
        "SELECT id, status, created_at FROM $submissions_table WHERE xrpl_account LIKE %s ORDER BY created_at DESC",
        $account_for_query
    ), ARRAY_A);

    $payload = [];
    if ($uuid) {
        $response = wp_remote_get("https://xumm.app/api/v1/platform/payload/$uuid", [
            'headers' => [
                'X-API-Key' => XUMM_API_KEY,
                'X-API-Secret' => XUMM_API_SECRET
            ],
            'timeout' => 15
        ]);
        if (!is_wp_error($response)) {
            $payload = json_decode(wp_remote_retrieve_body($response), true);
            if ($payload['meta']['signed']) {
                $tx_hash = $payload['response']['txid'] ?? '';
                $nft_id = $payload['custom_meta']['blob']['nft_id'] ?? $payload['payload']['request_json']['NFTokenID'] ?? '';
                if ($nft_id && $tx_hash) {
                    // Fallback: if not yet confirmed, check directly and update
                    $tx_details = wp_remote_post('https://xrplcluster.com/', [
                        'body' => json_encode(['method' => 'tx', 'params' => [['transaction' => $tx_hash, 'binary' => false]]]),
                        'headers' => ['Content-Type' => 'application/json'],
                        'timeout' => 10
                    ]);
                    if (!is_wp_error($tx_details)) {
                        $tx_body = json_decode(wp_remote_retrieve_body($tx_details), true);
                        $validated = $tx_body['result']['validated'] ?? false;
                        $tes = $tx_body['result']['meta']['TransactionResult'] ?? null;
                        if ($validated && $tes === 'tesSUCCESS') {
                            // Confirm NFT gone
                            $an_details = wp_remote_post('https://xrplcluster.com/', [
                                'body' => json_encode(['method' => 'account_nfts', 'params' => [['account' => $account, 'ledger_index' => 'validated']]]),
                                'headers' => ['Content-Type' => 'application/json'],
                                'timeout' => 10
                            ]);
                            if (!is_wp_error($an_details)) {
                                $an_body = json_decode(wp_remote_retrieve_body($an_details), true);
                                $nft_found = false;
                                foreach ($an_body['result']['account_nfts'] ?? [] as $owned_nft) {
                                    if (strtoupper($owned_nft['NFTokenID']) === strtoupper($nft_id)) {
                                        $nft_found = true;
                                        break;
                                    }
                                }
                                if (!$nft_found) {
                                    $updated = $wpdb->update($transfers_table, [
                                        'status' => 'confirmed',
                                        'tx_hash' => $tx_hash,
                                        'updated_at' => current_time('mysql', 1)
                                    ], ['payload_uuid' => $uuid, 'nft_token_id' => $nft_id], ['%s', '%s', '%s'], ['%s', '%s']);
                                    // Re-fetch transfers after update to reflect in response
                                    $transfers = $wpdb->get_results($query, ARRAY_A);
                                    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Fallback confirmed burn for NFT $nft_id, tx $tx_hash\n", FILE_APPEND);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'transfers' => ($type === 'transfers' || $type === '') ? $transfers : [],  // Default to sending transfers if type empty
        'submissions' => $type === 'submissions' ? $submissions : [],
        'signed' => !empty($uuid) && ($payload['meta']['signed'] ?? false),
        'nft_id' => $wpdb->get_var($wpdb->prepare(
            "SELECT nft_token_id FROM $transfers_table WHERE payload_uuid = %s",
            $uuid
        ))
    ]);
    exit;
}

    if ($bt_action === 'cleanup_expired' && $has_cron_key) {
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE $transfers_table SET status = 'expired' WHERE status = 'pending' AND created_at < %s",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        ));
        echo json_encode(['success' => true, 'updated' => $updated]);
        exit;
    }

    if ($bt_action === 'manual_update_status' && $has_cron_key) {
        $nft_id = sanitize_text_field($_GET['nft_id'] ?? '');
        $tx_hash = sanitize_text_field($_GET['tx_hash'] ?? '');
        $uuid = sanitize_text_field($_GET['uuid'] ?? '');
        if (!$nft_id || !$tx_hash) {
            json_error('Missing nft_id or tx_hash', 400);
        }
        if ($uuid === 'server-initiated') {
            $updated = $wpdb->update($transfers_table, [
                'status' => 'confirmed',
                'tx_hash' => $tx_hash,
                'updated_at' => current_time('mysql', 1)
            ], ['nft_token_id' => $nft_id], ['%s', '%s', '%s'], ['%s']);
        } else {
            if (!$uuid) {
                json_error('Missing uuid', 400);
            }
            $updated = $wpdb->update($transfers_table, [
                'status' => 'pending_confirmation',
                'tx_hash' => $tx_hash,
                'updated_at' => current_time('mysql', 1)
            ], ['nft_token_id' => $nft_id, 'payload_uuid' => $uuid], ['%s', '%s', '%s'], ['%s', '%s']);
        }
        if ($updated === false) {
            json_error('Failed to update transfer status', 500);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($bt_action === 'simulate_webhook' && $has_cron_key) {
        $uuid = sanitize_text_field($_GET['uuid'] ?? '');
        $tx_hash = sanitize_text_field($_GET['tx_hash'] ?? '');
        $nft_id = sanitize_text_field($_GET['nft_id'] ?? '');
        $account = sanitize_text_field($_GET['account'] ?? '');
        if (!$uuid || !$tx_hash || !$nft_id || !$account) {
            json_error('Missing uuid, tx_hash, nft_id, or account', 400);
        }
        $webhook_data = [
            'payloadResponse' => [
                'payload_uuidv4' => $uuid,
                'txid' => $tx_hash,
                'account' => $account
            ],
            'custom_meta' => [
                'nft_id' => $nft_id,
                'type' => 'nft_burn'
            ],
            'meta' => [
                'signed' => true
            ]
        ];
        $updated = $wpdb->update($transfers_table, [
            'status' => 'pending_confirmation',
            'tx_hash' => $tx_hash,
            'updated_at' => current_time('mysql', 1)
        ], ['payload_uuid' => $uuid, 'nft_token_id' => $nft_id], ['%s', '%s', '%s'], ['%s', '%s']);
        if ($updated === false) {
            json_error('Failed to update transfer status', 500);
        }
        echo json_encode(['status' => 'received']);
        exit;
    }

    if ($bt_action === 'cleanup_submissions' && $has_cron_key) {
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE $submissions_table SET status = 'expired' WHERE status = 'pending' AND created_at < %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        ));
        echo json_encode(['success' => true, 'updated' => $updated]);
        exit;
    }

    json_error('Invalid request', 400);
}
?>