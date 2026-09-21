<?php
/**
 * ============================================================================
 * TEMPLATE: page-artists.php  —  Creators directory  (/artists)
 * PATH: theme root (astra-child)
 * ============================================================================
 * Loaded by the artist_dir route in functions.php (Phase 4).
 * Lists artists that have set up a profile, each linking to /artists/{slug}.
 * Read-only & public.
 * ============================================================================
 */
if (!defined('ABSPATH')) { exit; }

global $wpdb;
$prof_table = $wpdb->prefix . 'imc_artist_profiles';
$artists = [];
$counts  = [];
if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $prof_table)) === $prof_table) {
    $artists = $wpdb->get_results(
        "SELECT artist_account, display_name, slug, profile_image_url
         FROM {$prof_table}
         WHERE display_name <> '' AND slug <> ''
         ORDER BY display_name ASC",
        ARRAY_A
    );
    // One grouped query for minted counts (avoids per-row lookups).
    $pt = $wpdb->prefix . 'imc_purchases';
    $rows = $wpdb->get_results(
        "SELECT artist_account, COUNT(*) AS n
         FROM {$pt}
         WHERE mint_status IN ('minted','claimed')
         GROUP BY artist_account",
        ARRAY_A
    );
    foreach (($rows ?: []) as $r) { $counts[$r['artist_account']] = (int) $r['n']; }
}

// v625-3b: verified-first ordering (stable partition). Source of truth = imc_verified_creators option.
if (!empty($artists) && function_exists('imc_is_verified_creator')) {
    // v636 Task1: 3-tier stable sort — verified (human) first, verified AI next, then all others.
    $__v = []; $__ai = []; $__u = [];
    foreach ($artists as $__a) {
        $__acct = $__a['artist_account'] ?? '';
        if (imc_is_verified_creator($__acct)) { $__v[] = $__a; }
        elseif (function_exists('imc_is_verified_ai_creator') && imc_is_verified_ai_creator($__acct)) { $__ai[] = $__a; }
        else { $__u[] = $__a; }
    }
    $artists = array_merge($__v, $__ai, $__u);
}

$default_pfp = 'data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22120%22 height=%22120%22><rect width=%22120%22 height=%22120%22 fill=%22%231a1a2e%22/><text x=%2260%22 y=%2278%22 font-size=%2250%22 fill=%22%23d4af37%22 text-anchor=%22middle%22>?</text></svg>';

get_header();
?>
<style>
body.has-imp-header{background:radial-gradient(1000px 520px at 50% -8%,rgba(212,175,55,.06),transparent 72%),linear-gradient(160deg,#08080e 0%,#0e0e16 55%,#141420 100%) !important;background-attachment:fixed !important;background-repeat:no-repeat !important;}
.imc-artists-page{max-width:1100px;margin:0 auto;padding:34px 18px 64px;color:#e8e8e8;}
.imc-artists-head{margin-bottom:28px;text-align:center;}
.imc-artists-head h1{margin:0;font-size:2rem;background:linear-gradient(180deg,#ffe066 0%,var(--imu-gold, #d6ba66) 40%,#996515 100%);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;}
.imc-artists-head p{margin:8px 0 0;color:#9aa0aa;font-size:.98rem;}
.imc-artists-grid{display:flex;flex-wrap:wrap;gap:16px;justify-content:center;}
.imc-artist-tile{width:190px;background:linear-gradient(160deg,#15151f 0%,#0f0f17 100%);border:1px solid #242433;border-radius:16px;padding:20px 14px;text-decoration:none;color:inherit;display:flex;flex-direction:column;align-items:center;text-align:center;transition:transform .12s ease,border-color .12s ease;}
.imc-artist-tile:hover{transform:translateY(-3px);border-color:#d4af37;}
.imc-artist-tile img{width:76px;height:76px;border-radius:50%;object-fit:cover;background:#1a1a2e;border:2px solid #d4af37;box-shadow:0 0 0 4px rgba(212,175,55,.08);}
.imc-artist-tile .nm{margin-top:13px;font-size:1rem;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;}
.imc-artist-tile .wl{margin-top:3px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.74rem;color:#8a8f99;}
.imc-artist-tile .ct{margin-top:9px;font-size:.76rem;color:#d4af37;}
.imc-artist-tile{position:relative;}
.imc-vbadge{position:absolute;top:10px;right:10px;display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:linear-gradient(180deg,#ffe066,#c8962f);color:#0f0f17;font-size:13px;font-weight:900;box-shadow:0 0 0 2px rgba(212,175,55,.20);z-index:2;}
.imc-ai-vbadge{background:linear-gradient(180deg,#7b5cff,#4a2fd0) !important;color:#fff !important;box-shadow:0 0 0 2px rgba(123,92,255,.30) !important;width:38px;height:38px;font-size:20px;}
.imc-artist-filters{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin:4px 0 22px;}
.imc-filter-btn{appearance:none;cursor:pointer;border:1px solid #2b2b3d;background:#12121b;color:#cfd2dc;font-size:.86rem;font-weight:700;padding:7px 16px;border-radius:999px;transition:all .12s ease;}
.imc-filter-btn:hover{border-color:#d4af37;color:#fff;}
.imc-filter-btn.active{background:linear-gradient(180deg,#ffe066,#c8962f);border-color:#c8962f;color:#0f0f17;}
.imc-filter-btn[data-filter="ai"].active{background:linear-gradient(180deg,#7b5cff,#4a2fd0);border-color:#4a2fd0;color:#fff;}
.imc-artist-tile.imc-hide{display:none !important;}
.imc-artists-empty{color:#9aa0aa;padding:50px 0;text-align:center;}
</style>

<div class="imc-artists-page">
  <div class="imc-artists-head">
    <h1>Creators</h1>
    <p>Discover the artists minting on IMCollectibles.</p>
  </div>

  <?php if (empty($artists)): ?>
    <div class="imc-artists-empty">No creator profiles yet &mdash; check back soon.</div>
  <?php else: ?>
    <div class="imc-artist-filters" role="group" aria-label="Filter creators">
      <button type="button" class="imc-filter-btn active" data-filter="all">All Artists</button>
      <button type="button" class="imc-filter-btn" data-filter="verified">&#10003; Verified</button>
      <button type="button" class="imc-filter-btn" data-filter="ai">&#129302; Verified AI</button>
    </div>
    <div class="imc-artists-grid">
      <?php foreach ($artists as $a):
        $name = $a['display_name'];
        $slug = $a['slug'];
        $acct = $a['artist_account'];
        $pfp  = !empty($a['profile_image_url']) ? $a['profile_image_url'] : '';
        $n    = isset($counts[$acct]) ? $counts[$acct] : 0;
        $short = function_exists('imc_wallet_short') ? imc_wallet_short($acct) : $acct;
        $is_v = function_exists('imc_is_verified_creator') ? imc_is_verified_creator($acct) : false;
        $is_ai = (!$is_v && function_exists('imc_is_verified_ai_creator')) ? imc_is_verified_ai_creator($acct) : false;
        $tier = $is_v ? 'verified' : ($is_ai ? 'ai' : 'none');
      ?>
        <a class="imc-artist-tile" data-tier="<?php echo $tier; ?>" href="<?php echo esc_url(home_url('/artists/' . $slug)); ?>">
          <?php if ($is_v): ?><span class="imc-vbadge" title="Verified Artist">&#10003;</span><?php elseif ($is_ai): ?><span class="imc-vbadge imc-ai-vbadge" title="Verified AI Artist">&#129302;</span><?php endif; ?>
          <img alt="<?php echo esc_attr($name); ?>" loading="lazy"
               src="<?php echo $pfp !== '' ? esc_url($pfp) : $default_pfp; ?>"
               onerror="this.src='<?php echo esc_js($default_pfp); ?>'">
          <div class="nm"><?php echo esc_html($name); ?></div>
          <div class="wl"><?php echo esc_html($short); ?></div>
          <?php if ($n > 0): ?><div class="ct"><?php echo number_format_i18n($n); ?> NFT<?php echo $n === 1 ? '' : 's'; ?> minted</div><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<script>
// v636 Task1: Creator directory filter (All / Verified / Verified AI) — pure show/hide, no reload.
(function(){
  var bar=document.querySelector('.imc-artist-filters'); if(!bar) return;
  var tiles=Array.prototype.slice.call(document.querySelectorAll('.imc-artist-tile'));
  bar.addEventListener('click',function(e){
    var btn=e.target.closest('.imc-filter-btn'); if(!btn) return;
    var f=btn.getAttribute('data-filter');
    bar.querySelectorAll('.imc-filter-btn').forEach(function(b){ b.classList.toggle('active', b===btn); });
    tiles.forEach(function(t){
      var tier=t.getAttribute('data-tier')||'none';
      var show=(f==='all')||(f==='verified'&&tier==='verified')||(f==='ai'&&tier==='ai');
      t.classList.toggle('imc-hide', !show);
    });
  });
})();
</script>
<?php get_footer(); ?>