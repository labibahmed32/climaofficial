<?php
/**
 * Clima Network — Server-side click redirect
 * Offer18 / Everflow test tools require HTTP 302 redirect (not JS redirect)
 */

$FB = 'https://clima-dashboard-default-rtdb.firebaseio.com';

$offerId = trim($_GET['offer'] ?? '');
$affId   = trim($_GET['aff']   ?? '');
$sub1    = $_GET['sub1'] ?? '';
$sub2    = $_GET['sub2'] ?? '';
$sub3    = $_GET['sub3'] ?? '';
$sub4    = $_GET['sub4'] ?? '';
$sub5    = $_GET['sub5'] ?? '';
$fbclid  = $_GET['fbclid'] ?? '';
$gclid   = $_GET['gclid']  ?? '';
$ttclid  = $_GET['ttclid'] ?? '';
$isTest  = isset($_GET['test']) && $_GET['test'] == '1';

if (!$offerId || !$affId) {
    http_response_code(400);
    die('Missing offer or aff parameter.');
}

/* ── Fetch offer from Firebase ── */
$offerJson = @file_get_contents($FB . '/offers/' . $offerId . '.json');
$offer = $offerJson ? json_decode($offerJson, true) : null;

if (!$offer || ($offer['status'] ?? '') !== 'active') {
    http_response_code(404);
    die('Offer not found or inactive.');
}

/* ── Fetch affiliate/user ── */
$userJson = @file_get_contents($FB . '/users/' . $affId . '.json');
$user = $userJson ? json_decode($userJson, true) : null;

if (!$user || ($user['status'] ?? '') !== 'active') {
    http_response_code(403);
    die('Affiliate not active.');
}

/* ── Generate click ID ── */
$clickId = 'c' . substr(bin2hex(random_bytes(8)), 0, 12);

/* ── Get IP ── */
$ip = '';
foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
    if (!empty($_SERVER[$k])) { $ip = trim(explode(',', $_SERVER[$k])[0]); break; }
}

/* ── DUPLICATE IP CHECK ──
   Same IP + same offer = duplicate (regardless of which affiliate). Block, log, no redirect. */
if ($ip) {
    $ipKey = preg_replace('/[.:]/', '_', $ip);
    $dedupUrl = $FB . '/clickDedup/' . urlencode($offerId) . '/' . urlencode($ipKey) . '.json';
    $dedupRes = @file_get_contents($dedupUrl);
    $existing = $dedupRes ? json_decode($dedupRes, true) : null;
    if ($existing) {
        /* Duplicate — record blocked click and show block page */
        $dupClickId = 'c' . substr(bin2hex(random_bytes(8)), 0, 12);
        $dupClickData = [
            'offerId'        => $offerId,
            'affiliateId'    => $affId,
            'timestamp'      => (int)(microtime(true) * 1000),
            'converted'      => false,
            'test'           => $isTest,
            'ip'             => $ip,
            'userAgent'      => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'referer'        => $_SERVER['HTTP_REFERER']    ?? '',
            'landingUrl'     => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''),
            'sub1'           => $sub1, 'sub2' => $sub2, 'sub3' => $sub3, 'sub4' => $sub4, 'sub5' => $sub5,
            'fbclid'         => $fbclid, 'gclid' => $gclid, 'ttclid' => $ttclid,
            'blocked'        => true,
            'blockReason'    => 'duplicate_ip',
            'originalClickId'=> is_array($existing) ? ($existing['clickId'] ?? '') : $existing,
            'source'         => 'php'
        ];
        $ctx2 = stream_context_create(['http' => ['method' => 'PUT', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode($dupClickData), 'timeout' => 4]]);
        @file_get_contents($FB . '/clicks/' . $dupClickId . '.json', false, $ctx2);
        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Already Clicked</title><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f5f7fa;color:#333;padding:24px;}.box{text-align:center;max-width:440px;padding:40px 30px;background:#fff;border-radius:14px;box-shadow:0 4px 24px rgba(0,0,0,.06);}.warn{font-size:54px;color:#f59e0b;margin-bottom:14px;}h2{font-size:20px;font-weight:700;color:#111827;margin:0 0 8px;}p{font-size:14px;color:#64748b;line-height:1.55;margin:0;}</style></head><body><div class="box"><div class="warn">&#9888;</div><h2>Link Already Used</h2><p>This offer has already been clicked from your network. Each user can only access this offer once.</p></div></body></html>';
        exit;
    }
}

/* ── GEO ENFORCEMENT (strict mode) ── */
$visitorCC = '';
$visitorCountryName = '';
if (!empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
    /* Cloudflare passes country code in this header — fastest path */
    $visitorCC = strtoupper(trim($_SERVER['HTTP_CF_IPCOUNTRY']));
}
if (($offer['countryMode'] ?? '') === 'specific' && !empty($offer['allowedCountries'])) {
    $allowed = array_filter(array_map('trim', array_map('strtoupper', explode(',', $offer['allowedCountries']))));
    /* ── Multi-provider consensus ─────────────────────────────────────
       Query 3 providers in parallel via curl_multi. The country code
       must get at least 2 votes (CF header counts as 1) to pass. */
    $votes = [];
    if ($visitorCC) { $votes[$visitorCC] = 1; }
    if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
        $endpoints = [
            ['url' => 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,countryCode,country', 'cc' => 'countryCode', 'name' => 'country'],
            ['url' => 'https://ipwho.is/' . urlencode($ip),                                              'cc' => 'country_code', 'name' => 'country'],
            ['url' => 'https://ipapi.co/' . urlencode($ip) . '/json/',                                   'cc' => 'country_code', 'name' => 'country_name']
        ];
        $mh = curl_multi_init();
        $handles = [];
        foreach ($endpoints as $i => $e) {
            $ch = curl_init($e['url']);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => 'Mozilla/5.0']);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }
        $running = null;
        do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.2); } while ($running > 0);
        foreach ($handles as $i => $ch) {
            $r = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            if ($r) {
                $d = json_decode($r, true);
                if (!empty($d[$endpoints[$i]['cc']])) {
                    $cc = strtoupper($d[$endpoints[$i]['cc']]);
                    $votes[$cc] = ($votes[$cc] ?? 0) + 1;
                    if (!$visitorCountryName && !empty($d[$endpoints[$i]['name']])) $visitorCountryName = $d[$endpoints[$i]['name']];
                }
            }
            curl_close($ch);
        }
        curl_multi_close($mh);
    }
    /* Pick winning country */
    $winningCC = null; $winningVotes = 0;
    foreach ($votes as $cc => $v) { if ($v > $winningVotes) { $winningCC = $cc; $winningVotes = $v; } }
    $visitorCC = $winningCC ?: $visitorCC;
    /* Require at least 2 votes for strict mode */
    $blockReason = '';
    if (!$visitorCC) {
        $blockReason = 'no_geo_detected';
    } elseif ($winningVotes < 2) {
        $blockReason = 'no_geo_consensus';
    } elseif (!in_array($visitorCC, $allowed, true)) {
        $blockReason = 'geo_not_allowed';
    }
    if ($blockReason) {
        /* Log blocked attempt */
        $bId = 'b' . substr(bin2hex(random_bytes(6)), 0, 10);
        $blockData = [
            'offerId' => $offerId, 'affiliateId' => $affId, 'timestamp' => (int)(microtime(true) * 1000),
            'ip' => $ip, 'country' => $visitorCC, 'countryName' => $visitorCountryName,
            'blocked' => true, 'blockReason' => $blockReason,
            'votes' => $votes, 'winningVotes' => $winningVotes,
            'allowedCountries' => implode(',', $allowed),
            'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'source' => 'php'
        ];
        $bCtx = stream_context_create(['http' => ['method' => 'PUT', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode($blockData), 'timeout' => 4]]);
        @file_get_contents($FB . '/blockedClicks/' . $bId . '.json', false, $bCtx);
        /* Render block page (no redirect) */
        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');
        $allowedDisplay = htmlspecialchars(implode(', ', array_slice($allowed, 0, 8)), ENT_QUOTES);
        $visitorDisplay = htmlspecialchars($visitorCountryName ?: $visitorCC, ENT_QUOTES);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Not Available</title><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f5f7fa;color:#333;padding:24px;}.box{text-align:center;max-width:440px;padding:40px 30px;background:#fff;border-radius:14px;box-shadow:0 4px 24px rgba(0,0,0,.06);}.warn{font-size:54px;color:#dc2626;margin-bottom:14px;}h2{font-size:20px;font-weight:700;color:#111827;margin:0 0 8px;}p{font-size:14px;color:#64748b;line-height:1.55;margin:0;}strong{color:#0A6C80;}</style></head><body><div class="box"><div class="warn">&#9888;</div><h2>Not Available in Your Region</h2><p>This offer is only available in: <strong>' . $allowedDisplay . '</strong>.' . ($visitorDisplay ? '<br>Detected location: <strong>' . $visitorDisplay . '</strong>' : '') . '</p></div></body></html>';
        exit;
    }
}

/* ── Build destination URL (replace macros) ── */
$destUrl = $offer['finalUrl'] ?? '';
$destUrl = str_replace('{click_id}', urlencode($clickId), $destUrl);
$destUrl = str_replace('{aff_id}',   urlencode($affId),   $destUrl);
$destUrl = str_replace('{sub1}',     urlencode($sub1),     $destUrl);
$destUrl = str_replace('{sub2}',     urlencode($sub2),     $destUrl);
$destUrl = str_replace('{sub3}',     urlencode($sub3),     $destUrl);
$destUrl = str_replace('{sub4}',     urlencode($sub4),     $destUrl);
$destUrl = str_replace('{sub5}',     urlencode($sub5),     $destUrl);
$destUrl = str_replace('{fbclid}',   urlencode($fbclid),   $destUrl);
$destUrl = str_replace('{gclid}',    urlencode($gclid),    $destUrl);
$destUrl = str_replace('{ttclid}',   urlencode($ttclid),   $destUrl);

if (!$destUrl) { http_response_code(500); die('Offer has no destination URL.'); }

/* ── Save click to Firebase ── */
$clickData = [
    'offerId'     => $offerId,
    'affiliateId' => $affId,
    'timestamp'   => (int)(microtime(true) * 1000),
    'converted'   => false,
    'test'        => $isTest,
    'ip'          => $ip,
    'userAgent'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'referer'     => $_SERVER['HTTP_REFERER']    ?? '',
    'landingUrl'  => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST']??'') . ($_SERVER['REQUEST_URI']??''),
    'sub1'        => $sub1,
    'sub2'        => $sub2,
    'sub3'        => $sub3,
    'sub4'        => $sub4,
    'sub5'        => $sub5,
    'fbclid'      => $fbclid,
    'gclid'       => $gclid,
    'ttclid'      => $ttclid,
    'country'     => '',
    'city'        => '',
    'device'      => '',
    'browser'     => '',
    'os'          => '',
    'isp'         => '',
    'source'      => 'php',
];

$ctx = stream_context_create([
    'http' => [
        'method'  => 'PUT',
        'header'  => "Content-Type: application/json\r\n",
        'content' => json_encode($clickData),
        'timeout' => 5,
        'ignore_errors' => true,
    ]
]);
@file_get_contents($FB . '/clicks/' . $clickId . '.json', false, $ctx);

/* ── Record dedup index so future same-IP clicks on this offer are blocked (any affiliate) ── */
if ($ip) {
    $ipKeyDone = preg_replace('/[.:]/', '_', $ip);
    $dedupPayload = json_encode(['clickId' => $clickId, 'affiliateId' => $affId, 'ts' => (int)(microtime(true) * 1000)]);
    $dedupCtx = stream_context_create(['http' => ['method' => 'PUT', 'header' => "Content-Type: application/json\r\n", 'content' => $dedupPayload, 'timeout' => 4]]);
    @file_get_contents($FB . '/clickDedup/' . urlencode($offerId) . '/' . urlencode($ipKeyDone) . '.json', false, $dedupCtx);
}

/* ── HTTP 302 Redirect ── */
header('Location: ' . $destUrl, true, 302);
exit;
