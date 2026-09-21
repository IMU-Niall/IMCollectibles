<?php
/**
 * IMU Marketing Admin System
 * File: marketing-admin.php
 * Path: /wp-content/themes/astra/inc/marketing-admin.php
 * 
 * Add to functions.php:
 * require_once get_stylesheet_directory() . '/inc/marketing-admin.php';
 */

if (!defined('ABSPATH')) exit;

// ============================================================================
// ADMIN MENU
// ============================================================================
add_action('admin_menu', 'imu_marketing_admin_menu');

function imu_marketing_admin_menu() {
    add_menu_page(
        'IMU Marketing',
        'IMU Marketing',
        'manage_options',
        'imu-marketing',
        'imu_marketing_banners_page',
        'dashicons-megaphone',
        30
    );
    
    add_submenu_page('imu-marketing', 'Banners', 'Banners', 'manage_options', 'imu-marketing', 'imu_marketing_banners_page');
    add_submenu_page('imu-marketing', 'Featured Collections', 'Featured Collections', 'manage_options', 'imu-featured', 'imu_featured_collections_page');
}

add_action('admin_enqueue_scripts', 'imu_marketing_admin_scripts');

function imu_marketing_admin_scripts($hook) {
    if (!in_array($hook, ['toplevel_page_imu-marketing', 'imu-marketing_page_imu-featured'])) return;
    wp_enqueue_media();
}

// ============================================================================
// BANNERS PAGE
// ============================================================================
function imu_marketing_banners_page() {
    if (isset($_POST['imu_banners_submit']) && check_admin_referer('imu_banners_save', 'imu_banners_nonce')) {
        $banners = [];
        for ($i = 0; $i < 14; $i++) {
            $banners[$i] = [
                'image_url' => esc_url_raw($_POST['banners'][$i]['image_url'] ?? ''),
                'link' => esc_url_raw($_POST['banners'][$i]['link'] ?? ''),
                'alt' => sanitize_text_field($_POST['banners'][$i]['alt'] ?? '')
            ];
        }
        update_option('imu_marketing_banners', $banners);
        update_option('imu_banner_reset_hour', min(23, max(0, absint($_POST['banner_reset_hour'] ?? 0))));
        echo '<div class="notice notice-success is-dismissible"><p>Banners saved!</p></div>';
    }
    
    $banners = get_option('imu_marketing_banners', array_fill(0, 14, ['image_url' => '', 'link' => '', 'alt' => '']));
    $reset_hour = get_option('imu_banner_reset_hour', 0);
    $current_index = imu_get_current_banner_index();
    ?>
    <div class="wrap">
        <h1>📢 Marketing Banners</h1>
        <div style="background:#e7f3ff;border:1px solid #72aee6;padding:15px;border-radius:4px;margin:20px 0;">
            <strong>Current Banner:</strong> #<?php echo $current_index + 1; ?> | 
            <strong>Reset Hour:</strong> <?php echo sprintf('%02d:00 UTC', $reset_hour); ?>
        </div>
        
        <form method="post">
            <?php wp_nonce_field('imu_banners_save', 'imu_banners_nonce'); ?>
            
            <table class="form-table">
                <tr>
                    <th>Daily Reset Hour (UTC)</th>
                    <td>
                        <select name="banner_reset_hour">
                            <?php for ($h = 0; $h < 24; $h++): ?>
                            <option value="<?php echo $h; ?>" <?php selected($reset_hour, $h); ?>><?php echo sprintf('%02d:00', $h); ?></option>
                            <?php endfor; ?>
                        </select>
                    </td>
                </tr>
            </table>
            
            <h2>Banner Slots (14 Total) - Recommended: 1400×400px</h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(350px,1fr));gap:20px;margin:20px 0;">
                <?php for ($i = 0; $i < 14; $i++): 
                    $b = $banners[$i] ?? ['image_url' => '', 'link' => '', 'alt' => ''];
                    $is_current = ($i === $current_index);
                ?>
                <div style="background:#fff;border:1px solid <?php echo $is_current ? '#2271b1' : '#c3c4c7'; ?>;padding:15px;border-radius:4px;<?php echo $is_current ? 'box-shadow:0 0 0 1px #2271b1;' : ''; ?>">
                    <div style="display:flex;justify-content:space-between;margin-bottom:10px;">
                        <strong>Banner #<?php echo $i + 1; ?></strong>
                        <?php if ($is_current): ?><span style="background:#00a32a;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;">LIVE</span><?php endif; ?>
                    </div>
                    <div style="height:100px;background:#f0f0f1;margin-bottom:10px;display:flex;align-items:center;justify-content:center;border-radius:4px;overflow:hidden;" id="preview-<?php echo $i; ?>">
                        <?php if ($b['image_url']): ?>
                        <img src="<?php echo esc_url($b['image_url']); ?>" style="max-width:100%;max-height:100%;">
                        <?php else: ?>
                        <span style="color:#8c8f94;">No image</span>
                        <?php endif; ?>
                    </div>
                    <p><label>Image URL</label><br>
                    <input type="text" name="banners[<?php echo $i; ?>][image_url]" id="banner-url-<?php echo $i; ?>" value="<?php echo esc_attr($b['image_url']); ?>" class="regular-text">
                    <button type="button" class="button" onclick="selectBannerImage(<?php echo $i; ?>)">Select</button></p>
                    <p><label>Link URL</label><br>
                    <input type="text" name="banners[<?php echo $i; ?>][link]" value="<?php echo esc_attr($b['link']); ?>" class="regular-text"></p>
                    <p><label>Alt Text</label><br>
                    <input type="text" name="banners[<?php echo $i; ?>][alt]" value="<?php echo esc_attr($b['alt']); ?>" class="regular-text"></p>
                </div>
                <?php endfor; ?>
            </div>
            <p><input type="submit" name="imu_banners_submit" class="button button-primary button-large" value="Save All Banners"></p>
        </form>
    </div>
    <script>
    function selectBannerImage(i) {
        var frame = wp.media({title:'Select Banner',button:{text:'Use this'},multiple:false,library:{type:'image'}});
        frame.on('select',function(){
            var a = frame.state().get('selection').first().toJSON();
            document.getElementById('banner-url-'+i).value = a.url;
            document.getElementById('preview-'+i).innerHTML = '<img src="'+a.url+'" style="max-width:100%;max-height:100%;">';
        });
        frame.open();
    }
    </script>
    <?php
}

// ============================================================================
// FEATURED COLLECTIONS PAGE
// ============================================================================
function imu_featured_collections_page() {
    if (isset($_POST['imu_featured_submit']) && check_admin_referer('imu_featured_save', 'imu_featured_nonce')) {
        $featured = [];
        for ($i = 0; $i < 5; $i++) {
            $featured[$i] = [
                'issuer' => sanitize_text_field($_POST['featured'][$i]['issuer'] ?? ''),
                'taxon' => absint($_POST['featured'][$i]['taxon'] ?? 0),
                'name' => sanitize_text_field(wp_unslash($_POST['featured'][$i]['name'] ?? '')),
                'image' => esc_url_raw($_POST['featured'][$i]['image'] ?? ''),
                'link_url' => esc_url_raw($_POST['featured'][$i]['link_url'] ?? ''),
            ];
        }
        update_option('imu_featured_collections', $featured);
        echo '<div class="notice notice-success is-dismissible"><p>Featured collections saved!</p></div>';
    }
    
    $featured = get_option('imu_featured_collections', array_fill(0, 5, ['issuer' => '', 'taxon' => 0, 'name' => '', 'image' => '']));
    ?>
    <div class="wrap">
        <h1>⭐ Featured Collections</h1>
        <p>Configure 5 collections to feature on the Trading Hub homepage.</p>
        
        <form method="post">
            <?php wp_nonce_field('imu_featured_save', 'imu_featured_nonce'); ?>
            
            <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:15px;margin:20px 0;">
                <?php for ($i = 0; $i < 5; $i++): 
                    $f = $featured[$i] ?? ['issuer' => '', 'taxon' => 0, 'name' => '', 'image' => ''];
                ?>
                <div style="background:#fff;border:1px solid #c3c4c7;padding:15px;border-radius:4px;">
                    <h3 style="margin:0 0 10px;font-size:14px;">Featured #<?php echo $i + 1; ?></h3>
                    <div style="aspect-ratio:1;background:#f0f0f1;margin-bottom:10px;display:flex;align-items:center;justify-content:center;border-radius:4px;overflow:hidden;" id="col-preview-<?php echo $i; ?>">
                        <?php if ($f['image']): ?>
                        <img src="<?php echo esc_url($f['image']); ?>" style="width:100%;height:100%;object-fit:cover;">
                        <?php else: ?>
                        <span style="color:#8c8f94;font-size:12px;">No image</span>
                        <?php endif; ?>
                    </div>
                    <p><label>Name</label><br><input type="text" name="featured[<?php echo $i; ?>][name]" value="<?php echo esc_attr(stripslashes($f['name'])); ?>" style="width:100%;"></p>
                    <p><label>Issuer</label><br><input type="text" name="featured[<?php echo $i; ?>][issuer]" value="<?php echo esc_attr($f['issuer']); ?>" style="width:100%;font-size:11px;"></p>
                    <p><label>Taxon</label><br><input type="number" name="featured[<?php echo $i; ?>][taxon]" value="<?php echo esc_attr($f['taxon']); ?>" style="width:100%;"></p>
                    <p><label>Image</label><br>
                    <input type="text" name="featured[<?php echo $i; ?>][image]" id="col-url-<?php echo $i; ?>" value="<?php echo esc_attr($f['image']); ?>" style="width:100%;">
                    <button type="button" class="button" onclick="selectColImage(<?php echo $i; ?>)">Select</button></p>
                    <p><label>Custom Link URL <small style="color:#8c8f94;">(optional — overrides default collection page)</small></label><br>
                    <input type="url" name="featured[<?php echo $i; ?>][link_url]" value="<?php echo esc_attr($f['link_url'] ?? ''); ?>" style="width:100%;" placeholder="e.g. https://mint.imcollectibles.io/potf/mint"></p>
                </div>
                <?php endfor; ?>
            </div>
            <p><input type="submit" name="imu_featured_submit" class="button button-primary button-large" value="Save Featured Collections"></p>
        </form>
    </div>
    <script>
    function selectColImage(i) {
        var frame = wp.media({title:'Select Image',button:{text:'Use this'},multiple:false,library:{type:'image'}});
        frame.on('select',function(){
            var a = frame.state().get('selection').first().toJSON();
            document.getElementById('col-url-'+i).value = a.url;
            document.getElementById('col-preview-'+i).innerHTML = '<img src="'+a.url+'" style="width:100%;height:100%;object-fit:cover;">';
        });
        frame.open();
    }
    </script>
    <?php
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

function imu_get_days_since_epoch() {
    $reset_hour = get_option('imu_banner_reset_hour', 0);
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $epoch = new DateTime('2024-01-01 00:00:00', new DateTimeZone('UTC'));
    $days = $epoch->diff($now)->days;
    if ((int)$now->format('G') < $reset_hour) $days--;
    return max(0, $days);
}

function imu_get_current_banner_index() {
    return imu_get_days_since_epoch() % 14;
}

function imu_get_current_banner() {
    $banners = get_option('imu_marketing_banners', []);
    $index = imu_get_current_banner_index();
    
    if (isset($banners[$index]) && !empty($banners[$index]['image_url'])) {
        return [
            'image_url' => $banners[$index]['image_url'],
            'link' => $banners[$index]['link'] ?? '',
            'alt' => $banners[$index]['alt'] ?? 'Marketing Banner',
            'index' => $index
        ];
    }
    
    // Fallback: find any banner with an image
    foreach ($banners as $i => $b) {
        if (!empty($b['image_url'])) {
            return ['image_url' => $b['image_url'], 'link' => $b['link'] ?? '', 'alt' => $b['alt'] ?? '', 'index' => $i];
        }
    }
    return null;
}

function imu_get_featured_collections() {
    $featured = get_option('imu_featured_collections', []);
    $collections = [];
    foreach ($featured as $f) {
        // Only require issuer - use fallback image if none set
        if (!empty($f['issuer'])) {
            $collections[] = [
                'issuer' => $f['issuer'],
                'taxon' => (int)($f['taxon'] ?? 0),
                'name' => stripslashes($f['name'] ?? '') ?: 'Collection',
                'image' => $f['image'] ?: '/wp-content/uploads/fallback-nft.svg',
                'link_url' => $f['link_url'] ?? '',
            ];
        }
    }
    return $collections;
}