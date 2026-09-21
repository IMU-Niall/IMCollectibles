<?php
/**
 * File: upload-handler.php
 * Path: /wp-content/themes/astra/xrpl-nft-marketplace/backend/upload-handler.php
 * Purpose: Handle file uploads to IPFS via Pinata
 * 
 * Actions:
 * - file: Upload media file to Pinata
 * - metadata: Upload metadata JSON to Pinata
 */

require_once dirname(__DIR__, 5) . '/wp-load.php';
if (!defined('ABSPATH')) exit;

header('Content-Type: application/json');

// Pinata API credentials - CONFIGURE THESE
define('PINATA_API_KEY', get_option('improtectors_pinata_api_key', ''));
define('PINATA_SECRET_KEY', get_option('improtectors_pinata_secret_key', ''));
define('PINATA_JWT', get_option('improtectors_pinata_jwt', '')); // Preferred method

// Max file sizes (bytes)
define('MAX_AUDIO_SIZE', 100 * 1024 * 1024);   // 100MB
define('MAX_VIDEO_SIZE', 500 * 1024 * 1024);   // 500MB
define('MAX_IMAGE_SIZE', 50 * 1024 * 1024);    // 50MB

function upload_error($msg, $code = 400) {
    wp_send_json_error(['error' => $msg], $code);
}

// Verify nonce
$nonce = sanitize_text_field($_POST['nonce'] ?? $_GET['nonce'] ?? '');
if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
    upload_error('Invalid security token', 403);
}

// Check Pinata configuration
if (empty(PINATA_JWT) && (empty(PINATA_API_KEY) || empty(PINATA_SECRET_KEY))) {
    upload_error('IPFS storage not configured. Please contact administrator.', 500);
}

$action = sanitize_text_field($_POST['action'] ?? 'file');

try {
    switch ($action) {
        case 'file':
            handleFileUpload();
            break;
        
        case 'metadata':
            handleMetadataUpload();
            break;
        
        default:
            upload_error('Invalid action');
    }
} catch (Exception $e) {
    upload_error('Upload error: ' . $e->getMessage(), 500);
}

/**
 * Handle media file upload to Pinata
 */
function handleFileUpload() {
    if (empty($_FILES['file'])) {
        upload_error('No file provided');
    }

    $file = $_FILES['file'];
    $type = sanitize_text_field($_POST['type'] ?? 'primary');

    // Validate upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server limit',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form limit',
            UPLOAD_ERR_PARTIAL => 'File only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
            UPLOAD_ERR_EXTENSION => 'Upload blocked by extension'
        ];
        upload_error($errors[$file['error']] ?? 'Upload failed');
    }

    // Detect MIME type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    // Validate file type and size
    $maxSize = MAX_IMAGE_SIZE;
    $allowedTypes = [];

    if (strpos($mimeType, 'audio/') === 0) {
        $maxSize = MAX_AUDIO_SIZE;
        $allowedTypes = ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/flac', 'audio/x-flac', 'audio/mp4', 'audio/m4a', 'audio/aac'];
    } elseif (strpos($mimeType, 'video/') === 0) {
        $maxSize = MAX_VIDEO_SIZE;
        $allowedTypes = ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-msvideo', 'video/x-matroska'];
    } elseif (strpos($mimeType, 'image/') === 0) {
        $maxSize = MAX_IMAGE_SIZE;
        $allowedTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp', 'image/svg+xml'];
    } else {
        upload_error('Unsupported file type: ' . $mimeType);
    }

    if (!in_array($mimeType, $allowedTypes)) {
        upload_error('File type not allowed: ' . $mimeType);
    }

    if ($file['size'] > $maxSize) {
        upload_error('File too large. Max: ' . formatBytes($maxSize));
    }

    // Upload to Pinata
    $result = uploadToPinata($file['tmp_name'], $file['name'], [
        'type' => $type,
        'mimeType' => $mimeType,
        'originalName' => $file['name']
    ]);

    if ($result['success']) {
        wp_send_json_success([
            'ipfs_hash' => $result['hash'],
            'ipfs_url' => 'ipfs://' . $result['hash'],
            'gateway_url' => 'https://gateway.pinata.cloud/ipfs/' . $result['hash'],
            'size' => $file['size'],
            'mime_type' => $mimeType
        ]);
    } else {
        upload_error('IPFS upload failed: ' . ($result['error'] ?? 'Unknown error'));
    }
}

/**
 * Handle metadata JSON upload to Pinata
 */
function handleMetadataUpload() {
    $metadataJson = stripslashes($_POST['metadata'] ?? '');
    
    if (empty($metadataJson)) {
        upload_error('No metadata provided');
    }

    // Validate JSON
    $metadata = json_decode($metadataJson, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        upload_error('Invalid JSON: ' . json_last_error_msg());
    }

    // Validate required fields
    if (empty($metadata['name']) || empty($metadata['description'])) {
        upload_error('Missing required metadata fields');
    }

    // Create temp file for metadata
    $tempFile = tempnam(sys_get_temp_dir(), 'metadata_');
    file_put_contents($tempFile, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Generate filename
    $filename = sanitize_file_name($metadata['name']) . '_metadata.json';

    // Upload to Pinata
    $result = uploadToPinata($tempFile, $filename, [
        'type' => 'metadata',
        'nftName' => $metadata['name'],
        'contentType' => $metadata[$metadata['nftType'] ?? 'art'] ?? 'unknown'
    ]);

    // Clean up temp file
    unlink($tempFile);

    if ($result['success']) {
        wp_send_json_success([
            'ipfs_hash' => $result['hash'],
            'ipfs_url' => 'ipfs://' . $result['hash'],
            'gateway_url' => 'https://gateway.pinata.cloud/ipfs/' . $result['hash']
        ]);
    } else {
        upload_error('Metadata upload failed: ' . ($result['error'] ?? 'Unknown error'));
    }
}

/**
 * Upload file to Pinata IPFS
 */
function uploadToPinata($filePath, $fileName, $metadata = []) {
    $endpoint = 'https://api.pinata.cloud/pinning/pinFileToIPFS';

    // Build multipart request
    $boundary = wp_generate_password(24, false);
    
    $body = '';
    
    // File part
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$fileName}\"\r\n";
    $body .= "Content-Type: application/octet-stream\r\n\r\n";
    $body .= file_get_contents($filePath) . "\r\n";
    
    // Pinata options
    $pinataOptions = [
        'cidVersion' => 1
    ];
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"pinataOptions\"\r\n";
    $body .= "Content-Type: application/json\r\n\r\n";
    $body .= json_encode($pinataOptions) . "\r\n";
    
    // Pinata metadata
    $pinataMetadata = [
        'name' => $fileName,
        'keyvalues' => array_merge([
            'uploadedAt' => date('c'),
            'platform' => 'IMU Marketplace'
        ], $metadata)
    ];
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"pinataMetadata\"\r\n";
    $body .= "Content-Type: application/json\r\n\r\n";
    $body .= json_encode($pinataMetadata) . "\r\n";
    
    $body .= "--{$boundary}--\r\n";

    // Build headers
    $headers = [
        'Content-Type' => 'multipart/form-data; boundary=' . $boundary
    ];

    // Use JWT if available, otherwise API keys
    if (!empty(PINATA_JWT)) {
        $headers['Authorization'] = 'Bearer ' . PINATA_JWT;
    } else {
        $headers['pinata_api_key'] = PINATA_API_KEY;
        $headers['pinata_secret_api_key'] = PINATA_SECRET_KEY;
    }

    // Make request
    $response = wp_remote_post($endpoint, [
        'headers' => $headers,
        'body' => $body,
        'timeout' => 120, // 2 minutes for large files
        'sslverify' => true
    ]);

    if (is_wp_error($response)) {
        return [
            'success' => false,
            'error' => $response->get_error_message()
        ];
    }

    $statusCode = wp_remote_retrieve_response_code($response);
    $responseBody = json_decode(wp_remote_retrieve_body($response), true);

    if ($statusCode === 200 && !empty($responseBody['IpfsHash'])) {
        return [
            'success' => true,
            'hash' => $responseBody['IpfsHash'],
            'size' => $responseBody['PinSize'] ?? 0,
            'timestamp' => $responseBody['Timestamp'] ?? ''
        ];
    }

    return [
        'success' => false,
        'error' => $responseBody['error'] ?? $responseBody['message'] ?? "HTTP {$statusCode}"
    ];
}

/**
 * Format bytes to human readable
 */
function formatBytes($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / (1024 * 1024), 1) . ' MB';
}
