<?php
/**
 * R5 / M2b-det — THE DUPLICATE-EDITION TRIPWIRE
 * ------------------------------------------------------------------
 * Standalone, read-only. NEW file: touches nothing existing. Runs the
 * standing detector query and compares against a captured baseline
 * (the recorded baseline pairs). Silence = healthy. ANY new tuple =
 * CRITICAL log lines + nonzero exit — a new row is the one thing
 * the four protection layers say can never happen.
 *
 * Location: same backend/ dir as mint-on-demand-handler.php.
 * Auth: CLI (hPanel cron / wp-cli box) runs freely; HTTP requires
 *        admin_key === IMC_COMP_ADMIN_KEY (mirrors the comp lane).
 * Modes: --init      capture/overwrite the baseline (run ONCE, day 1)
 *        (none)      weekly check against baseline
 *        --selftest  inject a fake tuple in-memory to prove the alarm
 */
$is_cli = (php_sapi_name() === 'cli');
$wp_load = dirname(__DIR__, 5) . '/wp-load.php';
if (!file_exists($wp_load)) { fwrite(STDERR, "wp-load not found\n"); exit(2); }
require_once $wp_load;

function det_log($m) {
    $line = '[' . date('Y-m-d H:i:s') . "] $m\n";
    @file_put_contents(__DIR__ . '/logs/duplicate-detector.log', $line, FILE_APPEND);
    if (php_sapi_name() === 'cli') echo $line;
}

if (!$is_cli) {
    $key = defined('IMC_COMP_ADMIN_KEY') ? IMC_COMP_ADMIN_KEY : '';
    if ($key === '' || !hash_equals($key, strval($_POST['admin_key'] ?? ''))) {
        http_response_code(403); die('forbidden');
    }
}

global $wpdb;
$t = $wpdb->prefix . 'imc_purchases';
$rows = $wpdb->get_results(
    "SELECT listing_id, edition_number, COUNT(*) n FROM $t
      WHERE edition_number > 0 AND mint_status IN ('minted','claimed','paid','minting')
      GROUP BY listing_id, edition_number HAVING n > 1
      ORDER BY listing_id, edition_number", ARRAY_A
);
$found = array_map(function ($r) {
    return $r['listing_id'] . ':' . $r['edition_number'] . ':' . $r['n'];
}, $rows ?: []);

$baseline_file = __DIR__ . '/logs/duplicate-detector.baseline.json';
$argv1 = $is_cli ? ($argv[1] ?? '') : '';

if ($argv1 === '--selftest') { $found[] = '999999:1:2'; det_log('SELFTEST: injected fake tuple'); }

if ($argv1 === '--init') {
    @mkdir(__DIR__ . '/logs', 0755, true);
    file_put_contents($baseline_file, json_encode($found, JSON_PRETTY_PRINT));
    det_log('BASELINE CAPTURED: ' . count($found) . ' historical tuple(s): ' . implode(', ', $found));
    exit(0);
}

if (!file_exists($baseline_file)) {
    det_log('NO BASELINE — run once with --init first. Current tuples: ' . count($found));
    if (!$is_cli) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'no baseline']); }
    exit(2);
}
$baseline = json_decode(file_get_contents($baseline_file), true) ?: [];
$new_tuples  = array_values(array_diff($found, $baseline));
$gone_tuples = array_values(array_diff($baseline, $found));   // e.g. a burn resolved one — informational

if (empty($new_tuples)) {
    det_log('OK — ' . count($found) . '/' . count($baseline) . ' tuples, all baseline.'
        . ($gone_tuples ? ' (resolved since baseline: ' . implode(', ', $gone_tuples) . ')' : ''));
    if (!$is_cli) { header('Content-Type: application/json'); echo json_encode(['ok' => true, 'tuples' => count($found)]); }
    exit(0);
}

// ── THE ALARM ──────────────────────────────────────────────────────
foreach ($new_tuples as $nt) {
    det_log("CRITICAL: NEW DUPLICATE EDITION TUPLE (listing:edition:count) => $nt");
    if (function_exists('mod_log')) { /* handler context only */ }
}
det_log('CRITICAL: a new-row event. Freeze mint-path deploys; audit the allocation-paths table; identify both purchases for the tuple(s) above.');
if (defined('IMC_ALERT_EMAIL') && IMC_ALERT_EMAIL) {
    @wp_mail(IMC_ALERT_EMAIL, '[IMC] DUPLICATE EDITION DETECTED', implode("\n", $new_tuples));
}
if (!$is_cli) { header('Content-Type: application/json'); http_response_code(500); echo json_encode(['ok' => false, 'new' => $new_tuples]); }
exit(1);