<?php
/**
 * The template for displaying the footer.
 * File: footer.php
 * Path: /wp-content/themes/astra/footer.php
 * 
 * v153b: Clean footer — no sitemap, brand + newsletter between dividers
 * - Dark glass design matching unified header v5.0
 * - CSS scoped under .imc-footer to avoid conflicts
 * - Preserves Astra hooks and newsletter subscription logic
 *
 * @package Astra
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

?>
<?php astra_content_bottom(); ?>
    </div> <!-- ast-container -->
    </div><!-- #content -->
<?php
    astra_content_after();
    astra_footer_before();
?>

<style>
/* ============================================
   IMC FOOTER v2.1 — Scoped under .imc-footer
   ============================================ */
.imc-footer {
    background: #0a0a0f !important;
    border-top: 1px solid rgba(var(--imu-gold-rgb), 0.15) !important;
    padding: 0 !important;
    margin: 0 !important;
    max-width: 100% !important;
    width: 100% !important;
    box-sizing: border-box !important;
    position: relative !important;
    z-index: 100 !important;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
}

.imc-footer * {
    box-sizing: border-box !important;
}

.imc-footer-inner {
    max-width: 1200px !important;
    margin: 0 auto !important;
    padding: 0 1.5rem !important;
}

/* ── Divider ── */
.imc-footer-divider {
    height: 1px !important;
    background: linear-gradient(90deg, transparent, rgba(var(--imu-gold-rgb), 0.35), transparent) !important;
    border: none !important;
    margin: 0 !important;
}

/* ── Main Section: Brand + Newsletter (between dividers) ── */
.imc-footer-main {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    gap: 2rem !important;
    padding: 2.25rem 0 !important;
}

.imc-footer-brand {
    display: flex !important;
    align-items: center !important;
    gap: 1rem !important;
    flex-shrink: 0 !important;
}

.imc-footer-brand-link {
    flex-shrink: 0 !important;
    line-height: 0 !important;
}

.imc-footer-brand-logo {
    width: 68px !important;
    height: 68px !important;
    border-radius: 50% !important;
    display: block !important;
}

.imc-footer-brand-text {
    display: flex !important;
    flex-direction: column !important;
    gap: 0.15rem !important;
}

.imc-footer-brand-name {
    text-decoration: none !important;
    font-weight: 700 !important;
    font-size: 1.4rem !important;
    display: block !important;
    line-height: 1.2 !important;
    /* v337: Cinzel Decorative gold gradient */
    font-family: 'Cinzel Decorative', 'Cinzel', 'Times New Roman', serif !important;
    letter-spacing: 0.06em !important;
    background: linear-gradient(180deg, #ffe066 0%, var(--imu-gold, #d6ba66) 40%, #996515 100%) !important;
    -webkit-background-clip: text !important;
    -webkit-text-fill-color: transparent !important;
    background-clip: text !important;
    filter: drop-shadow(0 0 14px rgba(212, 175, 55, 0.3)) !important;
}

.imc-footer-tagline {
    color: #8a8a8a !important;
    font-size: 0.85rem !important;
    line-height: 1.6 !important;
    margin: 0 !important;
    max-width: 320px !important;
}

/* Newsletter */
.imc-footer-newsletter {
    flex-shrink: 0 !important;
    max-width: 380px !important;
    width: 100% !important;
}

.imc-footer-newsletter-label {
    font-size: 0.8rem !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.1em !important;
    margin-bottom: 0.6rem !important;
    display: block !important;
    /* v337: Cinzel + gold gradient */
    font-family: 'Cinzel', 'Times New Roman', serif !important;
    background: linear-gradient(180deg, #ffe066 0%, var(--imu-gold, #d6ba66) 40%, #996515 100%) !important;
    -webkit-background-clip: text !important;
    -webkit-text-fill-color: transparent !important;
    background-clip: text !important;
    filter: drop-shadow(0 0 10px rgba(212, 175, 55, 0.25)) !important;
}

.imc-footer-newsletter-form {
    display: flex !important;
    gap: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
}

.imc-footer-newsletter-form input[type="email"] {
    flex: 1 !important;
    background: rgba(255, 255, 255, 0.05) !important;
    border: 1px solid rgba(var(--imu-gold-rgb), 0.25) !important;
    border-right: none !important;
    border-radius: 8px 0 0 8px !important;
    padding: 0.65rem 1rem !important;
    color: #e8e6e3 !important;
    font-size: 0.85rem !important;
    outline: none !important;
    transition: border-color 0.2s ease !important;
    min-width: 0 !important;
}

.imc-footer-newsletter-form input[type="email"]::placeholder {
    color: #666 !important;
}

.imc-footer-newsletter-form input[type="email"]:focus {
    border-color: var(--imu-gold, #d6ba66) !important;
}

.imc-footer-newsletter-form button {
    background: linear-gradient(135deg, #b89d4a, var(--imu-gold, #d6ba66)) !important;
    color: #000 !important;
    border: none !important;
    border-radius: 0 8px 8px 0 !important;
    padding: 0.65rem 1.25rem !important;
    font-weight: 700 !important;
    font-size: 0.85rem !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
    white-space: nowrap !important;
}

.imc-footer-newsletter-form button:hover {
    background: linear-gradient(135deg, var(--imu-gold, #d6ba66), #e8d38a) !important;
}

.imc-footer-newsletter-msg {
    font-size: 0.8rem !important;
    margin-top: 0.4rem !important;
}

.imc-footer-newsletter-msg.success {
    color: #10b981 !important;
}

.imc-footer-newsletter-msg.error {
    color: #ef4444 !important;
}

/* ── Bottom Bar ── */
.imc-footer-bottom {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    padding: 1.25rem 0 !important;
    flex-wrap: wrap !important;
    gap: 0.75rem !important;
}

.imc-footer-bottom-left {
    display: flex !important;
    align-items: center !important;
    gap: 1.5rem !important;
    flex-wrap: wrap !important;
}

.imc-footer-copyright {
    color: #555 !important;
    font-size: 0.78rem !important;
    margin: 0 !important;
}

.imc-footer-email a {
    color: #8a8a8a !important;
    text-decoration: none !important;
    font-size: 0.78rem !important;
    transition: color 0.2s ease !important;
}

.imc-footer-email a:hover {
    color: var(--imu-gold, #d6ba66) !important;
}

.imc-footer-powered {
    text-align: right !important;
    line-height: 1.5 !important;
    margin: 0 !important;
}

.imc-footer-powered-line1 {
    color: #8a8a8a !important;
    font-size: 0.78rem !important;
    font-weight: 600 !important;
    display: block !important;
}

.imc-footer-powered-line2 {
    color: #555 !important;
    font-size: 0.7rem !important;
    display: block !important;
}

/* ── Responsive ── */
@media (max-width: 768px) {
    .imc-footer-main {
        flex-direction: column !important;
        align-items: stretch !important;
        text-align: center !important;
    }

    .imc-footer-brand {
        justify-content: center !important;
    }

    .imc-footer-brand-text {
        text-align: left !important;
    }

    .imc-footer-tagline {
        max-width: 100% !important;
    }

    .imc-footer-newsletter {
        max-width: 100% !important;
    }

    .imc-footer-bottom {
        flex-direction: column !important;
        align-items: center !important;
        text-align: center !important;
    }

    .imc-footer-bottom-left {
        flex-direction: column !important;
        align-items: center !important;
        gap: 0.5rem !important;
    }

    .imc-footer-powered {
        text-align: center !important;
    }
}

@media (max-width: 480px) {
    .imc-footer-inner {
        padding: 0 1rem !important;
    }

    .imc-footer-brand-logo {
        width: 56px !important;
        height: 56px !important;
    }

    .imc-footer-brand-name {
        font-size: 1.2rem !important;
    }
}
</style>

<footer class="imc-footer">
    <div class="imc-footer-inner">
        
        <hr class="imc-footer-divider">
        
        <!-- Main: Brand + Newsletter (between the dividers) -->
        <div class="imc-footer-main">
            <div class="imc-footer-brand">
                <a href="<?php echo esc_url(home_url('/')); ?>" class="imc-footer-brand-link">
                    <img src="<?php echo esc_url(get_template_directory_uri()); ?>/images/protectors-logo.png" 
                         alt="IMCollectibles"
                         class="imc-footer-brand-logo"
                         onerror="this.src='https://imcollectibles.io/wp-content/uploads/2025/08/Protectors-Logo.png'">
                </a>
                <div class="imc-footer-brand-text">
                    <a href="<?php echo esc_url(home_url('/')); ?>" class="imc-footer-brand-name">IMCollectibles</a>
                    <p class="imc-footer-tagline">Mint with Protection.<br>Collect with Exclusivity.</p>
                </div>
            </div>
            
            <div class="imc-footer-newsletter">
                <span class="imc-footer-newsletter-label">Stay Updated</span>
                <form method="post" action="" class="imc-footer-newsletter-form">
                    <?php wp_nonce_field('footer_email_subscribe', 'footer_email_nonce'); ?>
                    <input type="email" name="subscriber_email" placeholder="Enter your email" required aria-label="Email for newsletter">
                    <button type="submit" name="subscribe_submit">Subscribe</button>
                </form>
                <?php
                if (isset($_POST['subscribe_submit']) && wp_verify_nonce($_POST['footer_email_nonce'], 'footer_email_subscribe')) {
                    $email = sanitize_email($_POST['subscriber_email']);
                    if (is_email($email)) {
                        $to = 'support@imcollectibles.io';
                        $subject = 'New Footer Subscription';
                        $message = "A new user has subscribed:\n\nEmail: $email\n\nPage: " . get_the_title() . "\nURL: " . get_permalink();
                        $headers = array('Content-Type: text/plain; charset=UTF-8');
                        if (wp_mail($to, $subject, $message, $headers)) {
                            echo '<p class="imc-footer-newsletter-msg success">Thank you for subscribing!</p>';
                            xaman_log("Email subscription successful for: $email");
                        } else {
                            echo '<p class="imc-footer-newsletter-msg error">Subscription failed. Please try again.</p>';
                            xaman_log("Email subscription failed for: $email");
                        }
                    } else {
                        echo '<p class="imc-footer-newsletter-msg error">Please enter a valid email address.</p>';
                        xaman_log("Invalid email submitted: $email");
                    }
                }
                ?>
            </div>
        </div>
        
        <hr class="imc-footer-divider">
        
        <!-- Bottom Bar -->
        <div class="imc-footer-bottom">
            <div class="imc-footer-bottom-left">
                <p class="imc-footer-copyright">&copy; <?php echo date('Y'); ?> IMCollectibles. All Rights Reserved.</p>
                <?php if (function_exists('imc_xrplto_attribution_render')) imc_xrplto_attribution_render(); ?>
                <span class="imc-footer-email">
                    <a href="mailto:Support@IMCollectibles.io">Support@IMCollectibles.io</a> &nbsp;|&nbsp;
                    <a href="<?php echo esc_url(home_url('/terms/')); ?>">Terms of Service</a> &nbsp;|&nbsp;
                    <a href="<?php echo esc_url(home_url('/privacy/')); ?>">Privacy Policy</a>
                </span>
            </div>
            <p class="imc-footer-powered">
                <span class="imc-footer-powered-line1">📡 Powered by IMU</span>
                <span class="imc-footer-powered-line2">Built on the XRPL</span>
            </p>
        </div>
        
    </div>
</footer>

<?php
astra_footer_after();
?>
</div><!-- #page -->
<?php
astra_body_bottom();
wp_footer();
?>
</body>
</html>