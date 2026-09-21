<?php
/**
 * Template Name: IMU Create NFT
 * File: page-mint.php (XLS-24d Entertainment NFT Mint Wizard)
 * Path: /wp-content/themes/astra/xrpl-nft-marketplace/frontend/page-mint.php
 * Shortcode: [xrpl_mint_wizard]
 * 
 * PRO-Compliant Entertainment NFT minting wizard with:
 * - Music (music.v1), Music Video (musicvideo.v1), Art (art.v1), Film (film.v1) support
 * - Full XLS-24d schema compliance for PRO reporting
 * - Writer credits with roles, ownership shares, IPI, PRO affiliations
 * - Master recording ownership
 * - Sample disclosure and AI disclosure (required)
 * - XUMM signing integration
 * 
 * @version 2.0.0 - XLS-24d Compliant
 */

// ── OG Meta ─────────────────────────────────────────────────────────────────
global $imc_og_data;
$imc_og_data = [
    'title'       => 'Create an NFT | IMCollectibles',
    'description' => 'Mint music, film, and art NFTs on the XRP Ledger. Full XLS-24d compliance, PRO royalty reporting, and VPS-protected master file delivery.',
    'image'       => defined('IMC_OG_DEFAULT_IMAGE') ? IMC_OG_DEFAULT_IMAGE : 'https://imcollectibles.io/wp-content/uploads/og-default.png',
    'url'         => home_url('/mint/'),
    'type'        => 'website',
];

get_header();

// Get connected account
$xrpl_account = function_exists('imc_session_wallet') ? imc_session_wallet() : '';
$nonce = wp_create_nonce('xrpl_marketplace_nonce');

$vps_config = [
    'endpoint' => defined('IMU_VPS_ENDPOINT') ? IMU_VPS_ENDPOINT : 'https://metadata.imcollectibles.io/media-processor.php',
    'apiKey'   => '', // A2 Stage-2: the key NEVER ships to the browser again -- all lanes ticket-auth, consumed server-side.
];

// FREE MINT PROMO -- DISPLAY data only (the server enforces in listings-handler;
// keep these defaults IN SYNC with imc_fm_months()/imc_fm_quotas() there, or set
// IMC_FREE_MINT_MONTHS / IMC_FREE_MINT_QUOTAS in wp-config once -- both files honour it).
$imc_fm_quotas = defined('IMC_FREE_MINT_QUOTAS') ? (array) IMC_FREE_MINT_QUOTAS
    : ['art' => 4, 'music' => 2, 'musicvideo' => 2, 'film' => 1, 'album' => 1, 'ebook' => 1, 'audiobook' => 1];
$imc_fm_months = defined('IMC_FREE_MINT_MONTHS') ? (array) IMC_FREE_MINT_MONTHS : ['2026-08', '2026-09'];
$imc_fm_on = (!defined('IMC_FREE_MINT_PROMO') || IMC_FREE_MINT_PROMO)
    && in_array(current_time('Y-m'), $imc_fm_months, true);
$imc_fm_remaining = [];
if ($imc_fm_on && $xrpl_account) {
    global $wpdb;
    $imc_fm_t = $wpdb->prefix . 'imc_listings';
    $imc_fm_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT nft_type, COUNT(*) c FROM $imc_fm_t WHERE artist_account = %s
           AND platform_fee_tx LIKE 'PROMO-%%' AND DATE_FORMAT(created_at, '%%Y-%%m') = %s
         GROUP BY nft_type",
        $xrpl_account, current_time('Y-m')
    ), ARRAY_A);
    $imc_fm_used = [];
    foreach ((array) $imc_fm_rows as $imc_fm_r) { $imc_fm_used[$imc_fm_r['nft_type']] = intval($imc_fm_r['c']); }
    foreach ($imc_fm_quotas as $imc_fm_ty => $imc_fm_q) {
        $imc_fm_remaining[$imc_fm_ty] = max(0, intval($imc_fm_q) - ($imc_fm_used[$imc_fm_ty] ?? 0));
    }
}

// Endpoints for JavaScript
$endpoints_json = wp_json_encode([
    'xummProxy'      => home_url('/xumm-proxy.php'),
    'mintHandler'    => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/mint-handler.php',
    'uploadHandler'  => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/upload-handler.php',
    // R-C3a: removed - pointed at the LEGACY store, never read.
    'schemasBase'    => 'https://imcollectibles.io/schemas/',
    'authMinter'     => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/authorized-minter-handler.php',
    'listings'       => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/listings-handler.php',
    'mintOnDemand'   => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/mint-on-demand-handler.php',
    'collectionsApi' => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/collections-api-handler.php',
    // M1-e2c: self-hosted PDF.js (Apache-2.0, Mozilla). mint.js lazy-loads these only
    // when a creator picks a PDF, so a normal /mint/ visit never pays for them.
    // CSP already permits it: script-src 'self', worker-src 'self' blob:.
    'pdfjs'          => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/frontend/pdf.min.js',
    'pdfjsWorker'    => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/frontend/pdf.worker.min.js'
]);

// v24-CS: Album Access NFT — wallet allowlist preview.
// Define IMC_ALBUM_PREVIEW_WALLETS in wp-config.php as an array of XRPL addresses
// that are permitted to access the full album minting wizard.
// Everyone else sees a non-clickable Coming Soon card.
//
// wp-config.php example:
//   define('IMC_ALBUM_PREVIEW_WALLETS', ['rYourWallet...', 'rAnotherWallet...']);
//
// To go fully public (all artists):
//   Change the single line below to: $album_is_live = true;
//
$_imc_preview_wallets = defined('IMC_ALBUM_PREVIEW_WALLETS')
    ? (array) IMC_ALBUM_PREVIEW_WALLETS
    : [];
$album_is_live = true; // Phase 7A (v536): album minting is now public for all artists
// M3: the selector is grouped. Group cards are the only thing shown at first;
// choosing one reveals its members. 'members' are keys of $content_types, so the
// leaf type - and therefore state.contentType - is unchanged everywhere downstream.
// Art and Film are single-member groups: they select their type directly today, and
// gain sub-types later (generative art, short film) with no further restructuring.
$content_groups = [
    'music' => [
        'name'        => 'Music Access',
        'icon'        => 'music',
        'description' => 'Tracks, videos and albums',
        'prompt'      => 'Which kind of music?',
        'members'     => ['music', 'musicVideo', 'album'],
    ],
    'books' => [
        'name'        => 'Comics &amp; Books',
        'icon'        => 'book',
        'description' => 'eBooks and AudioBooks',
        'prompt'      => 'Which kind of book?',
        'members'     => ['ebook', 'audiobook'],
    ],
    'art' => [
        'name'        => 'Art Access',
        'icon'        => 'palette',
        'description' => 'Digital art, fine art, photography',
        'prompt'      => '',
        'members'     => ['art'],
    ],
    'film' => [
        'name'        => 'Film Access',
        'icon'        => 'video',
        'description' => 'Feature films, shorts, documentaries',
        'prompt'      => '',
        'members'     => ['film'],
    ],
];


// Content type definitions - Music, Music Video, Art, Film, Album
$content_types = [
    'music' => [
        'name' => 'Music Access',
        'icon' => '<svg class="imc-ic" aria-hidden="true"><use href="#ic-music"></use></svg> ',
        'description' => 'Audio tracks - singles, albums, EPs, remixes. Mint an Access NFT for your music.',
        'media' => ['audio', 'image'],
        'schema' => 'music.v1',
        'nftType' => 'music.v1'
    ],
    'musicVideo' => [
        'name' => 'Music Video Access',
        'icon' => '<svg class="imc-ic" aria-hidden="true"><use href="#ic-clapper"></use></svg> ',
        'description' => 'Official videos, visualizers, live performances. Mint an Access NFT for your music video.',
        'media' => ['video', 'audio', 'image'],
        'schema' => 'musicvideo.v1',
        'nftType' => 'musicvideo.v1'
    ],
    'art' => [
        'name' => 'Art Access',
        'icon' => '<svg class="imc-ic" aria-hidden="true"><use href="#ic-palette"></use></svg> ',
        'description' => 'Digital art, fine art, photography. Mint an Access NFT for your artwork.',
        'media' => ['image'],
        'schema' => 'art.v1',
        'nftType' => 'art.v1'
    ],
    'film' => [
        'name' => 'Film Access',
        'icon' => '<svg class="imc-ic" aria-hidden="true"><use href="#ic-video"></use></svg> ',
        'description' => 'Feature films, shorts, documentaries, series episodes. Mint an Access NFT for your film.',
        'media' => ['video', 'image'],
        'schema' => 'film.v1',
        'nftType' => 'film.v1'
    ],
    'album' => [
        'name' => 'Album Access',
        'icon' => '<svg class="imc-ic" aria-hidden="true"><use href="#ic-disc"></use></svg> ',
        'description' => 'Multi-track EPs and albums. One NFT unlocks all tracks with sequential playback across the IMU ecosystem.',
        'media' => ['audio', 'image'],
        'schema' => 'album.v1',
        'nftType' => 'album.v1'
    ],
    'ebook' => [
        'name' => 'eBook Access',
        'icon' => '<svg class="imc-ic" aria-hidden="true"><use href="#ic-book"></use></svg> ',
        'description' => 'Books and comics to read. One NFT unlocks every page, with covers and book details.',
        'media' => ['image'],
        'schema' => 'ebook.v1',
        'nftType' => 'ebook.v1'
    ],
    'audiobook' => [
        'name' => 'AudioBook Access',
        'icon' => '<svg class="imc-ic" aria-hidden="true"><use href="#ic-book"></use></svg> ',
        'description' => 'Narrated books. One NFT unlocks the full audiobook, with covers and book details.',
        'media' => ['audio', 'image'],
        'schema' => 'audiobook.v1',
        'nftType' => 'audiobook.v1'
    ]
];

// ============================================================================
// DROPDOWN OPTIONS - Industry Standard Values
// ============================================================================

// Music genres
$genres_music = [
    'Electronic', 'Hip Hop', 'R&B', 'Pop', 'Rock', 'Jazz', 'Classical', 
    'Country', 'Reggae', 'Latin', 'Indie', 'Alternative', 'Metal', 'Punk', 
    'Folk', 'Soul', 'Funk', 'Blues', 'Ambient', 'Dance', 'House', 'Techno', 
    'Drum & Bass', 'Dubstep', 'Trap', 'Lo-Fi', 'Chillout', 'World', 
    'Gospel', 'Soundtrack', 'K-Pop', 'J-Pop', 'Afrobeats', 'Other'
];

// Work types (affects royalty rates)
$work_types = [
    'original' => 'Original - New composition',
    'cover' => 'Cover - New recording of existing work',
    'arrangement' => 'Arrangement - New arrangement of existing work',
    'remix' => 'Remix - Remix of existing recording',
    'medley' => 'Medley - Multiple works combined',
    'translation' => 'Translation - Lyrics translated to another language'
];

// Writer roles (PRO standard codes)
$writer_roles = [
    'CA' => 'CA - Composer & Author (wrote music and lyrics)',
    'C' => 'C - Composer (wrote music/melody only)',
    'A' => 'A - Author/Lyricist (wrote lyrics only)',
    'AR' => 'AR - Arranger',
    'SA' => 'SA - Sub-Arranger',
    'TR' => 'TR - Translator'
];

// Publisher types
$publisher_types = [
    'SE' => 'SE - Self-Published',
    'OP' => 'OP - Original Publisher',
    'SP' => 'SP - Sub-Publisher',
    'AM' => 'AM - Administrator'
];

// PRO Organizations (Performance Rights Organizations)
$pro_orgs = [
    '' => 'Select PRO...',
    'ASCAP' => 'ASCAP (USA)',
    'BMI' => 'BMI (USA)',
    'SESAC' => 'SESAC (USA)',
    'GMR' => 'GMR (USA)',
    'PRS' => 'PRS (UK)',
    'MCPS' => 'MCPS (UK)',
    'GEMA' => 'GEMA (Germany)',
    'SACEM' => 'SACEM (France)',
    'SOCAN' => 'SOCAN (Canada)',
    'APRA' => 'APRA (Australia)',
    'AMCOS' => 'AMCOS (Australia)',
    'JASRAC' => 'JASRAC (Japan)',
    'KOMCA' => 'KOMCA (South Korea)',
    'BUMA' => 'BUMA (Netherlands)',
    'STEMRA' => 'STEMRA (Netherlands)',
    'SGAE' => 'SGAE (Spain)',
    'SIAE' => 'SIAE (Italy)',
    'SUISA' => 'SUISA (Switzerland)',
    'STIM' => 'STIM (Sweden)',
    'KODA' => 'KODA (Denmark)',
    'TONO' => 'TONO (Norway)',
    'TEOSTO' => 'TEOSTO (Finland)',
    'SAMRO' => 'SAMRO (South Africa)',
    'CASH' => 'CASH (Hong Kong)',
    'COMPASS' => 'COMPASS (Singapore)',
    'Other' => 'Other'
];

// Languages (ISO 639-1)
$languages = [
    'en' => 'English',
    'es' => 'Spanish',
    'fr' => 'French',
    'de' => 'German',
    'it' => 'Italian',
    'pt' => 'Portuguese',
    'ja' => 'Japanese',
    'ko' => 'Korean',
    'zh' => 'Chinese',
    'ar' => 'Arabic',
    'hi' => 'Hindi',
    'ru' => 'Russian',
    'nl' => 'Dutch',
    'sv' => 'Swedish',
    'pl' => 'Polish',
    'tr' => 'Turkish',
    'th' => 'Thai',
    'vi' => 'Vietnamese',
    'id' => 'Indonesian',
    'tl' => 'Tagalog',
    'instrumental' => 'Instrumental (No Lyrics)',
    'other' => 'Other'
];

// Film genres
$genres_film = [
    'Action', 'Adventure', 'Animation', 'Biography', 'Comedy', 'Crime',
    'Documentary', 'Drama', 'Experimental', 'Family', 'Fantasy', 'Film Noir',
    'Historical', 'Horror', 'Musical', 'Mystery', 'Romance', 'Sci-Fi',
    'Short', 'Sport', 'Thriller', 'War', 'Western', 'Other'
];

// Film classifications
$film_classifications = [
    'feature' => 'Feature Film',
    'short' => 'Short Film',
    'documentary' => 'Documentary',
    'animation' => 'Animation',
    'series_episode' => 'Series Episode'
];

// Film audience ratings
$film_ratings = [
    'U' => 'U — Universal',
    'PG' => 'PG — Parental Guidance',
    '12A' => '12A — Cinema 12+',
    '12' => '12 — 12 and over',
    '15' => '15 — 15 and over',
    '18' => '18 — Adults only',
    'R' => 'R — Restricted (US)',
    'G' => 'G — General (US)',
    'PG-13' => 'PG-13 — Parental caution (US)',
    'NC-17' => 'NC-17 — Adults only (US)',
    'NR' => 'Not Rated',
    'TBC' => 'To Be Confirmed'
];

// Musical keys
$musical_keys = [
    '' => 'Select key...',
    'C Major' => 'C Major', 'C Minor' => 'C Minor',
    'C# Major' => 'C# Major', 'C# Minor' => 'C# Minor',
    'Db Major' => 'Db Major', 'Db Minor' => 'Db Minor',
    'D Major' => 'D Major', 'D Minor' => 'D Minor',
    'D# Major' => 'D# Major', 'D# Minor' => 'D# Minor',
    'Eb Major' => 'Eb Major', 'Eb Minor' => 'Eb Minor',
    'E Major' => 'E Major', 'E Minor' => 'E Minor',
    'F Major' => 'F Major', 'F Minor' => 'F Minor',
    'F# Major' => 'F# Major', 'F# Minor' => 'F# Minor',
    'Gb Major' => 'Gb Major', 'Gb Minor' => 'Gb Minor',
    'G Major' => 'G Major', 'G Minor' => 'G Minor',
    'G# Major' => 'G# Major', 'G# Minor' => 'G# Minor',
    'Ab Major' => 'Ab Major', 'Ab Minor' => 'Ab Minor',
    'A Major' => 'A Major', 'A Minor' => 'A Minor',
    'A# Major' => 'A# Major', 'A# Minor' => 'A# Minor',
    'Bb Major' => 'Bb Major', 'Bb Minor' => 'Bb Minor',
    'B Major' => 'B Major', 'B Minor' => 'B Minor'
];

// License types
$licenses = [
    'all_rights_reserved' => 'All Rights Reserved',
    'personal_use' => 'Personal Use Only',
    'creative_commons' => 'Creative Commons',
    'sync_ready' => 'Sync Ready (Licensing Available)',
    'exclusive' => 'Exclusive Rights'
];

// Music video types
$video_types = [
    'official_video' => 'Official Music Video',
    'lyric_video' => 'Lyric Video',
    'visualizer' => 'Visualizer / Audio Reactive',
    'live_performance' => 'Live Performance',
    'behind_the_scenes' => 'Behind The Scenes',
    'vertical_video' => 'Vertical Video (9:16)',
    'animated' => 'Animated Music Video'
];

// Sample clearance types
$clearance_types = [
    'licensed' => 'Licensed - Paid license obtained',
    'lease' => 'Lease - Leased beat/sample',
    'royalty_free' => 'Royalty Free - No clearance needed',
    'public_domain' => 'Public Domain - No copyright'
];

// AI created elements
// v43: Content-type-specific AI element options
$ai_elements_music = [
    'full_song' => 'Full Song',
    'lyrics' => 'Lyrics',
    'melody' => 'Melody',
    'vocal_performance' => 'Vocal Performance',
    'harmony' => 'Harmony/Chords',
    'rhythm' => 'Rhythm/Drums'
];
$ai_elements_art = [
    'full_artwork' => 'Full Artwork',
    'composition' => 'Composition/Layout',
    'color_palette' => 'Color Palette',
    'style_transfer' => 'Style/Aesthetic',
    'subject_matter' => 'Subject Matter',
    'background' => 'Background',
    'details' => 'Fine Details/Textures'
];
$ai_elements_film = [
    'full_film' => 'Full Film',
    'script' => 'Script/Screenplay',
    'visual_effects' => 'Visual Effects',
    'editing' => 'Editing',
    'soundtrack' => 'Soundtrack',
    'voiceover' => 'Voice/Narration'
];
// M1-e1c: AudioBook disclosure vocabulary (books, not tracks).
$ai_elements_audiobook = [
    'narration' => 'Narration Voice',
    'text' => 'Written Text',
    'cover_art' => 'Cover Art',
    'music_bed' => 'Music / Sound Bed',
    'editing' => 'Editing / Mastering'
];
// Default (backwards compatible)
$ai_elements = $ai_elements_music;

// Engineer roles
$engineer_roles = [
    'recording' => 'Recording Engineer',
    'mixing' => 'Mixing Engineer',
    'mastering' => 'Mastering Engineer'
];

// Common instruments
$instruments = [
    'Guitar', 'Bass', 'Drums', 'Piano', 'Keyboard', 'Synthesizer',
    'Violin', 'Cello', 'Trumpet', 'Saxophone', 'Flute', 'Percussion',
    'Vocals', 'Background Vocals', 'Strings', 'Brass', 'Woodwinds', 'Other'
];

$content_types_json = wp_json_encode($content_types);
?>

<div id="xrpl-mint-root" class="trading-hub-container mint-wizard-container"
     role="main"
     aria-label="NFT Minting Wizard"
     data-account="<?php echo esc_attr($xrpl_account); ?>"
     data-nonce="<?php echo esc_attr($nonce); ?>"
     data-endpoints='<?php echo $endpoints_json; ?>'
     data-content-types='<?php echo esc_attr($content_types_json); ?>'>

    <!-- Loading Overlay -->
    <div id="mint-loading-overlay" class="loading-overlay" style="display: none;" role="alert" aria-live="assertive" aria-label="Processing transaction">
        <div class="loading-spinner"></div>
        <span id="mint-loading-text">Processing...</span>
        <span id="mint-loading-warning" style="display:none; margin-top:12px; font-size:0.85rem; color:#d4af37; text-align:center; max-width:320px; line-height:1.4;">
            Please do not close or refresh this page — your files are being processed securely.
        </span>
    </div>

    <!-- Marketplace Navigation Header -->
    <nav class="marketplace-header-nav">
        <a href="<?php echo esc_url(home_url('/trading-hub/')); ?>" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
            Trading Hub
        </a>
        <a href="<?php echo esc_url(home_url('/collections/')); ?>" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
            Collections
        </a>
        <a href="<?php echo esc_url(home_url('/mint/')); ?>" class="nav-link active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>
            Mint NFT
        </a>
        <a href="<?php echo esc_url(home_url('/tasks/')); ?>" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            Tasks
        </a>
        <a href="<?php echo esc_url(home_url('/burn-to-earn/')); ?>" class="nav-link">🔥 Burn to Earn</a>
        <a href="<?php echo esc_url(home_url('/trading-hub-dashboard/')); ?>" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
            Dashboard
        </a>
        <a href="<?php echo esc_url(home_url('/my-nfts/')); ?>" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>
            My NFTs
        </a>
    </nav>

    <!-- Hero Section -->
    <svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true">
<defs>
<symbol id="ic-book" viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></symbol>
<symbol id="ic-warning" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></symbol>
<symbol id="ic-check-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M8 12.5l2.5 2.5L16 9.5"/></symbol>
<symbol id="ic-x-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></symbol>
<symbol id="ic-lock" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></symbol>
<symbol id="ic-shield" viewBox="0 0 24 24"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z"/></symbol>
<symbol id="ic-music" viewBox="0 0 24 24"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></symbol>
<symbol id="ic-clapper" viewBox="0 0 24 24"><rect x="2" y="8" width="20" height="13" rx="2"/><path d="M2 8l3-5.5 5 1-3 5M12 3.5l5 1-3 5"/></symbol>
<symbol id="ic-video" viewBox="0 0 24 24"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2"/></symbol>
<symbol id="ic-disc" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></symbol>
<symbol id="ic-image" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></symbol>
<symbol id="ic-palette" viewBox="0 0 24 24"><path d="M12 2a10 10 0 0 0 0 20c1.5 0 2.2-.9 2.2-2 0-1.2 1-2 2.2-2h1.8a3.8 3.8 0 0 0 3.8-3.8C22 7.6 17.5 2 12 2z"/><circle cx="7.5" cy="10.5" r="1"/><circle cx="12" cy="7.5" r="1"/><circle cx="16.5" cy="10.5" r="1"/></symbol>
<symbol id="ic-scroll" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/></symbol>
<symbol id="ic-bot" viewBox="0 0 24 24"><rect x="4" y="8" width="16" height="12" rx="2"/><path d="M12 8V4"/><circle cx="12" cy="3" r="1"/><circle cx="9" cy="13" r="1"/><circle cx="15" cy="13" r="1"/><path d="M9 17h6"/></symbol>
<symbol id="ic-coins" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v12"/><path d="M15.5 9.5c0-1.2-1.6-2-3.5-2s-3.5.8-3.5 2 1.2 1.7 3.5 2.2 3.5 1 3.5 2.2-1.6 2-3.5 2-3.5-.8-3.5-2"/></symbol>
<symbol id="ic-clipboard" viewBox="0 0 24 24"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></symbol>
<symbol id="ic-folder" viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></symbol>
<symbol id="ic-pencil" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></symbol>
<symbol id="ic-refresh" viewBox="0 0 24 24"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></symbol>
<symbol id="ic-bolt" viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></symbol>
<symbol id="ic-bulb" viewBox="0 0 24 24"><path d="M9 18h6M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.4 1 2.3h6c0-.9.4-1.8 1-2.3A7 7 0 0 0 12 2z"/></symbol>
<symbol id="ic-dice" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.2"/><circle cx="15.5" cy="15.5" r="1.2"/><circle cx="15.5" cy="8.5" r="1.2"/><circle cx="8.5" cy="15.5" r="1.2"/></symbol>
<symbol id="ic-calendar" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></symbol>
<symbol id="ic-sparkle" viewBox="0 0 24 24"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 3l.7 1.8L21.5 5.5l-1.8.7L19 8l-.7-1.8-1.8-.7 1.8-.7z"/></symbol>
<symbol id="ic-gift" viewBox="0 0 24 24"><rect x="3" y="8" width="18" height="4"/><path d="M12 8v13M5 12v9h14v-9"/><path d="M12 8c-2.5 0-4.5-1.2-4.5-2.8S9 2.6 10.4 3.2 12 8 12 8zm0 0c2.5 0 4.5-1.2 4.5-2.8S15 2.6 13.6 3.2 12 8 12 8z"/></symbol>
<symbol id="ic-drop" viewBox="0 0 24 24"><path d="M12 2.7S6 10 6 14a6 6 0 0 0 12 0c0-4-6-11.3-6-11.3z"/></symbol>
<symbol id="ic-box" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4a2 2 0 0 0 1-1.7z"/><path d="M3.3 7l8.7 5 8.7-5M12 22V12"/></symbol>
<symbol id="ic-gear" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M19.1 4.9L17 7M7 17l-2.1 2.1"/></symbol>
<symbol id="ic-link" viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></symbol>
<symbol id="ic-rocket" viewBox="0 0 24 24"><path d="M5 15c-1.5 1.3-2 5.5-2 5.5s4.2-.5 5.5-2c.8-.9.7-2.2-.1-3s-2.5-.4-3.4-.5z"/><path d="M13 15l-4-4c1-2.6 2.4-5 4.2-6.8A12.6 12.6 0 0 1 22 2c0 2.7-.9 7.6-6.2 8.8-1.8 1.8-.2 3.2-2.8 4.2z"/><path d="M9 11H4.5S5 8 6.5 7 11 7 11 7M13 15v4.5s3-.5 4-2 0-4.5 0-4.5"/></symbol>
<symbol id="ic-smile" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></symbol>
<symbol id="ic-diamond" viewBox="0 0 24 24"><path d="M6 3h12l4 6-10 12L2 9z"/><path d="M2 9h20M12 3L8 9l4 12 4-12-4-6"/></symbol>
<symbol id="ic-volume" viewBox="0 0 24 24"><path d="M11 5L6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M19 5a10 10 0 0 1 0 14"/></symbol>
<symbol id="ic-monitor" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></symbol>
<symbol id="ic-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20z"/></symbol>
<symbol id="ic-hash" viewBox="0 0 24 24"><path d="M4 9h16M4 15h16M10 3L8 21M16 3l-2 18"/></symbol>
<symbol id="ic-chart" viewBox="0 0 24 24"><path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="5" width="3" height="13"/></symbol>
<symbol id="ic-eye" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></symbol>
<symbol id="ic-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></symbol>
<symbol id="ic-help" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.83 1c0 2-3 2.1-3 3.9"/><line x1="12" y1="17.2" x2="12.01" y2="17.2"/></symbol>
</defs>
</svg>
<div id="mint-sticky-bar" class="mint-sticky-bar">
    <span class="msb-title" id="msb-title">Create Your NFT</span>
    <span class="msb-step" id="msb-step"></span>
    <span class="msb-spacer"></span>
    <span class="msb-chip" id="msb-auth-chip" style="display:none;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-check-circle"></use></svg> Authorized</span>
    <button type="button" class="msb-book" id="msb-setup" title="Setup guide" aria-label="Open setup guide" onclick="imcShowPrep()"><svg class="imc-ic" aria-hidden="true"><use href="#ic-book"></use></svg></button>
    <button type="button" class="msb-book" id="msb-book" data-step="0" title="Step help" aria-label="Open guide" onclick="imcShowGuide(parseInt(this.dataset.step||'0',10))"><svg class="imc-ic" aria-hidden="true"><use href="#ic-help"></use></svg></button>
</div>
<section class="mint-hero">
        <h1 id="mint-hero-title">Create Your NFT</h1>
        <p class="hero-subtitle" id="mint-hero-subtitle">Mint music, music videos, art, and films with full metadata support on the XRP Ledger. XLS-24d compliant NFTs for industry-standard royalty tracking and streaming access.</p>
    </section>

    <!-- Wallet Connection Check -->
    <div id="wallet-check" class="wallet-check-section" style="<?php echo $xrpl_account ? 'display:none;' : ''; ?>">
        <div class="wallet-required-card">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="2" y="6" width="20" height="12" rx="2"></rect>
                <path d="M22 10H2"></path>
                <path d="M6 14h.01"></path>
            </svg>
            <h3>Connect Your Wallet</h3>
            <p>You need to connect your XRPL wallet to mint NFTs.</p>
            <button id="connect-wallet-btn" class="btn-primary">Connect with XUMM</button>
        </div>
    </div>
    
    <!-- ================================================================ -->
    <!-- v409 PHASE 8: MINT PREP OVERLAY                                  -->
    <!-- Shows first-time guidance before auth check + wizard.            -->
    <!-- Normal document flow — no z-index, no position:fixed.            -->
    <!-- Auth check + draft check run in background below this.           -->
    <!-- ================================================================ -->
    <div id="imc-mint-prep" class="mint-prep-overlay"
         style="<?php echo !$xrpl_account ? 'display:none;' : ''; ?>">
        <div class="mint-prep-card">
            <button type="button" class="guide-close prep-close" onclick="imcHidePrep()" aria-label="Close setup guide">&times;</button>
            <div class="mint-prep-header">
                <h2><svg class="imc-ic" aria-hidden="true"><use href="#ic-clipboard"></use></svg> What You'll Need to Create Your NFT</h2>
                <p>Before you start, here's a quick overview of everything involved in creating your collection and minting NFTs on IMCollectibles.</p>
            </div>

            <div class="mint-prep-sections">

                <div class="prep-section">
                    <div class="prep-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-folder"></use></svg> </div>
                    <div class="prep-content">
                        <h3>Collection Setup</h3>
                        <ul>
                            <li>Collection Name <button type="button" class="imc-tip" tabindex="0" aria-label="Info">ℹ️<span class="imc-tip-text">Like an album name for music, a gallery for art, or a series for film. Buyers browse and search by collection name.</span></button></li>
                            <li>Cover Image <button type="button" class="imc-tip" tabindex="0" aria-label="Info">ℹ️<span class="imc-tip-text">The thumbnail shown when browsing collections. Square (1:1) works best. This is the collection image, not the individual NFT artwork.</span></button></li>
                            <li>Description</li>
                            <li>Taxon Number <button type="button" class="imc-tip" tabindex="0" aria-label="Info">ℹ️<span class="imc-tip-text">A unique number on the XRP Ledger that groups your NFTs together. Pick any number — just different from your other collections.</span></button></li>
                        </ul>
                    </div>
                </div>

                <div class="prep-section">
                    <div class="prep-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-music"></use></svg> </div>
                    <div class="prep-content">
                        <h3>Your Content</h3>
                        <ul>
                            <li>Content Type <button type="button" class="imc-tip" tabindex="0" aria-label="Info">ℹ️<span class="imc-tip-text">Music, Music Video, Art, or Film — each type has its own metadata fields and upload requirements.</span></button></li>
                            <li>NFT Title <button type="button" class="imc-tip" tabindex="0" aria-label="Info">ℹ️<span class="imc-tip-text">What buyers see on marketplaces. For music, try: "Song Title" Access Pass.</span></button></li>
                            <li>NFT Description</li>
                            <li>AI Disclosure (required)</li>
                        </ul>
                    </div>
                </div>

                <div class="prep-section">
                    <div class="prep-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-box"></use></svg> </div>
                    <div class="prep-content">
                        <h3>Media Files</h3>
                        <ul>
                            <li><strong><svg class="imc-ic" aria-hidden="true"><use href="#ic-music"></use></svg> Music:</strong> Master audio + preview clip (≤30s) + cover art</li>
                            <li><strong><svg class="imc-ic" aria-hidden="true"><use href="#ic-clapper"></use></svg> Music Video:</strong> Master video + audio track + preview + cover art</li>
                            <li><strong><svg class="imc-ic" aria-hidden="true"><use href="#ic-palette"></use></svg> Art:</strong> Master artwork (watermarked preview auto-generated)</li>
                            <li><strong><svg class="imc-ic" aria-hidden="true"><use href="#ic-video"></use></svg> Film:</strong> Master film + trailer/preview (≤120s) + poster</li>
                        </ul>
                    </div>
                </div>

                <div class="prep-section">
                    <div class="prep-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-coins"></use></svg> </div>
                    <div class="prep-content">
                        <h3>Pricing &amp; Editions</h3>
                        <ul>
                            <li>Number of editions <button type="button" class="imc-tip" tabindex="0" aria-label="Info">ℹ️<span class="imc-tip-text">Fixed (set supply cap) or Open Edition (unlimited for a time window). Cannot be increased after minting.</span></button></li>
                            <li>Price per edition (XRP or USD-pegged)</li>
                            <li>Additional payment tokens (optional)</li>
                        </ul>
                    </div>
                </div>

                <div class="prep-section">
                    <div class="prep-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-gear"></use></svg> </div>
                    <div class="prep-content">
                        <h3>Optional Extras</h3>
                        <ul>
                            <li>Custom display traits (genre, mood, rarity)</li>
                            <li>Rarity tiers with unique artwork per tier</li>
                            <li>Allowlist / early access wallets</li>
                            <li>Royalty % for secondary sales <button type="button" class="imc-tip" tabindex="0" aria-label="Info">ℹ️<span class="imc-tip-text">0-50% earned automatically on every resale. Enforced on-chain by the XRPL.</span></button></li>
                            <li>Release schedule (immediate or future date)</li>
                        </ul>
                    </div>
                </div>

                <div class="prep-section">
                    <div class="prep-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-pencil"></use></svg> </div>
                    <div class="prep-content">
                        <h3>If Your Content is Registered</h3>
                        <p class="prep-note">Music, Film &amp; Albums only — skip if not registered!</p>
                        <ul>
                            <li>P.R.O details (ASCAP, BMI, PRS, etc.)</li>
                            <li>ISRC, ISWC, UPC codes</li>
                            <li>Publisher &amp; writer credits with ownership splits</li>
                        </ul>
                    </div>
                </div>

                <div class="prep-section prep-section-highlight">
                    <div class="prep-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-shield"></use></svg> </div>
                    <div class="prep-content">
                        <h3>Authorized Minter Setup</h3>
                        <p>IMCollectibles uses <strong>Mint-on-Demand</strong> — we mint NFTs on your behalf when buyers purchase. You remain the <strong>Issuer</strong> and receive <strong>all royalties</strong> directly to your wallet.</p>
                        <div class="prep-warning">
                            <strong><svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> Important:</strong> Each XRPL wallet can only have <strong>one authorized minter</strong> at a time. If you use other XRPL marketplaces (XRP Cafe, OnXRP, xrp.cafe), we recommend using a <strong>separate wallet</strong> for IMCollectibles.
                        </div>
                        <p class="prep-reassure">This is a one-time setup you'll complete on the next screen. It's revocable anytime.</p>
                    </div>
                </div>

            </div>

            <div class="mint-prep-actions">
                <label class="mint-prep-remember">
                    <input type="checkbox" id="mint-prep-dont-show">
                    <span>Don't show this again</span>
                </label>
                <button type="button" class="btn-primary mint-prep-go" id="mint-prep-dismiss">
                    Start Creating
                </button>
            </div>
        </div>
    </div>

    <!-- ================================================================ -->
<!-- AUTHORIZED MINTER CHECK - Insert after wallet-check div -->
<!-- ================================================================ -->
<div id="auth-minter-section" class="auth-minter-section" 
     style="<?php echo !$xrpl_account ? 'display:none;' : ''; ?>"
     data-account="<?php echo esc_attr($xrpl_account); ?>"
     data-platform="<?php echo defined('IMC_PLATFORM_WALLET') ? esc_attr(IMC_PLATFORM_WALLET) : ''; ?>">
    
    <!-- Loading State -->
    <div id="auth-checking" class="auth-card auth-checking">
        <div class="auth-spinner"></div>
        <p>Checking authorization status...</p>
    </div>
    
    <!-- ✅ AUTHORIZED STATE -->
    <div id="auth-success" class="auth-card auth-success" style="display:none;">
        <div class="auth-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-check-circle"></use></svg> </div>
        <div class="auth-content">
            <h4>Authorized for Mint-on-Demand</h4>
            <p>IMCollectibles can mint NFTs on your behalf. <strong>You are the Issuer</strong> and receive all royalties automatically.</p>
        </div>
    </div>
    
    <!-- ⚠️ HAS ANOTHER MINTER (Unified Switch Flow) -->
    <div id="auth-conflict" class="auth-card auth-conflict" style="display:none;">
        <div class="auth-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> </div>
        <div class="auth-content">
            <h4 id="conflict-title">Another Marketplace Authorized</h4>
            <p id="conflict-message">Your wallet currently has another marketplace set as authorized minter:</p>
            <code id="other-minter-address">-</code>
            
            <div class="auth-warning-box" style="margin-top: 16px;">
                <p>You can switch to IMCollectibles, but please note:</p>
                <p style="margin-top: 8px;"><strong><svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> Switching will remove authorization from your current marketplace.</strong> If you have active listings or pending mints elsewhere, complete those first before switching.</p>
                <p style="margin-top: 8px;">Once you switch, only IMCollectibles can mint on your behalf. You can switch back at any time from the other marketplace.</p>
            </div>
            
            <div class="auth-switch-section">
                <p class="switch-warning" id="switch-warning"></p>
                <div style="display: flex; gap: 12px; margin-top: 12px; flex-wrap: wrap;">
                    <button type="button" class="btn-primary btn-authorize" id="btn-update-auth">
                        <svg class="imc-ic" aria-hidden="true"><use href="#ic-refresh"></use></svg> Switch to IMCollectibles
                    </button>
                    <a href="https://imcollectibles.io/authorized-minting/" target="_blank" class="btn-secondary" style="text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                        <svg class="imc-ic" aria-hidden="true"><use href="#ic-book"></use></svg> Learn More
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 🔐 NOT AUTHORIZED (Needs Setup) -->
    <div id="auth-required" class="auth-card auth-required" style="display:none;">
        <div class="auth-header">
            <span class="auth-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-shield"></use></svg> </span>
            <h4>Authorization Required</h4>
        </div>
        
        <div class="auth-explainer">
            <p>To enable <strong>Mint-on-Demand</strong>, you need to authorize IMCollectibles as your NFT minter. This is a <strong>one-time setup</strong>.</p>
            
            <div class="auth-benefits">
                <h5>How it works:</h5>
                <div class="benefit-item">
                    <span class="benefit-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-palette"></use></svg> </span>
                    <div>
                        <strong>You are the Issuer</strong>
                        <span>Your wallet is recorded as the NFT creator on-chain</span>
                    </div>
                </div>
                <div class="benefit-item">
                    <span class="benefit-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-coins"></use></svg> </span>
                    <div>
                        <strong>You get ALL royalties</strong>
                        <span>Transfer fees go directly to YOUR wallet forever</span>
                    </div>
                </div>
                <div class="benefit-item">
                    <span class="benefit-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-bolt"></use></svg> </span>
                    <div>
                        <strong>Mint-on-Demand</strong>
                        <span>NFTs only minted when purchased - no upfront reserve costs!</span>
                    </div>
                </div>
                <div class="benefit-item">
                    <span class="benefit-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-refresh"></use></svg> </span>
                    <div>
                        <strong>Revocable anytime</strong>
                        <span>You can remove this authorization whenever you want</span>
                    </div>
                </div>
            </div>
            
            <div class="auth-warning-box">
                <strong><svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> Important - Read Before Authorizing:</strong>
                <p>Each XRPL wallet can only have <strong>ONE authorized minter</strong> at a time.</p>
                <ul>
                    <li>If you already use <strong>XRP Cafe, OnXRP, xrp.cafe</strong>, or any other XRPL marketplace, you'll need to use a <strong>different wallet</strong> for IMCollectibles.</li>
                    <li>Or, revoke their authorization first (in their app), then authorize IMCollectibles.</li>
                </ul>
            </div>
        </div>
        
        <button type="button" class="btn-primary btn-authorize" id="btn-authorize">
            <svg class="imc-ic" aria-hidden="true"><use href="#ic-shield"></use></svg> Authorize IMCollectibles
        </button>
        
        <p class="auth-platform-info">
            Platform Wallet: <code>rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR</code>
        </p>
    </div>
</div>

<!-- XUMM Authorization Modal -->
<div id="auth-xumm-modal" class="modal auth-modal" style="display:none;">
    <div class="modal-content">
        <button class="modal-close" id="auth-modal-close">&times;</button>
        
        <div class="modal-header">
            <h3><svg class="imc-ic" aria-hidden="true"><use href="#ic-shield"></use></svg> Authorize IMCollectibles</h3>
        </div>
        
        <div class="modal-body">
            <p>Sign the <strong>AccountSet</strong> transaction in Xaman:</p>
            
            <ul class="auth-modal-benefits">
                <li><svg class="imc-ic" aria-hidden="true"><use href="#ic-check-circle"></use></svg> You remain the <strong>Issuer</strong> of all NFTs</li>
                <li><svg class="imc-ic" aria-hidden="true"><use href="#ic-check-circle"></use></svg> All <strong>royalties</strong> go directly to you</li>
                <li><svg class="imc-ic" aria-hidden="true"><use href="#ic-check-circle"></use></svg> You can <strong>revoke</strong> this anytime</li>
            </ul>
            
            <div class="xumm-qr-container">
                <img id="auth-qr-code" src="" alt="Scan with Xaman" />
            </div>
            
            <a id="auth-deep-link" href="#" target="_blank" class="btn-primary btn-block">
                Open in Xaman
            </a>
            
            <div id="auth-poll-status" class="auth-poll-status">
                <div class="spinner-small"></div>
                <span>Waiting for signature...</span>
            </div>
        </div>
    </div>
</div>

    <!-- Mint Wizard (only shown when wallet connected) -->
    <div id="mint-wizard" class="mint-wizard" role="form" aria-label="Create NFT" style="<?php echo !$xrpl_account ? 'display:none;' : ''; ?>">

        <!-- PP-6 P1 (fix 3): Draft Resume Banner — homed INSIDE the wizard, in normal
             flow BEFORE the progress steps, so it sits below the sticky sub-header bar.
             The reveal in mint.js also measures any fixed-bar overlap and pushes the
             banner down dynamically (per ruling). -->
        <div id="draft-resume-banner" class="draft-resume-banner" style="display:none;">
            <div class="draft-resume-content">
                <span class="draft-resume-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-pencil"></use></svg> </span>
                <span class="draft-info-text">You have a saved draft</span>
            </div>
            <div class="draft-resume-actions">
                <button type="button" class="btn-primary btn-small" onclick="window.resumeMintDraft()">Resume Draft</button>
                <button type="button" class="btn-ghost btn-small" onclick="window.discardMintDraft()">Discard</button>
            </div>
        </div>

        <!-- Progress Steps - 10 Total Steps (3-7 hidden when unlicensed) -->
        <div class="wizard-progress" id="wizard-progress" role="progressbar" aria-label="Minting progress" aria-valuemin="1" aria-valuemax="7" aria-valuenow="1">
            <div class="progress-step active" data-step="0">
                <span class="step-number">1</span>
                <span class="step-label">Type</span>
            </div>
            <div class="progress-line"></div>
            <div class="progress-step" data-step="1">
                <span class="step-number">2</span>
                <span class="step-label">Collection</span>
            </div>
            <div class="progress-line"></div>
            <div class="progress-step" data-step="2">
                <span class="step-number">3</span>
                <span class="step-label">Details</span>
            </div>
            <div class="progress-line licensed-step" style="display:none;"></div>
            <div class="progress-step licensed-step" data-step="3" style="display:none;">
                <span class="step-number">4</span>
                <span class="step-label" id="step-label-3">Industry Info</span>
            </div>
            <div class="progress-line"></div>
            <div class="progress-step" data-step="4">
                <span class="step-number">5</span>
                <span class="step-label">Media</span>
            </div>
            <div class="progress-line"></div>
            <div class="progress-step" data-step="5">
                <span class="step-number">6</span>
                <span class="step-label">Pricing</span>
            </div>
            <div class="progress-line"></div>
            <div class="progress-step" data-step="6">
                <span class="step-number">7</span>
                <span class="step-label">Review</span>
            </div>
        </div>

        <!-- ================================================================ -->
        <!-- STEP 0: Collection Setup (ALWAYS REQUIRED - even for singles) -->
        <!-- ================================================================ -->
        <div class="wizard-step active" data-step="0">
            <h2>What type of content? <button type="button" class="imc-guide-btn" onclick="imcShowGuide(0)"><svg class="imc-ic" aria-hidden="true"><use href="#ic-help"></use></svg> Guide</button></h2>
            <p class="step-description">Select the type of content you want to mint. Each type supports full metadata and streaming access on the IMU ecosystem.</p>
            
            <?php
            /* M3: Coming-Soon is evaluated per LEAF type and lifted to the group only when
               every member is gated - otherwise the Books group would disappear for wallets
               that cannot yet mint eBooks, hiding AudioBook, which is live. */
            $imc_cs_for = function ($type) use ($album_is_live, $xrpl_account) {
                if ($type === 'album' && !$album_is_live) { return true; }
                if (in_array($type, ['ebook', 'audiobook'], true)) {
                    $nt_live    = defined('IMC_NEWTYPES_LIVE') ? (array) IMC_NEWTYPES_LIVE : [];
                    $nt_preview = defined('IMC_NEWTYPE_PREVIEW_WALLETS') ? (array) IMC_NEWTYPE_PREVIEW_WALLETS : [];
                    return !in_array($type, $nt_live, true)
                           && !($xrpl_account !== '' && in_array($xrpl_account, $nt_preview, true));
                }
                return false;
            };
            $imc_type_icon = ['music'=>'music','musicVideo'=>'clapper','art'=>'palette','film'=>'video',
                              'album'=>'disc','audiobook'=>'book','ebook'=>'book'];
            ?>
            <div class="content-type-group-grid">
                <?php foreach ($content_groups as $gkey => $g):
                    $members_cs = array_map($imc_cs_for, $g['members']);
                    $group_cs   = !in_array(false, $members_cs, true);   // every member gated
                    $single     = (count($g['members']) === 1);
                    $g_classes  = 'content-group-card' . ($group_cs ? ' coming-soon' : '');
                    // A group card NEVER carries data-type: only leaf types may reach selectContentType().
                    $g_attrs    = $group_cs
                        ? 'data-coming-soon="true" aria-disabled="true" tabindex="-1"'
                        : 'data-group="' . esc_attr($gkey) . '"' . ($single ? ' data-single-type="' . esc_attr($g['members'][0]) . '"' : '');
                ?>
                <div class="<?php echo $g_classes; ?>" <?php echo $g_attrs; ?>>
                    <div class="type-icon"><svg class="imc-ic imc-ic--type" aria-hidden="true"><use href="#ic-<?php echo esc_attr($g['icon']); ?>"></use></svg></div>
                    <h3><?php echo wp_kses_post($g['name']); ?></h3>
                    <p><?php echo esc_html($g['description']); ?></p>
                    <?php if ($group_cs): ?><span class="coming-soon-badge">Coming Soon</span><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <?php foreach ($content_groups as $gkey => $g): if (count($g['members']) === 1) { continue; } ?>
            <div class="content-subtype-panel" id="ct-sub-<?php echo esc_attr($gkey); ?>" data-group="<?php echo esc_attr($gkey); ?>" style="display:none;">
                <h4 class="content-subtype-title"><?php echo esc_html($g['prompt']); ?></h4>
                <div class="content-type-grid content-subtype-grid">
                    <?php foreach ($g['members'] as $type):
                        $info         = $content_types[$type];
                        $is_cs        = $imc_cs_for($type);
                        $card_classes = 'content-type-card' . ($is_cs ? ' coming-soon' : '');
                        $cs_attrs     = $is_cs
                            ? 'data-coming-soon="true" aria-disabled="true" tabindex="-1"'
                            : 'data-type="' . esc_attr($type) . '"';
                    ?>
                    <div class="<?php echo $card_classes; ?>" <?php echo $cs_attrs; ?>>
                        <div class="type-icon"><svg class="imc-ic imc-ic--type" aria-hidden="true"><use href="#ic-<?php echo esc_attr($imc_type_icon[$type] ?? 'music'); ?>"></use></svg></div>
                        <h3><?php echo esc_html($info['name']); ?></h3>
                        <p><?php echo esc_html($info['description']); ?></p>
                        <?php if ($is_cs): ?><span class="coming-soon-badge">Coming Soon</span><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
            
            <!-- M1-e1b: AudioBook sub-type, revealed by mint.js when the card is chosen. -->
            <div id="audiobook-subtype-panel" class="ab-subtype-panel" style="display:none;">
                <h3 class="ab-subtype-title">What kind of AudioBook?</h3>
                <div class="ab-subtype-grid">
                    <label class="ab-subtype-card">
                        <input type="radio" name="audiobook_format" value="single" id="ab-format-single">
                        <span class="ab-subtype-name">Single file</span>
                        <span class="ab-subtype-desc">One continuous narration.</span>
                    </label>
                    <label class="ab-subtype-card">
                        <input type="radio" name="audiobook_format" value="chaptered" id="ab-format-chaptered">
                        <span class="ab-subtype-name">Chaptered</span>
                        <span class="ab-subtype-desc">Per-chapter files. Listeners can jump to any chapter.</span>
                    </label>
                </div>
            </div>
            
            <div class="step-navigation" style="margin-top: 20px;">
            </div>
        </div>
        <div class="wizard-step" data-step="1">
            <h2>Set Up Your Collection <button type="button" class="imc-guide-btn" onclick="imcShowGuide(1)"><svg class="imc-ic" aria-hidden="true"><use href="#ic-help"></use></svg> Guide</button></h2>
            <p class="step-description">Every NFT belongs to a collection for easy discovery. Create a new collection or add to an existing one. Even single releases get their own collection!</p>
            
            <div class="collection-setup-tabs">
                <button type="button" class="collection-tab active" data-tab="new">Create New Collection</button>
                <button type="button" class="collection-tab" data-tab="existing">Add to Existing</button>
            </div>
            
            <!-- New Collection Form -->
            <div class="collection-tab-content active" data-tab-content="new">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Collection Name <span class="required">*</span></label>
                        <input type="text" id="collection-name" placeholder="e.g., My Album 2026, Art Collection, Film Series" maxlength="100">
                        <small class="form-hint" id="collection-name-hint"><svg class="imc-ic" aria-hidden="true"><use href="#ic-bulb"></use></svg> Tip: Use a descriptive name like "Singles 2026", your artist name, or your project title</small>
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Collection Taxon <span class="required">*</span></label>
                        <div class="taxon-input-row">
                            <input type="number" id="collection-taxon" min="0" max="4294967295" step="1" placeholder="e.g., 1, 100, 1000">
                            <span id="taxon-status" class="taxon-status"></span>
                        </div>
                        <small class="form-hint"><svg class="imc-ic" aria-hidden="true"><use href="#ic-link"></use></svg> XRPL NFTokenTaxon — groups all NFTs in this collection on-chain. Choose any number from 0 to 4,294,967,295. Each collection must have a unique taxon. <a href="https://xrpl.org/docs/concepts/tokens/nfts/collections" target="_blank" rel="noopener">Learn more</a></small>
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Collection Description</label>
                        <textarea id="collection-description" rows="3" placeholder="Describe what this collection is about..."></textarea>
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Collection Cover Image</label>
                        <div class="collection-image-upload" id="collection-image-upload">
                            <div class="upload-dropzone small" id="collection-cover-dropzone">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="3" width="18" height="18" rx="2"/>
                                    <circle cx="8.5" cy="8.5" r="1.5"/>
                                    <path d="M21 15l-5-5L5 21"/>
                                </svg>
                                <span>Click to upload cover image</span>
                            </div>
                            <input type="file" id="collection-cover-input" accept="image/*" style="display:none;">
                        </div>
                        <div id="collection-cover-preview" class="upload-preview" style="display:none;"></div>
                    </div>
                </div>
                
                <!-- Collection Action Bar -->
                <div class="collection-action-bar">
                    <div id="collection-status" class="collection-status" style="display:none;"></div>
                    <button type="button" class="btn-primary btn-collection-continue" id="collection-continue-btn">
                        Create Collection & Continue →
                    </button>
                </div>
            </div>
            
            <!-- Existing Collection Selection -->
            <div class="collection-tab-content" data-tab-content="existing">
                <div id="existing-collections-list" class="existing-collections-grid">
                    <div class="loading-collections">
                        <div class="loading-spinner small"></div>
                        <span>Loading your collections...</span>
                    </div>
                </div>
                <div id="no-collections-msg" style="display:none;" class="no-collections-message">
                    <p>You don't have any collections yet.</p>
                    <button type="button" class="btn-secondary" onclick="document.querySelector('[data-tab=new]').click()">Create Your First Collection</button>
                </div>
                
                <!-- Existing Collection Action Bar -->
                <div class="collection-action-bar" id="existing-collection-action" style="display:none;">
                    <div id="existing-collection-status" class="collection-status info">
                        <span id="selected-collection-info"></span>
                    </div>
                    <button type="button" class="btn-primary btn-collection-continue" id="existing-continue-btn">
                        Continue with Collection →
                    </button>
                </div>
            </div>
        </div>
        <div class="wizard-step" data-step="2">
            <h2>NFT Information <button type="button" class="imc-guide-btn" onclick="imcShowGuide(2)"><svg class="imc-ic" aria-hidden="true"><use href="#ic-help"></use></svg> Guide</button></h2>
            <p class="step-description">Set the core details for your Access NFT. This is what buyers see on marketplaces.</p>
            
            <div class="form-grid">
                <!-- NFT Title -->
                <div class="form-group full-width">
                    <label for="nft-title">NFT Title <span class="required">*</span></label>
                    <input type="text" id="nft-title" name="title" placeholder="e.g. 'Song Title' Access Pass" required>
                    <span class="field-hint" id="nft-title-hint">This is the title shown on XRPL marketplaces. Suggestion: <em>"Your Song Title" Access Pass</em></span>
                </div>

                <!-- NFT Description -->
                <div class="form-group full-width">
                    <label for="nft-description">NFT Description <span class="required">*</span></label>
                    <textarea id="nft-description" name="description" rows="4" placeholder="e.g. This 'Song Title' Access NFT provides you exclusive access to the full quality music on IMUTV, IMUP3, and IMC! Grab a piece of history, and own a collectible with genuine exclusivity!" required></textarea>
                    <span class="field-hint" id="nft-description-hint">Describe what the buyer gets. This is displayed on marketplaces and in wallet apps.</span>
                </div>

                <!-- Content Flags -->
                <div class="form-group full-width content-flags-section">
                    <label class="content-flags-heading">Content Flags</label>
                    <div class="content-flags-grid">
                        
                        <!-- Streaming Access Granted (permanent, always on) -->
                        <div class="content-flag-card streaming-access-flag">
                            <div class="content-flag-check">
                                <input type="checkbox" id="nft-streaming-access" name="streaming_access" checked disabled>
                                <span class="flag-checkmark locked">✓</span>
                            </div>
                            <div class="content-flag-info">
                                <span class="content-flag-label">Streaming Access Granted</span>
                                <span class="content-flag-hint">This NFT grants the holder full streaming access to the attached media across the IMU ecosystem (IMUTV, IMUP3, IMCollectibles). This flag is permanently stamped in the NFT metadata.</span>
                            </div>
                            <span class="flag-badge flag-badge-locked"><svg class="imc-ic" aria-hidden="true"><use href="#ic-lock"></use></svg> Always On</span>
                        </div>
                        
                        <!-- Explicit Content (optional) -->
                        <div class="content-flag-card explicit-flag">
                            <div class="content-flag-check">
                                <input type="checkbox" id="nft-explicit" name="explicit">
                                <span class="flag-checkmark"></span>
                            </div>
                            <div class="content-flag-info">
                                <span class="content-flag-label">Contains Explicit Content</span>
                                <span class="content-flag-hint">Check this if your content contains explicit language, themes, or imagery. This flag is shown on marketplaces and used for content filtering.</span>
                            </div>
                        </div>

                    </div>
                    <!-- Hidden input to ensure streaming_access value is always submitted (disabled inputs don't submit) -->
                    <input type="hidden" name="streaming_access" value="on">
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- CUSTOM DISPLAY TRAITS (attributes[] on marketplaces)         -->
            <!-- ============================================================ -->
            <div class="credits-section custom-traits-section">
                <h3>Custom Display Traits</h3>
                <p class="section-hint">
                    These traits are shown on XRPL marketplaces like Bithomp and xrp.cafe. 
                    We've added a default "Access Pass" trait — feel free to edit or add more. Edition info is added automatically.
                </p>
                <p class="section-hint" style="color:#39d353;margin-top:4px;">
                    <svg class="imc-ic" aria-hidden="true"><use href="#ic-dice"></use></svg> <strong>Using Rarity Tiers?</strong> Each tier can have its own unique artwork traits in the Upload step. Traits added here apply to <em>all</em> editions.
                </p>

                <!-- Quick-start trait templates -->
                <div class="trait-templates">
                    <span class="trait-templates-label">Quick add:</span>
                    <button type="button" class="trait-template-btn" onclick="applyTraitTemplate('genre')"><svg class="imc-ic" aria-hidden="true"><use href="#ic-music"></use></svg> Genre</button>
                    <button type="button" class="trait-template-btn" onclick="applyTraitTemplate('mood')"><svg class="imc-ic" aria-hidden="true"><use href="#ic-smile"></use></svg> Mood</button>
                    <button type="button" class="trait-template-btn" onclick="applyTraitTemplate('rarity')"><svg class="imc-ic" aria-hidden="true"><use href="#ic-diamond"></use></svg> Rarity</button>
                    <button type="button" class="trait-template-btn" onclick="applyTraitTemplate('frequency')"><svg class="imc-ic" aria-hidden="true"><use href="#ic-volume"></use></svg> 432Hz</button>
                    <button type="button" class="trait-template-btn" onclick="applyTraitTemplate('collab')"><svg class="imc-ic" aria-hidden="true"><use href="#ic-gift"></use></svg> Featured Artist</button>
                    <button type="button" class="trait-template-btn" onclick="applyTraitTemplate('era')"><svg class="imc-ic" aria-hidden="true"><use href="#ic-calendar"></use></svg> Era</button>
                </div>

                <!-- Dynamic trait rows (first row pre-filled) -->
                <div class="dynamic-list" id="custom-traits-list">
                    <!-- Default "Access Pass" trait row added by JS on step init -->
                </div>

                <button type="button" class="btn-secondary btn-small" id="add-trait-btn">+ Add Trait</button>

                <!-- Trait preview summary -->
                <div class="trait-preview-summary" id="trait-preview-summary" style="display: none;">
                    <h4>Traits Preview</h4>
                    <div class="trait-tags" id="trait-tags"></div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- ART SUBTYPE SELECTOR (only visible for Art Access)           -->
            <!-- ============================================================ -->
            <div class="credits-section art-subtype-section content-field art" style="display:none;">
                <h3><svg class="imc-ic" aria-hidden="true"><use href="#ic-palette"></use></svg> Art Classification</h3>
                <p class="section-hint">
                    Help buyers understand what type of artwork this NFT represents.
                </p>
                <div class="art-subtype-selector">
                    <label class="art-subtype-option">
                        <input type="radio" name="art_subtype" value="digital_art" checked>
                        <span class="art-subtype-card">
                            <span class="art-subtype-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-monitor"></use></svg> </span>
                            <span class="art-subtype-text">Digital Art</span>
                            <span class="art-subtype-hint">Born-digital artwork — illustrations, 3D renders, generative art, graphic design, photography</span>
                        </span>
                    </label>
                    <label class="art-subtype-option">
                        <input type="radio" name="art_subtype" value="fine_art">
                        <span class="art-subtype-card">
                            <span class="art-subtype-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-image"></use></svg> </span>
                            <span class="art-subtype-text">Fine Art</span>
                            <span class="art-subtype-hint">Digitally scanned or traced physical artwork — paintings, drawings, prints, sculptures (photographed)</span>
                        </span>
                    </label>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- ART COPYRIGHT (optional, only visible for Art Access)        -->
            <!-- ============================================================ -->
            <div class="credits-section art-copyright-section content-field art" style="display:none;">
                <h3><svg class="imc-ic" aria-hidden="true"><use href="#ic-scroll"></use></svg> Copyright Information <span style="color:#888;font-size:0.8em;font-weight:400;">(Optional)</span></h3>
                <p class="section-hint">
                    If your artwork is copyrighted or registered, enter the details below. 
                    This information will be embedded in the NFT metadata for provenance.
                </p>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="art-copyright-holder">Copyright Holder</label>
                        <input type="text" id="art-copyright-holder" name="art_copyright_holder" placeholder="e.g. Jane Smith / Studio Name">
                    </div>
                    <div class="form-group">
                        <label for="art-copyright-year">Copyright Year</label>
                        <input type="number" id="art-copyright-year" name="art_copyright_year" min="1900" max="2099" placeholder="e.g. 2026">
                    </div>
                    <div class="form-group">
                        <label for="art-copyright-registration">Registration Number</label>
                        <input type="text" id="art-copyright-registration" name="art_copyright_registration" placeholder="e.g. VA 1-234-567">
                        <span class="field-hint">US Copyright Office, WIPO, or other registration body</span>
                    </div>
                    <div class="form-group">
                        <label for="art-license-type">License Type</label>
                        <select id="art-license-type" name="art_license_type">
                            <option value="">Select...</option>
                            <option value="all_rights_reserved">All Rights Reserved</option>
                            <option value="cc_by">Creative Commons — Attribution (CC BY)</option>
                            <option value="cc_by_sa">CC BY-SA (ShareAlike)</option>
                            <option value="cc_by_nc">CC BY-NC (NonCommercial)</option>
                            <option value="cc_by_nc_sa">CC BY-NC-SA</option>
                            <option value="cc_by_nd">CC BY-ND (NoDerivatives)</option>
                            <option value="cc_by_nc_nd">CC BY-NC-ND</option>
                            <option value="cc0">CC0 (Public Domain)</option>
                            <option value="custom">Custom License</option>
                        </select>
                    </div>
                    <div class="form-group full-width">
                        <label for="art-medium">Medium / Technique</label>
                        <input type="text" id="art-medium" name="art_medium" placeholder="e.g. Oil on canvas, Digital illustration (Procreate), 3D render (Blender)">
                        <span class="field-hint">Helps buyers understand the creation method</span>
                    </div>
                    <div class="form-group full-width">
                        <label for="art-dimensions">Dimensions</label>
                        <input type="text" id="art-dimensions" name="art_dimensions" placeholder="e.g. 4000×3000px / 24×18 inches">
                        <span class="field-hint">Digital resolution or physical dimensions of the original</span>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- AI DISCLOSURE (ALL CONTENT TYPES)                            -->
            <!-- ============================================================ -->
            <!-- M1-e1b: Book Details (AudioBook). Named inputs, so drafts persist them automatically. -->
            <!-- M2-b: eBook Details. Shown only for the eBook type. -->
            <div class="credits-section ebook-only-section" style="display:none;">
                <h3 class="credits-title">eBook Details</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="ebook-format">Book type <span class="required">*</span></label>
                        <select id="ebook-format" name="ebook_format">
                            <option value="">Select...</option>
                            <option value="novel">Novel</option>
                            <option value="short">Short story</option>
                            <option value="comic">Comic / graphic novel</option>
                            <option value="magazine">Magazine</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="ebook-author">Author <span class="required">*</span></label>
                        <input type="text" id="ebook-author" name="ebook_author" maxlength="200" placeholder="Who wrote it">
                    </div>
                    <div class="form-group">
                        <label for="ebook-illustrator">Illustrator</label>
                        <input type="text" id="ebook-illustrator" name="ebook_illustrator" maxlength="200" placeholder="Optional">
                    </div>
                    <div class="form-group">
                        <label for="ebook-publisher">Publisher</label>
                        <input type="text" id="ebook-publisher" name="ebook_publisher" maxlength="200" placeholder="Optional - self-published is fine">
                    </div>
                    <div class="form-group">
                        <label for="ebook-isbn">ISBN / ASIN</label>
                        <input type="text" id="ebook-isbn" name="ebook_isbn" maxlength="40" placeholder="Optional">
                    </div>
                    <div class="form-group">
                        <label for="ebook-language">Language</label>
                        <input type="text" id="ebook-language" name="ebook_language" maxlength="60" placeholder="e.g. English">
                    </div>
                </div>
            </div>
            <div class="credits-section audiobook-only-section" style="display:none;">
                <h3><svg class="imc-ic" aria-hidden="true"><use href="#ic-scroll"></use></svg> Book Details</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label for="ab-author">Author <span class="required">*</span></label>
                        <input type="text" id="ab-author" name="ab_author" maxlength="200" placeholder="Author name">
                    </div>
                    <div class="form-group">
                        <label for="ab-narrator">Narrator <span class="required">*</span></label>
                        <input type="text" id="ab-narrator" name="ab_narrator" maxlength="200" placeholder="Narrator name">
                    </div>
                    <div class="form-group">
                        <label for="ab-publisher">Publisher</label>
                        <input type="text" id="ab-publisher" name="ab_publisher" maxlength="200">
                    </div>
                    <div class="form-group">
                        <label for="ab-series">Series + number</label>
                        <input type="text" id="ab-series" name="ab_series" maxlength="200" placeholder="e.g. The Frequency Wars #2">
                    </div>
                    <div class="form-group">
                        <label for="ab-isbn">ISBN / ASIN</label>
                        <input type="text" id="ab-isbn" name="ab_isbn" maxlength="40">
                    </div>
                    <div class="form-group">
                        <label for="ab-language">Language</label>
                        <input type="text" id="ab-language" name="ab_language" maxlength="60" placeholder="English">
                    </div>
                    <div class="form-group">
                        <label for="ab-rights">Rights holder</label>
                        <input type="text" id="ab-rights" name="ab_rights_holder" maxlength="200">
                    </div>
                    <div class="form-group">
                        <label for="ab-release-date">Release date</label>
                        <input type="date" id="ab-release-date" name="ab_release_date">
                    </div>
                    <div class="form-group full-width">
                        <label class="checkbox-label"><input type="checkbox" id="ab-abridged" name="ab_abridged" value="1"> This edition is abridged</label>
                    </div>
                </div>
            </div>
            <div class="credits-section ai-section">
                <h3><svg class="imc-ic" aria-hidden="true"><use href="#ic-bot"></use></svg> AI Disclosure <span class="required">*</span></h3>
                <p class="section-hint">Transparency about AI usage is required for consumer protection.</p>
                
                <div class="form-group">
                    <label>Was AI used in creating this content? <span class="required">*</span></label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="ai_used" value="false" checked required>
                            <span>No - 100% human created</span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="ai_used" value="true">
                            <span>Yes - AI was used in creation</span>
                        </label>
                    </div>
                </div>
                
                <div id="ai-details" style="display: none;">
                    <div class="form-group">
                        <label>AI Platform Used</label>
                        <input type="text" name="ai_platform" placeholder="e.g., Suno, Udio, AIVA, Midjourney, Runway, etc.">
                    </div>

                    <div class="form-group">
                        <label>AI Creation Date</label>
                        <input type="date" name="ai_creation_date">
                        <span class="field-hint">When was the AI-generated content created?</span>
                    </div>
                    
                    <div class="form-group">
                        <label>AI-Created Elements</label>
                        <div class="checkbox-grid" id="ai-created-grid">
                            <?php foreach ($ai_elements as $value => $label): ?>
                            <label class="checkbox-label">
                                <input type="checkbox" name="ai_elements[]" value="<?php echo esc_attr($value); ?>">
                                <span><?php echo esc_html($label); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Human-Created Elements</label>
                        <div class="checkbox-grid" id="human-created-grid">
                            <?php foreach ($ai_elements as $value => $label): if ($value !== 'full_song'): ?>
                            <label class="checkbox-label">
                                <input type="checkbox" name="human_elements[]" value="<?php echo esc_attr($value); ?>" checked>
                                <span><?php echo esc_html($label); ?></span>
                            </label>
                            <?php endif; endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Video AI Disclosure (Music Video Only) -->
            <div class="credits-section ai-section content-field musicVideo" style="display: none;">
                <h3><svg class="imc-ic" aria-hidden="true"><use href="#ic-clapper"></use></svg> Video AI Disclosure <span class="required">*</span></h3>
                <p class="section-hint">Separate disclosure for AI usage in video production.</p>
                
                <div class="form-group">
                    <label>Was AI used in creating the video? <span class="required">*</span></label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="video_ai_used" value="false" checked>
                            <span>No - 100% human filmed/created</span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="video_ai_used" value="true">
                            <span>Yes - AI was used in video creation</span>
                        </label>
                    </div>
                </div>
                
                <div id="video-ai-details" style="display: none;">
                    <div class="form-group">
                        <label>AI Video Platform Used</label>
                        <input type="text" name="video_ai_platform" placeholder="e.g., Runway, Pika, Sora, etc.">
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- LICENSED / COPYRIGHTED / REGISTERED TOGGLE (MUSIC ONLY)      -->
            <!-- ============================================================ -->
            <div class="credits-section licensed-toggle-section music-only-section">
                <h3><svg class="imc-ic" aria-hidden="true"><use href="#ic-music"></use></svg> Music Registration Status</h3>
                <p class="section-hint">
                    If your music is registered with a P.R.O (e.g. ASCAP, BMI, PRS), licensed, or copyrighted, 
                    select <strong>Yes</strong> to unlock full metadata fields (track info, credits, publishing, rights, IDs) — 
                    just like any other DSP requires.
                </p>

                <div class="licensed-toggle-wrapper">
                    <label class="licensed-toggle-question">Is your music published, licensed, or copyrighted/registered?</label>
                    <div class="licensed-toggle-options">
                        <label class="licensed-option">
                            <input type="radio" name="music_licensed" value="no" checked>
                            <span class="licensed-card" data-licensed="no">
                                <span class="licensed-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-x-circle"></use></svg> </span>
                                <span class="licensed-text">No</span>
                                <span class="licensed-hint">Skip metadata — go straight to upload & mint</span>
                            </span>
                        </label>
                        <label class="licensed-option">
                            <input type="radio" name="music_licensed" value="yes">
                            <span class="licensed-card" data-licensed="yes">
                                <span class="licensed-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-check-circle"></use></svg> </span>
                                <span class="licensed-text">Yes</span>
                                <span class="licensed-hint">Fill in track info, credits, publishing, rights & IDs</span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- LICENSED / REGISTERED TOGGLE (FILM ONLY)                     -->
            <!-- ============================================================ -->
            <div class="credits-section licensed-toggle-section film-only-section" style="display:none;">
                <h3><svg class="imc-ic" aria-hidden="true"><use href="#ic-video"></use></svg> Film Registration Status</h3>
                <p class="section-hint">
                    If your film is registered, licensed, or has formal distribution rights, 
                    select <strong>Yes</strong> to unlock full film metadata fields (descriptive info, credits, rights & identifiers).
                </p>

                <div class="licensed-toggle-wrapper">
                    <label class="licensed-toggle-question">Is this film registered, licensed, or copyrighted?</label>
                    <div class="licensed-toggle-options">
                        <label class="licensed-option">
                            <input type="radio" name="film_licensed" value="no" checked>
                            <span class="licensed-card" data-licensed="no">
                                <span class="licensed-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-x-circle"></use></svg> </span>
                                <span class="licensed-text">No</span>
                                <span class="licensed-hint">Skip metadata — go straight to upload & mint</span>
                            </span>
                        </label>
                        <label class="licensed-option">
                            <input type="radio" name="film_licensed" value="yes">
                            <span class="licensed-card" data-licensed="yes">
                                <span class="licensed-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-check-circle"></use></svg> </span>
                                <span class="licensed-text">Yes</span>
                                <span class="licensed-hint">Fill in film details, credits & rights metadata</span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
        <div class="wizard-step licensed-step" data-step="3">
            <div class="imc-tabs" id="industry-tabs">
            <span class="imc-tabs-guide"><button type="button" class="imc-guide-btn" onclick="imcShowGuide(3)"><svg class="imc-ic" aria-hidden="true"><use href="#ic-help"></use></svg> Guide</button></span>
                <button type="button" class="imc-tab" data-tab="0">Track Info</button>
                <button type="button" class="imc-tab" data-tab="1">Credits</button>
                <button type="button" class="imc-tab" data-tab="2">Publishing</button>
                <button type="button" class="imc-tab" data-tab="3">Rights</button>
                <button type="button" class="imc-tab" data-tab="4">Identifiers</button>
            </div>
            <div class="imc-tabpane" data-tab="0">
            <h2 id="step3-heading">Track Information</h2>
            <p class="step-description" id="step3-description">Enter detailed track metadata. This information is used for P.R.O reporting and DSP integration.</p>
            
            <!-- ═══════ MUSIC: Track Information ═══════ -->
            <div class="music-step-content" id="step3-music">
            <div class="form-grid">
                <!-- Work Type (Required for PRO) -->
                <div class="form-group">
                    <label for="nft-work-type">Work Type <span class="required">*</span></label>
                    <select id="nft-work-type" name="work_type" required>
                        <option value="">Select work type...</option>
                        <?php foreach ($work_types as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field-hint">Affects royalty rates - covers have different rates than originals</span>
                </div>

                <!-- Genre -->
                <div class="form-group">
                    <label for="nft-genre">Genre <span class="required">*</span></label>
                    <select id="nft-genre" name="genre" required>
                        <option value="">Select genre...</option>
                        <?php foreach ($genres_music as $g): ?>
                        <option value="<?php echo esc_attr($g); ?>"><?php echo esc_html($g); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Subgenre -->
                <div class="form-group">
                    <label for="nft-subgenre">Subgenre</label>
                    <input type="text" id="nft-subgenre" name="subgenre" placeholder="e.g., Melodic Techno, Trap Soul">
                </div>

                <!-- Language -->
                <div class="form-group">
                    <label for="nft-language">Language</label>
                    <select id="nft-language" name="language">
                        <option value="">Select language...</option>
                        <?php foreach ($languages as $code => $name): ?>
                        <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- BPM -->
                <div class="form-group">
                    <label for="nft-bpm">BPM</label>
                    <input type="number" id="nft-bpm" name="bpm" min="1" max="300" placeholder="e.g., 128">
                </div>

                <!-- Musical Key -->
                <div class="form-group">
                    <label for="nft-key">Musical Key</label>
                    <select id="nft-key" name="key">
                        <?php foreach ($musical_keys as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Duration (auto-detected or manual) -->
                <div class="form-group">
                    <label for="nft-duration">Duration (seconds)</label>
                    <input type="number" id="nft-duration" name="duration" min="1" placeholder="Auto-detected from uploaded file">
                    <span class="field-hint">Auto-filled from uploaded file if available</span>
                </div>

                <!-- Alternative Titles -->
                <div class="form-group full-width">
                    <label for="nft-alt-titles">Alternative Titles</label>
                    <input type="text" id="nft-alt-titles" name="alternative_titles" placeholder="Radio Edit, Club Mix, Acoustic Version (comma-separated)">
                    <span class="field-hint">Alternative titles help PROs match your work</span>
                </div>

                <!-- Lyrics -->
                <div class="form-group full-width">
                    <label for="nft-lyrics">Lyrics</label>
                    <textarea id="nft-lyrics" name="lyrics" rows="6" placeholder="Enter full lyrics (optional)..."></textarea>
                </div>

                <!-- Creation Date -->
                <div class="form-group">
                    <label for="nft-creation-date">Track Creation Date</label>
                    <input type="date" id="nft-creation-date" name="creation_date">
                </div>

                <!-- Music Video Type (only for music videos) -->
                <div class="form-group content-field musicVideo" style="display: none;">
                    <label for="nft-video-type">Video Type <span class="required">*</span></label>
                    <select id="nft-video-type" name="video_type">
                        <option value="">Select video type...</option>
                        <?php foreach ($video_types as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Video Resolution (auto-detected for music videos) -->
                <div class="form-group content-field musicVideo" style="display: none;">
                    <label for="nft-resolution">Video Resolution</label>
                    <input type="text" id="nft-resolution" name="resolution" placeholder="e.g., 1920x1080 (auto-detected)">
                </div>

                <!-- Frame Rate -->
                <div class="form-group content-field musicVideo" style="display: none;">
                    <label for="nft-framerate">Frame Rate</label>
                    <input type="number" id="nft-framerate" name="framerate" min="1" max="120" placeholder="e.g., 24, 30, 60">
                </div>
            </div>
            </div><!-- /music-step-content -->

            <!-- ═══════ FILM: Descriptive Metadata (Public-Facing) ═══════ -->
            <div class="film-step-content" id="step3-film" style="display:none;">
            <div class="form-grid">
                <!-- Official Title -->
                <div class="form-group full-width">
                    <label for="film-title">Official Title <span class="required">*</span></label>
                    <input type="text" id="film-title" name="film_title" placeholder="e.g., The Great Journey" maxlength="200">
                </div>
                <!-- Original Language Title -->
                <div class="form-group full-width">
                    <label for="film-original-title">Original Language Title <span style="color:#888;font-size:0.8em;">(if different)</span></label>
                    <input type="text" id="film-original-title" name="film_original_title" placeholder="e.g., El Gran Viaje" maxlength="200">
                </div>
                <!-- Logline -->
                <div class="form-group full-width">
                    <label for="film-logline">Logline <span style="color:#888;font-size:0.8em;">(1 sentence)</span></label>
                    <input type="text" id="film-logline" name="film_logline" placeholder="A one-sentence pitch for your film" maxlength="300">
                </div>
                <!-- Short Synopsis -->
                <div class="form-group full-width">
                    <label for="film-short-synopsis">Short Synopsis <span style="color:#888;font-size:0.8em;">(≈50 words)</span></label>
                    <textarea id="film-short-synopsis" name="film_short_synopsis" rows="2" placeholder="Brief synopsis for listings and catalogue displays..." maxlength="500"></textarea>
                </div>
                <!-- Long Synopsis -->
                <div class="form-group full-width">
                    <label for="film-long-synopsis">Long Synopsis <span style="color:#888;font-size:0.8em;">(≈200 words)</span></label>
                    <textarea id="film-long-synopsis" name="film_long_synopsis" rows="5" placeholder="Full synopsis for press and distribution..." maxlength="2000"></textarea>
                </div>
                <!-- Classification -->
                <div class="form-group">
                    <label for="film-classification">Film Classification <span class="required">*</span></label>
                    <select id="film-classification" name="film_classification" required>
                        <option value="">Select...</option>
                        <?php foreach ($film_classifications as $val => $label): ?>
                        <option value="<?php echo esc_attr($val); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Primary Genre -->
                <div class="form-group">
                    <label for="film-genre">Primary Genre <span class="required">*</span></label>
                    <select id="film-genre" name="film_genre">
                        <option value="">Select genre...</option>
                        <?php foreach ($genres_film as $g): ?>
                        <option value="<?php echo esc_attr($g); ?>"><?php echo esc_html($g); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Secondary Genres -->
                <div class="form-group">
                    <label for="film-subgenres">Secondary Genres / Sub-genres</label>
                    <input type="text" id="film-subgenres" name="film_subgenres" placeholder="e.g., Coming-of-Age, Road Movie">
                </div>
                <!-- Target Audience Rating -->
                <div class="form-group">
                    <label for="film-rating">Target Audience Rating <span class="required">*</span></label>
                    <select id="film-rating" name="film_rating">
                        <option value="">Select rating...</option>
                        <?php foreach ($film_ratings as $val => $label): ?>
                        <option value="<?php echo esc_attr($val); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Language -->
                <div class="form-group">
                    <label for="film-language">Primary Language</label>
                    <select id="film-language" name="film_language">
                        <option value="">Select language...</option>
                        <?php foreach ($languages as $code => $name): ?>
                        <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Runtime -->
                <div class="form-group">
                    <label for="film-runtime">Runtime (minutes)</label>
                    <input type="number" id="film-runtime" name="film_runtime" min="1" max="600" placeholder="e.g., 92">
                </div>
            </div>

            <!-- Film Credits -->
            <div class="credits-section" style="margin-top:20px;">
                <h3><svg class="imc-ic" aria-hidden="true"><use href="#ic-clapper"></use></svg> Key Credits</h3>
                
                <!-- Directors -->
                <div style="margin-bottom:12px;">
                    <label style="display:block;font-size:0.85rem;color:#ccc;margin-bottom:6px;">Directors <span class="required">*</span></label>
                    <div class="dynamic-list" id="film-directors-list">
                        <div class="credit-row film-credit-row" data-index="0">
                            <div class="form-group" style="flex:1;">
                                <input type="text" name="film_director_0" placeholder="Director name">
                            </div>
                            <div class="credit-actions">
                                <span class="primary-badge">Primary</span>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn-secondary btn-small" id="add-film-director-btn">+ Add Director</button>
                </div>
                
                <!-- Producers -->
                <div style="margin-bottom:12px;">
                    <label style="display:block;font-size:0.85rem;color:#ccc;margin-bottom:6px;">Producers <span class="required">*</span></label>
                    <div class="dynamic-list" id="film-producers-list">
                        <div class="credit-row film-credit-row" data-index="0">
                            <div class="form-group" style="flex:1;">
                                <input type="text" name="film_producer_0" placeholder="Producer name">
                            </div>
                            <div class="credit-actions">
                                <span class="primary-badge">Primary</span>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn-secondary btn-small" id="add-film-producer-btn">+ Add Producer</button>
                </div>
                
                <!-- Writers -->
                <div style="margin-bottom:12px;">
                    <label style="display:block;font-size:0.85rem;color:#ccc;margin-bottom:6px;">Writers <span class="required">*</span></label>
                    <div class="dynamic-list" id="film-writers-list">
                        <div class="credit-row film-credit-row" data-index="0">
                            <div class="form-group" style="flex:1;">
                                <input type="text" name="film_writer_0" placeholder="Writer name">
                            </div>
                            <div class="credit-actions">
                                <span class="primary-badge">Primary</span>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn-secondary btn-small" id="add-film-writer-btn">+ Add Writer</button>
                </div>
                
                <!-- Lead Cast -->
                <div style="margin-bottom:12px;">
                    <label style="display:block;font-size:0.85rem;color:#ccc;margin-bottom:6px;">Lead Cast <span class="required">*</span></label>
                    <div class="dynamic-list" id="film-cast-list">
                        <div class="credit-row film-credit-row" data-index="0">
                            <div class="form-group" style="flex:1;">
                                <input type="text" name="film_cast_0" placeholder="Actor/actress name">
                            </div>
                            <div class="credit-actions">
                                <span class="primary-badge">Primary</span>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn-secondary btn-small" id="add-film-cast-btn">+ Add Cast Member</button>
                </div>
            </div>
            </div><!-- /film-step-content -->
            </div>
            <div class="imc-tabpane" data-tab="1">
            <h2 id="step4-heading">Artists & Credits</h2>
            <p class="step-description" id="step4-description">Add all contributors. Writer information is critical for PRO royalty reporting.</p>
            
            <!-- ═══════ MUSIC: Artists & Credits ═══════ -->
            <div class="music-step-content" id="step4-music">
            <!-- Primary Artists Section -->
            <div class="credits-section">
                <h3>Primary Artists <span class="required">*</span></h3>
                <p class="section-hint">Main performing artist(s) - at least one required</p>
                
                <div class="dynamic-list" id="primary-artists-list">
                    <div class="credit-row artist-row" data-index="0">
                        <div class="form-group">
                            <label>Artist Name <span class="required">*</span></label>
                            <input type="text" name="primary_artist_name_0" placeholder="Artist/Band name" required>
                        </div>
                        <div class="credit-actions">
                            <span class="primary-badge">Primary</span>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-secondary btn-small" id="add-primary-artist-btn">+ Add Primary Artist</button>
            </div>

            <!-- Featured Artists Section -->
            <div class="credits-section">
                <h3>Featured Artists</h3>
                <p class="section-hint">Artists featured on the track (optional)</p>
                
                <div class="dynamic-list" id="featured-artists-list">
                    <!-- Dynamically added -->
                </div>
                <button type="button" class="btn-secondary btn-small" id="add-featured-artist-btn">+ Add Featured Artist</button>
            </div>

            <!-- Writers Section (CRITICAL FOR PRO) -->
            <div class="credits-section writers-section">
                <h3>Songwriters & Composers <span class="required">*</span></h3>
                <p class="section-hint">At least one writer required. This information is submitted to P.R.O.s for royalty calculation.</p>
                
                <div class="pro-info-box">
                    <strong><svg class="imc-ic" aria-hidden="true"><use href="#ic-clipboard"></use></svg> P.R.O Reporting Note:</strong> Writer roles and ownership shares determine how royalties are split. 
                    Ownership shares should total 100%. IPI numbers and P.R.O affiliations enable accurate royalty routing.
                </div>
                
                <div class="dynamic-list" id="writers-list">
                    <div class="writer-row" data-index="0">
                        <div class="form-grid writer-grid">
                            <div class="form-group">
                                <label>Writer Name <span class="required">*</span></label>
                                <input type="text" name="writer_name_0" placeholder="Legal name" required>
                            </div>
                            <div class="form-group">
                                <label>Role <span class="required">*</span></label>
                                <select name="writer_role_0" required>
                                    <?php foreach ($writer_roles as $code => $label): ?>
                                    <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Ownership % <span class="required">*</span></label>
                                <input type="number" name="writer_ownership_0" min="0" max="100" step="0.01" placeholder="e.g., 50" required>
                            </div>
                            <div class="form-group">
                                <label>IPI/CAE Number</label>
                                <input type="text" name="writer_ipi_0" placeholder="9-11 digits">
                            </div>
                            <div class="form-group">
                                <label>P.R.O Affiliation</label>
                                <select name="writer_pro_0">
                                    <?php foreach ($pro_orgs as $code => $label): ?>
                                    <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <button type="button" class="btn-remove-row" onclick="removeWriterRow(this)">✕</button>
                    </div>
                </div>
                <div class="ownership-total">
                    <span>Total Ownership:</span>
                    <span id="writer-ownership-total">0%</span>
                    <span class="ownership-warning" id="ownership-warning" style="display: none;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> Should equal 100%</span>
                </div>
                <button type="button" class="btn-secondary btn-small" id="add-writer-btn">+ Add Writer</button>
            </div>

            <!-- Producers Section -->
            <div class="credits-section">
                <h3>Producers</h3>
                <p class="section-hint">Record producers (optional)</p>
                
                <div class="dynamic-list" id="producers-list">
                    <!-- Dynamically added -->
                </div>
                <button type="button" class="btn-secondary btn-small" id="add-producer-btn">+ Add Producer</button>
            </div>

            <!-- Engineers Section -->
            <div class="credits-section">
                <h3>Engineers</h3>
                <p class="section-hint">Recording, mixing, and mastering engineers (optional)</p>
                
                <div class="dynamic-list" id="engineers-list">
                    <!-- Dynamically added -->
                </div>
                <button type="button" class="btn-secondary btn-small" id="add-engineer-btn">+ Add Engineer</button>
            </div>

            <!-- Musicians Section -->
            <div class="credits-section">
                <h3>Session Musicians</h3>
                <p class="section-hint">Additional musicians who played on the track (optional)</p>
                
                <div class="dynamic-list" id="musicians-list">
                    <!-- Dynamically added -->
                </div>
                <button type="button" class="btn-secondary btn-small" id="add-musician-btn">+ Add Musician</button>
            </div>

            <!-- Video Credits (Music Video Only) -->
            <div class="credits-section content-field musicVideo" style="display: none;">
                <h3>Video Credits</h3>
                <p class="section-hint">Director, cinematographer, editor, etc.</p>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Director</label>
                        <input type="text" name="video_director" placeholder="Director name">
                    </div>
                    <div class="form-group">
                        <label>Cinematographer</label>
                        <input type="text" name="video_cinematographer" placeholder="DP name">
                    </div>
                    <div class="form-group">
                        <label>Editor</label>
                        <input type="text" name="video_editor" placeholder="Editor name">
                    </div>
                    <div class="form-group">
                        <label>Colorist</label>
                        <input type="text" name="video_colorist" placeholder="Colorist name">
                    </div>
                    <div class="form-group">
                        <label>Choreographer</label>
                        <input type="text" name="video_choreographer" placeholder="Choreographer name">
                    </div>
                </div>
                
                <!-- Video Cast -->
                <h4>Cast / Featured Talent</h4>
                <div class="dynamic-list" id="video-cast-list">
                    <!-- Dynamically added -->
                </div>
                <button type="button" class="btn-secondary btn-small" id="add-cast-btn">+ Add Cast Member</button>
                
                <!-- Production Info -->
                <h4>Production Details</h4>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Production Company</label>
                        <input type="text" name="production_company" placeholder="Production company name">
                    </div>
                    <div class="form-group">
                        <label>Filming Location</label>
                        <input type="text" name="filming_location" placeholder="City, State">
                    </div>
                    <div class="form-group">
                        <label>Filming Country</label>
                        <input type="text" name="filming_country" placeholder="e.g., US, GB, CA" maxlength="2">
                        <span class="field-hint">ISO 3166-1 alpha-2 code</span>
                    </div>
                </div>
            </div>
            </div><!-- /music-step-content step4 -->

            <!-- ═══════ FILM: Administrative & Rights Metadata ═══════ -->
            <div class="film-step-content" id="step4-film" style="display:none;">
                <!-- v147: Copyright Ownership first, full width -->
                <div class="credits-section">
                    <h4>Copyright Ownership <span class="required">*</span></h4>
                    <p class="section-hint">Add all copyright holders. Ownership must total 100%.</p>
                    <div class="dynamic-list" id="film-copyright-owners-list">
                        <div class="film-copyright-row" data-index="0">
                            <div class="form-grid" style="grid-template-columns:1fr 120px 120px auto;gap:8px;">
                                <div class="form-group">
                                    <label>Copyright Holder <span class="required">*</span></label>
                                    <input type="text" name="film_copyright_holder_0" placeholder="e.g., Studio Name Ltd" required>
                                </div>
                                <div class="form-group">
                                    <label>Ownership %</label>
                                    <input type="number" name="film_copyright_ownership_0" min="0" max="100" step="0.01" value="100" class="film-copyright-pct" required>
                                </div>
                                <div class="form-group">
                                    <label>Year</label>
                                    <input type="number" name="film_copyright_year_0" min="1900" max="2099" value="<?php echo date('Y'); ?>">
                                </div>
                                <div class="credit-actions" style="padding-top:22px;">
                                    <span class="primary-badge">Primary</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="ownership-total">
                        <span>Total Ownership:</span>
                        <span id="film-copyright-ownership-total">100.00%</span>
                        <span class="ownership-warning" id="film-copyright-warning" style="display: none;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> Must equal 100%</span>
                    </div>
                    <button type="button" class="btn-secondary btn-small" id="add-film-copyright-owner-btn">+ Add Owner</button>
                </div>
                
                <!-- Production Company (below copyright) -->
                <div class="form-group" style="margin-top:16px;">
                    <label for="film-production-company">Production Company <span class="required">*</span></label>
                    <input type="text" id="film-production-company" name="film_production_company" placeholder="e.g., Studio Name Ltd">
                </div>

            <div class="form-grid">
                <!-- ISAN -->
                <div class="form-group">
                    <label for="film-isan">ISAN <span style="color:#888;font-size:0.8em;">(optional)</span></label>
                    <input type="text" id="film-isan" name="film_isan" placeholder="International Standard Audiovisual Number">
                    <span class="field-hint">ISAN uniquely identifies audiovisual works worldwide</span>
                </div>
                <!-- EIDR -->
                <div class="form-group">
                    <label for="film-eidr">EIDR <span style="color:#888;font-size:0.8em;">(if applicable)</span></label>
                    <input type="text" id="film-eidr" name="film_eidr" placeholder="e.g., 10.5240/XXXX-XXXX-XXXX-XXXX-XXXX-X">
                    <span class="field-hint">Entertainment Identifier Registry</span>
                </div>
                <!-- Original Release Date -->
                <div class="form-group">
                    <label for="film-release-date">Original Release Date <span class="required">*</span></label>
                    <input type="date" id="film-release-date" name="film_release_date">
                </div>
                <!-- Country of Origin -->
                <div class="form-group">
                    <label for="film-country">Country of Origin <span class="required">*</span></label>
                    <input type="text" id="film-country" name="film_country" placeholder="e.g., United Kingdom">
                </div>
                <!-- Territories Owned -->
                <div class="form-group">
                    <label for="film-territories">Territories Owned</label>
                    <input type="text" id="film-territories" name="film_territories" placeholder="e.g., Worldwide, EU, North America">
                </div>
                <!-- License Expiration -->
                <div class="form-group">
                    <label for="film-license-expiry">License Expiration Date <span style="color:#888;font-size:0.8em;">(if applicable)</span></label>
                    <input type="date" id="film-license-expiry" name="film_license_expiry">
                </div>
                <!-- License Type / Streaming Rights -->
                <div class="form-group">
                    <label for="film-license-type">Streaming Rights / License Type <span class="required">*</span></label>
                    <select id="film-license-type" name="film_license_type">
                        <?php foreach ($licenses as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Music Cue Sheet -->
            <div class="credits-section" style="margin-top:20px;">
                <h3><svg class="imc-ic" aria-hidden="true"><use href="#ic-music"></use></svg> Music Cue Sheet</h3>
                <p class="section-hint">Upload your cue sheet listing all songs/compositions used in the film. Accepts CSV or PDF format.</p>
                <div class="form-group">
                    <label>Cue Sheet File <span style="color:#888;font-size:0.8em;">(CSV or PDF)</span></label>
                    <input type="file" id="cue-sheet-file" name="cue_sheet_file" accept=".csv,.pdf,application/pdf,text/csv" style="padding:8px;background:rgba(255,255,255,0.04);border:1px solid #444;border-radius:8px;color:#ccc;width:100%;">
                    <span class="field-hint">Standard cue sheet format — include song title, composer/artist, duration, and usage type</span>
                </div>
            </div>

            <!-- Commercial Rights for Film -->
            <div class="credits-section" style="margin-top:20px;">
                <h3>Commercial Rights <span class="required">*</span></h3>
                <div class="form-group">
                    <label class="checkbox-label important-checkbox">
                        <input type="checkbox" id="film-commercial-rights" name="film_commercial_rights">
                        <span>I confirm I have the commercial rights to mint and distribute this film as an NFT</span>
                    </label>
                </div>
            </div>
            </div><!-- /film-step-content step4 -->
            </div>
            <div class="imc-tabpane" data-tab="2">
            <h2>Publishing & Master Recording</h2>
            <p class="step-description">Enter publishing information and master recording ownership details.</p>
            
            <!-- Publishers Section -->
            <div class="credits-section">
                <h3>Publishers</h3>
                <p class="section-hint">Music publishers managing your composition rights (if any)</p>
                
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="self-published" name="self_published" checked>
                        <span>I am self-published (no external publisher)</span>
                    </label>
                </div>
                
                <div id="publishers-container" style="display: none;">
                    <div class="dynamic-list" id="publishers-list">
                        <div class="publisher-row" data-index="0">
                            <div class="form-grid publisher-grid">
                                <div class="form-group">
                                    <label>Publisher Name</label>
                                    <input type="text" name="publisher_name_0" placeholder="Publishing company">
                                </div>
                                <div class="form-group">
                                    <label>Type</label>
                                    <select name="publisher_type_0">
                                        <?php foreach ($publisher_types as $code => $label): ?>
                                        <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Ownership %</label>
                                    <input type="number" name="publisher_ownership_0" min="0" max="100" step="0.01" placeholder="e.g., 50">
                                </div>
                                <div class="form-group">
                                    <label>Publisher IPI</label>
                                    <input type="text" name="publisher_ipi_0" placeholder="9-11 digits">
                                </div>
                                <div class="form-group">
                                    <label>Publisher P.R.O</label>
                                    <select name="publisher_pro_0">
                                        <?php foreach ($pro_orgs as $code => $label): ?>
                                        <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <button type="button" class="btn-remove-row" onclick="removePublisherRow(this)">✕</button>
                        </div>
                    </div>
                    <div class="ownership-total">
                        <span>Total Ownership:</span>
                        <span id="publisher-ownership-total">0%</span>
                        <span class="ownership-warning" id="publisher-ownership-warning" style="display: none;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> Must equal 100%</span>
                    </div>
                    <button type="button" class="btn-secondary btn-small" id="add-publisher-btn">+ Add Publisher</button>
                </div>
            </div>

            <!-- Master Recording Section -->
            <div class="credits-section">
                <h3>Master Recording</h3>
                <p class="section-hint">Who owns the sound recording (master)? Ownership must total 100%.</p>
                
                <div class="dynamic-list" id="master-owners-list">
                    <div class="master-owner-row" data-index="0">
                        <div class="form-grid" style="grid-template-columns:1fr 120px auto;gap:8px;">
                            <div class="form-group">
                                <label>Owner Name <span class="required">*</span></label>
                                <input type="text" id="master-owner" name="master_owner_name_0" placeholder="Your name, label, or company" required>
                            </div>
                            <div class="form-group">
                                <label>Ownership %</label>
                                <input type="number" name="master_owner_pct_0" min="0" max="100" step="0.01" value="100" class="master-owner-pct" required>
                            </div>
                            <div class="credit-actions" style="padding-top:22px;">
                                <span class="primary-badge">Primary</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="ownership-total">
                    <span>Total Ownership:</span>
                    <span id="master-ownership-total">100.00%</span>
                    <span class="ownership-warning" id="master-ownership-warning" style="display: none;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> Must equal 100%</span>
                </div>
                <button type="button" class="btn-secondary btn-small" id="add-master-owner-btn">+ Add Owner</button>
            </div>

            <!-- Copyright Information -->
            <div class="credits-section">
                <h3>Copyright Information <span class="required">*</span></h3>
                
                <!-- Composition Copyright (©) -->
                <div style="margin-bottom:16px;">
                    <h4 style="color:#d4af37;margin-bottom:8px;">© Composition Copyright <span class="required">*</span></h4>
                    <p class="section-hint">Who owns the musical composition (lyrics + melody)? Ownership must total 100%.</p>
                    <div class="dynamic-list" id="comp-copyright-list">
                        <div class="comp-copyright-row" data-index="0">
                            <div class="form-grid" style="grid-template-columns:1fr 120px 120px auto;gap:8px;">
                                <div class="form-group">
                                    <label>Copyright Holder</label>
                                    <input type="text" name="comp_copyright_holder_0" placeholder="© Owner name" required>
                                </div>
                                <div class="form-group">
                                    <label>Ownership %</label>
                                    <input type="number" name="comp_copyright_pct_0" min="0" max="100" step="0.01" value="100" class="comp-copyright-pct">
                                </div>
                                <div class="form-group">
                                    <label>Year</label>
                                    <input type="number" name="comp_copyright_year_0" min="1900" max="2100" value="<?php echo date('Y'); ?>">
                                </div>
                                <div class="credit-actions" style="padding-top:22px;">
                                    <button type="button" class="btn-remove-row" onclick="removeCompCopyrightRow(this)" style="visibility:hidden;">✕</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="ownership-total">
                        <span>Total:</span>
                        <span id="comp-copyright-total">100.00%</span>
                        <span class="ownership-warning" id="comp-copyright-warning" style="display: none;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> Must equal 100%</span>
                    </div>
                    <button type="button" class="btn-secondary btn-small" id="add-comp-copyright-btn">+ Add Owner</button>
                </div>
                
                <!-- Sound Recording Copyright (℗) -->
                <div style="padding-top:12px;border-top:1px solid rgba(255,255,255,0.06);">
                    <h4 style="color:#d4af37;margin-bottom:8px;">℗ Sound Recording Copyright <span class="required">*</span></h4>
                    <p class="section-hint">Who owns the actual sound recording (master)? Ownership must total 100%.</p>
                    <div class="dynamic-list" id="sound-copyright-list">
                        <div class="sound-copyright-row" data-index="0">
                            <div class="form-grid" style="grid-template-columns:1fr 120px 120px auto;gap:8px;">
                                <div class="form-group">
                                    <label>Copyright Holder</label>
                                    <input type="text" name="sound_copyright_holder_0" placeholder="℗ Label or owner" required>
                                </div>
                                <div class="form-group">
                                    <label>Ownership %</label>
                                    <input type="number" name="sound_copyright_pct_0" min="0" max="100" step="0.01" value="100" class="sound-copyright-pct">
                                </div>
                                <div class="form-group">
                                    <label>Year</label>
                                    <input type="number" name="sound_copyright_year_0" min="1900" max="2100" value="<?php echo date('Y'); ?>">
                                </div>
                                <div class="credit-actions" style="padding-top:22px;">
                                    <button type="button" class="btn-remove-row" onclick="removeSoundCopyrightRow(this)" style="visibility:hidden;">✕</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="ownership-total">
                        <span>Total:</span>
                        <span id="sound-copyright-total">100.00%</span>
                        <span class="ownership-warning" id="sound-copyright-warning" style="display: none;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> Must equal 100%</span>
                    </div>
                    <button type="button" class="btn-secondary btn-small" id="add-sound-copyright-btn">+ Add Owner</button>
                </div>
            </div>
            </div>
            <div class="imc-tabpane" data-tab="3">
            <h2>Rights & Compliance</h2>
            <p class="step-description">Declare your rights, sample usage, and AI involvement. These disclosures are required.</p>
            
            <!-- Commercial Rights -->
            <div class="credits-section">
                <h3>Commercial Rights <span class="required">*</span></h3>
                
                <div class="form-group">
                    <label class="checkbox-label important-checkbox">
                        <input type="checkbox" id="commercial-rights" name="commercial_rights" required>
                        <span>I confirm I have the commercial rights to mint and sell this content as an NFT</span>
                    </label>
                    <span class="field-hint">You must have full rights to commercially distribute this content</span>
                </div>
            </div>

            <!-- License & Permissions -->
            <div class="credits-section">
                <h3>License & Permissions</h3>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>License Type <span class="required">*</span></label>
                        <select id="license-type" name="license_type" required>
                            <?php foreach ($licenses as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="permissions-grid">
                    <h4>Permissions Granted to NFT Holder:</h4>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="permission_streaming" checked disabled>
                            <input type="hidden" name="permission_streaming" value="on">
                            <span>Streaming <span style="color:#888;font-size:0.8em;">(included via IMU)</span></span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="permission_remix">
                            <span>Remix Rights</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="permission_sync">
                            <span>Sync Licensing</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="permission_commercial">
                            <span>Commercial Use</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Samples Disclosure (REQUIRED) -->
            <div class="credits-section samples-section">
                <h3>Sample Disclosure <span class="required">*</span></h3>
                <p class="section-hint">You must disclose if your track contains samples from other recordings.</p>
                
                <div class="form-group">
                    <label>Does this track contain samples? <span class="required">*</span></label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="contains_samples" value="false" checked required>
                            <span>No - This is 100% original</span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="contains_samples" value="true">
                            <span>Yes - Contains samples</span>
                        </label>
                    </div>
                </div>
                
                <div id="samples-details" style="display: none;">
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="samples_cleared">
                            <span>All samples have been cleared/licensed</span>
                        </label>
                    </div>
                    
                    <div class="form-group">
                        <label>Clearance Documentation URL</label>
                        <input type="url" name="clearance_proof_uri" placeholder="https://... link to clearance proof">
                    </div>
                    
                    <h4>Sample Details</h4>
                    <div class="dynamic-list" id="samples-list">
                        <div class="sample-row" data-index="0">
                            <div class="form-grid sample-grid">
                                <div class="form-group">
                                    <label>Original Track</label>
                                    <input type="text" name="sample_track_0" placeholder="Original song title">
                                </div>
                                <div class="form-group">
                                    <label>Original Artist</label>
                                    <input type="text" name="sample_artist_0" placeholder="Original artist">
                                </div>
                                <div class="form-group">
                                    <label>Original ISRC</label>
                                    <input type="text" name="sample_isrc_0" placeholder="If known">
                                </div>
                                <div class="form-group">
                                    <label>Clearance Type</label>
                                    <select name="sample_clearance_0">
                                        <option value="">Select...</option>
                                        <?php foreach ($clearance_types as $value => $label): ?>
                                        <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <button type="button" class="btn-remove-row" onclick="removeSampleRow(this)">✕</button>
                        </div>
                    </div>
                    <button type="button" class="btn-secondary btn-small" id="add-sample-btn">+ Add Another Sample</button>
                </div>
            </div>

            <!-- AI Disclosure moved to Step 2 for all content types -->
            </div>
            <div class="imc-tabpane" data-tab="4">
            <h2>Industry Identifiers</h2>
            <p class="step-description">Enter industry codes for proper tracking. These enable PRO reporting and platform integration.</p>
            
            <div class="form-grid">
                <!-- ISRC (Audio) -->
                <div class="form-group">
                    <label for="nft-isrc">ISRC (Audio Recording)</label>
                    <input type="text" id="nft-isrc" name="isrc" placeholder="e.g., GBXXX2600001" pattern="[A-Z]{2}[A-Z0-9]{3}[0-9]{7}">
                    <span class="field-hint">International Standard Recording Code - 12 characters</span>
                </div>

                <!-- ISRC Video (Music Video Only) -->
                <div class="form-group content-field musicVideo" style="display: none;">
                    <label for="nft-isrc-video">ISRC (Video Recording)</label>
                    <input type="text" id="nft-isrc-video" name="isrc_video" placeholder="e.g., GBXXX2600002" pattern="[A-Z]{2}[A-Z0-9]{3}[0-9]{7}">
                    <span class="field-hint">Separate ISRC for the music video</span>
                </div>

                <!-- ISWC -->
                <div class="form-group">
                    <label for="nft-iswc">ISWC (Composition)</label>
                    <input type="text" id="nft-iswc" name="iswc" placeholder="e.g., T-123.456.789-0">
                    <span class="field-hint">International Standard Musical Work Code - for the composition</span>
                </div>

                <!-- UPC -->
                <div class="form-group">
                    <label for="nft-upc">UPC/EAN</label>
                    <input type="text" id="nft-upc" name="upc" placeholder="e.g., 123456789012">
                    <span class="field-hint">Universal Product Code - if released commercially</span>
                </div>
            </div>

            <!-- Release Information -->
            <div class="credits-section">
                <h3>Release Information</h3>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Release Date</label>
                        <input type="date" name="release_date">
                    </div>
                    <div class="form-group">
                        <label>First Release Territory</label>
                        <input type="text" name="first_release_territory" placeholder="e.g., US, GB, worldwide" maxlength="20">
                    </div>
                    <div class="form-group">
                        <label>Release Type</label>
                        <select name="release_type">
                            <option value="">Select...</option>
                            <option value="single">Single</option>
                            <option value="ep">EP</option>
                            <option value="album">Album</option>
                            <option value="compilation">Compilation</option>
                            <option value="soundtrack">Soundtrack</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Album Name</label>
                        <input type="text" name="album_name" placeholder="If part of an album">
                    </div>
                    <div class="form-group">
                        <label>Track Number</label>
                        <input type="number" name="track_number" min="1" placeholder="e.g., 1">
                    </div>
                    <div class="form-group">
                        <label>Total Tracks</label>
                        <input type="number" name="total_tracks" min="1" placeholder="Total tracks on release">
                    </div>
                </div>
            </div>

            <!-- Video Release (Music Video Only) -->
            <div class="credits-section content-field musicVideo" style="display: none;">
                <h3>Video Release Information</h3>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Video Release Date</label>
                        <input type="date" name="video_release_date">
                        <span class="field-hint">May differ from audio release</span>
                    </div>
                </div>
            </div>
            </div>
        </div>
        <div class="wizard-step" data-step="4">
            <h2>Upload Your Media <button type="button" class="imc-guide-btn" onclick="imcShowGuide(4)"><svg class="imc-ic" aria-hidden="true"><use href="#ic-help"></use></svg> Guide</button></h2>
            <!-- Music/MusicVideo upload guidance (matches art-upload-guidance style) -->
            <div class="content-field music musicVideo audiobook" style="display:none;">
                <p class="step-description">Upload your content files. Your master file is stored securely off-chain. Preview clip and cover art are pinned publicly to IPFS.</p>
                <div class="art-upload-guidance">
                    <div class="guidance-item">
                        <span class="guidance-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-lock"></use></svg> </span>
                        <div>
                            <strong>Master File</strong> — Your full-quality, original audio or video. Stored securely off-chain on IMCollectibles servers and only unlocked for verified NFT holders across IMUTV, IMUP3, and IMCollectibles.
                        </div>
                    </div>
                    <div class="guidance-item">
                        <span class="guidance-icon">▶️</span>
                        <div>
                            <strong>Preview Clip</strong> — A short preview (max 30 seconds) pinned publicly to IPFS. This is what non-holders hear when browsing your NFT on any XRPL marketplace.
                        </div>
                    </div>
                    <div class="guidance-item">
                        <span class="guidance-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-image"></use></svg> </span>
                        <div>
                            <strong>Cover Art</strong> — Your album or single artwork, pinned to IPFS. Displayed on all marketplaces and platforms that read XRPL NFT metadata.
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Art-specific upload guidance -->
            <div class="art-upload-info content-field art" style="display:none;">
                <p class="step-description">Upload your master artwork. For supported formats (PNG, JPG, GIF, WebP, BMP, TIFF), a watermarked preview is <strong>automatically generated</strong> — no separate preview upload needed.</p>
                <div class="art-upload-guidance">
                    <div class="guidance-item">
                        <span class="guidance-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-lock"></use></svg> </span>
                        <div>
                            <strong>Master Artwork</strong> — The full-quality, unwatermarked original file. Stored securely off-chain and only unlocked for NFT holders across IMUTV, IMUP3, and IMCollectibles.
                        </div>
                    </div>
                    <div class="guidance-item">
                        <span class="guidance-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-sparkle"></use></svg> </span>
                        <div>
                            <strong>Auto-Watermarked Preview</strong> — A "Protected by IMCollectibles" watermarked version is auto-generated and pinned to IPFS as your public preview. PSD and RAW files require a manual preview upload.
                        </div>
                    </div>
                </div>
                <!-- v43: Dynamic notices shown/hidden by JS based on file type -->
                <div id="art-watermark-notice" style="display:none; margin-top:10px; padding:10px 14px; background:rgba(212,175,55,0.1); border:1px solid rgba(212,175,55,0.3); border-radius:6px; color:#d4af37; font-size:0.9rem;">
                    <svg class="imc-ic" aria-hidden="true"><use href="#ic-sparkle"></use></svg> <strong>Auto-watermark active</strong> — Your public preview will be generated with an "IMCollectibles" watermark. No separate preview upload needed.
                </div>
                <div id="art-watermark-manual-notice" style="display:none; margin-top:10px; padding:10px 14px; background:rgba(255,100,100,0.1); border:1px solid rgba(255,100,100,0.3); border-radius:6px; color:#e88; font-size:0.9rem;">
                    ℹ️ This file format (PSD/RAW) cannot be auto-watermarked. Please upload a separate preview image below.
                </div>
            </div>

            <!-- Film-specific upload guidance -->
            <div class="content-field film" style="display:none;">
                <p class="step-description">Upload your film files. Your master film is stored securely off-chain. The preview clip and poster are pinned publicly to IPFS.</p>
                <div class="art-upload-guidance">
                    <div class="guidance-item">
                        <span class="guidance-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-lock"></use></svg> </span>
                        <div>
                            <strong>Master Film</strong> — Your full-quality film file. Stored securely off-chain on IMCollectibles servers and only unlocked for verified NFT holders across IMUTV and IMCollectibles.
                        </div>
                    </div>
                    <div class="guidance-item">
                        <span class="guidance-icon">▶️</span>
                        <div>
                            <strong>Preview Clip / Trailer</strong> — A short preview (max 120 seconds) pinned publicly to IPFS. This is what non-holders see when browsing your NFT on any XRPL marketplace.
                        </div>
                    </div>
                    <div class="guidance-item">
                        <span class="guidance-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-image"></use></svg> </span>
                        <div>
                            <strong>Poster / Cover Art</strong> — Your film poster or key art, pinned to IPFS. Displayed on all marketplaces and platforms.
                        </div>
                    </div>
                </div>
            </div>

            <!-- v24: Album-specific upload guidance -->
            <div class="content-field album" style="display:none;">
                <p class="step-description">Upload your album cover and per-track files. Master audio is stored securely off-chain. Preview clips and the album cover are pinned publicly to IPFS.</p>
                <div class="art-upload-guidance">
                    <div class="guidance-item">
                        <span class="guidance-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-image"></use></svg> </span>
                        <div>
                            <strong>Album Cover</strong> — Pinned to IPFS. Displayed on all marketplaces and shown across IMUTV, IMUP3, and IMCollectibles as the album artwork.
                        </div>
                    </div>
                    <div class="guidance-item">
                        <span class="guidance-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-lock"></use></svg> </span>
                        <div>
                            <strong>Master Audio (per track)</strong> — Full-quality audio, stored securely off-chain. Unlocked for NFT holders only across the entire IMU ecosystem.
                        </div>
                    </div>
                    <div class="guidance-item">
                        <span class="guidance-icon">▶️</span>
                        <div>
                            <strong>Preview Clip (per track)</strong> — Max 30 seconds per track, pinned to IPFS. Lets buyers hear each track before minting.
                        </div>
                    </div>
                </div>
            </div>

            <!-- v24: Album track builder — shown only when album content type is selected -->
            <div id="album-upload-section" class="content-field album" style="display:none;">
                <div id="album-tracks-container">
                    <!-- Track cards injected here by addAlbumTrackCard() in mint.js -->
                </div>
                <div class="album-track-add-row" style="margin-top:12px;">
                    <button type="button" class="btn-secondary" onclick="addAlbumTrackCard()" id="add-track-btn">
                        + Add Track
                    </button>
                    <span class="field-hint" style="margin-left:10px;">Add up to 20 tracks. Each track needs a master audio file and a 30-second preview clip.</span>
                </div>
            </div>

            <!-- Cover Artwork Mode selector — full-width row above the 3 upload zones -->
            <!-- Hidden for art/album (those types handle it differently) -->
            <div class="tier-mode-selector-row content-field music musicVideo film art" style="display:none;">
                <div class="tier-mode-toggle">
                    <label class="tier-toggle-label">Cover Artwork Mode:</label>
                    <div class="tier-toggle-options">
                        <label class="tier-toggle-option">
                            <input type="radio" name="tier_mode" value="single" checked>
                            <span class="tier-toggle-card active" data-mode="single">
                                <span class="tier-toggle-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-image"></use></svg> </span>
                                <span class="tier-toggle-text">Single Cover</span>
                                <span class="tier-toggle-hint">All editions get the same artwork</span>
                            </span>
                        </label>
                        <label class="tier-toggle-option">
                            <input type="radio" name="tier_mode" value="tiers">
                            <span class="tier-toggle-card" data-mode="tiers">
                                <span class="tier-toggle-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-dice"></use></svg> </span>
                                <span class="tier-toggle-text">Rarity Tiers</span>
                                <span class="tier-toggle-hint">Different artwork per rarity — mystery mint!</span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- v699: Listing card cover for TIERED mints — shown only when Rarity Tiers is
                 selected (mint.js toggles #tier-card-cover-row). Optional + display-only; the card
                 falls back to the first tier's cover when empty, so single-cover and existing
                 listings are unaffected. -->
            <div class="form-group full-width tier-card-cover-row" id="tier-card-cover-row" style="display:none;">
                <label>Listing Card Cover <span style="opacity:0.65;font-weight:normal;">(optional — shown on the listing card only)</span></label>
                <div class="collection-image-upload" id="tier-card-cover-upload">
                    <div class="upload-dropzone small" id="tier-card-cover-dropzone">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="18" height="18" rx="2"/>
                            <circle cx="8.5" cy="8.5" r="1.5"/>
                            <path d="M21 15l-5-5L5 21"/>
                        </svg>
                        <span>Click to upload a card cover</span>
                    </div>
                    <input type="file" id="tier-card-cover-input" accept="image/*" style="display:none;">
                </div>
                <div id="tier-card-cover-preview" class="upload-preview" style="display:none;"></div>
            </div>

            <div class="upload-zones">
                <!-- Primary Media Upload (Master Audio or Video - stored OFF-IPFS) -->
                <div class="upload-zone" id="primary-upload-zone">
                    <div class="upload-dropzone" id="primary-dropzone">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                            <polyline points="17 8 12 3 7 8"></polyline>
                            <line x1="12" y1="3" x2="12" y2="15"></line>
                        </svg>
                        <h4 id="primary-upload-label">Upload Master Audio</h4>
                        <p id="primary-upload-hint">MP3, WAV, FLAC, M4A (Max 500MB) — Stored securely off-chain</p>
                        <input type="file" id="primary-file-input" accept="audio/*" hidden>
                        <button type="button" class="btn-secondary" onclick="document.getElementById('primary-file-input').click()">Choose File</button>
                    </div>
                    <div class="upload-preview" id="primary-preview" style="display: none;">
                        <div class="preview-info">
                            <span class="preview-name" id="primary-filename"></span>
                            <span class="preview-size" id="primary-filesize"></span>
                            <span class="preview-badge master-badge"><svg class="imc-ic" aria-hidden="true"><use href="#ic-lock"></use></svg> Master (Off-Chain)</span>
                        </div>
                        <div class="upload-progress" id="primary-progress" style="display: none;">
                            <div class="progress-bar"><div class="progress-fill" id="primary-progress-fill"></div></div>
                            <span class="progress-text" id="primary-progress-text">0%</span>
                        </div>
                        <button type="button" class="btn-remove" id="primary-remove">✕</button>
                    </div>
                </div>

                <!-- Preview Upload (≤30s music / ≤120s film - stored on IPFS) -->
                <div class="upload-zone content-field music musicVideo film audiobook" id="preview-upload-zone">
                    <div class="upload-dropzone" id="preview-dropzone">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polygon points="5 3 19 12 5 21 5 3"></polygon>
                        </svg>
                        <h4 id="preview-upload-label">Upload Preview Audio</h4>
                        <p id="preview-upload-hint">MP3, WAV, FLAC (Max 30 seconds) — Public preview on IPFS</p>
                        <input type="file" id="preview-file-input" accept="audio/*" hidden>
                        <button type="button" class="btn-secondary" onclick="document.getElementById('preview-file-input').click()">Choose Preview</button>
                    </div>
                    <div class="upload-preview" id="preview-preview" style="display: none;">
                        <div class="preview-info">
                            <span class="preview-name" id="preview-filename"></span>
                            <span class="preview-size" id="preview-filesize"></span>
                            <span class="preview-badge preview-badge-ipfs"><svg class="imc-ic" aria-hidden="true"><use href="#ic-globe"></use></svg> Preview (IPFS)</span>
                            <span class="preview-duration-badge" id="preview-duration-badge" style="display:none;">⏱ --s</span>
                        </div>
                        <div class="upload-progress" id="preview-progress" style="display: none;">
                            <div class="progress-bar"><div class="progress-fill" id="preview-progress-fill"></div></div>
                            <span class="progress-text" id="preview-progress-text">0%</span>
                        </div>
                        <button type="button" class="btn-remove" id="preview-remove">✕</button>
                    </div>
                    <div class="preview-duration-warning" id="preview-duration-warning" style="display: none;">
                        <span class="warning-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-warning"></use></svg> </span>
                        <span>Preview exceeds max duration. Detected: <strong id="preview-detected-duration">--</strong></span>
                    </div>
                </div>

                <!-- Secondary Audio (Music Video only — hidden by updateUploadLabels; class intentionally excludes musicVideo v485) -->
                <div class="upload-zone content-field" id="audio-upload-zone" style="display: none;">
                    <div class="upload-dropzone" id="audio-dropzone">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 18V5l12-2v13"></path>
                            <circle cx="6" cy="18" r="3"></circle>
                            <circle cx="18" cy="16" r="3"></circle>
                        </svg>
                        <h4>Upload Audio Track</h4>
                        <p>Audio file for the music video (MP3, WAV, FLAC)</p>
                        <input type="file" id="audio-file-input" accept="audio/*" hidden>
                        <button type="button" class="btn-secondary" onclick="document.getElementById('audio-file-input').click()">Choose Audio</button>
                    </div>
                    <div class="upload-preview" id="audio-preview" style="display: none;">
                        <div class="preview-info">
                            <span class="preview-name" id="audio-filename"></span>
                            <span class="preview-size" id="audio-filesize"></span>
                        </div>
                        <button type="button" class="btn-remove" id="audio-remove">✕</button>
                    </div>
                </div>

                <!-- Cover Art / Thumbnail Upload -->
                <div class="upload-zone" id="cover-upload-zone">

                    <!-- SINGLE COVER (default) -->
                    <div id="single-cover-section">
                        <!-- v144/v43: Art-only master artwork upload — shown FIRST for art flow -->
                        <div id="art-master-single-zone" class="upload-zone" style="display:none;">
                            <div class="upload-dropzone" id="art-master-dropzone">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                                </svg>
                                <h4>Upload Master Artwork</h4>
                                <p>Full quality original — PNG, TIFF, PSD, JPG, RAW (Max 500MB) — Stored securely off-chain</p>
                                <input type="file" id="art-master-file-input" accept="image/*,.psd,.tiff,.tif,.bmp,.raw" hidden>
                                <button type="button" class="btn-secondary" onclick="document.getElementById('art-master-file-input').click()">Choose Master File</button>
                            </div>
                            <div class="upload-preview" id="art-master-preview" style="display: none;">
                                <div class="preview-info">
                                    <span class="preview-name" id="art-master-filename"></span>
                                    <span class="preview-size" id="art-master-filesize"></span>
                                    <span class="preview-badge master-badge"><svg class="imc-ic" aria-hidden="true"><use href="#ic-lock"></use></svg> Master (Off-Chain)</span>
                                </div>
                                <button type="button" class="btn-remove" id="art-master-remove">✕</button>
                            </div>
                        </div>

                        <div class="upload-dropzone" id="cover-dropzone">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                <polyline points="21 15 16 10 5 21"></polyline>
                            </svg>
                            <h4 id="cover-upload-label">Upload Cover Art</h4>
                            <p id="cover-upload-hint">PNG, JPG, GIF, WebP (Min 1000x1000 recommended)</p>
                            <input type="file" id="cover-file-input" accept="image/*" hidden>
                            <button type="button" class="btn-secondary" onclick="document.getElementById('cover-file-input').click()">Choose File</button>
                        </div>
                        <div class="upload-preview" id="cover-preview" style="display: none;">
                            <img id="cover-preview-img" src="" alt="Cover preview">
                            <div class="preview-info">
                                <span class="preview-name" id="cover-filename"></span>
                                <span class="preview-size" id="cover-filesize"></span>
                            </div>
                            <div class="upload-progress" id="cover-progress" style="display: none;">
                                <div class="progress-bar"><div class="progress-fill" id="cover-progress-fill"></div></div>
                                <span class="progress-text" id="cover-progress-text">0%</span>
                            </div>
                            <button type="button" class="btn-remove" id="cover-remove">✕</button>
                        </div>
                    </div>

                    <!-- RARITY TIERS BUILDER -->
                    <div id="tier-builder-section" style="display: none;">
                        <div class="tier-builder-intro">
                            <p>Create different cover artworks and master audio/video files for each rarity tier. Buyers get a random tier when they mint — mystery box style!</p>
                            <div class="tier-bulk-explainer">
                                <div class="tier-bulk-explainer-col">
                                    <strong><svg class="imc-ic" aria-hidden="true"><use href="#ic-folder"></use></svg> Cover Folder Naming</strong>
                                    <p>Name your cover images <code>1.jpg</code>, <code>2.png</code>, <code>3.jpg</code>, etc. — the number is the tier box (1 = rarest). Upload the folder below and tiers are created automatically with covers assigned. If an image is missing or fails, its tier box is kept <strong>empty in place</strong> (numbering stays aligned) and flagged — add a cover to every box before publishing. Keep your folder and traits file aligned: image <code>N</code> = tier box <code>N</code>.</p>
                                </div>
                                <div class="tier-bulk-explainer-col">
                                    <strong><svg class="imc-ic" aria-hidden="true"><use href="#ic-clipboard"></use></svg> Traits CSV Format</strong>
                                    <p>Upload a <code>.csv</code> or Excel <code>.xlsx</code> file. Required column: <code>tier_number</code> (or <code>#</code>) — the tier box number. Optional reserved columns: <code>tier_name</code>, <code>editions</code>, <code>master_file</code> (pool item — accepts <code>3</code> or <code>Media 3</code>). Every other column header becomes an NFT trait. Keep tier numbers aligned with your cover folder (tier <code>1</code> = image <code>1</code>). <a href="#" id="bulk-traits-template-link-intro" style="color:#d4af37;">Download template ↓</a></p>
                                    <p style="font-size:0.75rem;color:#7a7a8a;margin-top:4px;">Example: <code>tier_number, tier_name, editions, master_file, Background, Rarity</code></p>
                                </div>
                            </div>
                        </div>

                        <!-- ── SHARED MASTER MEDIA POOL (music/mv/film) ── -->
                        <!-- Hidden for art (each art tier has its own master via pool) -->
                        <div id="pool-master-section" class="pool-master-section" style="display:none;">
                            <div class="pool-section-header">
                                <strong><svg class="imc-ic" aria-hidden="true"><use href="#ic-music"></use></svg> Master Media Files</strong>
                                <span class="pool-section-hint">Upload your audio/video files once here, then assign them to tiers below. Saves on fees — same file across multiple tiers counts once.</span>
                            </div>
                            <div id="pool-master-container" class="pool-master-container">
                                <!-- Pool zones rendered by JS -->
                            </div>
                            <button type="button" class="btn-secondary" id="add-pool-master-btn" style="margin-top:10px;">
                                + Add Media File
                            </button>
                        </div>

                        <!-- ── BULK UPLOAD TOOLS ── -->
                        <div id="bulk-upload-section" class="bulk-upload-section">
                            <div class="bulk-upload-header">
                                <strong><svg class="imc-ic" aria-hidden="true"><use href="#ic-folder"></use></svg> Bulk Upload Tools</strong>
                                <span class="bulk-upload-hint">Speed up creation for large collections</span>
                            </div>
                            <div class="bulk-upload-controls">
                                <!-- Numbered cover folder -->
                                <div class="bulk-tool-card">
                                    <div class="bulk-tool-title"><svg class="imc-ic" aria-hidden="true"><use href="#ic-image"></use></svg> Numbered Cover Folder</div>
                                    <div class="bulk-tool-desc">Upload a folder of images named 1.png, 2.jpg, etc. — auto-creates tiers and assigns covers. A missing image leaves its tier box empty in place (numbering stays aligned) for you to fill before publishing.</div>
                                    <label class="btn-secondary bulk-folder-btn">
                                        Choose Folder
                                        <input type="file" id="bulk-cover-folder-input" multiple webkitdirectory accept="image/*" style="display:none;">
                                    </label>
                                </div>
                                <!-- Traits CSV -->
                                <div class="bulk-tool-card">
                                    <div class="bulk-tool-title"><svg class="imc-ic" aria-hidden="true"><use href="#ic-clipboard"></use></svg> Traits CSV</div>
                                    <div class="bulk-tool-desc">Upload a CSV or Excel (.xlsx) file with columns: <code>tier_number</code> (or <code>#</code>), <code>tier_name, editions, master_file, Trait1, Trait2…</code></div>
                                    <label class="btn-secondary bulk-folder-btn">
                                        Choose CSV / Excel
                                        <input type="file" id="bulk-traits-csv-input" accept=".csv,.txt,.xlsx,.xls" style="display:none;">
                                    </label>
                                    <a href="#" id="bulk-traits-template-link" style="font-size:0.78rem;color:#d4af37;margin-left:8px;">Download template</a>
                                </div>
                            </div>
                        </div>

                        <div class="tier-cards-container" id="tier-cards-container">
                            <!-- Tier cards are added dynamically by JS -->
                        </div>

                        <button type="button" class="btn-secondary tier-add-btn" id="add-tier-btn">
                            + Add Tier
                        </button>
                        
                        <!-- Tier Summary Bar -->
                        <div class="tier-summary" id="tier-summary" style="display: none;">
                            <div class="tier-summary-header">
                                <span class="tier-summary-title">Tier Distribution</span>
                                <span class="tier-summary-count" id="tier-total-editions">0 total editions</span>
                            </div>
                            <div class="tier-distribution-bar" id="tier-distribution-bar"></div>
                            <div class="tier-distribution-legend" id="tier-distribution-legend"></div>
                            <div class="tier-validation" id="tier-validation" style="display: none;"></div>
                        </div>
                    </div>

                </div>
                <!-- M1-e1d: optional back cover (AudioBook) - grid sibling of the other zones. -->
                <div class="upload-zone content-field audiobook ebook" id="back-cover-upload-zone" style="display:none;">
                    <div class="upload-dropzone" id="back-cover-dropzone">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                            <circle cx="8.5" cy="8.5" r="1.5"></circle>
                            <polyline points="21 15 16 10 5 21"></polyline>
                        </svg>
                        <h4 id="back-cover-upload-label">Upload Back Cover</h4>
                        <p id="back-cover-upload-hint">PNG, JPG, WebP (optional) - watermarked for the public preview</p>
                        <input type="file" id="back-cover-file-input" accept="image/*" hidden>
                        <button type="button" class="btn-secondary" onclick="document.getElementById('back-cover-file-input').click()">Choose File</button>
                    </div>
                    <div class="upload-preview" id="back-cover-preview" style="display: none;">
                        <div class="preview-info">
                            <span class="preview-name" id="back-cover-filename"></span>
                            <span class="preview-size" id="back-cover-filesize"></span>
                        </div>
                        <button type="button" class="btn-remove" id="back-cover-remove">&#10005;</button>
                    </div>
                </div>
                <!-- M1-e2c: book pages (optional for AudioBook; the eBook type reuses this in M2). -->
                <div class="content-field ebook" id="book-pages-section" style="display:none;">
                    <div class="bp-head">
                        <h4>Book Pages</h4>
                        <p class="bp-hint">Pages are holder-only - never watermarked, never public. Add page images and/or a PDF; mix them freely and drag to reorder. Max 1,000 pages, 10MB per image, 500MB per PDF.</p>
                    </div>
                    <div class="bp-panel" id="bp-pages-panel">
                        <div class="bp-actions">
                            <button type="button" class="btn-secondary" onclick="document.getElementById('bp-files-input').click()">Add Pages</button>
                            <button type="button" class="btn-secondary" onclick="document.getElementById('bp-folder-input').click()">Add Folder</button>
                            <button type="button" class="btn-secondary" onclick="document.getElementById('bp-csv-input').click()">Import CSV</button>
                            <a href="#" id="bp-csv-template" class="bp-template-link">Download CSV template</a>
                            <input type="file" id="bp-files-input" accept="image/jpeg,image/png,image/webp,application/pdf,.pdf" multiple hidden>
                            <input type="file" id="bp-folder-input" accept="image/jpeg,image/png,image/webp,application/pdf,.pdf" multiple webkitdirectory hidden>
                            <input type="file" id="bp-csv-input" accept=".csv,.txt" hidden>
                        </div>
                        <p class="bp-parity" id="bp-parity-note"></p>
                        <div id="bp-pages-list"></div>
                    </div>
                </div>
                <!-- M1-e2b: chapter manager (AudioBook / chaptered). Sibling of the other zones. -->
                <div class="content-field audiobook audiobook-chaptered-only" id="audiobook-chapters-section" style="display:none;">
                    <div class="ab-chapters-head">
                        <h4>Chapters</h4>
                        <p class="ab-chapters-hint">One audio file per chapter, in order. Listeners get a chapter list and can jump between them. Max 100 chapters, 1GB each.</p>
                    </div>
                    <div id="audiobook-chapters-container"></div>
                    <div class="ab-chapter-add-row">
                        <button type="button" class="btn-secondary" onclick="addChapterCard()" id="add-chapter-btn">+ Add Chapter</button>
                        <span class="ab-chapter-count" id="ab-chapter-count"></span>
                    </div>
                </div>
            </div>

            <!-- U3 (Unlockables Master): holder-only vault files -- optional, any type -->
            <div class="ul-section" id="unlockables-section">
                <div class="ul-section-header">
                    <h4>🎁 Unlockable Content <span class="ul-optional">(optional)</span></h4>
                    <p class="ul-hint">Files only NFT holders can download -- PDFs, bonus tracks, stems, print files and more. Stored privately, delivered through holder verification, and they travel with the NFT on resale. Fee per file by size (1 XRP up to 25MB, up to 10 XRP for 1GB); 10 files max. <strong>Downloadable by holders by default.</strong></p>
                </div>
                <div class="upload-dropzone ul-dropzone" id="ul-dropzone">
                    <svg class="imc-ic" aria-hidden="true"><use href="#ic-upload"></use></svg>
                    <h4>Add unlockable files</h4>
                    <p>PDF, EPUB, TXT, ZIP, images, audio, video -- max 1GB each</p>
                    <input type="file" id="ul-file-input" multiple hidden>
                </div>
                <div class="ul-pool-list" id="ul-pool-list"></div>
                <div class="ul-summary" id="ul-summary" style="display:none;">
                    <span id="ul-summary-count"></span>
                    <span id="ul-summary-fee"></span>
                </div>
            </div>

            <!-- Auto-detected info -->
            <div class="auto-detected-info" id="auto-detected" style="display: none;">
                <h4>Auto-detected Properties</h4>
                <div class="detected-grid">
                    <div class="detected-item" id="detected-duration-wrap" style="display: none;">
                        <span class="label">Duration</span>
                        <span class="value" id="detected-duration">-</span>
                    </div>
                    <div class="detected-item" id="detected-resolution-wrap" style="display: none;">
                        <span class="label">Resolution</span>
                        <span class="value" id="detected-resolution">-</span>
                    </div>
                    <div class="detected-item" id="detected-format-wrap" style="display: none;">
                        <span class="label">Format</span>
                        <span class="value" id="detected-format">-</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="wizard-step" data-step="5">
            <h2>Pricing &amp; Editions <button type="button" class="imc-guide-btn" onclick="imcShowGuide(5)"><svg class="imc-ic" aria-hidden="true"><use href="#ic-help"></use></svg> Guide</button></h2>

 <!-- Platform Fee Estimate -->
            <div class="mint-cost-estimate platform-fee-estimate">
                <h4>Platform Fee Estimate</h4>
                <div class="cost-breakdown">
                    <div class="cost-item">
                        <span>Base fee per master file (<span id="fee-type-label">Music Access</span>)</span>
                        <span id="fee-base-amount">2.50 XRP</span>
                    </div>
                    <div class="cost-item">
                        <span>Per-edition fee (<span id="fee-editions-count">1</span> editions)</span>
                        <span id="fee-per-edition">0.06 XRP</span>
                    </div>
                    <div class="cost-item" id="fee-ul-row" style="display:none;">
                        <span>Unlockable content (<span id="fee-ul-count">0</span> files)</span>
                        <span id="fee-ul-amount">0.00 XRP</span>
                    </div>
                    <div class="cost-item total">
                        <span>Estimated total</span>
                        <span id="fee-total">2.56 XRP</span>
                    </div>
                </div>
                <!-- OE-v1: Future primary mint fee note (hidden for Fixed Edition) -->
                <p class="fee-note" id="fee-future-note" style="display:none;">
                    ℹ️ A <strong>2% platform fee per mint</strong> will be introduced when XRPL Batch Transactions are enabled. This has no impact on your listing today.
                </p>
                <p class="fee-note">
                    <strong>How it works:</strong> This one-time fee covers listing your NFT on the marketplace. 
                    NFTs are minted on-chain only when buyers purchase them. You receive 100% of sales directly.
                </p>
            </div>
            
            <!-- v76: Unified Pricing & Editions Section -->
            <div class="credits-section">
                <h3><svg class="imc-ic" aria-hidden="true"><use href="#ic-coins"></use></svg> Pricing & Editions</h3>
                <p class="section-hint">Set your NFT price, editions count, and accepted payment methods.</p>

                <!-- OE-v1: Edition Type Toggle -->
                <div class="form-group" id="oe-edition-type-group">
                    <label class="section-label">Edition Type</label>
                    <div class="tier-toggle-options" id="edition-type-toggle">
                        <label class="tier-toggle-card active" id="oe-toggle-fixed">
                            <input type="radio" name="edition_type" value="fixed" checked>
                            <div class="tier-toggle-content">
                                <span class="tier-toggle-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-hash"></use></svg></span>
                                <div>
                                    <strong>Fixed Edition</strong>
                                    <small>Set a supply cap (1–10,000 editions)</small>
                                </div>
                            </div>
                        </label>
                        <label class="tier-toggle-card" id="oe-toggle-open">
                            <input type="radio" name="edition_type" value="open">
                            <div class="tier-toggle-content">
                                <span class="tier-toggle-icon">∞</span>
                                <div>
                                    <strong>Open Edition</strong>
                                    <small>Unlimited supply for a set time window</small>
                                </div>
                            </div>
                        </label>
                    </div>
                    <span class="field-hint" id="oe-type-hint">Fixed Edition: supply is capped at your chosen editions count.</span>
                </div>

                <!-- OE-v1: Duration Picker (shown only for Open Edition) -->
                <div class="form-group" id="oe-duration-group" style="display:none;">
                    <label class="section-label">Mint Window</label>
                    <p class="section-hint">How long should minting be open? Min 1 hour, max 1 year. The window starts at your launch time.</p>

                    <div class="oe-quickpick-row">
                        <button type="button" class="oe-quickpick-btn" data-days="30">30 Days</button>
                        <button type="button" class="oe-quickpick-btn" data-days="90">90 Days</button>
                        <button type="button" class="oe-quickpick-btn" data-days="180">180 Days</button>
                        <button type="button" class="oe-quickpick-btn" data-days="365">1 Year</button>
                        <button type="button" class="oe-quickpick-btn" data-custom="1">Custom ▾</button>
                    </div>

                    <div id="oe-custom-range" style="display:none; margin-top:12px;">
                        <div class="form-row-2col">
                            <div class="form-group">
                                <label for="oe-end-date">End Date <span class="required">*</span></label>
                                <input type="date" id="oe-end-date" name="oe_end_date">
                            </div>
                            <div class="form-group">
                                <label for="oe-end-time">End Time (local) <span class="required">*</span></label>
                                <input type="time" id="oe-end-time" name="oe_end_time" value="23:59">
                            </div>
                        </div>
                    </div>

                    <div id="oe-duration-summary" class="oe-duration-summary" style="display:none;"></div>
                    <!-- Hidden: UTC ISO strings sent to server -->
                    <input type="hidden" id="oe-open-edition-ends-at" name="open_edition_ends_at">
                </div>

                <!-- Editions Count — hidden for Open Edition -->
                <div class="form-group editions-group" id="editions-count-group">
                    <label for="nft-editions">Number of Editions <span class="required">*</span></label>
                    <input type="number" id="nft-editions" name="editions" 
                           min="1" max="10000" value="1" 
                           placeholder="e.g., 100">
                    <span class="field-hint">How many copies to make available (1-10,000)</span>
                    <div class="editions-tier-notice"></div>
                </div>
                
                <!-- Pricing Mode Toggle -->
                <div class="pricing-mode-section" id="pricing-mode-section">
                    <label class="section-label">Pricing Mode</label>
                    <div class="pricing-mode-toggle">
                        <label class="mode-option selected" data-mode="static">
                            <input type="radio" name="pricing_mode" value="static" checked>
                            <span class="mode-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-drop"></use></svg> </span>
                            <span class="mode-content">
                                <strong>Static Pricing</strong>
                                <small>Set fixed prices per token</small>
                            </span>
                        </label>
                        <label class="mode-option" data-mode="dynamic">
                            <input type="radio" name="pricing_mode" value="dynamic">
                            <span class="mode-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-chart"></use></svg></span>
                            <span class="mode-content">
                                <strong>Dynamic Pricing</strong>
                                <small>USD price, live token conversion</small>
                            </span>
                        </label>
                        <label class="mode-option" data-mode="pwyw">
                            <input type="radio" name="pricing_mode" value="pwyw">
                            <span class="mode-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-gift"></use></svg></span>
                            <span class="mode-content">
                                <strong>Pay What You Want</strong>
                                <small>Buyers choose their price above your minimum</small>
                            </span>
                        </label>
                        <label class="mode-option" data-mode="free">
                            <input type="radio" name="pricing_mode" value="free">
                            <span class="mode-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-gift"></use></svg> </span>
                            <span class="mode-content">
                                <strong>Free Mint</strong>
                                <small>Collectors pay nothing (network fees only)</small>
                            </span>
                        </label>
                        <!-- PP-3: Progressive Pricing — a presentation-layer mode. On the wire it
                             submits pricing_mode=static + the progressive config (the server model:
                             progressive is a MODIFIER of standard pricing, not a fifth mode). -->
                        <label class="mode-option" data-mode="progressive">
                            <input type="radio" name="pricing_mode" value="progressive" style="display:none;">
                            <span class="mode-content">
                                <span class="mode-icon">📈</span>
                                <strong>Progressive Pricing</strong>
                                <small>The price rises with every mint</small>
                            </span>
                        </label>
                    </div>
                </div>
                
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <!-- STATIC PRICING MODE -->
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <div class="static-pricing-section" id="static-pricing-section">
                    
                    <div class="free-note" id="free-note" style="display:none; margin-bottom:14px; padding:12px 14px; border-radius:8px; background:rgba(80,200,120,0.08); border:1px solid rgba(80,200,120,0.35); font-size:0.9em; line-height:1.5;">
                        <svg class="imc-ic" aria-hidden="true"><use href="#ic-gift"></use></svg> <strong>Free Mint:</strong> collectors mint this at <strong>no charge</strong> — they only pay the XRPL network fee. No prices are set for this listing.
                    </div>
                    
                    <div class="pwyw-note" id="pwyw-note" style="display:none; margin-bottom:14px; padding:12px 14px; border-radius:8px; background:rgba(212,175,55,0.08); border:1px solid rgba(212,175,55,0.3); font-size:0.9em; line-height:1.5;">
                        <svg class="imc-ic" aria-hidden="true"><use href="#ic-bulb"></use></svg> <strong>Pay What You Want:</strong> the amounts below are the <strong>minimum (floor) price</strong> accepted for each token. Buyers enter their own amount at or above these minimums. Set a token's minimum to <strong>0</strong> to allow any amount.
                    </div>
                    
                    <!-- XRP Price (Primary) -->
                    <div class="token-price-box xrp-primary">
                        <div class="token-info">
                            <span class="token-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-drop"></use></svg> </span>
                            <span class="token-name">XRP</span>
                            <span class="token-badge">Primary</span>
                        </div>
                        <div class="token-price-input">
                            <input type="number" id="nft-price" name="price" 
                                   min="0" step="0.01" value="10" 
                                   placeholder="10.00">
                            <span class="currency-label">XRP</span>
                        </div>
                    </div>
                    
                    <!-- Add Token Section with Inline Input -->
                    <div class="add-token-section">
                        <label>Add Additional Payment Tokens:</label>
                        <div class="token-add-row" id="token-add-row">
                            <!-- v86: Tokens loaded dynamically from Token Manager -->
                            <select id="static-token-selector">
                                <option value="">Loading tokens...</option>
                            </select>
                            <!-- Inline price input (shows when token selected) -->
                            <div class="inline-price-input" id="inline-price-input" style="display:none;">
                                <input type="number" id="new-token-price" min="0" step="0.000001" placeholder="Price">
                                <span class="token-suffix" id="new-token-suffix">TOKEN</span>
                            </div>
                            <button type="button" id="add-static-token-btn" class="btn-add" disabled>Add</button>
                        </div>
                        
                        <!-- Custom Token Fields (shows when custom selected) -->
                        <div class="custom-token-inline" id="custom-token-inline" style="display:none;">
                            <input type="text" id="static-custom-code" placeholder="Code (e.g. MYTOKEN)" maxlength="20">
                            <input type="text" id="static-custom-issuer" placeholder="Issuer (rXXX...)">
                        </div>
                    </div>
                    
                    <!-- Added Tokens List -->
                    <div class="added-tokens-list" id="static-tokens-list">
                        <!-- Dynamically populated -->
                    </div>

                    <!-- PP-3: Progressive Pricing panel — visible ONLY in Progressive mode -->
                    <div id="progressive-pricing-panel" style="display:none;margin-top:18px;padding:16px;border:1px solid rgba(124,92,255,.45);border-radius:12px;background:rgba(124,92,255,.06);">
                        <div style="font-weight:700;margin-bottom:4px;">📈 Progressive Pricing</div>
                        <p style="font-size:.85rem;opacity:.8;margin:0 0 12px;">The price rises by your increment with every mint. Your prices above are the STARTING prices.</p>
                        <div id="pp-scope-cards" style="display:none;gap:10px;margin-bottom:12px;">
                            <label class="pp-scope-card selected" data-scope="together" style="flex:1;padding:10px 12px;border:1px solid rgba(255,255,255,.18);border-radius:9px;cursor:pointer;font-size:.85rem;">
                                <strong>Progress Together</strong><br><span style="opacity:.75;">One shared climb — every mint in any currency raises all prices one step.</span>
                            </label>
                            <label class="pp-scope-card" data-scope="individual" style="flex:1;padding:10px 12px;border:1px solid rgba(255,255,255,.18);border-radius:9px;cursor:pointer;font-size:.85rem;">
                                <strong>Progress Individually</strong><br><span style="opacity:.75;">Each currency climbs on its own mints only.</span>
                            </label>
                        </div>
                        <div id="pp-increment-rows"></div>
                        <div id="pp-preview" style="margin-top:10px;font-size:.88rem;padding:10px 12px;border-radius:8px;background:rgba(255,255,255,.05);display:none;"></div>
                    </div>
                </div>
                
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <!-- DYNAMIC PRICING MODE (hidden by default) -->
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <div class="dynamic-pricing-section" id="dynamic-pricing-section" style="display:none;">
                    
                    <!-- USD Base Price -->
                    <div class="usd-price-box">
                        <div class="token-info">
                            <span class="token-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-coins"></use></svg></span>
                            <span class="token-name">Base Price</span>
                        </div>
                        <div class="token-price-input">
                            <span class="price-prefix">$</span>
                            <input type="number" id="dynamic-usd-price" min="0.01" max="5000" step="0.01" value="9.99" placeholder="9.99">
                            <span class="currency-label">USD</span>
                        </div>
                    </div>
                    <p class="dynamic-hint">XRP amount calculated at checkout using live exchange rate. Max $5,000 USD.</p>

                    <!-- PP-5: progressive on dynamic -- one USD increment, inline toggle -->
                    <div id="pp-dyn-box" style="margin-top:14px;padding:14px;border:1px solid rgba(124,92,255,.45);border-radius:12px;background:rgba(124,92,255,.06);color:#fff;">
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:700;">
                            <input type="checkbox" id="pp-dyn-toggle" style="width:18px;height:18px;accent-color:#7c5cff;"> 📈 Progressive Pricing
                        </label>
                        <p style="font-size:.82rem;opacity:.8;margin:6px 0 0;">The USD price rises with every mint. Your price above is the STARTING price.</p>
                        <div id="pp-dyn-fields" style="display:none;margin-top:10px;">
                            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                                <input type="number" min="0" step="0.01" id="pp-dyn-inc" placeholder="Increment per mint ($)"
                                    style="flex:1;min-width:150px;padding:9px 11px;border-radius:7px;background:rgba(255,255,255,.06);color:#fff;border:1px solid rgba(255,255,255,.18);">
                                <input type="number" min="0" step="0.01" id="pp-dyn-cap" placeholder="Price cap ($, optional)"
                                    style="flex:1;min-width:150px;padding:9px 11px;border-radius:7px;background:rgba(255,255,255,.06);color:#fff;border:1px solid rgba(255,255,255,.18);">
                            </div>
                            <div style="font-size:.78rem;opacity:.65;margin-top:5px;">Optional — the price stops climbing at the cap.</div>
                            <div id="pp-dyn-preview" style="margin-top:9px;font-size:.88rem;padding:9px 11px;border-radius:8px;background:rgba(255,255,255,.07);color:#fff;display:none;"></div>
                        </div>
                    </div>
                    
                    <!-- v84: XRP Preview Box (always shown, no other tokens) -->
                    <div class="xrp-preview-box" style="background:var(--color-surface-alt,#1a1a2e);border:1px solid var(--gold,#d4af37);border-radius:12px;padding:1.25rem;margin-top:1rem;">
                        <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.75rem;">
                            <span style="font-size:1.5rem;"><svg class="imc-ic" aria-hidden="true"><use href="#ic-drop"></use></svg> </span>
                            <strong style="color:var(--gold,#d4af37);">XRP Payment</strong>
                            <span style="background:#10b981;color:#fff;font-size:10px;padding:2px 8px;border-radius:4px;font-weight:700;">LIVE RATE</span>
                        </div>
                        <div style="font-size:1.5rem;color:#fff;" id="dynamic-xrp-calc">⏳ Calculating...</div>
                        <p style="color:#9ca3af;font-size:0.85rem;margin-top:0.5rem;">Price updates automatically based on XRP/USD rate</p>
                    </div>
                    
                    <!-- v84: Info box about XRP-only -->
                    <div style="background:rgba(212,175,55,0.1);border:1px solid var(--gold,#d4af37);border-radius:8px;padding:1rem;margin-top:1rem;">
                        <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem;">
                            <span>ℹ️</span>
                            <strong style="color:var(--gold,#d4af37);">Dynamic Pricing = XRP Only</strong>
                        </div>
                        <p style="color:#9ca3af;font-size:0.85rem;margin:0;">
                            Dynamic pricing automatically converts USD to XRP at checkout. 
                            To accept other XRPL tokens (XFT, RLUSD, etc.), use <strong>Static pricing</strong>.
                        </p>
                    </div>
                </div>
                
                <!-- Revenue Summary (updates based on mode) -->
                <div class="revenue-calculator" id="revenue-calculator">
                    <h4>Revenue Summary</h4>
                    <div class="revenue-grid">
                        <div class="revenue-item">
                            <span class="revenue-label">Price per edition:</span>
                            <span class="revenue-value" id="calc-price">10.00 XRP</span>
                        </div>
                        <div class="revenue-item">
                            <span class="revenue-label">Total editions:</span>
                            <span class="revenue-value" id="calc-editions">1</span>
                        </div>
                        <!-- v485: Royalty row — makes the on-chain royalty % visible before mint fires -->
                        <div class="revenue-item">
                            <span class="revenue-label">Secondary royalty:</span>
                            <span class="revenue-value" id="calc-royalty">5.0%</span>
                        </div>
                        <div class="revenue-item revenue-total">
                            <span class="revenue-label">Maximum revenue:</span>
                            <span class="revenue-value" id="calc-total">10.00 XRP</span>
                        </div>
                    </div>
                    <p class="revenue-note"><svg class="imc-ic" aria-hidden="true"><use href="#ic-coins"></use></svg> You receive 100% of sales directly to your wallet.</p>
                </div>
            </div>


            <!-- On-Chain Royalties (splits UI removed 24 Aug — D3; returns post XRPL Batch amendment as a real, server-enforced feature) -->
            <div class="credits-section">
                <h3>On-Chain Royalties</h3>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="nft-royalty">Transfer Fee (Secondary Sales)</label>
                        <input type="number" id="nft-royalty" name="royalty" min="0" max="50" value="5" step="0.1">
                        <span class="field-hint">0-50% earned on secondary sales</span>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" id="nft-transferable" name="transferable" checked>
                            <span>Transferable (can be sold/traded)</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" id="mint-downloadable" name="downloadable">
                            <span>Allow owners to download the master file</span>
                        </label>
                        <span class="field-hint">When enabled, NFT holders can download the original high-quality file. Disabled by default to protect your content.</span>
                    </div>
                </div>
                
            </div>
            
            <!-- v75: Mint Limit Section -->
            <div class="credits-section">
                <h3><svg class="imc-ic" aria-hidden="true"><use href="#ic-lock"></use></svg> Mint Limits</h3>
                <p class="section-hint">Optionally limit how many editions a single wallet can mint.</p>
                
                <div class="mint-limit-section">
                    <label class="mint-limit-toggle">
                        <input type="checkbox" id="mint-limit-enabled">
                        <span>Limit mints per wallet</span>
                    </label>
                    
                    <div class="mint-limit-config" id="mint-limit-config" style="display:none;">
                        <div class="form-group">
                            <label>Maximum mints per wallet:</label>
                            <input type="number" id="mint-limit-per-wallet" min="1" max="100" placeholder="e.g. 3">
                            <small>Each wallet can mint up to this many editions</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- v71: Allowlist (Optional) -->
            <div class="credits-section">
                <h3><svg class="imc-ic" aria-hidden="true"><use href="#ic-clipboard"></use></svg> Allowlist (Optional)</h3>
                <p class="section-hint">Restrict minting to specific wallets, offer early access, or provide discounts.</p>
                
                <label class="checkbox-label allowlist-master-toggle">
                    <input type="checkbox" id="enable-allowlist">
                    <span>Enable allowlist for this listing</span>
                </label>
                
                <div id="allowlist-config" style="display:none; margin-top:16px;">
                    <div class="form-group">
                        <label>Allowlist Type</label>
                        <select id="allowlist-type" class="form-select">
                            <option value="early_access">Early Access — Mint before public launch</option>
                            <option value="exclusive">Exclusive — ONLY allowlisted wallets can mint</option>
                            <option value="discount">Discount — Unlimited special pricing for allowlisted wallets</option>
                            <option value="discount_limited">Holder Discount — Quantity-limited discount based on CSV holdings</option>
                            <option value="limit_override">Mint Cap — Limit total mints per wallet</option>
                        </select>
                    </div>
                    
                    <div id="allowlist-early-config" class="allowlist-type-config">
                        <div class="form-group">
                            <label>Early Access Window</label>
                            <div class="input-with-suffix">
                                <input type="number" id="allowlist-early-hours" min="1" max="168" value="24">
                                <span class="input-suffix">hours before launch</span>
                            </div>
                        </div>
                    </div>
                    
                    <div id="allowlist-discount-config" class="allowlist-type-config" style="display:none;">
                        <!-- v729 (A3): price-or-percent parity with the creator dashboard.
                             Previously the creation flow was percent-only AND discount_limited
                             sent no discount at all (B3). -->
                        <div class="form-group">
                            <label>Discount Mode</label>
                            <select id="allowlist-discount-mode" class="form-select">
                                <option value="percent">Percentage off</option>
                                <option value="price">Fixed price (XRP)</option>
                                <option value="free">Free claim (0 XRP)</option>
                            </select>
                        </div>
                        <div class="form-group" id="allowlist-discount-percent-group">
                            <label>Base Discount Percentage</label>
                            <div class="input-with-suffix">
                                <input type="number" id="allowlist-discount-percent" min="1" max="100" value="20">
                                <span class="input-suffix">% off</span>
                            </div>
                            <span class="field-hint">Applies to XRP and any accepted token without its own rule below</span>
                        </div>
                        <div class="form-group" id="allowlist-discount-price-group" style="display:none;">
                            <label>Fixed Price (XRP)</label>
                            <div class="input-with-suffix">
                                <input type="number" id="allowlist-custom-price" min="0" step="0.000001" value="1">
                                <span class="input-suffix">XRP</span>
                            </div>
                            <span class="field-hint">What allowlisted wallets pay instead of the listing price — token payments follow proportionally unless a token has its own rule below</span>
                        </div>
                        <!-- v731 (A4b): per-token pricing matrix — rows built by JS from the live
                             token config; hidden until the feature flag is on and hidden on free mode. -->
                        <div class="form-group" id="allowlist-matrix-wrap" style="display:none;"
                             data-enabled="<?php echo (defined('IMC_ALLOWLIST_CURRENCY_MATRIX') && IMC_ALLOWLIST_CURRENCY_MATRIX) ? '1' : '0'; ?>">
                            <label>Per-Token Pricing (optional)</label>
                            <span class="field-hint">A rule here <strong>replaces</strong> the discount above for wallets paying in that token — even if it's smaller. Tokens without a rule keep the discount above. Free claims are free in every currency, so this section disappears for free lists.</span>
                            <div id="allowlist-matrix-rows"></div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label id="allowlist-max-mint-label">Per-Wallet Mint Limit</label>
                        <div class="input-with-suffix">
                            <input type="number" id="allowlist-max-mint" min="1" max="100" value="5">
                            <span class="input-suffix">editions max</span>
                        </div>
                        <span class="field-hint" id="allowlist-max-mint-hint">Maximum editions each allowlisted wallet can mint</span>
                    </div>
                    
                    <div class="form-group">
                        <label>Wallet Addresses</label>
                        <textarea id="allowlist-wallets" rows="4" placeholder="Enter wallet addresses, one per line (amount optional):&#10;rWalletAddress1...&#10;rWalletAddress2...,3"></textarea>
                        <div class="allowlist-upload-row">
                            <span class="field-hint">Or upload CSV:</span>
                            <input type="file" id="allowlist-csv" accept=".csv,.txt">
                        </div>
                        <span id="allowlist-csv-hint" class="field-hint" style="display:none; margin-top:4px;">CSV files with a quantity column will automatically set individual per-wallet limits</span>
                    </div>
                    
                    <div id="allowlist-preview" class="allowlist-preview" style="display:none;">
                        ✓ <span id="allowlist-count">0</span> valid wallet(s) added
                    </div>
                </div>
            </div>
        </div>
        <div class="wizard-step" data-step="6">
            <h2>Launch &amp; Review <button type="button" class="imc-guide-btn" onclick="imcShowGuide(6)"><svg class="imc-ic" aria-hidden="true"><use href="#ic-help"></use></svg> Guide</button></h2>

            <p class="step-description">Configure final settings and review your NFT before minting.</p>
            
            <!-- Collection Settings (read-only — configured in Step 2) -->
            <div class="credits-section">
                <h3>Collection Settings</h3>
                <p class="section-hint">Configured in Step 1. <a href="#" onclick="goToStep(0);return false;" style="color:var(--gold);">Go back to edit</a></p>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Collection Name</label>
                        <div class="review-value" id="review-collection-name">—</div>
                    </div>
                    <div class="form-group">
                        <label>Taxon (XRPL)</label>
                        <div class="review-value review-taxon-badge" id="review-collection-taxon">—</div>
                    </div>
                    <div class="form-group full-width">
                        <label>Description</label>
                        <div class="review-value" id="review-collection-desc">—</div>
                    </div>
                </div>
                <!-- Hidden inputs so collectFormData() still picks them up -->
                <input type="hidden" id="nft-collection" name="collection_name" value="">
                <input type="hidden" id="nft-collection-desc" name="collection_description" value="">
            </div>

            <!-- Launch Scheduling -->
            <div class="credits-section launch-scheduling-section">
                <h3><svg class="imc-ic" aria-hidden="true"><use href="#ic-rocket"></use></svg> Release Schedule</h3>
                <p class="section-hint">Choose when your NFT listing goes live on the marketplace.</p>
                
                <div class="launch-toggle-group">
                    <label class="launch-option">
                        <input type="radio" name="launch_type" value="immediate" checked>
                        <div class="launch-option-card active">
                            <span class="launch-option-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-bolt"></use></svg> </span>
                            <div class="launch-option-text">
                                <strong>Publish Immediately</strong>
                                <span>Goes live as soon as you pay the platform fee</span>
                            </div>
                        </div>
                    </label>
                    <label class="launch-option">
                        <input type="radio" name="launch_type" value="scheduled">
                        <div class="launch-option-card">
                            <span class="launch-option-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-calendar"></use></svg> </span>
                            <div class="launch-option-text">
                                <strong>Schedule Release</strong>
                                <span>Set a specific date &amp; time for the listing to appear</span>
                            </div>
                        </div>
                    </label>
                </div>

                <div class="scheduled-fields" id="scheduled-fields" style="display: none;">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="launch-date">Release Date <span class="required">*</span></label>
                            <input type="date" id="launch-date" name="launch_date">
                        </div>
                        <div class="form-group">
                            <label for="launch-time">Release Time <span class="required">*</span></label>
                            <input type="time" id="launch-time" name="launch_time" value="12:00">
                        </div>
                        <div class="form-group">
                            <label for="launch-timezone">Timezone</label>
                            <select id="launch-timezone" name="launch_timezone">
                                <option value="UTC">UTC</option>
                                <option value="America/New_York">Eastern (ET)</option>
                                <option value="America/Chicago">Central (CT)</option>
                                <option value="America/Denver">Mountain (MT)</option>
                                <option value="America/Los_Angeles">Pacific (PT)</option>
                                <option value="Europe/London" selected>London (GMT/BST)</option>
                                <option value="Europe/Paris">Central European (CET)</option>
                                <option value="Asia/Tokyo">Japan (JST)</option>
                                <option value="Asia/Shanghai">China (CST)</option>
                                <option value="Australia/Sydney">Sydney (AEST)</option>
                            </select>
                        </div>
                    </div>
                    <div class="schedule-preview" id="schedule-preview" style="display: none;">
                        <span class="schedule-preview-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-calendar"></use></svg></span>
                        <span id="schedule-preview-text">—</span>
                    </div>
                </div>
            </div>

            <!-- Metadata Preview -->
            <div class="metadata-preview">
                <h3>NFT Preview</h3>
                <div class="preview-card">
                    <div class="preview-image" id="preview-image">
                        <span>No image uploaded</span>
                    </div>
                    <div class="preview-details">
                        <h4 id="preview-title">-</h4>
                        <p id="preview-description">-</p>
                        <div class="preview-meta">
                            <span id="preview-type">-</span>
                            <span id="preview-duration">-</span>
                            <span id="preview-schema">-</span>
                        </div>
                        <!-- v485: Pricing/royalty confirmation rows — artist sees these before minting -->
                        <div class="preview-meta" style="margin-top:10px;display:grid;grid-template-columns:1fr 1fr;gap:4px 12px;font-size:0.78rem;opacity:0.85;">
                            <span style="opacity:0.6;">Price:</span>
                            <span id="preview-price" style="color:var(--imc-gold,var(--imu-gold, #d6ba66));font-weight:600;">—</span>
                            <span style="opacity:0.6;">Royalty:</span>
                            <span id="preview-royalty" style="color:var(--imc-gold,var(--imu-gold, #d6ba66));font-weight:600;">—</span>
                            <span style="opacity:0.6;">Editions:</span>
                            <span id="preview-editions" style="color:var(--imc-gold,var(--imu-gold, #d6ba66));font-weight:600;">—</span>
                            <span style="opacity:0.6;">Transferable:</span>
                            <span id="preview-transferable" style="color:var(--imc-gold,var(--imu-gold, #d6ba66));font-weight:600;">—</span>
                        </div>
                    </div>
                </div>
                <details class="json-preview" open>
                    <summary><svg class="imc-ic" aria-hidden="true"><use href="#ic-clipboard"></use></svg> Raw Metadata JSON (XLS-24d Compliant)</summary>
                    <pre id="metadata-json-preview">{}</pre>
                </details>
            </div>

            <!-- v409 Phase 2: Preview Collection button -->
            <div class="preview-action-bar">
                <button type="button" class="btn-secondary preview-collection-btn" id="preview-collection-btn" onclick="imcOpenCollectionPreview()">
                    <svg class="imc-ic" aria-hidden="true"><use href="#ic-eye"></use></svg> Preview Collection
                </button>
                <span class="preview-hint">Opens in a new tab — your progress is auto-saved</span>
            </div>

            <!-- AI Disclosure now lives in Step 2 for all content types -->

            <!-- Compliance Checklist -->
            <div class="compliance-checklist">
                <h4>Pre-Mint Checklist</h4>
                <div id="checklist-items">
                    <div class="checklist-item" id="check-rights">
                        <span class="check-icon">○</span>
                        <span>Commercial rights confirmed</span>
                    </div>
                    <div class="checklist-item" id="check-writers">
                        <span class="check-icon">○</span>
                        <span>Writer credits with ownership %</span>
                    </div>
                    <div class="checklist-item" id="check-master">
                        <span class="check-icon">○</span>
                        <span>Master recording owner specified</span>
                    </div>
                    <div class="checklist-item" id="check-samples">
                        <span class="check-icon">○</span>
                        <span>Sample disclosure completed</span>
                    </div>
                    <div class="checklist-item" id="check-ai">
                        <span class="check-icon">○</span>
                        <span>AI disclosure completed</span>
                    </div>
                </div>
            </div>
        </div>
        <!-- ================================================================ -->
        <!-- STEP 1: Content Type Selection (Music, Music Video, Art, Film) -->
        <!-- ================================================================ -->
        <!-- ================================================================ -->
        <!-- STEP 2: NFT Information (Marketing Essentials) -->
        <!-- ================================================================ -->
            <!-- ============================================================ -->
            <!-- v24: EP/ALBUM TYPE + LICENSED TOGGLE (ALBUM ONLY)            -->
            <!-- Hidden by default; shown when album content type selected.    -->
            <!-- album-only-section class controlled by updateFieldVisibility  -->
            <!-- ============================================================ -->
            <div class="credits-section licensed-toggle-section album-only-section" style="display:none;">
                <h3><svg class="imc-ic" aria-hidden="true"><use href="#ic-disc"></use></svg> Album Type</h3>
                <p class="section-hint">
                    Are you releasing an EP (typically 3–6 tracks) or a full Album (7+ tracks)?
                    This is stamped in your NFT metadata and affects how the release is displayed across the IMU ecosystem.
                </p>
                <div class="licensed-toggle-wrapper">
                    <label class="licensed-toggle-question">Release type</label>
                    <div class="licensed-toggle-options">
                        <label class="licensed-option">
                            <input type="radio" name="album_type" value="ep" checked>
                            <span class="licensed-card" data-licensed="ep">
                                <span class="licensed-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-music"></use></svg> </span>
                                <span class="licensed-text">EP</span>
                                <span class="licensed-hint">Extended Play — typically 3–6 tracks</span>
                            </span>
                        </label>
                        <label class="licensed-option">
                            <input type="radio" name="album_type" value="album">
                            <span class="licensed-card" data-licensed="album">
                                <span class="licensed-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-disc"></use></svg> </span>
                                <span class="licensed-text">Album</span>
                                <span class="licensed-hint">Full-length release — 7+ tracks</span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="credits-section licensed-toggle-section album-only-section" style="display:none;">
                <h3><svg class="imc-ic" aria-hidden="true"><use href="#ic-music"></use></svg> Album Registration Status</h3>
                <p class="section-hint">
                    If your album is registered with a P.R.O (e.g. ASCAP, BMI, PRS), licensed, or copyrighted,
                    select <strong>Yes</strong> to unlock the Rights step where you can add writer, publisher and rights information
                    for your release.
                </p>
                <div class="licensed-toggle-wrapper">
                    <label class="licensed-toggle-question">Is this release published, licensed, or registered?</label>
                    <div class="licensed-toggle-options">
                        <label class="licensed-option">
                            <input type="radio" name="album_licensed" value="no" checked>
                            <span class="licensed-card" data-licensed="no">
                                <span class="licensed-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-x-circle"></use></svg> </span>
                                <span class="licensed-text">No</span>
                                <span class="licensed-hint">Skip rights metadata — go straight to upload & mint</span>
                            </span>
                        </label>
                        <label class="licensed-option">
                            <input type="radio" name="album_licensed" value="yes">
                            <span class="licensed-card" data-licensed="yes">
                                <span class="licensed-icon"><svg class="imc-ic" aria-hidden="true"><use href="#ic-check-circle"></use></svg> </span>
                                <span class="licensed-text">Yes</span>
                                <span class="licensed-hint">Add rights metadata in the Rights step</span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
        <!-- ================================================================ -->
        <!-- STEP 3: Track Information (Music) / Film Descriptive Metadata   -->
        <!-- ================================================================ -->
        <!-- ================================================================ -->
        <!-- STEP 4: Artists & Credits (PRO Critical) / Film Admin & Rights   -->
        <!-- ================================================================ -->
        <!-- ================================================================ -->
        <!-- STEP 5: Publishing & Master Recording -->
        <!-- ================================================================ -->
        <!-- ================================================================ -->
        <!-- STEP 6: Rights & Compliance (Samples, AI Disclosure) -->
        <!-- ================================================================ -->
        <!-- ================================================================ -->
        <!-- STEP 7: Industry Identifiers & Release Info -->
        <!-- ================================================================ -->
        <!-- ================================================================ -->
        <!-- STEP 8: Upload Media (moved here to prevent spam uploads) -->
        <!-- ================================================================ -->
        <!-- ================================================================ -->
        <!-- STEP 9: Collection, Royalties & Review -->
        <!-- ================================================================ -->
        <!-- ================================================================ -->
        <!-- Navigation Buttons -->
        <!-- ================================================================ -->
        <div class="wizard-navigation" id="wizard-nav" role="navigation" aria-label="Wizard navigation">
            <!-- U0 (unlockables master): running fee total -- fed by updateFeeFooter() in mint.js;
                 component-shaped stash so U3 adds the unlockable term without changing this seat -->
            <div class="wizard-fee-chip" id="wizard-fee-chip" style="display:none;" aria-live="polite"></div>
            <button type="button" class="btn-secondary" id="wizard-prev" style="display: none;" aria-label="Go to previous step">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                Previous
            </button>
            <button type="button" class="btn-outline btn-save-draft" id="wizard-save-draft" style="display: none;" title="Save progress and continue later" aria-label="Save draft">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Save Progress
            </button>
            <button type="button" class="btn-primary" id="wizard-next" aria-label="Go to next step">
                Next
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
            </button>
            <button type="button" class="btn-primary btn-mint" id="wizard-mint" style="display: none;" aria-label="Create listing and pay minting fee">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>
    Create Listing & Pay Fee
</button>
        </div>
    </div>

    <!-- Success Modal -->
    <div id="mint-success-modal" class="modal" style="display: none;" role="dialog" aria-label="Minting success" aria-modal="true">
        <div class="modal-content">
            <div class="success-icon">✓</div>
            <h3>NFT Minted Successfully!</h3>
            <p>Your XLS-24d compliant NFT has been created on the XRP Ledger.</p>
            <div class="success-details">
                <div class="detail-row">
                    <span>NFT ID:</span>
                    <code id="success-nft-id">-</code>
                </div>
                <div class="detail-row">
                    <span>Transaction:</span>
                    <a id="success-tx-link" href="#" target="_blank">View on Explorer</a>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn-secondary" onclick="window.location.href='<?php echo esc_url(home_url('/my-nfts/')); ?>'">View My NFTs</button>
                <button class="btn-primary" onclick="location.reload()">Mint Another</button>
            </div>
        </div>
    </div>
</div>

<script>
// Pass PHP data to JavaScript
window.MINT_CONFIG = {
    account: '<?php echo esc_js($xrpl_account); ?>',
    nonce: '<?php echo esc_js($nonce); ?>',
    endpoints: <?php echo $endpoints_json; ?>,
    contentTypes: <?php echo $content_types_json; ?>,
    schemasBase: 'https://imcollectibles.io/schemas/',
    writerRoles: <?php echo wp_json_encode($writer_roles); ?>,
    publisherTypes: <?php echo wp_json_encode($publisher_types); ?>,
    proOrgs: <?php echo wp_json_encode($pro_orgs); ?>,
    engineerRoles: <?php echo wp_json_encode($engineer_roles); ?>,
    instruments: <?php echo wp_json_encode($instruments); ?>,
    clearanceTypes: <?php echo wp_json_encode($clearance_types); ?>,
    aiElements: <?php echo wp_json_encode($ai_elements); ?>,
    aiElementsByType: {
        music: <?php echo wp_json_encode($ai_elements_music); ?>,
        musicVideo: <?php echo wp_json_encode($ai_elements_music); ?>,
        art: <?php echo wp_json_encode($ai_elements_art); ?>,
        film: <?php echo wp_json_encode($ai_elements_film); ?>,
        audiobook: <?php echo wp_json_encode($ai_elements_audiobook); ?>
    },
    vpsMedia: {
        endpoint: '<?php echo esc_js($vps_config['endpoint']); ?>',
        apiKey: '<?php echo esc_js($vps_config['apiKey']); ?>'
    },
    ulFeeGrid: <?php echo wp_json_encode(defined('IMC_UNLOCKABLE_FEE_GRID') ? (array) IMC_UNLOCKABLE_FEE_GRID : [[26214400,1.0],[52428800,1.5],[104857600,2.0],[262144000,2.5],[524288000,5.0],[1073741824,10.0]]); ?>,
    freeMints: {
        active: <?php echo $imc_fm_on ? 'true' : 'false'; ?>,
        monthLabel: '<?php echo esc_js(date_i18n('F Y')); ?>',
        quotas: <?php echo wp_json_encode($imc_fm_quotas); ?>,
        remaining: <?php echo wp_json_encode((object) $imc_fm_remaining); ?>
    }
};

// Revenue calculation for pricing & editions
// v229: Fee calculation REMOVED — was using hardcoded wrong values (baseFee=1.00, perEdition=0.05)
// that overwrote the correct type-aware fee estimate from mint.js updateFeeEstimate().
// Fee preview is now exclusively handled by updateFeeEstimate() in mint.js which uses
// the correct per-type rates: music=2.5/0.06, musicVideo=3.0/0.08, film=5.0/0.10.
// v365: Art uses tiered flat fee (≤100 tiers=2, ≤500=4, 500+=6 XRP) + 0.03 per NFT.
// v485: Added royalty row (calc-royalty) + dynamic pricing mode detection.
function setupFeeCalculation() {
    const priceInput    = document.getElementById('nft-price');
    const editionsInput = document.getElementById('nft-editions');
    const royaltyInput  = document.getElementById('nft-royalty');
    
    // Revenue calculator elements
    const calcPrice   = document.getElementById('calc-price');
    const calcEditions= document.getElementById('calc-editions');
    const calcTotal   = document.getElementById('calc-total');
    const calcRoyalty = document.getElementById('calc-royalty');  // v485
    
    function updateCalculations() {
        // v485: Detect dynamic pricing mode (price input is hidden; static XRP value is stale)
        const isDynamic = document.querySelector('input[name="pricing_mode"]:checked')?.value === 'dynamic';

        const price    = parseFloat(priceInput?.value) || 0;
        const editions = parseInt(editionsInput?.value) || 1;
        // v485: isNaN() fix mirrors mint.js — 0% royalty is valid, blank defaults to 5
        const royRaw   = parseFloat(royaltyInput?.value);
        const royalty  = isNaN(royRaw) ? 5 : royRaw;
        
        // Update revenue calculator
        if (calcPrice)    calcPrice.textContent    = isDynamic ? '(dynamic)' : price.toFixed(2) + ' XRP';
        if (calcEditions) calcEditions.textContent = editions.toLocaleString();
        if (calcTotal)    calcTotal.textContent    = isDynamic ? '(dynamic)' : (price * editions).toFixed(2) + ' XRP';
        if (calcRoyalty)  calcRoyalty.textContent  = royalty.toFixed(1) + '%';   // v485
    }
    
    // Bind events
    priceInput?.addEventListener('input', updateCalculations);
    editionsInput?.addEventListener('input', updateCalculations);
    royaltyInput?.addEventListener('input', updateCalculations);  // v485
    // Also rebind when pricing mode radio changes
    document.querySelectorAll('input[name="pricing_mode"]').forEach(r =>
        r.addEventListener('change', updateCalculations)
    );  // v485
    
    // Initial calculation
    updateCalculations();
}

// Call on DOM ready
document.addEventListener('DOMContentLoaded', setupFeeCalculation);
</script>

<!-- v409: Step Guide Panel -->
<div class="imc-guide-overlay" id="imc-guide-overlay" onclick="imcCloseGuide()"></div>
<div class="imc-guide-panel" id="imc-guide-panel">
    <button class="guide-close" onclick="imcCloseGuide()" aria-label="Close guide">×</button>
    <span class="guide-step-badge" id="imc-guide-badge">Step 0</span>
    <h2 class="guide-title" id="imc-guide-title">Guide</h2>
    <div class="guide-body" id="imc-guide-body"></div>
</div>

<!-- ====================================================================== -->
<!-- v24: ALBUM TRACK METADATA POPUP — 5-page modal                         -->
<!-- Shown when artist clicks "📋 Details" on a track card in Step 8.       -->
<!-- All fields are optional — none block minting.                           -->
<!-- JS: openTrackMetadataPopup / saveTrackMetadata / closeTrackMetadataPopup -->
<!-- ====================================================================== -->
<div id="album-track-popup-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:10000; align-items:center; justify-content:center;">
    <div id="album-track-popup" style="background:var(--imc-card-bg,#1a1a2e); border:1px solid rgba(255,255,255,0.12); border-radius:12px; width:min(560px,95vw); max-height:90vh; overflow-y:auto; padding:28px 28px 20px;">

        <!-- Popup header -->
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:18px;">
            <h3 style="margin:0; font-size:1.1rem;">Track Details <span id="popup-page-indicator" style="font-weight:400; opacity:0.6; font-size:0.85rem; margin-left:8px;">1 / 5</span></h3>
            <button type="button" onclick="closeTrackMetadataPopup()" style="background:none; border:none; color:inherit; font-size:1.4rem; cursor:pointer; opacity:0.7; line-height:1;">×</button>
        </div>

        <!-- Page 1: Basic Track Info -->
        <div id="track-popup-page-1">
            <p style="margin:0 0 16px; opacity:0.65; font-size:0.85rem;">Basic track information stamped in per-track metadata.</p>
            <div class="form-group">
                <label for="popup-track-title">Track Title <span class="required">*</span></label>
                <input type="text" id="popup-track-title" placeholder="e.g. Summer Nights">
                <span class="field-hint">Used as the track name in album metadata and player queues.</span>
            </div>
            <div class="form-grid" style="margin-top:14px;">
                <div class="form-group">
                    <label for="popup-track-genre">Genre</label>
                    <input type="text" id="popup-track-genre" placeholder="e.g. Electronic, Hip Hop">
                </div>
                <div class="form-group">
                    <label for="popup-track-bpm">BPM</label>
                    <input type="number" id="popup-track-bpm" placeholder="e.g. 128" min="1" max="999">
                </div>
                <div class="form-group">
                    <label for="popup-track-key">Musical Key</label>
                    <input type="text" id="popup-track-key" placeholder="e.g. C Major">
                </div>
                <div class="form-group">
                    <label for="popup-track-explicit">Explicit Content</label>
                    <select id="popup-track-explicit">
                        <option value="no">No</option>
                        <option value="yes">Yes</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Page 2: Songwriters & Composers -->
        <div id="track-popup-page-2" style="display:none;">
            <p style="margin:0 0 16px; opacity:0.65; font-size:0.85rem;">Songwriter and composer details for PRO reporting. Optional — only required for registered tracks.</p>
            <div class="form-group">
                <label for="popup-writer-name-0">Primary Songwriter / Composer</label>
                <input type="text" id="popup-writer-name-0" placeholder="Full legal name">
            </div>
            <div class="form-grid" style="margin-top:14px;">
                <div class="form-group">
                    <label for="popup-writer-pro">PRO Affiliation</label>
                    <input type="text" id="popup-writer-pro" placeholder="e.g. ASCAP, BMI, PRS">
                </div>
                <div class="form-group">
                    <label for="popup-writer-ipi">IPI / CAE Number</label>
                    <input type="text" id="popup-writer-ipi" placeholder="e.g. 00123456789">
                </div>
            </div>
        </div>

        <!-- Page 3: Publishing & Rights -->
        <div id="track-popup-page-3" style="display:none;">
            <p style="margin:0 0 16px; opacity:0.65; font-size:0.85rem;">Publishing information and master rights ownership. Optional.</p>
            <div class="form-group">
                <label for="popup-publisher-name">Publisher Name</label>
                <input type="text" id="popup-publisher-name" placeholder="e.g. My Publishing Ltd">
            </div>
            <div class="form-grid" style="margin-top:14px;">
                <div class="form-group">
                    <label for="popup-publisher-pro">Publisher PRO</label>
                    <input type="text" id="popup-publisher-pro" placeholder="e.g. ASCAP, BMI, PRS">
                </div>
                <div class="form-group">
                    <label for="popup-publisher-ipi">Publisher IPI</label>
                    <input type="text" id="popup-publisher-ipi" placeholder="e.g. 00987654321">
                </div>
            </div>
            <div class="form-group" style="margin-top:14px;">
                <label for="popup-master-owner">Master Rights Owner</label>
                <input type="text" id="popup-master-owner" placeholder="Individual or label name who owns the master recording">
            </div>
        </div>

        <!-- Page 4: Additional Contributors -->
        <div id="track-popup-page-4" style="display:none;">
            <p style="margin:0 0 16px; opacity:0.65; font-size:0.85rem;">Featured artists, producers, and engineers. Optional — used for metadata completeness.</p>
            <div class="form-group">
                <label for="popup-featured-artist">Featured Artist(s)</label>
                <input type="text" id="popup-featured-artist" placeholder="e.g. Artist Name (separate with commas)">
            </div>
            <div class="form-group" style="margin-top:14px;">
                <label for="popup-producer">Producer(s)</label>
                <input type="text" id="popup-producer" placeholder="e.g. Producer Name">
            </div>
            <div class="form-group" style="margin-top:14px;">
                <label for="popup-engineer">Mixing / Mastering Engineer</label>
                <input type="text" id="popup-engineer" placeholder="e.g. Engineer Name">
            </div>
        </div>

        <!-- Page 5: IDs & Identifiers -->
        <div id="track-popup-page-5" style="display:none;">
            <p style="margin:0 0 16px; opacity:0.65; font-size:0.85rem;">Industry identifiers for this track. Optional — used for DSP integration and PRO lookup.</p>
            <div class="form-group">
                <label for="popup-isrc">ISRC</label>
                <input type="text" id="popup-isrc" placeholder="e.g. GBUM71505078" maxlength="12">
                <span class="field-hint">International Standard Recording Code — 12 characters. Get one free at isrc.ifpi.org</span>
            </div>
            <div class="form-group" style="margin-top:14px;">
                <label for="popup-iswc">ISWC</label>
                <input type="text" id="popup-iswc" placeholder="e.g. T-034524680-1">
                <span class="field-hint">International Standard Musical Work Code — assigned by your PRO</span>
            </div>
        </div>

        <!-- Popup navigation -->
        <div style="display:flex; align-items:center; justify-content:space-between; margin-top:22px; padding-top:16px; border-top:1px solid rgba(255,255,255,0.08);">
            <button type="button" id="popup-prev-btn" class="btn-secondary" onclick="showPopupPage(window._popupPage ? window._popupPage-1 : 1)" style="display:none;">← Back</button>
            <div style="flex:1;"></div>
            <button type="button" id="popup-next-btn" class="btn-primary" onclick="showPopupPage(window._popupPage ? window._popupPage+1 : 2)">Next →</button>
            <button type="button" id="popup-save-btn" class="btn-primary" onclick="saveTrackMetadata()" style="display:none;">Save Track Details</button>
        </div>

        <script>
        // Keep _trackPopupPage in sync with navigation buttons
        (function() {
            var origShow = window.showPopupPage;
            window.showPopupPage = function(p) {
                window._popupPage = p;
                if (origShow) origShow(p);
            };
        })();
        </script>
    </div>
</div>

<?php get_footer(); ?>