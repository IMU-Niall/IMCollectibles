<?php
/**
 * Template Name: NFT Collection Page
 * File: page-collection.php
 * Path: /wp-content/themes/astra/page-collection.php (THEME ROOT)
 * 
 * This template handles the /collections/ URL.
 * It delegates to collections.php which handles:
 * - Browse mode (no params) - shows all indexed collections
 * - Collection mode (with issuer/taxon) - shows specific collection
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Include the main collections template
// This handles get_header(), get_footer(), and all logic
require_once get_stylesheet_directory() . '/page-templates/collections.php';
