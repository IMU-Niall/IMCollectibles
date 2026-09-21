<?php
/**
 * Template Name: View on TV
 * File: page-view-on-tv.php
 * Path: /wp-content/themes/astra/page-templates/page-view-on-tv.php
 *
 * v409: Tutorial page explaining how to view NFTs on TV via IMUTV/IMUP3
 */
get_header();
?>
<style>
:root{--tv-gold:var(--imu-gold, #d6ba66);--tv-gold-light:#e8d38a;--tv-gold-glow:rgba(var(--imu-gold-rgb), .12);--tv-bg:#0a0a12;--tv-card:#12121a;--tv-border:rgba(var(--imu-gold-rgb), .15);--tv-text:#e8e6e3;--tv-muted:#7a7a8a;--tv-radius:14px}
.tv-wrap{max-width:860px;margin:0 auto;padding:2rem 1.5rem 5rem;min-height:80vh}
.tv-back{display:inline-flex;align-items:center;gap:.5rem;color:var(--tv-muted);text-decoration:none;font-size:.88rem;margin-bottom:1.75rem;transition:color .18s}
.tv-back:hover{color:var(--tv-gold)}
.tv-hero{text-align:center;margin-bottom:3rem}
.tv-hero h1{font-family:'Lora',serif;font-size:2.2rem;color:var(--tv-gold);margin:0 0 .75rem;text-shadow:0 0 30px rgba(var(--imu-gold-rgb), .2)}
.tv-hero p{color:var(--tv-muted);font-size:1.05rem;max-width:560px;margin:0 auto;line-height:1.6}
.tv-steps{display:flex;flex-direction:column;gap:1.25rem;margin-bottom:3rem}
.tv-step{display:flex;gap:1.25rem;align-items:flex-start;background:var(--tv-card);border:1px solid var(--tv-border);border-radius:var(--tv-radius);padding:1.5rem;transition:border-color .2s,transform .2s}
.tv-step:hover{border-color:rgba(var(--imu-gold-rgb), .35);transform:translateX(4px)}
.tv-step-num{flex-shrink:0;width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,rgba(var(--imu-gold-rgb), .15),rgba(var(--imu-gold-rgb), .05));border:2px solid var(--tv-gold);display:flex;align-items:center;justify-content:center;font-family:'Lora',serif;font-weight:700;font-size:1.1rem;color:var(--tv-gold)}
.tv-step-content h3{font-family:'Lora',serif;font-size:1.1rem;color:var(--tv-gold-light);margin:0 0 .4rem}
.tv-step-content p{color:var(--tv-text);font-family:'Montserrat',sans-serif;font-size:.92rem;margin:0;line-height:1.55}
.tv-step-content .tv-note{color:var(--tv-muted);font-size:.82rem;margin-top:.35rem}
.tv-platforms{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.6rem}
.tv-platform-badge{display:inline-flex;align-items:center;gap:.35rem;background:rgba(var(--imu-gold-rgb), .08);border:1px solid var(--tv-border);border-radius:8px;padding:.35rem .75rem;font-size:.78rem;color:var(--tv-text);font-family:'Montserrat',sans-serif}
.tv-cta{text-align:center;margin-top:2.5rem;padding:2rem;background:var(--tv-card);border:1px solid var(--tv-border);border-radius:var(--tv-radius)}
.tv-cta h2{font-family:'Lora',serif;font-size:1.4rem;color:var(--tv-gold);margin:0 0 .75rem}
.tv-cta p{color:var(--tv-muted);font-size:.9rem;margin:0 0 1.25rem;line-height:1.5}
.tv-cta-links{display:flex;flex-wrap:wrap;gap:.75rem;justify-content:center}
.tv-cta-link{display:inline-flex;align-items:center;gap:.5rem;padding:.75rem 1.5rem;border-radius:10px;text-decoration:none;font-family:'Montserrat',sans-serif;font-weight:600;font-size:.9rem;transition:all .2s}
.tv-cta-link.primary{background:var(--tv-gold);color:#000}
.tv-cta-link.primary:hover{background:var(--tv-gold-light);transform:translateY(-2px);box-shadow:0 4px 20px rgba(var(--imu-gold-rgb), .3)}
.tv-cta-link.secondary{background:transparent;border:1px solid var(--tv-border);color:var(--tv-gold)}
.tv-cta-link.secondary:hover{border-color:var(--tv-gold);transform:translateY(-2px)}
@media(max-width:600px){
    .tv-hero h1{font-size:1.6rem}
    .tv-step{flex-direction:column;align-items:center;text-align:center;gap:.75rem}
    .tv-cta-links{flex-direction:column;align-items:center}
}
</style>

<div class="tv-wrap">
    <a href="javascript:history.back()" class="tv-back">← Back</a>

    <div class="tv-hero">
        <h1>📺 How to View Your NFTs on TV</h1>
        <p>Your IMCollectibles NFTs aren't just digital collectibles — they're playable media you can enjoy on your TV, phone, and desktop through IMUTV and IMUP3.</p>
    </div>

    <div class="tv-steps">
        <div class="tv-step">
            <div class="tv-step-num">1</div>
            <div class="tv-step-content">
                <h3>Purchase an NFT on IMCollectibles</h3>
                <p>Browse and mint any Music, Art, or Film Access NFT from our marketplace. Once minted, the NFT is yours on the XRP Ledger — and so is access to the master file.</p>
            </div>
        </div>

        <div class="tv-step">
            <div class="tv-step-num">2</div>
            <div class="tv-step-content">
                <h3>Create a Free IMUTV Account</h3>
                <p>Visit <a href="https://imutv.tv" target="_blank" style="color:var(--tv-gold);text-decoration:underline;">imutv.tv</a> and sign up for a free account. This is your gateway to the IMU entertainment ecosystem.</p>
            </div>
        </div>

        <div class="tv-step">
            <div class="tv-step-num">3</div>
            <div class="tv-step-content">
                <h3>Connect Your Xaman Wallet</h3>
                <p>Link the Xaman (XUMM) wallet that holds your NFTs to your IMUTV account. This lets IMUTV verify your NFT ownership and unlock your master files.</p>
            </div>
        </div>

        <div class="tv-step">
            <div class="tv-step-num">4</div>
            <div class="tv-step-content">
                <h3>Download IMUTV</h3>
                <p>Install the IMUTV app on your preferred platform and enjoy your NFT content on the big screen or on mobile.</p>
                <div class="tv-platforms">
                    <span class="tv-platform-badge">📺 Roku</span>
                    <span class="tv-platform-badge">🔥 Fire TV</span>
                    <span class="tv-platform-badge">📱 Google TV</span>
                    <span class="tv-platform-badge">📱 Android TV</span>
                    <span class="tv-platform-badge">🍎 iOS</span>
                    <span class="tv-platform-badge">▶️ Play Store</span>
                </div>
            </div>
        </div>

        <div class="tv-step">
            <div class="tv-step-num">5</div>
            <div class="tv-step-content">
                <h3>Download IMUP3</h3>
                <p>For music NFTs, install the IMUP3 audio player — the dedicated music and podcast player for the IMU ecosystem.</p>
                <div class="tv-platforms">
                    <span class="tv-platform-badge">▶️ Play Store</span>
                    <span class="tv-platform-badge">🍎 iOS</span>
                </div>
            </div>
        </div>

        <div class="tv-step">
            <div class="tv-step-num">6</div>
            <div class="tv-step-content">
                <h3>Sign In with Your IMUTV Account</h3>
                <p>Log into IMUTV or IMUP3 using the account you created in Step 2. Your connected wallet and NFT entitlements sync automatically.</p>
            </div>
        </div>

        <div class="tv-step">
            <div class="tv-step-num">7</div>
            <div class="tv-step-content">
                <h3>Enjoy Your NFTs &amp; Earn Rewards</h3>
                <p>Access your NFTs directly on mobile or TV. Create custom playlists, enjoy full master-quality playback, and earn XFT token rewards just for playing your NFT content.</p>
                <p class="tv-note">💡 The more you play, the more you earn — XFT streaming royalties are distributed to NFT holders automatically.</p>
            </div>
        </div>
    </div>

    <div class="tv-cta">
        <h2>Ready to Get Started?</h2>
        <p>Browse our marketplace for Music, Art, and Film Access NFTs — then follow the steps above to enjoy them across all your devices.</p>
        <div class="tv-cta-links">
            <a href="<?php echo esc_url(home_url('/collections/')); ?>" class="tv-cta-link primary">🎵 Browse Collections</a>
            <a href="https://imutv.tv" target="_blank" class="tv-cta-link secondary">📺 Visit IMUTV.tv</a>
            <a href="<?php echo esc_url(home_url('/recent-mints/')); ?>" class="tv-cta-link secondary">🆕 New Drops</a>
        </div>
    </div>
</div>

<?php get_footer(); ?>