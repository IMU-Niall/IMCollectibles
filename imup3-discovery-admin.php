<?php
/**
 * IMUP3 Discovery Sections — admin + storage  (S1e / D-42)
 * File: imup3-discovery-admin.php
 * Path: /wp-content/themes/astra/inc/imup3-discovery-admin.php
 *
 * Add to functions.php beside the other inc/ requires:
 *   require_once get_stylesheet_directory() . '/inc/imup3-discovery-admin.php';
 *
 * WHAT THIS IS
 *   The curation backend for the IMUP3 Discover home screen. Two-level:
 *   SECTIONS (music / album / art / video, each titled, ordered, toggleable)
 *   each holding MEMBERS (collections, by issuer + taxon).
 *
 * WHAT THIS IS NOT
 *   It is NOT imu_featured_collections. That option is IMC's own 5-slot
 *   featured CAROUSEL on the website and is left completely alone.
 *
 * ROTATION
 *   Curation is stable; the SLICE shown rotates DAILY. A section may hold 30
 *   collections and show 6 — which 6 is seeded by the date, so every user sees
 *   the same set on a given day and it changes at midnight UTC. Same
 *   mt_srand(crc32(...)) pattern proven in the v772 collections forever-scroll.
 *
 * READ PATH
 *   imup3-discovery-handler.php (?action=sections) — public, cached, no writes.
 */

if (!defined('ABSPATH')) exit;

const IMUP3_DISCOVERY_OPTION = 'imup3_discovery_sections';

/** The four sections IMUP3 renders, in order. Keys are contract — do not rename. */
function imup3_discovery_default_sections() {
    return [
        ['key' => 'music', 'title' => 'Featured Music',  'type' => 'music', 'show' => 6, 'enabled' => 1, 'members' => []],
        ['key' => 'album', 'title' => 'Featured Albums', 'type' => 'album', 'show' => 6, 'enabled' => 1, 'members' => []],
        ['key' => 'art',   'title' => 'Featured Art',    'type' => 'art',   'show' => 6, 'enabled' => 1, 'members' => []],
        ['key' => 'video', 'title' => 'Featured Video',  'type' => 'video', 'show' => 6, 'enabled' => 1, 'members' => []],
        // P1e (D-60/D-61): two sections the STORE cannot filter, curated here instead.
        //
        // ★ This works because the handler resolves members from issuer+taxon stored
        //   in WordPress - `type` is only the View All target, not a query. So a
        //   section for a concept the indexer knows nothing about behaves exactly
        //   like the four above, and needs NO VPS work.
        //
        // GAMING = NFTs usable in-game. There is no ledger-side signal for this, so
        //   membership is curated at COLLECTION level. NOT Champions of Frequencies,
        //   which is a separate product.
        // ACCESS = every NFT minted on IMC - all are master-protected. Membership is
        //   a fact about ORIGIN, not a curation, so the eventual View All target is
        //   wp_imc_collections (already served by collections-api-handler's
        //   list_all). Access NFTs ALSO appear in music/album/art/video - this is a
        //   dedicated view of the same NFTs, not an exclusive category.
        //
        // ⚠ THE ARRAY ORDER IS THE ON-SCREEN ORDER, and it matches DiscoverScreen's
        //   PLACEHOLDER_SECTIONS: music, album, video, art, gaming, access.
        ['key' => 'gaming', 'title' => 'Featured Gaming', 'type' => 'gaming', 'show' => 6, 'enabled' => 1, 'members' => []],
        ['key' => 'access', 'title' => 'Access NFTs',     'type' => 'access', 'show' => 6, 'enabled' => 1, 'members' => []],
        // UX-B (3 Sep 2026): the Discover HERO. Rendered by the app as a carousel
        //   (hoisted by KEY, so its position here does not matter). show=5 and
        //   <=5 curated members => the daily rotation never runs = editorial.
        //   `type` is only the View All target (D-61), served by the paged
        //   section endpoint. Empty until curated -> the app hides it (B-7).
        ['key' => 'new_to_xrpl', 'title' => 'New to the XRPL', 'type' => 'new_to_xrpl', 'show' => 5, 'enabled' => 1, 'members' => []],
    ];
}

function imup3_discovery_get_sections() {
    $stored = get_option(IMUP3_DISCOVERY_OPTION, null);
    if (!is_array($stored) || empty($stored)) return imup3_discovery_default_sections();

    // Merge stored over defaults by key, so a new default section appears
    // automatically without wiping curation that already exists.
    $byKey = [];
    foreach ($stored as $s) {
        if (!empty($s['key'])) $byKey[$s['key']] = $s;
    }
    $out = [];
    foreach (imup3_discovery_default_sections() as $def) {
        $out[] = isset($byKey[$def['key']]) ? array_merge($def, $byKey[$def['key']]) : $def;
    }
    return $out;
}

// ============================================================================
// ADVERTISING BANNER (UX tune 3)
//
// NOT a discovery section: a slide is an UPLOADED IMAGE plus a destination LINK,
// so it carries no issuer/taxon, never touches the store, and can never be
// dropped by the indexer the way a curated collection can.
//
// Stored as a flat list; served by /app-ads; rendered by the app between
// Featured Albums and Featured Art. Empty list => the band does not render.
// ============================================================================
const IMUP3_ADS_OPTION = 'imup3_app_ads';
const IMUP3_ADS_SLOTS  = 5;

function imup3_ads_get() {
    $rows = get_option(IMUP3_ADS_OPTION, []);
    if (!is_array($rows)) return [];
    $out = [];
    foreach ($rows as $r) {
        $image = esc_url_raw((string) ($r['image'] ?? ''));
        if ($image === '') continue;                 // no image => not a slide
        if (empty($r['enabled'])) continue;
        $out[] = [
            'image' => $image,
            'link'  => esc_url_raw((string) ($r['link'] ?? '')),
            'title' => sanitize_text_field((string) ($r['title'] ?? '')),
        ];
    }
    return $out;
}

// ============================================================================
// ADMIN MENU  — its own top-level item; IMU Marketing is left untouched.
// ============================================================================
add_action('admin_menu', 'imup3_discovery_admin_menu');
function imup3_discovery_admin_menu() {
    add_menu_page(
        'IMUP3 Discovery',
        'IMUP3 Discovery',
        'manage_options',
        'imup3-discovery',
        'imup3_discovery_admin_page',
        'dashicons-playlist-audio',
        31
    );
}

// ============================================================================
// ADMIN PAGE
// ============================================================================
function imup3_discovery_admin_page() {
    wp_enqueue_media();   // UX tune 3: the banner slots use the WP media picker
    if (isset($_POST['imup3_discovery_submit']) && check_admin_referer('imup3_discovery_save', 'imup3_discovery_nonce')) {
        $sections = [];
        foreach (imup3_discovery_default_sections() as $def) {
            $k   = $def['key'];
            $raw = $_POST['sections'][$k] ?? [];

            // Members arrive as one "issuer:taxon" per line — the same shape the
            // collection cards already use everywhere else in the ecosystem.
            $members = [];
            $lines = preg_split('/\r\n|\r|\n/', (string) ($raw['members'] ?? ''));
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $parts  = explode(':', $line);
                $issuer = trim($parts[0] ?? '');
                $taxon  = isset($parts[1]) ? absint(trim($parts[1])) : 0;
                if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/', $issuer)) continue; // silently drop malformed
                $members[] = ['issuer' => $issuer, 'taxon' => $taxon];
            }

            // P2-C: THE SECOND LIST. Same parser, same validation, parsed inline so
            // the two cannot drift apart.
            //
            //   members      the LADDER  - a handful, rotated daily on the front page
            //   all_members  VIEW ALL    - the full catalogue for that concept
            //
            // ★ Gaming and album need this because the STORE CANNOT FILTER for them
            //   (D-61). Album is curated rather than pulled from wp_imc_listings
            //   because an album minted on ANOTHER marketplace is still an album,
            //   and IMC's listings cannot see it.
            // ⚠ all_members WILL grow past 100 - the app pages it via
            //   ?key=<section>&offset=&limit=, never in one request.
            $all_members = [];
            $all_lines = preg_split('/\r\n|\r|\n/', (string) ($raw['all_members'] ?? ''));
            foreach ($all_lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $parts  = explode(':', $line);
                $issuer = trim($parts[0] ?? '');
                $taxon  = isset($parts[1]) ? absint(trim($parts[1])) : 0;
                if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/', $issuer)) continue;
                $all_members[] = ['issuer' => $issuer, 'taxon' => $taxon];
            }

            $sections[] = [
                'key'     => $k,
                'title'   => sanitize_text_field(wp_unslash($raw['title'] ?? $def['title'])),
                'type'    => $def['type'],                      // fixed — the app filters on it
                'show'    => min(12, max(1, absint($raw['show'] ?? $def['show']))),
                'enabled' => empty($raw['enabled']) ? 0 : 1,
                'members'     => $members,
                'all_members' => $all_members,
            ];
        }
        update_option(IMUP3_DISCOVERY_OPTION, $sections);

        // UX tune 3 - the advertising slides travel with the same form + nonce.
        $ads = [];
        for ($i = 0; $i < IMUP3_ADS_SLOTS; $i++) {
            $raw   = $_POST['ads'][$i] ?? [];
            $image = esc_url_raw(trim((string) ($raw['image'] ?? '')));
            if ($image === '') continue;
            $ads[] = [
                'image'   => $image,
                'link'    => esc_url_raw(trim((string) ($raw['link'] ?? ''))),
                'title'   => sanitize_text_field((string) ($raw['title'] ?? '')),
                'enabled' => !empty($raw['enabled']) ? 1 : 0,
            ];
        }
        update_option(IMUP3_ADS_OPTION, $ads);
        echo '<div class="notice notice-success is-dismissible"><p>IMUP3 Discovery sections saved.</p></div>';
    }

    $sections = imup3_discovery_get_sections();
    ?>
    <div class="wrap">
        <h1>IMUP3 Discovery Sections</h1>
        <p style="max-width:820px">
            Curates the <strong>IMUP3 Discover home screen</strong>. Each section renders as a row with a
            <em>View All</em> button. Add as many collections as you like — the app shows the number set in
            <strong>Show</strong>, and <strong>which ones rotate daily</strong>, so a well-stocked section stays
            fresh without further work.
        </p>
        <p style="max-width:820px">
            One collection per line, as <code>issuer:taxon</code> — for example
            <code>rKDFM3xaC3B7ijWkX4iHcMTcLFgxW2dK74:12345</code>. Taxon may be omitted for 0.
            Malformed lines are dropped on save.
        </p>
        <p style="max-width:820px"><em>This is separate from IMU Marketing &rsaquo; Featured Collections,
            which is the IMC website carousel and is not affected.</em></p>

        <form method="post">
            <?php wp_nonce_field('imup3_discovery_save', 'imup3_discovery_nonce'); ?>
            <?php foreach ($sections as $s): $k = esc_attr($s['key']); ?>
                <div style="background:#fff;border:1px solid #ccd0d4;padding:16px 20px;margin:18px 0;max-width:820px">
                    <h2 style="margin-top:0"><?php echo esc_html(strtoupper($s['key'])); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="t-<?php echo $k; ?>">Row title</label></th>
                            <td><input type="text" id="t-<?php echo $k; ?>" class="regular-text"
                                       name="sections[<?php echo $k; ?>][title]"
                                       value="<?php echo esc_attr($s['title']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="s-<?php echo $k; ?>">Show per day</label></th>
                            <td><input type="number" id="s-<?php echo $k; ?>" min="1" max="12" class="small-text"
                                       name="sections[<?php echo $k; ?>][show]"
                                       value="<?php echo esc_attr($s['show']); ?>">
                                <p class="description">How many of the collections below appear each day.</p></td>
                        </tr>
                        <tr>
                            <th scope="row">Enabled</th>
                            <td><label><input type="checkbox" name="sections[<?php echo $k; ?>][enabled]" value="1"
                                       <?php checked(!empty($s['enabled'])); ?>> Show this row in IMUP3</label></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="m-<?php echo $k; ?>">Featured (ladder)</label></th>
                            <td>
                                <textarea id="m-<?php echo $k; ?>" rows="8" class="large-text code"
                                          name="sections[<?php echo $k; ?>][members]"
                                          placeholder="rIssuerAddress:taxon"><?php
                                    $lines = [];
                                    foreach ((array) $s['members'] as $m) {
                                        $lines[] = $m['issuer'] . ':' . (int) $m['taxon'];
                                    }
                                    echo esc_textarea(implode("\n", $lines));
                                ?></textarea>
                                <p class="description"><?php echo count((array) $s['members']); ?> collection(s) in the daily rotation.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="am-<?php echo $k; ?>">View All (full list)</label></th>
                            <td>
                                <textarea id="am-<?php echo $k; ?>" rows="10" class="large-text code"
                                          name="sections[<?php echo $k; ?>][all_members]"
                                          placeholder="rIssuerAddress:taxon"><?php
                                    $alines = [];
                                    foreach ((array) ($s['all_members'] ?? []) as $m) {
                                        $alines[] = $m['issuer'] . ':' . (int) $m['taxon'];
                                    }
                                    echo esc_textarea(implode("\n", $alines));
                                ?></textarea>
                                <p class="description">
                                    <?php echo count((array) ($s['all_members'] ?? [])); ?> collection(s) in the full list.
                                    Shown when a user taps <strong>View all</strong>. Leave empty to fall back to the
                                    rotation list above. The app pages this, so it may grow to any size.
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            <?php endforeach; ?>
            <div class="card" style="max-width:900px;margin-top:24px;padding:12px 16px">
                <h2 style="margin-top:0">ADVERTISING BANNER</h2>
                <p class="description" style="margin-bottom:12px">
                    Shown on Discover between <strong>Featured Albums</strong> and <strong>Featured Art</strong>.
                    Upload an image and give it a destination link. Wide artwork works best (about 16:6).
                    Leave the image blank to retire a slot. Keep buy / mint calls to action out of the
                    artwork &mdash; the app is a viewer, not a store.
                </p>
                <table class="form-table" role="presentation">
                    <?php
                    $ads_saved = get_option(IMUP3_ADS_OPTION, []);
                    if (!is_array($ads_saved)) $ads_saved = [];
                    for ($i = 0; $i < IMUP3_ADS_SLOTS; $i++):
                        $a = $ads_saved[$i] ?? ['image' => '', 'link' => '', 'title' => '', 'enabled' => 1];
                    ?>
                    <tr>
                        <th scope="row">Slide <?php echo (int) ($i + 1); ?></th>
                        <td>
                            <p>
                                <input type="text" class="large-text imup3-ad-image" id="ad-img-<?php echo (int) $i; ?>"
                                       name="ads[<?php echo (int) $i; ?>][image]" placeholder="Image URL"
                                       value="<?php echo esc_attr($a['image'] ?? ''); ?>">
                                <button type="button" class="button imup3-ad-upload"
                                        data-target="ad-img-<?php echo (int) $i; ?>">Upload / choose image</button>
                            </p>
                            <p>
                                <input type="text" class="large-text" name="ads[<?php echo (int) $i; ?>][link]"
                                       placeholder="Destination link (https://...)"
                                       value="<?php echo esc_attr($a['link'] ?? ''); ?>">
                            </p>
                            <p>
                                <input type="text" class="regular-text" name="ads[<?php echo (int) $i; ?>][title]"
                                       placeholder="Title (accessibility only, not shown)"
                                       value="<?php echo esc_attr($a['title'] ?? ''); ?>">
                                <label style="margin-left:12px">
                                    <input type="checkbox" name="ads[<?php echo (int) $i; ?>][enabled]" value="1"
                                        <?php checked(!empty($a['enabled'])); ?>> Enabled
                                </label>
                            </p>
                            <?php if (!empty($a['image'])): ?>
                                <img src="<?php echo esc_url($a['image']); ?>" alt=""
                                     style="max-width:320px;height:auto;border:1px solid #ccd0d4">
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endfor; ?>
                </table>
            </div>

            <script>
            jQuery(function ($) {
                $('.imup3-ad-upload').on('click', function (e) {
                    e.preventDefault();
                    var targetId = $(this).data('target');
                    var frame = wp.media({ title: 'Choose banner image', multiple: false,
                                           library: { type: 'image' },
                                           button: { text: 'Use this image' } });
                    frame.on('select', function () {
                        var url = frame.state().get('selection').first().toJSON().url;
                        $('#' + targetId).val(url);
                    });
                    frame.open();
                });
            });
            </script>

            <?php submit_button('Save Discovery Sections', 'primary', 'imup3_discovery_submit'); ?>
        </form>
    </div>
    <?php
}