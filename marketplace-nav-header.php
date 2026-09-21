<?php
/**
 * File: marketplace-nav-header.php
 * Path: /wp-content/themes/astra/template-parts/marketplace-nav-header.php
 * 
 * Reusable marketplace navigation header.
 * Include this in any marketplace-related page:
 * <?php get_template_part('template-parts/marketplace-nav-header'); ?>
 * 
 * Or directly include:
 * <?php include get_stylesheet_directory() . '/template-parts/marketplace-nav-header.php'; ?>
 */

// Get current page for active state
$current_url = home_url($_SERVER['REQUEST_URI']);
$current_path = parse_url($current_url, PHP_URL_PATH);

// Navigation items - FULL marketplace navigation
$nav_items = [
    [
        'url' => '/trading-hub/',
        'label' => 'Trading Hub',
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>'
    ],
    [
        'url' => '/collections/',
        'label' => 'Collections',
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>'
    ],
    [
        'url' => '/mint/',
        'label' => 'Mint NFT',
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>'
    ],
    [
        'url' => '/tasks/',
        'label' => 'Tasks',
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>'
    ],
    [
        'url' => '/burn-to-earn/',
        'label' => '🔥 Burn to Earn',
        'icon' => ''
    ],
    [
        'url' => '/trading-hub-dashboard/',
        'label' => 'Dashboard',
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>'
    ],
    [
        'url' => '/my-nfts/',
        'label' => 'My NFTs',
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>'
    ]
];
?>

<nav class="marketplace-header-nav">
    <?php foreach ($nav_items as $item): 
        $is_active = (strpos($current_path, rtrim($item['url'], '/')) !== false);
    ?>
    <a href="<?php echo esc_url(home_url($item['url'])); ?>" 
       class="nav-link <?php echo $is_active ? 'active' : ''; ?>">
        <?php if (!empty($item['icon'])): ?>
            <?php echo $item['icon']; ?>
        <?php endif; ?>
        <?php echo esc_html($item['label']); ?>
    </a>
    <?php endforeach; ?>
</nav>
