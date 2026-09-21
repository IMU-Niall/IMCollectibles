<?php
/**
 * IMCollectibles — 404 Error Page
 * Branded error page matching the dark theme with gold accents
 * 
 * @package IMCollectibles
 * @since v163
 */

get_header();
?>

<style>
.imc-404-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 70vh;
    padding: 2rem;
    text-align: center;
    background: #0a0a0f;
    color: #e8e6e3;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
}

.imc-404-code {
    font-size: clamp(6rem, 15vw, 12rem);
    font-weight: 800;
    line-height: 1;
    margin-bottom: 0.5rem;
    background: linear-gradient(135deg, var(--imu-gold, #d6ba66) 0%, #d4af37 50%, #b89d4a 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    filter: drop-shadow(0 0 30px rgba(var(--imu-gold-rgb), 0.15));
    letter-spacing: -0.03em;
}

.imc-404-title {
    font-size: clamp(1.25rem, 3vw, 1.75rem);
    font-weight: 600;
    color: #e8e6e3;
    margin-bottom: 0.75rem;
}

.imc-404-message {
    font-size: 1rem;
    color: #8a8a8a;
    max-width: 440px;
    line-height: 1.6;
    margin-bottom: 2rem;
}

.imc-404-actions {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
    justify-content: center;
}

.imc-404-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
    cursor: pointer;
}

.imc-404-btn-primary {
    background: linear-gradient(135deg, var(--imu-gold, #d6ba66), #d4af37);
    color: #0a0a0f;
    border: none;
}

.imc-404-btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 20px rgba(var(--imu-gold-rgb), 0.3);
    color: #0a0a0f;
    text-decoration: none;
}

.imc-404-btn-secondary {
    background: transparent;
    color: var(--imu-gold, #d6ba66);
    border: 1px solid rgba(var(--imu-gold-rgb), 0.3);
}

.imc-404-btn-secondary:hover {
    background: rgba(var(--imu-gold-rgb), 0.08);
    border-color: rgba(var(--imu-gold-rgb), 0.5);
    color: var(--imu-gold, #d6ba66);
    text-decoration: none;
}

.imc-404-links {
    margin-top: 2.5rem;
    display: flex;
    gap: 1.5rem;
    flex-wrap: wrap;
    justify-content: center;
}

.imc-404-links a {
    color: #8a8a8a;
    text-decoration: none;
    font-size: 0.85rem;
    transition: color 0.2s;
}

.imc-404-links a:hover {
    color: var(--imu-gold, #d6ba66);
}

@media (prefers-reduced-motion: reduce) {
    .imc-404-btn-primary:hover { transform: none; }
}

@media (max-width: 480px) {
    .imc-404-container { padding: 1.5rem 1rem; }
    .imc-404-actions { flex-direction: column; width: 100%; max-width: 280px; }
    .imc-404-btn { justify-content: center; }
}
</style>

<div class="imc-404-container" role="main" aria-label="Page not found">
    <div class="imc-404-code">404</div>
    <h1 class="imc-404-title">Page Not Found</h1>
    <p class="imc-404-message">
        The page you're looking for doesn't exist or has been moved. 
        It might have been an old link from IMProtectors that's changed.
    </p>
    <div class="imc-404-actions">
        <a href="<?php echo esc_url(home_url('/')); ?>" class="imc-404-btn imc-404-btn-primary">
            ← Back to Home
        </a>
        <a href="<?php echo esc_url(home_url('/collections/')); ?>" class="imc-404-btn imc-404-btn-secondary">
            Browse Collections
        </a>
    </div>
    <div class="imc-404-links">
        <a href="<?php echo esc_url(home_url('/trading-hub/')); ?>">Trading Hub</a>
        <a href="<?php echo esc_url(home_url('/mint/')); ?>">Mint NFTs</a>
        <a href="<?php echo esc_url(home_url('/my-nfts/')); ?>">My NFTs</a>
        <a href="mailto:Support@imcollectibles.io">Contact Support</a>
    </div>
</div>

<?php get_footer(); ?>