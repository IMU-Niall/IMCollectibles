<?php
/**
 * IMC Token Manager v86
 * Path: /xrpl-nft-marketplace/backend/admin-token-manager.php
 * 
 * Admin page for managing supported XRPL tokens
 * Uses WordPress options (like marketing-admin.php) instead of custom tables
 */

if (!defined('ABSPATH')) exit;

// ============================================================================
// DEFAULT TOKENS
// ============================================================================

function imc_get_default_tokens() {
    return [
        'XRP' => [
            'ticker' => 'XRP',
            'display_name' => 'XRP',
            'icon_emoji' => '💧',
            'icon_url' => '',
            'issuer' => '',
            'trustline_url' => '',
            'decimals' => 6,
            'is_native' => true,
            'enabled_mint' => true,
            'enabled_offers' => true,
            'sort_order' => 1
        ],
        'XFT' => [
            'ticker' => 'XFT',
            'display_name' => 'XFT Token',
            'icon_emoji' => '🎵',
            'icon_url' => '',
            'issuer' => 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt',
            'trustline_url' => 'https://xrpl.services/?issuer=rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt&currency=XFT&limit=1000000000',
            'decimals' => 8,
            'is_native' => false,
            'enabled_mint' => true,
            'enabled_offers' => true,
            'sort_order' => 10
        ],
        'RLUSD' => [
            'ticker' => 'RLUSD',
            'display_name' => 'RLUSD Stablecoin',
            'icon_emoji' => '💵',
            'icon_url' => '',
            'issuer' => 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De',
            'trustline_url' => 'https://xrpl.services/?issuer=rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De&currency=RLUSD&limit=1000000000',
            'decimals' => 8,
            'is_native' => false,
            'enabled_mint' => true,
            'enabled_offers' => true,
            'sort_order' => 20
        ],
        'FARM' => [
            'ticker' => 'FARM',
            'display_name' => 'FARM',
            'icon_emoji' => '🚜',
            'icon_url' => '',
            'issuer' => 'rPrAEfVATUNDTJm9CUa8tYeD7oJrVdEGhU',
            'trustline_url' => 'https://xrpl.services/?issuer=rPrAEfVATUNDTJm9CUa8tYeD7oJrVdEGhU&currency=4641524D00000000000000000000000000000000&limit=995053913.7393122',
            'decimals' => 8,
            'is_native' => false,
            'enabled_mint' => true,
            'enabled_offers' => true,
            'sort_order' => 30
        ],
        'SPIFFY' => [
            'ticker' => 'SPIFFY',
            'display_name' => 'SPIFFY',
            'icon_emoji' => '🎵',
            'icon_url' => '',
            'issuer' => 'rZ4yugfiQQMWx1a2ZxvzskL75TZeGgMFp',
            'trustline_url' => 'https://xrpl.services/?issuer=rZ4yugfiQQMWx1a2ZxvzskL75TZeGgMFp&currency=5350494646590000000000000000000000000000&limit=992438.3731592544',
            'decimals' => 8,
            'is_native' => false,
            'enabled_mint' => true,
            'enabled_offers' => true,
            'sort_order' => 40
        ],
        '666' => [
            'ticker' => '666',
            'display_name' => '666',
            'icon_emoji' => '🔥',
            'icon_url' => '',
            'issuer' => 'rhvf9fe6PP3GC8Bku2Ug7iQPjPDxYZfrxN',
            'trustline_url' => 'https://xrpl.services/?issuer=rhvf9fe6PP3GC8Bku2Ug7iQPjPDxYZfrxN&currency=666&limit=9993910.699887836',
            'decimals' => 8,
            'is_native' => false,
            'enabled_mint' => true,
            'enabled_offers' => true,
            'sort_order' => 50
        ],
        'SCHMECKLES' => [
            'ticker' => 'SCHMECKLES',
            'display_name' => 'Schmeckles',
            'icon_emoji' => '🪙',
            'icon_url' => '',
            'issuer' => 'rPxw83ZP6thv7KmG5DpAW4cDW55DZRZ9wu',
            'trustline_url' => 'https://xrpl.services/?issuer=rPxw83ZP6thv7KmG5DpAW4cDW55DZRZ9wu&currency=SCHMECKLES&limit=1000000000',
            'decimals' => 8,
            'is_native' => false,
            'enabled_mint' => true,
            'enabled_offers' => true,
            'sort_order' => 60
        ],
        'XMEME' => [
            'ticker' => 'XMEME',
            'display_name' => 'XMEME',
            'icon_emoji' => '🟢',
            'icon_url' => '',
            'issuer' => 'r4UPddYeGeZgDhSGPkooURsQtmGda4oYQW',
            'trustline_url' => 'https://xrpl.services/?issuer=r4UPddYeGeZgDhSGPkooURsQtmGda4oYQW&currency=XMEME&limit=1000000000',
            'decimals' => 8,
            'is_native' => false,
            'enabled_mint' => true,
            'enabled_offers' => true,
            'sort_order' => 70
        ],
        'CORN' => [
            'ticker' => 'CORN',
            'display_name' => 'CORN',
            'icon_emoji' => '🌽',
            'icon_url' => '',
            'issuer' => 'rBTjqSwQnbjCgFtHSjzDHRVArTdrmxpesK',
            'trustline_url' => 'https://xrpl.services/?issuer=rBTjqSwQnbjCgFtHSjzDHRVArTdrmxpesK&currency=434F524E00000000000000000000000000000000&limit=999564.3811482821',
            'decimals' => 8,
            'is_native' => false,
            'enabled_mint' => true,
            'enabled_offers' => true,
            'sort_order' => 80
        ],
                'HORDE' => [
            'ticker' => '$HORDE',
            'display_name' => 'HORDE',
            'icon_emoji' => '🧟',
            'icon_url' => '',
            'issuer' => 'rwdZkUex3qhVdEHCoX9bno2gVe411LLfeR',
            'trustline_url' => 'https://xrpl.services/?issuer=rwdZkUex3qhVdEHCoX9bno2gVe411LLfeR&currency=24484F5244450000000000000000000000000000&limit=99911172898.25089',
            'currency_hex' => '24484F5244450000000000000000000000000000', // v607: explicit (apostrophe + mixed-case)
            'decimals' => 8,
            'is_native' => false,
            'enabled_mint' => true,
            'enabled_offers' => true,
            'sort_order' => 80
        ],
        'BUT' => [
            'ticker' => 'BUT',
            'display_name' => 'BUT',
            'icon_emoji' => '☂️',
            'icon_url' => '',
            'issuer' => 'riQtZKAtGWGRThMNBGz8RtLGAKHd7Za8x',
            'trustline_url' => 'https://xrpl.services/?issuer=riQtZKAtGWGRThMNBGz8RtLGAKHd7Za8x&currency=BUT&limit=88842358.92244188',
            'decimals' => 8,
            'is_native' => false,
            'enabled_mint' => true,
            'enabled_offers' => true,
            'sort_order' => 90
        ],
        'TOE' => [
            'ticker' => 'TOE',
            'display_name' => 'Toekin',
            'icon_emoji' => '👣',
            'icon_url' => '',
            'issuer' => 'rfCNgbLFAyCiY5FNyrNhokdcsjZ3X2atoe',
            'trustline_url' => 'https://xrpl.services/?issuer=rfCNgbLFAyCiY5FNyrNhokdcsjZ3X2atoe&currency=TOE&limit=1010101010',
            'decimals' => 8,
            'is_native' => false,
            'enabled_mint' => true,
            'enabled_offers' => true,
            'sort_order' => 100
        ],
        'Kedas Brew Coin' => [
            'ticker' => 'Kedas Brew Coin',
            'display_name' => 'Kedas Brew Coin',
            'icon_emoji' => '☕',
            'icon_url' => '',
            'issuer' => 'r4MHjxd35frAtqaNP7vvcLva8iDBi3aXer',
            'trustline_url' => 'https://xrpl.services/?issuer=r4MHjxd35frAtqaNP7vvcLva8iDBi3aXer&currency=4B6564612773204272657720436F696E00000000&limit=99999.99999999993',
            'currency_hex' => '4B6564612773204272657720436F696E00000000', // v607: explicit (apostrophe + mixed-case)
            'decimals' => 8,
            'is_native' => false,
            'enabled_mint' => true,
            'enabled_offers' => true,
            'sort_order' => 110
        ]
    ];
}

// ============================================================================
// ADMIN MENU
// ============================================================================

add_action('admin_menu', 'imc_token_manager_menu');

function imc_token_manager_menu() {
    add_submenu_page(
        'tools.php',
        'Token Manager',
        '🪙 Token Manager',
        'manage_options',
        'imc-token-manager',
        'imc_token_manager_page'
    );
}

// ============================================================================
// ADMIN PAGE
// ============================================================================

function imc_token_manager_page() {
    // Get stored tokens or defaults
    $tokens = get_option('imc_supported_tokens', imc_get_default_tokens());
    
    $message = '';
    $error = '';
    
    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('imc_token_action', 'imc_token_nonce')) {
        $action = sanitize_text_field($_POST['token_action'] ?? '');
        
        if ($action === 'add' || $action === 'edit') {
            $ticker = strtoupper(sanitize_text_field($_POST['ticker'] ?? ''));
            $is_native = isset($_POST['is_native']);
            
            if (empty($ticker)) {
                $error = 'Ticker is required';
            } elseif (empty($_POST['display_name'])) {
                $error = 'Display name is required';
            } elseif (!$is_native && empty($_POST['issuer'])) {
                $error = 'Issuer is required for non-native tokens';
            } elseif ($action === 'add' && isset($tokens[$ticker])) {
                $error = 'Token with this ticker already exists';
            } else {
                $tokens[$ticker] = [
                    'ticker' => $ticker,
                    'display_name' => sanitize_text_field($_POST['display_name'] ?? ''),
                    'issuer' => sanitize_text_field($_POST['issuer'] ?? ''),
                    'icon_emoji' => sanitize_text_field($_POST['icon_emoji'] ?? '🪙'),
                    'icon_url' => esc_url_raw($_POST['icon_url'] ?? ''),
                    'trustline_url' => esc_url_raw($_POST['trustline_url'] ?? ''),
                    'decimals' => intval($_POST['decimals'] ?? 8),
                    'is_native' => $is_native,
                    'enabled_mint' => isset($_POST['enabled_mint']),
                    'enabled_offers' => isset($_POST['enabled_offers']),
                    'sort_order' => intval($_POST['sort_order'] ?? 100)
                ];
                update_option('imc_supported_tokens', $tokens);
                $message = $action === 'add' ? 'Token added!' : 'Token updated!';
            }
        } elseif ($action === 'delete') {
            $ticker = strtoupper(sanitize_text_field($_POST['delete_ticker'] ?? ''));
            if ($ticker === 'XRP') {
                $error = 'Cannot delete native XRP token';
            } elseif (isset($tokens[$ticker])) {
                unset($tokens[$ticker]);
                update_option('imc_supported_tokens', $tokens);
                $message = 'Token deleted!';
            }
        }
    }
    
    // Sort tokens by sort_order
    uasort($tokens, function($a, $b) {
        return ($a['sort_order'] ?? 100) - ($b['sort_order'] ?? 100);
    });
    
    // Edit mode
    $edit_token = null;
    if (isset($_GET['edit']) && isset($tokens[$_GET['edit']])) {
        $edit_token = $tokens[$_GET['edit']];
    }
    
    ?>
    <style>
        .imc-tm-wrap { max-width: 1200px; }
        .imc-tm-grid { display: grid; grid-template-columns: 1fr 380px; gap: 2rem; margin-top: 20px; }
        @media (max-width: 1024px) { .imc-tm-grid { grid-template-columns: 1fr; } }
        .imc-tm-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 8px; padding: 1.5rem; }
        .imc-tm-table { width: 100%; border-collapse: collapse; }
        .imc-tm-table th, .imc-tm-table td { padding: 0.6rem 0.75rem; text-align: left; border-bottom: 1px solid #eee; }
        .imc-tm-table th { background: #f9f9f9; font-weight: 600; }
        .imc-tm-token-row:hover { background: #f9f9f9; }
        .imc-tm-icon { font-size: 1.3rem; }
        .imc-tm-ticker { font-weight: 600; font-family: monospace; }
        .imc-tm-issuer { font-size: 0.75rem; color: #666; font-family: monospace; }
        .imc-tm-badge { display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 0.7rem; margin-right: 3px; }
        .imc-tm-badge-mint { background: #d4edda; color: #155724; }
        .imc-tm-badge-offers { background: #cce5ff; color: #004085; }
        .imc-tm-badge-native { background: #fff3cd; color: #856404; }
        .imc-tm-badge-disabled { background: #f8d7da; color: #721c24; }
        .imc-tm-actions a { margin-right: 6px; text-decoration: none; font-size: 0.9rem; }
        .imc-tm-form label { display: block; margin-bottom: 4px; font-weight: 500; font-size: 0.9rem; }
        .imc-tm-form input[type="text"], .imc-tm-form input[type="url"], .imc-tm-form input[type="number"] {
            width: 100%; padding: 6px 8px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 0.9rem;
        }
        .imc-tm-form .form-row { margin-bottom: 0.9rem; }
        .imc-tm-form .checkbox-row { display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 0.5rem; }
        .imc-tm-form .checkbox-item { display: flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; }
        .imc-tm-help { font-size: 0.75rem; color: #666; margin-top: 2px; }
    </style>
    
    <div class="wrap imc-tm-wrap">
        <h1>🪙 Token Manager</h1>
        <p>Manage supported XRPL tokens for minting and trading.</p>
        
        <?php if ($message): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($message); ?></p></div><?php endif; ?>
        <?php if ($error): ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
        
        <div class="imc-tm-grid">
            <div class="imc-tm-card">
                <h2>Supported Tokens (<?php echo count($tokens); ?>)</h2>
                <table class="imc-tm-table">
                    <thead><tr><th>Icon</th><th>Ticker</th><th>Name</th><th>Issuer</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($tokens as $ticker => $token): ?>
                        <tr class="imc-tm-token-row">
                            <td class="imc-tm-icon"><?php echo !empty($token['icon_url']) ? '<img src="'.esc_url($token['icon_url']).'" style="width:20px;height:20px;">' : esc_html($token['icon_emoji'] ?? '🪙'); ?></td>
                            <td class="imc-tm-ticker"><?php echo esc_html($ticker); ?></td>
                            <td><?php echo esc_html($token['display_name'] ?? $ticker); ?></td>
                            <td class="imc-tm-issuer"><?php echo !empty($token['is_native']) ? '<span class="imc-tm-badge imc-tm-badge-native">Native</span>' : esc_html(substr($token['issuer'] ?? '', 0, 6) . '...' . substr($token['issuer'] ?? '', -4)); ?></td>
                            <td>
                                <?php if (!empty($token['enabled_mint'])): ?><span class="imc-tm-badge imc-tm-badge-mint">Mint</span><?php endif; ?>
                                <?php if (!empty($token['enabled_offers'])): ?><span class="imc-tm-badge imc-tm-badge-offers">Offers</span><?php endif; ?>
                                <?php if (empty($token['enabled_mint']) && empty($token['enabled_offers'])): ?><span class="imc-tm-badge imc-tm-badge-disabled">Disabled</span><?php endif; ?>
                            </td>
                            <td class="imc-tm-actions">
                                <a href="?page=imc-token-manager&edit=<?php echo esc_attr($ticker); ?>">✏️</a>
                                <?php if ($ticker !== 'XRP'): ?>
                                <a href="#" onclick="if(confirm('Delete <?php echo esc_js($ticker); ?>?')){document.getElementById('del-<?php echo esc_attr($ticker); ?>').submit();}return false;" style="color:#dc3545;">🗑️</a>
                                <form id="del-<?php echo esc_attr($ticker); ?>" method="post" style="display:none;">
                                    <?php wp_nonce_field('imc_token_action', 'imc_token_nonce'); ?>
                                    <input type="hidden" name="token_action" value="delete">
                                    <input type="hidden" name="delete_ticker" value="<?php echo esc_attr($ticker); ?>">
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="imc-tm-card">
                <h2><?php echo $edit_token ? '✏️ Edit Token' : '➕ Add Token'; ?></h2>
                <form method="post" class="imc-tm-form">
                    <?php wp_nonce_field('imc_token_action', 'imc_token_nonce'); ?>
                    <input type="hidden" name="token_action" value="<?php echo $edit_token ? 'edit' : 'add'; ?>">
                    
                    <div class="form-row">
                        <label>Ticker *</label>
                        <input type="text" name="ticker" value="<?php echo esc_attr($edit_token['ticker'] ?? ''); ?>" placeholder="XFT" maxlength="20" required <?php echo ($edit_token && $edit_token['ticker'] === 'XRP') ? 'readonly' : ''; ?>>
                    </div>
                    <div class="form-row">
                        <label>Display Name *</label>
                        <input type="text" name="display_name" value="<?php echo esc_attr($edit_token['display_name'] ?? ''); ?>" placeholder="XFT Token" required>
                    </div>
                    <div class="form-row">
                        <label>Issuer Address</label>
                        <input type="text" name="issuer" value="<?php echo esc_attr($edit_token['issuer'] ?? ''); ?>" placeholder="rGpno...">
                        <div class="imc-tm-help">XRPL r-address (not required for XRP)</div>
                    </div>
                    <div class="form-row">
                        <label>Icon Emoji</label>
                        <input type="text" name="icon_emoji" value="<?php echo esc_attr($edit_token['icon_emoji'] ?? '🪙'); ?>" placeholder="🪙" maxlength="10">
                    </div>
                    <div class="form-row">
                        <label>Icon URL (optional)</label>
                        <input type="url" name="icon_url" value="<?php echo esc_url($edit_token['icon_url'] ?? ''); ?>" placeholder="https://...">
                    </div>
                    <div class="form-row">
                        <label>Trustline URL</label>
                        <input type="url" name="trustline_url" value="<?php echo esc_url($edit_token['trustline_url'] ?? ''); ?>" placeholder="https://xrpl.services/?issuer=...">
                        <div class="imc-tm-help">Shown when user needs to set trustline</div>
                    </div>
                    <div class="form-row">
                        <label>Decimals</label>
                        <input type="number" name="decimals" value="<?php echo intval($edit_token['decimals'] ?? 8); ?>" min="0" max="15">
                    </div>
                    <div class="form-row">
                        <label>Sort Order</label>
                        <input type="number" name="sort_order" value="<?php echo intval($edit_token['sort_order'] ?? 100); ?>" min="0">
                        <div class="imc-tm-help">Lower = appears first in dropdowns</div>
                    </div>
                    <div class="form-row checkbox-row">
                        <div class="checkbox-item"><input type="checkbox" name="is_native" id="is_native" <?php checked(!empty($edit_token['is_native'])); ?>><label for="is_native">Native (XRP)</label></div>
                        <div class="checkbox-item"><input type="checkbox" name="enabled_mint" id="enabled_mint" <?php checked($edit_token['enabled_mint'] ?? true); ?>><label for="enabled_mint">Minting</label></div>
                        <div class="checkbox-item"><input type="checkbox" name="enabled_offers" id="enabled_offers" <?php checked($edit_token['enabled_offers'] ?? true); ?>><label for="enabled_offers">Offers</label></div>
                    </div>
                    <p>
                        <button type="submit" class="button button-primary"><?php echo $edit_token ? 'Update' : 'Add Token'; ?></button>
                        <?php if ($edit_token): ?><a href="?page=imc-token-manager" class="button">Cancel</a><?php endif; ?>
                    </p>
                </form>
            </div>
        </div>
    </div>
    <?php
}

// ============================================================================
// PUBLIC API FUNCTIONS
// ============================================================================

function imc_get_supported_tokens($context = 'all') {
    $tokens = get_option('imc_supported_tokens', imc_get_default_tokens());
    
    // Filter by context
    if ($context === 'mint') {
        $tokens = array_filter($tokens, function($t) { return !empty($t['enabled_mint']); });
    } elseif ($context === 'offers') {
        $tokens = array_filter($tokens, function($t) { return !empty($t['enabled_offers']); });
    }
    
    // Sort by sort_order
    uasort($tokens, function($a, $b) {
        return ($a['sort_order'] ?? 100) - ($b['sort_order'] ?? 100);
    });
    
    return array_values($tokens);
}

function imc_get_token_by_ticker($ticker) {
    $tokens = get_option('imc_supported_tokens', imc_get_default_tokens());
    $needle = strtoupper(trim((string) $ticker));
    if (isset($tokens[$needle])) return $tokens[$needle]; // fast path (uppercase keys)
    // v607: case-insensitive match by array key OR ticker (handles mixed-case keys
    // like 'Kedas Brew Coin' / 'Toeken' that strtoupper alone never finds).
    foreach ($tokens as $k => $t) {
        if (strtoupper($k) === $needle || strtoupper($t['ticker'] ?? '') === $needle) return $t;
    }
    return null;
}

// ============================================================================
// AJAX ENDPOINTS
// ============================================================================

add_action('wp_ajax_imc_get_tokens', 'imc_ajax_get_tokens');
add_action('wp_ajax_nopriv_imc_get_tokens', 'imc_ajax_get_tokens');

function imc_ajax_get_tokens() {
    $context = sanitize_text_field($_GET['context'] ?? 'all');
    $tokens = imc_get_supported_tokens($context);
    
    $formatted = array_map(function($t) {
        return [
            'ticker' => $t['ticker'] ?? '',
            'currency' => $t['ticker'] ?? '',
            'issuer' => $t['issuer'] ?? '',
            'name' => $t['display_name'] ?? $t['ticker'] ?? '',
            'icon' => $t['icon_emoji'] ?? '🪙',
            'icon_url' => $t['icon_url'] ?? '',
            'trustline_url' => $t['trustline_url'] ?? '',
            'decimals' => intval($t['decimals'] ?? 8),
            'is_native' => !empty($t['is_native'])
        ];
    }, $tokens);
    
    wp_send_json_success(['tokens' => $formatted]);
}

// NOTE: imc_check_trustline AJAX endpoint is already defined in price-oracle.php
// Do NOT redefine it here to avoid "Cannot redeclare" fatal error