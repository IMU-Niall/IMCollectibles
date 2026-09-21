<?php
/**
 * IMUP3 Discovery Sections — public read endpoint  (S1e / D-42)
 * File: imup3-discovery-handler.php
 * Path: /wp-content/themes/astra/xrpl-nft-marketplace/backend/imup3-discovery-handler.php
 *
 * GET ?action=sections
 *   Returns the curated Discover home for IMUP3: four sections
 *   (music / album / art / video), each with today's rotated slice.
 *
 * PUBLIC READ, on the same reasoning collections-api-handler applies to
 * get_by_artist: the data is public (collection identity, chosen by an admin).
 * No writes, no user data, no market fields (D-36).
 *
 * ROTATION (D-42): curation is stable, the SLICE rotates DAILY. Seeded by the
 * UTC date + section key, so every user sees the same set on a given day and it
 * turns over at midnight. Same mt_srand(crc32(...)) pattern as the v772
 * collections forever-scroll.
 *
 * The response carries collection IDENTITY only. The app resolves names, covers
 * and counts from the VPS store (?action=discover / ?action=collections), which
 * already applies imc_out()'s gateway policy — so nothing is duplicated here and
 * nothing goes stale.
 */

// WordPress bootstrap — same form as collections-api-handler.php in this
// directory. From .../themes/astra/xrpl-nft-marketplace/backend/ the web root
// is FIVE levels up, six on installs with an extra wrapper directory.
$wp_load = dirname(__FILE__, 5) . '/wp-load.php';
if (!file_exists($wp_load)) {
    $wp_load = dirname(__FILE__, 6) . '/wp-load.php';
}
require_once $wp_load;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$action = $_REQUEST['action'] ?? '';

if ($action !== 'sections') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

if (!function_exists('imup3_discovery_get_sections')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Discovery admin not loaded']);
    exit;
}

// UTC so the rotation turns over at the same instant for every user, wherever
// the server or the phone happens to be.
$day = gmdate('Y-m-d');

$out = [];
foreach (imup3_discovery_get_sections() as $s) {
    if (empty($s['enabled'])) continue;

    $members = array_values(array_filter((array) ($s['members'] ?? []), function ($m) {
        return !empty($m['issuer']);
    }));

    $show = min(12, max(1, (int) ($s['show'] ?? 6)));
    // UX tune (3 Sep 2026): the Discover HERO is editorial - it must never be
    //   daily-sliced. Whatever 'Show per day' holds, send every curated member.
    if (($s['key'] ?? '') === 'new_to_xrpl') $show = max($show, count($members));

    // Deterministic daily slice. Shuffle a COPY of the index list, not the
    // members themselves, so the stored curation order is never disturbed.
    if (count($members) > $show) {
        $idx = range(0, count($members) - 1);
        mt_srand(crc32($day . '|' . $s['key']));
        shuffle($idx);
        mt_srand();                       // restore global randomness
        $idx = array_slice($idx, 0, $show);
        sort($idx);                       // present in curation order, not shuffle order
        $picked = [];
        foreach ($idx as $i) $picked[] = $members[$i];
        $members = $picked;
    }

    $items = [];
    foreach ($members as $m) {
        $items[] = [
            'issuer' => (string) $m['issuer'],
            'taxon'  => (int) ($m['taxon'] ?? 0),
        ];
    }

    $out[] = [
        'key'         => (string) $s['key'],
        'title'       => (string) $s['title'],
        'type'        => (string) $s['type'],   // music|album|art|video — the View All target
        'collections' => $items,
        'total'       => count((array) ($s['members'] ?? [])),
    ];
}

echo json_encode([
    'success'  => true,
    'day'      => $day,
    'sections' => $out,
]);