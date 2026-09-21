<?php
/**
 * IMC Price Oracle v82
 * 
 * Real-time XRPL token pricing via DEX orderbook and AMM queries
 * NO hardcoded fallback prices - returns null if price unavailable
 * 
 * @version 2.2.0 (v82)
 */

if (!defined('ABSPATH')) exit;

define('IMC_PRICE_ORACLE_VER', '2.2.0');

// XRPL endpoints for price fetching
define('IMC_XRPL_ENDPOINTS_LIST', 'https://xrplcluster.com,https://s1.ripple.com:51234,https://s2.ripple.com:51234');

// Cache TTLs
define('IMC_CACHE_TTL_XRP', 60);
define('IMC_CACHE_TTL_TOKENS', 120);
// v713: issuer TransferRate. Longer TTL than prices because a transfer fee changes very
// rarely -- and never again once an issuer is blackholed. Short enough that a creator
// adjusting their fee is picked up within minutes.
define('IMC_CACHE_TTL_TRANSFER_RATE', 300);

// ============================================================================
// DATABASE TABLE SETUP - v82: Safer initialization
// ============================================================================

function imc_ensure_price_tables() {
    global $wpdb;
    
    // v82: Safety check - ensure $wpdb is properly initialized
    if (!$wpdb || empty($wpdb->prefix)) {
        error_log('IMC Price Oracle: $wpdb not ready, skipping table creation');
        return;
    }
    
    $token_table = $wpdb->prefix . 'imc_token_registry';
    $cache_table = $wpdb->prefix . 'imc_price_cache';
    
    // v82: Use option to track if tables were created (avoid repeated checks)
    $tables_created = get_option('imc_price_tables_v82', false);
    if ($tables_created) {
        return; // Tables already created
    }
    
    $charset = $wpdb->get_charset_collate();
    
    // Token registry
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $token_table));
    if ($table_exists !== $token_table) {
        $sql = "CREATE TABLE `{$token_table}` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticker VARCHAR(20) NOT NULL,
            currency_hex VARCHAR(40) DEFAULT NULL,
            issuer VARCHAR(40) DEFAULT NULL,
            display_name VARCHAR(50) DEFAULT NULL,
            description VARCHAR(255) DEFAULT NULL,
            logo_url VARCHAR(255) DEFAULT NULL,
            trustline_url TEXT DEFAULT NULL,
            is_native TINYINT(1) DEFAULT 0,
            is_stablecoin TINYINT(1) DEFAULT 0,
            has_amm TINYINT(1) DEFAULT 0,
            default_discount TINYINT UNSIGNED DEFAULT 0,
            sort_order INT DEFAULT 100,
            enabled TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY ticker_issuer (ticker(20), issuer(40))
        ) {$charset}";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Seed defaults
        imc_seed_default_tokens();
    }
    
    // Price cache
    $cache_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $cache_table));
    if ($cache_exists !== $cache_table) {
        $sql = "CREATE TABLE `{$cache_table}` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticker VARCHAR(20) NOT NULL,
            issuer VARCHAR(40) DEFAULT NULL,
            price_usd DECIMAL(18,8) NOT NULL,
            price_xrp DECIMAL(18,8) DEFAULT NULL,
            source VARCHAR(20) DEFAULT 'dex',
            fetched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME DEFAULT NULL,
            UNIQUE KEY ticker_issuer (ticker(20), issuer(40)),
            INDEX idx_expires (expires_at)
        ) {$charset}";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    // Mark tables as created
    update_option('imc_price_tables_v82', true);
}

function imc_seed_default_tokens() {
    global $wpdb;
    
    if (!$wpdb || empty($wpdb->prefix)) return;
    
    $table = $wpdb->prefix . 'imc_token_registry';
    
    $defaults = [
        ['XRP', null, null, 'XRP', 'Native XRPL currency', 1, 0, 0, 0, 0],
        ['RLUSD', '524C555344000000000000000000000000000000', 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De', 'Ripple USD', 'USD stablecoin', 0, 1, 1, 0, 1],
        ['XFT', '5846540000000000000000000000000000000000', 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt', 'XFT Token', 'IMUTV Ecosystem', 0, 0, 0, 0, 2],
        ['SOLO', '534F4C4F00000000000000000000000000000000', 'rsoLo2S1kiGeCcn6hCUXVrCpGMWLrRrLZz', 'Sologenic', 'DEX Token', 0, 0, 1, 0, 10],
        ['CORE', '434F524500000000000000000000000000000000', 'rcoreNywaoz2ZCQ8Lg2EbSLnGuRBmun6D', 'Coreum', 'Coreum Token', 0, 0, 1, 0, 11],
    ];
    
    foreach ($defaults as $t) {
        $wpdb->replace($table, [
            'ticker' => $t[0],
            'currency_hex' => $t[1],
            'issuer' => $t[2],
            'display_name' => $t[3],
            'description' => $t[4],
            'is_native' => $t[5],
            'is_stablecoin' => $t[6],
            'has_amm' => $t[7],
            'default_discount' => $t[8],
            'sort_order' => $t[9],
            'enabled' => 1
        ]);
    }
}

// ============================================================================
// TOKEN REGISTRY
// ============================================================================

class IMC_Token_Registry {
    
    private static function get_table() {
        global $wpdb;
        return $wpdb->prefix . 'imc_token_registry';
    }
    
    public static function get_all($include_disabled = false) {
        global $wpdb;
        $table = self::get_table();
        
        // Check table exists
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) {
            imc_ensure_price_tables();
        }
        
        $where = $include_disabled ? '' : 'WHERE enabled = 1';
        $tokens = $wpdb->get_results(
            "SELECT * FROM `{$table}` {$where} ORDER BY sort_order ASC, ticker ASC",
            ARRAY_A
        );
        
        return $tokens ?: [];
    }
    
    public static function get_by_ticker($ticker) {
        global $wpdb;
        $table = self::get_table();
        $ticker = strtoupper(trim($ticker));
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE UPPER(ticker) = %s LIMIT 1",
            $ticker
        ), ARRAY_A);
    }
    
    public static function add_token($data) {
        global $wpdb;
        $table = self::get_table();
        
        $ticker = strtoupper(sanitize_text_field($data['ticker'] ?? ''));
        $issuer = sanitize_text_field($data['issuer'] ?? '');
        
        if (empty($ticker)) return new WP_Error('invalid', 'Ticker required');
        
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE ticker = %s AND (issuer = %s OR (issuer IS NULL AND %s = ''))",
            $ticker, $issuer, $issuer
        ));
        
        if ($exists) return new WP_Error('duplicate', 'Token already exists');
        
        $currency_hex = $data['currency_hex'] ?? null;
        if (empty($currency_hex) && strlen($ticker) > 3) {
            $currency_hex = str_pad(strtoupper(bin2hex($ticker)), 40, '0');
        }
        
        $result = $wpdb->insert($table, [
            'ticker' => $ticker,
            'currency_hex' => $currency_hex,
            'issuer' => $issuer ?: null,
            'display_name' => sanitize_text_field($data['display_name'] ?? $ticker),
            'description' => sanitize_text_field($data['description'] ?? ''),
            'logo_url' => esc_url_raw($data['logo_url'] ?? ''),
            'trustline_url' => sanitize_url($data['trustline_url'] ?? ''),
            'is_native' => empty($issuer) && $ticker === 'XRP' ? 1 : 0,
            'is_stablecoin' => !empty($data['is_stablecoin']) ? 1 : 0,
            'has_amm' => !empty($data['has_amm']) ? 1 : 0,
            'default_discount' => max(0, min(99, intval($data['default_discount'] ?? 0))),
            'sort_order' => intval($data['sort_order'] ?? 100),
            'enabled' => 1
        ]);
        
        return $result ? $wpdb->insert_id : new WP_Error('insert_failed', 'Failed to add token');
    }
    
    public static function update_token($id, $data) {
        global $wpdb;
        $table = self::get_table();
        
        $update = [];
        if (isset($data['display_name'])) $update['display_name'] = sanitize_text_field($data['display_name']);
        if (isset($data['description'])) $update['description'] = sanitize_text_field($data['description']);
        if (isset($data['logo_url'])) $update['logo_url'] = esc_url_raw($data['logo_url']);
        if (isset($data['trustline_url'])) $update['trustline_url'] = sanitize_url($data['trustline_url']);
        if (isset($data['has_amm'])) $update['has_amm'] = $data['has_amm'] ? 1 : 0;
        if (isset($data['default_discount'])) $update['default_discount'] = max(0, min(99, intval($data['default_discount'])));
        if (isset($data['sort_order'])) $update['sort_order'] = intval($data['sort_order']);
        if (isset($data['enabled'])) $update['enabled'] = $data['enabled'] ? 1 : 0;
        
        if (empty($update)) return false;
        return $wpdb->update($table, $update, ['id' => intval($id)]);
    }
    
    public static function delete_token($id) {
        global $wpdb;
        return $wpdb->delete(self::get_table(), ['id' => intval($id)]);
    }
}

// ============================================================================
// PRICE ORACLE - v82: Real DEX/AMM queries, NO hardcoded fallbacks
// ============================================================================

class IMC_Price_Oracle {
    
    /**
     * Make XRPL JSON-RPC request with failover
     */
    public static function xrpl_request($method, $params = []) {
        $endpoints = explode(',', IMC_XRPL_ENDPOINTS_LIST);
        
        $body = json_encode([
            'method' => $method,
            'params' => [$params]
        ]);
        
        foreach ($endpoints as $url) {
            $response = wp_remote_post(trim($url), [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $body,
                'timeout' => 10
            ]);
            
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $data = json_decode(wp_remote_retrieve_body($response), true);
                if (isset($data['result']) && empty($data['result']['error'])) {
                    return $data['result'];
                }
            }
        }
        return null;
    }
    
    /**
     * Get XRP/USD price from external APIs
     * v82: NO hardcoded fallback - returns null if all APIs fail
     */
    /**
     * v713: Read an issuer's TransferRate (transfer fee) from the ledger.
     *
     * WHY THIS EXISTS: on the XRPL, `Amount` is what the DESTINATION receives, and the
     * sender is debited `Amount x TransferRate`. If `SendMax` is omitted it defaults to
     * `Amount`, so a payment in a token whose issuer charges a transfer fee can never
     * deliver the full amount -- it fails tecPATH_PARTIAL every time, regardless of the
     * sender's balance. (Observed live with PAX, which burns 0.33% on transfer.)
     *
     * RETURNS: a float MULTIPLIER -- 1.0 = no fee, 1.0033 = 0.33%.
     *          **null on ANY failure or out-of-spec value.**
     *
     * The null return is deliberate and load-bearing: the caller must FAIL FAST rather
     * than assume 1.0, because assuming no fee is exactly what produces the unsignable
     * payment we are fixing. Never cache a failure -- only successful reads are stored,
     * so a transient ledger problem retries on the next attempt instead of being pinned
     * for the whole TTL.
     *
     * @param  string     $issuer  Token issuer account.
     * @return float|null          Multiplier, or null if it could not be established.
     */
    public static function get_transfer_rate($issuer) {
        if (empty($issuer) || !preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $issuer)) {
            return null;
        }

        $cache_key = 'imc_tr_' . substr(md5($issuer), 0, 20);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return (float) $cached;
        }

        $result = self::xrpl_request('account_info', [
            'account'      => $issuer,
            'ledger_index' => 'validated'
        ]);

        // xrpl_request already returns null on transport failure or a ledger error
        // (including an unfunded account), so this covers every unreadable case.
        if (!is_array($result) || !isset($result['account_data'])) {
            return null;
        }

        // TransferRate is absent when no fee is set. Valid values are 0 (no fee) or
        // 1000000000..2000000000, where 1000000000 = 1.0 = no fee.
        $raw = (int) ($result['account_data']['TransferRate'] ?? 0);

        if ($raw === 0 || $raw === 1000000000) {
            $rate = 1.0;
        } elseif ($raw > 1000000000 && $raw <= 2000000000) {
            $rate = $raw / 1000000000;
        } else {
            // Out of spec. Fail closed rather than guess at a multiplier.
            return null;
        }

        set_transient($cache_key, $rate, IMC_CACHE_TTL_TRANSFER_RATE);
        return $rate;
    }

    public static function get_xrp_usd_price() {
        $cached = get_transient('imc_xrp_usd');
        if ($cached !== false && $cached > 0) {
            return (float)$cached;
        }
        
        $price = null;
        
        // CoinGecko
        $r = wp_remote_get('https://api.coingecko.com/api/v3/simple/price?ids=ripple&vs_currencies=usd', ['timeout' => 10]);
        if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) {
            $d = json_decode(wp_remote_retrieve_body($r), true);
            if (isset($d['ripple']['usd'])) $price = (float)$d['ripple']['usd'];
        }
        
        // Kraken fallback
        if (!$price) {
            $r = wp_remote_get('https://api.kraken.com/0/public/Ticker?pair=XRPUSD', ['timeout' => 10]);
            if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) {
                $d = json_decode(wp_remote_retrieve_body($r), true);
                if (isset($d['result']['XXRPZUSD']['c'][0])) $price = (float)$d['result']['XXRPZUSD']['c'][0];
            }
        }
        
        // Bitstamp fallback
        if (!$price) {
            $r = wp_remote_get('https://www.bitstamp.net/api/v2/ticker/xrpusd/', ['timeout' => 10]);
            if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) {
                $d = json_decode(wp_remote_retrieve_body($r), true);
                if (isset($d['last'])) $price = (float)$d['last'];
            }
        }
        
        if ($price && $price > 0) {
            set_transient('imc_xrp_usd', $price, IMC_CACHE_TTL_XRP);
        }
        return $price;
    }
    
    /**
     * Get token price from DEX orderbook
     * v82: Queries XRPL book_offers for best ask price - NO FALLBACKS
     */
    public static function get_dex_price($ticker, $issuer, $xrp_usd, $currency_hex = '') {
        if (empty($issuer) || $ticker === 'XRP') return null;
        
        $currency = $currency_hex ?: $ticker;
        
        // Query DEX orderbook: TOKEN -> XRP (selling tokens for XRP)
        $result = self::xrpl_request('book_offers', [
            'taker_gets' => ['currency' => 'XRP'],
            'taker_pays' => ['currency' => $currency, 'issuer' => $issuer],
            'limit' => 10
        ]);
        
        if (!empty($result['offers'])) {
            foreach ($result['offers'] as $offer) {
                // TakerGets = XRP amount, TakerPays = token amount
                $gets_xrp = is_array($offer['TakerGets']) 
                    ? (float)$offer['TakerGets']['value'] 
                    : (float)$offer['TakerGets'] / 1000000;
                $pays_token = is_array($offer['TakerPays']) 
                    ? (float)$offer['TakerPays']['value'] 
                    : (float)$offer['TakerPays'] / 1000000;
                
                if ($pays_token > 0 && $gets_xrp > 0) {
                    $xrp_per_token = $gets_xrp / $pays_token;
                    $usd_price = $xrp_per_token * $xrp_usd;
                    if ($usd_price > 0) return $usd_price;
                }
            }
        }
        
        // Also try reverse orderbook: XRP -> TOKEN (buying tokens with XRP)
        $result2 = self::xrpl_request('book_offers', [
            'taker_gets' => ['currency' => $currency, 'issuer' => $issuer],
            'taker_pays' => ['currency' => 'XRP'],
            'limit' => 10
        ]);
        
        if (!empty($result2['offers'])) {
            foreach ($result2['offers'] as $offer) {
                // TakerGets = token amount, TakerPays = XRP amount
                $gets_token = is_array($offer['TakerGets']) 
                    ? (float)$offer['TakerGets']['value'] 
                    : (float)$offer['TakerGets'] / 1000000;
                $pays_xrp = is_array($offer['TakerPays']) 
                    ? (float)$offer['TakerPays']['value'] 
                    : (float)$offer['TakerPays'] / 1000000;
                
                if ($gets_token > 0 && $pays_xrp > 0) {
                    $xrp_per_token = $pays_xrp / $gets_token;
                    $usd_price = $xrp_per_token * $xrp_usd;
                    if ($usd_price > 0) return $usd_price;
                }
            }
        }
        
        return null; // v82: NO FALLBACK - return null if no DEX orders
    }
    
    /**
     * Get token price from AMM pool
     * v82: Queries XRPL amm_info for pool-based pricing
     */
    public static function get_amm_price($ticker, $issuer, $xrp_usd, $currency_hex = '') {
        if (empty($issuer) || $ticker === 'XRP') return null;
        
        $currency = $currency_hex ?: $ticker;
        
        $result = self::xrpl_request('amm_info', [
            'asset' => ['currency' => 'XRP'],
            'asset2' => ['currency' => $currency, 'issuer' => $issuer]
        ]);
        
        if (!empty($result['amm'])) {
            $amount = $result['amm']['amount'] ?? null;
            $amount2 = $result['amm']['amount2'] ?? null;
            
            // Determine which is XRP and which is token
            $xrp_in_pool = 0;
            $token_in_pool = 0;
            
            if (is_string($amount) || (is_array($amount) && !isset($amount['currency']))) {
                // amount is XRP (drops or string)
                $xrp_in_pool = is_string($amount) ? (float)$amount / 1000000 : (float)$amount / 1000000;
                $token_in_pool = is_array($amount2) ? (float)($amount2['value'] ?? 0) : 0;
            } else {
                // amount is token, amount2 is XRP
                $token_in_pool = is_array($amount) ? (float)($amount['value'] ?? 0) : 0;
                $xrp_in_pool = is_string($amount2) ? (float)$amount2 / 1000000 : (is_array($amount2) ? (float)($amount2['value'] ?? 0) : (float)$amount2 / 1000000);
            }
            
            if ($token_in_pool > 0 && $xrp_in_pool > 0) {
                $xrp_per_token = $xrp_in_pool / $token_in_pool;
                return $xrp_per_token * $xrp_usd;
            }
        }
        
        return null; // v82: NO FALLBACK
    }
    
    /**
     * Get token USD price using DEX + AMM
     * v82: NO hardcoded fallbacks - returns null if price unavailable
     */
    public static function get_token_usd_price($ticker, $issuer = '') {
        $ticker = strtoupper(trim($ticker));
        
        // XRP: get from external APIs
        if ($ticker === 'XRP') return self::get_xrp_usd_price();
        
        // Stablecoins: return 1.00
        $token = IMC_Token_Registry::get_by_ticker($ticker);
        if (!empty($token['is_stablecoin'])) return 1.0;
        
        // Check cache
        $cache_key = 'imc_price_' . md5($ticker . '|' . $issuer);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached > 0 ? (float)$cached : null;
        }
        
        // Get XRP/USD first
        $xrp_usd = self::get_xrp_usd_price();
        if (!$xrp_usd) return null;
        
        // Get issuer from registry if not provided
        if (empty($issuer) && $token) $issuer = $token['issuer'] ?? '';
        if (empty($issuer)) return null;
        
        $currency_hex = $token['currency_hex'] ?? '';
        $final = null;
        
        // Try AMM first (usually better liquidity)
        if (!empty($token['has_amm'])) {
            $final = self::get_amm_price($ticker, $issuer, $xrp_usd, $currency_hex);
        }
        
        // Fallback to DEX orderbook
        if ($final === null) {
            $final = self::get_dex_price($ticker, $issuer, $xrp_usd, $currency_hex);
        }
        
        // v82: Cache result (cache null as 0 to avoid repeated slow queries)
        set_transient($cache_key, $final ?: 0, IMC_CACHE_TTL_TOKENS);
        
        return $final; // May be null if no DEX/AMM liquidity
    }
    
    /**
     * Calculate token amount for USD price with discount
     * v82: Returns unavailable flag instead of failing
     */
    public static function calculate_token_amount($usd_price, $ticker, $issuer = '', $discount_pct = 0) {
        $token_usd = self::get_token_usd_price($ticker, $issuer);
        
        if ($token_usd === null || $token_usd <= 0) {
            return [
                'ticker' => $ticker,
                'issuer' => $issuer,
                'amount' => null,
                'unavailable' => true,
                'reason' => 'No DEX/AMM liquidity found for ' . $ticker
            ];
        }
        
        $discount_pct = max(0, min(99, (float)$discount_pct));
        $effective = $usd_price * (1 - $discount_pct / 100);
        $amount = $effective / $token_usd;
        $decimals = ($ticker === 'XRP') ? 6 : 8;
        
        return [
            'ticker' => $ticker,
            'issuer' => $issuer,
            'amount' => round($amount, $decimals),
            'token_usd_price' => $token_usd,
            'discount_pct' => $discount_pct,
            'effective_usd' => $effective,
            'original_usd' => $usd_price,
            'unavailable' => false
        ];
    }
    
    /**
     * Calculate all enabled token prices for a USD amount
     * v82: Returns dictionary keyed by ticker
     */
    public static function calculate_all_prices($base_usd, $enabled_tokens = null) {
        $tokens = IMC_Token_Registry::get_all();
        $results = [];
        
        foreach ($tokens as $token) {
            $discount = 0;
            
            if ($enabled_tokens !== null) {
                $found = false;
                foreach ($enabled_tokens as $et) {
                    if (strtoupper($et['ticker'] ?? '') === strtoupper($token['ticker'])) {
                        $discount = (float)($et['discount_pct'] ?? $token['default_discount'] ?? 0);
                        $found = true;
                        break;
                    }
                }
                if (!$found) continue;
            } else {
                $discount = (float)($token['default_discount'] ?? 0);
            }
            
            $calc = self::calculate_token_amount($base_usd, $token['ticker'], $token['issuer'] ?? '', $discount);
            
            if ($calc) {
                $calc['display_name'] = $token['display_name'] ?? $token['ticker'];
                $calc['logo_url'] = $token['logo_url'] ?? '';
                $calc['is_native'] = !empty($token['is_native']);
                $calc['is_stablecoin'] = !empty($token['is_stablecoin']);
                $results[$token['ticker']] = $calc;
            }
        }
        return $results;
    }
    
    public static function refresh_all_prices() {
        delete_transient('imc_xrp_usd');
        self::get_xrp_usd_price();
        
        foreach (IMC_Token_Registry::get_all() as $t) {
            if (!empty($t['is_stablecoin']) || !empty($t['is_native'])) continue;
            delete_transient('imc_price_' . md5($t['ticker'] . '|' . ($t['issuer'] ?? '')));
            self::get_token_usd_price($t['ticker'], $t['issuer'] ?? '');
        }
    }
}

// ============================================================================
// TRUSTLINE HELPER
// ============================================================================

class IMC_Trustline_Helper {
    
    public static function has_trustline($wallet, $ticker, $issuer) {
        if (strtoupper($ticker) === 'XRP') return true;
        if (empty($issuer)) return false; // v691: fail closed — cannot verify a trustline without the issuer
        
        $result = IMC_Price_Oracle::xrpl_request('account_lines', [
            'account' => $wallet,
            'peer' => $issuer
        ]);

        // v733: TRI-STATE. xrpl_request returns null when EVERY endpoint failed (transport
        // error or ledger error on all three) — that is "COULD NOT VERIFY", not "no
        // trustline". Returning false here produced hard "Set trustline" warnings for
        // wallets whose trustline is provably set (observed live with XFT on /mint when
        // the host could not reach any XRPL endpoint). null lets callers fail OPEN.
        if ($result === null) {
            error_log('IMC has_trustline: all XRPL endpoints unreachable — verdict unverifiable for ' . $wallet . ' ' . $ticker);
            return null;
        }

        if (!empty($result['lines'])) {
            $token = IMC_Token_Registry::get_by_ticker($ticker);
            // v691: match the ledger's on-wire currency — non-standard codes (>3 chars) are the
            // 40-char ASCII-hex; prefer a registry hex, else derive it (same as get_known_tokens).
            $currency = !empty($token['currency_hex'])
                ? strtoupper($token['currency_hex'])
                : ((strlen($ticker) > 3) ? strtoupper(str_pad(bin2hex($ticker), 40, '0')) : strtoupper($ticker));
            // v733: also accept the PLAIN ticker form — a registry row carrying a stray
            // currency_hex for a 3-char code must not fail a genuine plain-code line.
            $imc_accept = [strtoupper($currency), strtoupper($ticker)];
            if (strlen($ticker) > 3) $imc_accept[] = strtoupper(str_pad(bin2hex($ticker), 40, '0'));

            foreach ($result['lines'] as $line) {
                if (in_array(strtoupper($line['currency']), $imc_accept, true)) return true;
            }
        }
        return false;
    }
    
    public static function get_trustline_url($ticker) {
        $token = IMC_Token_Registry::get_by_ticker($ticker);
        if (!$token || empty($token['issuer'])) return null;
        
        if (!empty($token['trustline_url'])) return $token['trustline_url'];
        
        $currency = $token['currency_hex'] ?? $ticker;
        // v716 (G2A): xrpl.services, matching the format seeded for every curated token in
        // the Token Manager. This branch only runs when a registry token has no stored URL,
        // so before v716 those fell back to a xumm.app deeplink -- Xaman-only, and not the
        // destination we standardised on.
        return "https://xrpl.services/?issuer={$token['issuer']}&currency={$currency}&limit=1000000000";
    }
}

// ============================================================================
// REST API ENDPOINTS
// ============================================================================

add_action('rest_api_init', function() {
    $ns = 'imc-price/v1';
    
    register_rest_route($ns, '/prices', [
        'methods' => 'GET',
        'callback' => function() {
            $tokens = IMC_Token_Registry::get_all();
            $prices = [];
            
            foreach ($tokens as $t) {
                $usd = IMC_Price_Oracle::get_token_usd_price($t['ticker'], $t['issuer'] ?? '');
                $prices[$t['ticker']] = [
                    'usd' => $usd,
                    'display_name' => $t['display_name'],
                    'is_native' => !empty($t['is_native']),
                    'is_stablecoin' => !empty($t['is_stablecoin']),
                    'unavailable' => $usd === null
                ];
            }
            
            return rest_ensure_response(['prices' => $prices, 'fetched_at' => time()]);
        },
        'permission_callback' => '__return_true'
    ]);
    
    register_rest_route($ns, '/calculate', [
        'methods' => 'GET',
        'callback' => function($req) {
            $base = (float)$req->get_param('base_usd');
            if ($base <= 0) return new WP_Error('invalid', 'base_usd must be positive', ['status' => 400]);
            
            $enabled_tokens = $req->get_param('tokens') ? json_decode($req->get_param('tokens'), true) : null;
            $prices = IMC_Price_Oracle::calculate_all_prices($base, $enabled_tokens);
            
            return rest_ensure_response(['base_usd' => $base, 'prices' => $prices, 'calculated_at' => time()]);
        },
        'permission_callback' => '__return_true'
    ]);
    
    register_rest_route($ns, '/trustline/check', [
        'methods' => 'GET',
        'callback' => function($req) {
            $wallet = sanitize_text_field($req->get_param('wallet'));
            $ticker = strtoupper(sanitize_text_field($req->get_param('ticker')));
            
            if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $wallet)) {
                return new WP_Error('invalid_wallet', 'Invalid wallet address', ['status' => 400]);
            }
            
            $token  = IMC_Token_Registry::get_by_ticker($ticker);
            $issuer = imc_resolve_trustline_issuer($ticker, $req->get_param('issuer') ?? '', $token);
            $has    = IMC_Trustline_Helper::has_trustline($wallet, $ticker, $issuer);
            // v733: tri-state — null (unverifiable) fails OPEN, same contract as the AJAX path.
            $verified = ($has !== null);
            if ($has === null) $has = true;
            
            return rest_ensure_response([
                'wallet' => $wallet,
                'verified' => $verified,
                'ticker' => $ticker,
                'has_trustline' => $has,
                'is_native' => !empty($token['is_native']),
                'trustline_url' => IMC_Trustline_Helper::get_trustline_url($ticker)
            ]);
        },
        'permission_callback' => '__return_true'
    ]);
    
    register_rest_route($ns, '/debug', [
        'methods' => 'GET',
        'callback' => function() {
            delete_transient('imc_xrp_usd');
            
            $debug = ['timestamp' => date('Y-m-d H:i:s'), 'version' => IMC_PRICE_ORACLE_VER, 'sources_tried' => []];
            
            // CoinGecko
            $cg_start = microtime(true);
            $r = wp_remote_get('https://api.coingecko.com/api/v3/simple/price?ids=ripple&vs_currencies=usd', ['timeout' => 10]);
            $cg_time = round((microtime(true) - $cg_start) * 1000, 2);
            
            if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) {
                $b = json_decode(wp_remote_retrieve_body($r), true);
                $debug['sources_tried']['coingecko'] = ['status' => 'success', 'response_time_ms' => $cg_time, 'price' => $b['ripple']['usd'] ?? null];
                if (!isset($debug['final_price']) && isset($b['ripple']['usd'])) {
                    $debug['final_price'] = (float)$b['ripple']['usd'];
                    $debug['source_used'] = 'coingecko';
                }
            } else {
                $debug['sources_tried']['coingecko'] = ['status' => 'failed', 'response_time_ms' => $cg_time];
            }
            
            // Kraken
            $kr_start = microtime(true);
            $r = wp_remote_get('https://api.kraken.com/0/public/Ticker?pair=XRPUSD', ['timeout' => 10]);
            $kr_time = round((microtime(true) - $kr_start) * 1000, 2);
            
            if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) {
                $b = json_decode(wp_remote_retrieve_body($r), true);
                $debug['sources_tried']['kraken'] = ['status' => 'success', 'response_time_ms' => $kr_time, 'price' => $b['result']['XXRPZUSD']['c'][0] ?? null];
            } else {
                $debug['sources_tried']['kraken'] = ['status' => 'failed', 'response_time_ms' => $kr_time];
            }
            
            // Bitstamp
            $bs_start = microtime(true);
            $r = wp_remote_get('https://www.bitstamp.net/api/v2/ticker/xrpusd/', ['timeout' => 10]);
            $bs_time = round((microtime(true) - $bs_start) * 1000, 2);
            
            if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) {
                $b = json_decode(wp_remote_retrieve_body($r), true);
                $debug['sources_tried']['bitstamp'] = ['status' => 'success', 'response_time_ms' => $bs_time, 'price' => $b['last'] ?? null];
            } else {
                $debug['sources_tried']['bitstamp'] = ['status' => 'failed', 'response_time_ms' => $bs_time];
            }
            
            // Test XFT DEX price
            $xft = IMC_Token_Registry::get_by_ticker('XFT');
            if ($xft && isset($debug['final_price'])) {
                $dex_start = microtime(true);
                $dex = IMC_Price_Oracle::get_dex_price('XFT', $xft['issuer'], $debug['final_price'], $xft['currency_hex'] ?? '');
                $dex_time = round((microtime(true) - $dex_start) * 1000, 2);
                $debug['token_tests']['XFT_dex'] = ['source' => 'dex_orderbook', 'response_time_ms' => $dex_time, 'price_usd' => $dex, 'available' => $dex !== null];
                
                // Also test via get_token_usd_price
                delete_transient('imc_price_' . md5('XFT|' . $xft['issuer']));
                $full = IMC_Price_Oracle::get_token_usd_price('XFT', $xft['issuer']);
                $debug['token_tests']['XFT_full'] = ['price_usd' => $full, 'available' => $full !== null];
            }
            
            $debug['get_xrp_usd_price_result'] = IMC_Price_Oracle::get_xrp_usd_price();
            $debug['note'] = 'v82: NO hardcoded fallbacks - null means no DEX/AMM liquidity';
            
            return rest_ensure_response($debug);
        },
        'permission_callback' => '__return_true'
    ]);
    
    register_rest_route($ns, '/refresh', [
        'methods' => 'POST',
        'callback' => function() {
            IMC_Price_Oracle::refresh_all_prices();
            return rest_ensure_response(['success' => true, 'refreshed_at' => time()]);
        },
        'permission_callback' => function() { return current_user_can('manage_options'); }
    ]);
});

// ============================================================================
// AJAX HANDLERS
// ============================================================================

add_action('wp_ajax_imc_price_calculate', 'imc_ajax_price_calculate');
add_action('wp_ajax_nopriv_imc_price_calculate', 'imc_ajax_price_calculate');

function imc_ajax_price_calculate() {
    $base_usd = (float)($_GET['base_usd'] ?? $_POST['base_usd'] ?? 0);
    $ticker = strtoupper(sanitize_text_field($_GET['ticker'] ?? $_POST['ticker'] ?? ''));
    $discount = (float)($_GET['discount_pct'] ?? $_POST['discount_pct'] ?? 0);
    
    if ($base_usd <= 0) wp_send_json_error(['message' => 'Invalid base_usd']);
    
    if (empty($ticker)) {
        wp_send_json_success(['prices' => IMC_Price_Oracle::calculate_all_prices($base_usd)]);
    } else {
        $token = IMC_Token_Registry::get_by_ticker($ticker);
        $result = IMC_Price_Oracle::calculate_token_amount($base_usd, $ticker, $token['issuer'] ?? '', $discount);
        
        if ($result && !$result['unavailable']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error(['message' => 'Price unavailable for ' . $ticker, 'unavailable' => true]);
        }
    }
}

/**
 * v691: Resolve a token's issuer for a trustline check, fragmentation-proof.
 * Order: explicit issuer (the listing's accepted_currencies — the ONLY source that covers
 * creator-custom tokens) -> DB registry -> Token Manager option list. Returns '' when
 * unresolvable, so has_trustline() fails CLOSED (prompts Set Trustline) rather than open.
 */
function imc_resolve_trustline_issuer($ticker, $explicit = '', $reg = null) {
    $explicit = trim((string) $explicit);
    if (preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $explicit)) return $explicit;
    if ($reg === null && class_exists('IMC_Token_Registry')) $reg = IMC_Token_Registry::get_by_ticker($ticker);
    if (!empty($reg['issuer'])) return $reg['issuer'];
    if (function_exists('imc_get_token_by_ticker')) {
        $opt = imc_get_token_by_ticker($ticker);
        if (!empty($opt['issuer'])) return $opt['issuer'];
    }
    return '';
}

add_action('wp_ajax_imc_check_trustline', 'imc_ajax_check_trustline');
add_action('wp_ajax_nopriv_imc_check_trustline', 'imc_ajax_check_trustline');

function imc_ajax_check_trustline() {
    $wallet = sanitize_text_field($_GET['wallet'] ?? $_POST['wallet'] ?? '');
    $ticker = strtoupper(sanitize_text_field($_GET['ticker'] ?? $_POST['ticker'] ?? ''));
    
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $wallet)) {
        wp_send_json_error(['message' => 'Invalid wallet']);
    }
    
    $token  = IMC_Token_Registry::get_by_ticker($ticker);
    $issuer = imc_resolve_trustline_issuer($ticker, $_GET['issuer'] ?? $_POST['issuer'] ?? '', $token);
    $has    = IMC_Trustline_Helper::has_trustline($wallet, $ticker, $issuer);
    // v733: tri-state — null means "could not verify" (every XRPL endpoint failed).
    // The platform's contract is FAIL OPEN on our own outage (an artist or buyer is
    // never warned/blocked because WE could not reach the ledger), so null maps to
    // has_trustline=true with verified=false for observability.
    $verified = ($has !== null);
    if ($has === null) $has = true;

    // v695: return the resolved issuer, display name, and a Set-Trustline deeplink so the client
    // result is honest (deeplink built from the resolved issuer + derived hex, same as
    // imc_build_trustset — NOT registry-bound get_trustline_url, which is null for custom tokens).
    $currency = !empty($token['currency_hex'])
        ? strtoupper($token['currency_hex'])
        : ((strlen($ticker) > 3) ? strtoupper(str_pad(bin2hex($ticker), 40, '0')) : strtoupper($ticker));
    $trustline_url = $issuer
        ? 'https://xrpl.services/?issuer=' . rawurlencode($issuer) . '&currency=' . rawurlencode($currency) . '&limit=1000000000'
        : '';

    wp_send_json_success([
        'wallet'        => $wallet,
        'ticker'        => $ticker,
        'has_trustline' => $has,
        'verified'      => $verified, // v733: false = fail-open default, not a ledger verdict
        'is_native'     => (is_array($token) && !empty($token['is_native'])),
        'issuer'        => $issuer,
        'name'          => (is_array($token) && !empty($token['display_name'])) ? $token['display_name'] : $ticker,
        'trustline_url' => $trustline_url,
        // v715: the issuer's transfer fee, as a multiplier (1.0 = none, 1.0033 = 0.33%).
        // Surfaced here because this endpoint is what every "?" popover already calls --
        // the mint popup AND the collection-card badges. The card surfaces have no way to
        // know the rate themselves, so returning it here is the only single change that
        // reaches them all. null means "could not establish" and the UI simply says nothing.
        // Cached for IMC_CACHE_TTL_TRANSFER_RATE, so this costs no extra ledger traffic.
        'transfer_rate' => ($issuer && strtoupper($ticker) !== 'XRP')
            ? IMC_Price_Oracle::get_transfer_rate($issuer)
            : null,
    ]);
}

add_action('wp_ajax_imc_build_trustset', 'imc_ajax_build_trustset');
add_action('wp_ajax_nopriv_imc_build_trustset', 'imc_ajax_build_trustset');

/**
 * v692: Build a wallet-agnostic "set trustline" payload for a token.
 * Returns a parametric TrustSet txjson (for Joey / WalletConnect local signing) AND a Xaman
 * deeplink, both derived from the EXPLICIT issuer + ticker — never the registry-bound
 * get_trustline_url(), which returns null for creator-custom tokens. Fails closed when the
 * issuer is unresolvable: you cannot set a trustline without one. Builds a payload only; it
 * signs and submits nothing (the user's own wallet does that), so no nonce/auth is required.
 */
function imc_ajax_build_trustset() {
    $ticker  = strtoupper(sanitize_text_field($_GET['ticker'] ?? $_POST['ticker'] ?? ''));
    $account = sanitize_text_field($_GET['account'] ?? $_POST['account'] ?? '');

    if ($ticker === '' || $ticker === 'XRP') {
        wp_send_json_error(['message' => 'XRP needs no trustline']);
    }

    $reg    = IMC_Token_Registry::get_by_ticker($ticker);
    $issuer = imc_resolve_trustline_issuer($ticker, $_GET['issuer'] ?? $_POST['issuer'] ?? '', $reg);
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $issuer)) {
        wp_send_json_error(['message' => 'Unable to resolve token issuer']);
    }

    // Same ledger-currency derivation as has_trustline(): prefer a registry hex, else derive the
    // 40-char ASCII-hex for non-standard (>3-char) codes; standard 3-char codes stay as-is.
    $currency = !empty($reg['currency_hex'])
        ? strtoupper($reg['currency_hex'])
        : ((strlen($ticker) > 3) ? strtoupper(str_pad(bin2hex($ticker), 40, '0')) : strtoupper($ticker));

    // v716 (G2A): xrpl.services. This is the NON-Joey path only -- Joey signs the $txjson
    // built below and never opens a URL, so that branch is untouched. The 40-char hex form
    // is accepted here: several live curated tokens ($HORDE, CORN, Keda's Brew Coin) are
    // seeded with hex currency codes against this same endpoint.
    $deeplink = 'https://xrpl.services/?issuer=' . rawurlencode($issuer)
              . '&currency=' . rawurlencode($currency) . '&limit=1000000000';

    // Joey txjson requires the buyer's Account; omit it (Xaman-only response) if not a valid r-address.
    $txjson = null;
    if (preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        // Flags 131072 = tfSetNoRipple. Rippling OFF is the correct configuration
        // for a normal holder trustline: with it on, a third party can move balances
        // through the account between two trustlines of the same currency.
        //
        // ⚠ DO NOT rely on the protocol default here. The XRPL docs describe a
        // default NoRipple state for accounts without DefaultRipple, but that
        // passage is about RESERVE accounting, not the lsfNoRipple flag itself -
        // VERIFIED on a live IMC-initiated trustline, which Xaman reported as
        // "suboptimal configuration detected - rippling is not turned off".
        // The flag has to be sent explicitly.
        //
        // Idempotent: TrustSet flags are per-transaction, so re-sending this on a
        // later limit change simply keeps NoRipple on. We never send
        // tfClearNoRipple (262144) anywhere.
        // Matches the remote signing service, which has always set it.
        $txjson = [
            'TransactionType' => 'TrustSet',
            'Account'         => $account,
            'Flags'           => 131072,
            'LimitAmount'     => ['currency' => $currency, 'issuer' => $issuer, 'value' => '1000000000'],
        ];
    }

    wp_send_json_success([
        'ticker'        => $ticker,
        'issuer'        => $issuer,
        'currency'      => $currency,
        'txjson'        => $txjson,
        'trustline_url' => $deeplink,
    ]);
}

// Initialize tables - v82: Use plugins_loaded hook (after $wpdb is ready)
add_action('plugins_loaded', 'imc_ensure_price_tables', 20);