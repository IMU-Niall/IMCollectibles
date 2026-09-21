<?php
/**
 * Template: Marketplace Advertisements Admin
 * Path: /public_html/wp-content/themes/astra/admin-advertisements.php
 * Description: Admin interface for selecting marketplace banners and collection images from Media Library
 */

if (!current_user_can('manage_options')) {
    wp_die('Unauthorized access');
}

$nonce = wp_create_nonce('xrpl_advertisement_nonce');
?>

<div class="wrap">
    <h1>Marketplace Advertisements</h1>
    <div id="advertisement-admin">
        <section class="advertisement-section">
            <h2>Select Assets from Media Library</h2>
            <form id="asset-select-form">
                <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
                <div class="asset-select-row">
                    <label for="banner-image-id">Marketplace Banner (1200x200px recommended)</label>
                    <input type="hidden" id="banner-image-id" name="banner_image_id">
                    <img id="banner-image-preview" src="" alt="Banner Preview" style="display: none; max-width: 200px;">
                    <button type="button" class="button select-media" data-target="banner-image-id">Select Banner</button>
                </div>
                <div class="asset-select-row">
                    <label for="guardians-image-id">Guardians Cover (400x400px recommended)</label>
                    <input type="hidden" id="guardians-image-id" name="collection_image_id[guardians]">
                    <img id="guardians-image-preview" src="" alt="Guardians Preview" style="display: none; max-width: 100px;">
                    <button type="button" class="button select-media" data-target="guardians-image-id">Select Guardians Cover</button>
                </div>
                <div class="asset-select-row">
                    <label for="frequencies-image-id">Frequencies Cover (400x400px recommended)</label>
                    <input type="hidden" id="frequencies-image-id" name="collection_image_id[frequencies]">
                    <img id="frequencies-image-preview" src="" alt="Frequencies Preview" style="display: none; max-width: 100px;">
                    <button type="button" class="button select-media" data-target="frequencies-image-id">Select Frequencies Cover</button>
                </div>
                <div class="asset-select-row">
                    <label for="ledger-image-id">Ledger Cover (400x400px recommended)</label>
                    <input type="hidden" id="ledger-image-id" name="collection_image_id[ledger]">
                    <img id="ledger-image-preview" src="" alt="Ledger Preview" style="display: none; max-width: 100px;">
                    <button type="button" class="button select-media" data-target="ledger-image-id">Select Ledger Cover</button>
                </div>
                <div class="asset-select-row">
                    <label for="lasvegas-image-id">Las Vegas Cover (400x400px recommended)</label>
                    <input type="hidden" id="lasvegas-image-id" name="collection_image_id[lasvegas]">
                    <img id="lasvegas-image-preview" src="" alt="Las Vegas Preview" style="display: none; max-width: 100px;">
                    <button type="button" class="button select-media" data-target="lasvegas-image-id">Select Las Vegas Cover</button>
                </div>
                <button type="submit" class="button button-primary">Save Assets</button>
            </form>
            <div id="asset-list">
                <!-- Populated via JavaScript -->
            </div>
        </section>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const selectForm = document.getElementById('asset-select-form');
    const assetList = document.getElementById('asset-list');

    // Initialize WordPress Media Library
    const mediaFrames = {};

    document.querySelectorAll('.select-media').forEach(button => {
        button.addEventListener('click', () => {
            const targetId = button.dataset.target;
            if (!mediaFrames[targetId]) {
                mediaFrames[targetId] = wp.media({
                    title: 'Select Image',
                    button: { text: 'Use Image' },
                    multiple: false,
                    library: { type: 'image' }
                });
                mediaFrames[targetId].on('select', () => {
                    const attachment = mediaFrames[targetId].state().get('selection').first().toJSON();
                    const input = document.getElementById(targetId);
                    const preview = document.getElementById(`${targetId}-preview`);
                    if (input && preview) {
                        input.value = attachment.id;
                        preview.src = attachment.url;
                        preview.style.display = 'block';
                    }
                });
            }
            mediaFrames[targetId].open();
        });
    });

    // Load existing assets
    async function loadAssets() {
        try {
            const res = await fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'xrpl_list_assets',
                    nonce: '<?php echo esc_attr($nonce); ?>'
                })
            });
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}: ${res.statusText}`);
            }
            const text = await res.text();
            try {
                const data = JSON.parse(text);
                if (data.success) {
                    assetList.innerHTML = [
                        data.banner ? `<div class="asset-item"><img src="${data.banner.url}" alt="Banner"><p>Banner</p><button class="delete-asset" data-id="${data.banner.id}" data-type="banner">Delete</button></div>` : '',
                        ...Object.entries(data.collections || {}).map(([slug, { url, id }]) => `
                            <div class="asset-item">
                                <img src="${url}" alt="${slug}">
                                <p>${slug.charAt(0).toUpperCase() + slug.slice(1)}</p>
                                <button class="delete-asset" data-id="${id}" data-type="collection" data-slug="${slug}">Delete</button>
                            </div>
                        `)
                    ].join('');
                    // Update previews
                    if (data.banner) {
                        const bannerPreview = document.getElementById('banner-image-preview');
                        const bannerInput = document.getElementById('banner-image-id');
                        if (bannerPreview && bannerInput) {
                            bannerPreview.src = data.banner.url;
                            bannerPreview.style.display = 'block';
                            bannerInput.value = data.banner.id;
                        }
                    }
                    Object.entries(data.collections || []).forEach(([slug, { url, id }]) => {
                        const preview = document.getElementById(`${slug}-image-preview`);
                        const input = document.getElementById(`${slug}-image-id`);
                        if (preview && input) {
                            preview.src = url;
                            preview.style.display = 'block';
                            input.value = id;
                        }
                    });
                } else {
                    console.warn('Load assets error:', data.error);
                    assetList.innerHTML = '<p>No assets selected.</p>';
                }
            } catch (e) {
                console.error('JSON parse error:', e, 'Response text:', text);
                throw new Error('Invalid JSON response');
            }
        } catch (err) {
            console.error('Error loading assets:', err);
            assetList.innerHTML = '<p>Failed to load assets. Check server logs.</p>';
        }
    }

    // Handle asset selection save
    selectForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(selectForm);
        formData.append('action', 'xrpl_save_assets');
        try {
            const res = await fetch(ajaxurl, {
                method: 'POST',
                body: formData
            });
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}: ${res.statusText}`);
            }
            const text = await res.text();
            try {
                const data = JSON.parse(text);
                if (data.success) {
                    alert('Assets saved successfully.');
                    loadAssets();
                } else {
                    alert(data.error || 'Failed to save assets.');
                }
            } catch (e) {
                console.error('JSON parse error:', e, 'Response text:', text);
                throw new Error('Invalid JSON response');
            }
        } catch (err) {
            console.error('Asset save error:', err);
            alert('Save failed. Check console for details.');
        }
    });

    // Handle asset deletion
    assetList.addEventListener('click', async (e) => {
        if (e.target.classList.contains('delete-asset')) {
            const id = e.target.dataset.id;
            const type = e.target.dataset.type;
            const slug = e.target.dataset.slug || '';
            if (confirm(`Delete ${type}${slug ? ` (${slug})` : ''}?`)) {
                try {
                    const res = await fetch(ajaxurl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'xrpl_delete_asset',
                            id,
                            type,
                            slug,
                            nonce: '<?php echo esc_attr($nonce); ?>'
                        })
                    });
                    if (!res.ok) {
                        throw new Error(`HTTP ${res.status}: ${res.statusText}`);
                    }
                    const text = await res.text();
                    try {
                        const data = JSON.parse(text);
                        if (data.success) {
                            alert('Asset deleted successfully.');
                            loadAssets();
                        } else {
                            alert(data.error || 'Failed to delete asset.');
                        }
                    } catch (e) {
                        console.error('JSON parse error:', e, 'Response text:', text);
                        throw new Error('Invalid JSON response');
                    }
                } catch (err) {
                    console.error('Asset deletion error:', err);
                    alert('Deletion failed. Check console for details.');
                }
            }
        }
    });

    loadAssets();
});
</script>

<?php
// Enqueue WordPress Media Library scripts
wp_enqueue_media();
wp_enqueue_script('jquery');
?>