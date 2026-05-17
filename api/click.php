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
    /* 24-hour TTL: only block if dedup entry is less than 24 hours old */
    $dedupTsMs = is_array($existing) ? ($existing['ts'] ?? 0) : 0;
    $isExpired = ($dedupTsMs === 0) || ((microtime(true) * 1000) - $dedupTsMs) > 86400000;
    if ($existing && !$isExpired) {
        /* Duplicate within 24h — enrich with geo + UA parse, record blocked click, show block page */
        $dupGeo = ['country' => '', 'countryName' => '', 'city' => '', 'region' => '', 'isp' => '', 'lat' => 0, 'lon' => 0, 'ipTimezone' => ''];
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
            $gCh = curl_init('http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,countryCode,region,regionName,city,timezone,isp,org,as,lat,lon');
            curl_setopt_array($gCh, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
            $gR = curl_exec($gCh); curl_close($gCh);
            if ($gR) {
                $gD = json_decode($gR, true);
                if ($gD && ($gD['status'] ?? '') === 'success') {
                    $dupGeo['country']     = $gD['countryCode'] ?? '';
                    $dupGeo['countryName'] = $gD['country'] ?? '';
                    $dupGeo['city']        = $gD['city'] ?? '';
                    $dupGeo['region']      = $gD['regionName'] ?? '';
                    $dupGeo['isp']         = $gD['isp'] ?? '';
                    $dupGeo['org']         = $gD['org'] ?? '';
                    $dupGeo['asn']         = $gD['as'] ?? '';
                    $dupGeo['lat']         = $gD['lat'] ?? 0;
                    $dupGeo['lon']         = $gD['lon'] ?? 0;
                    $dupGeo['ipTimezone']  = $gD['timezone'] ?? '';
                }
            }
        }
        /* Light UA parse */
        $uaStr = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $devType = (preg_match('/Mobi|Android|iPhone|iPod/i', $uaStr)) ? 'mobile' : ((preg_match('/Tablet|iPad/i', $uaStr)) ? 'tablet' : 'desktop');
        $browser = 'Other'; $browserVer = '';
        if (preg_match('/Edg\/(\d+)/i', $uaStr, $m))                  { $browser='Edge';    $browserVer=$m[1]; }
        elseif (preg_match('/OPR\/(\d+)/i', $uaStr, $m))              { $browser='Opera';   $browserVer=$m[1]; }
        elseif (preg_match('/Firefox\/(\d+)/i', $uaStr, $m))          { $browser='Firefox'; $browserVer=$m[1]; }
        elseif (preg_match('/Chrome\/(\d+)/i', $uaStr, $m))           { $browser='Chrome';  $browserVer=$m[1]; }
        elseif (preg_match('/Version\/(\d+).*Safari/i', $uaStr, $m))  { $browser='Safari';  $browserVer=$m[1]; }
        $os = 'Other'; $osVer = '';
        if (preg_match('/Windows NT ([\d.]+)/i', $uaStr, $m))     { $os='Windows'; $wv=['10.0'=>'10','6.3'=>'8.1','6.2'=>'8','6.1'=>'7']; $osVer = $wv[$m[1]] ?? $m[1]; }
        elseif (preg_match('/Android ([\d.]+)/i', $uaStr, $m))    { $os='Android'; $osVer=$m[1]; }
        elseif (preg_match('/iPhone OS ([\d_]+)/i', $uaStr, $m))  { $os='iOS'; $osVer=str_replace('_','.',$m[1]); }
        elseif (preg_match('/iPad.*OS ([\d_]+)/i', $uaStr, $m))   { $os='iPadOS'; $osVer=str_replace('_','.',$m[1]); }
        elseif (preg_match('/Mac OS X ([\d_]+)/i', $uaStr, $m))   { $os='macOS'; $osVer=str_replace('_','.',$m[1]); }
        elseif (preg_match('/Linux/i', $uaStr))                   { $os='Linux'; }
        $dupClickId = 'c' . substr(bin2hex(random_bytes(8)), 0, 12);
        $dupClickData = [
            'offerId'        => $offerId,
            'affiliateId'    => $affId,
            'timestamp'      => (int)(microtime(true) * 1000),
            'converted'      => false,
            'test'           => $isTest,
            'ip'             => $ip,
            'country'        => $dupGeo['country'],
            'countryName'    => $dupGeo['countryName'],
            'city'           => $dupGeo['city'],
            'region'         => $dupGeo['region'],
            'isp'            => $dupGeo['isp'],
            'org'            => $dupGeo['org'] ?? '',
            'asn'            => $dupGeo['asn'] ?? '',
            'lat'            => $dupGeo['lat'],
            'lon'            => $dupGeo['lon'],
            'ipTimezone'     => $dupGeo['ipTimezone'],
            'userAgent'      => $uaStr,
            'device'         => $devType,
            'browser'        => $browser,
            'browserVer'     => $browserVer,
            'os'             => $os,
            'osVer'          => $osVer,
            'referer'        => $_SERVER['HTTP_REFERER']    ?? '',
            'landingUrl'     => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''),
            'sub1'           => $sub1, 'sub2' => $sub2, 'sub3' => $sub3, 'sub4' => $sub4, 'sub5' => $sub5,
            'fbclid'         => $fbclid, 'gclid' => $gclid, 'ttclid' => $ttclid,
            'blocked'        => true,
            'blockReason'    => 'duplicate_ip',
            'originalClickId'=> is_array($existing) ? ($existing['clickId'] ?? '') : $existing,
            'originalAffiliateId' => is_array($existing) ? ($existing['affiliateId'] ?? '') : '',
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
    /* Determine if visitor country is allowed.
       If detected country IS in the allowed list, allow it even with 1 vote.
       Only block if country is NOT in allowed list or no country detected. */
    $blockReason = '';
    if (!$visitorCC) {
        $blockReason = 'no_geo_detected';
    } elseif (!in_array($visitorCC, $allowed, true)) {
        $blockReason = 'geo_not_allowed';
    }
    if ($blockReason) {
        /* Enrich geo-blocked click with full data so it appears in admin click reports */
        $geoB = ['city'=>'','region'=>'','isp'=>'','org'=>'','asn'=>'','lat'=>0,'lon'=>0,'ipTimezone'=>''];
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
            $gCh2 = curl_init('http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,countryCode,regionName,city,timezone,isp,org,as,lat,lon');
            curl_setopt_array($gCh2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
            $gR2 = curl_exec($gCh2); curl_close($gCh2);
            if ($gR2) { $gD2 = json_decode($gR2, true); if ($gD2 && ($gD2['status']??'') === 'success') {
                $geoB['city']=$gD2['city']??''; $geoB['region']=$gD2['regionName']??'';
                $geoB['isp']=$gD2['isp']??''; $geoB['org']=$gD2['org']??'';
                $geoB['asn']=$gD2['as']??''; $geoB['lat']=$gD2['lat']??0;
                $geoB['lon']=$gD2['lon']??0; $geoB['ipTimezone']=$gD2['timezone']??'';
            }}
        }
        $uaStr2 = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $devType2 = (preg_match('/Mobi|Android|iPhone|iPod/i', $uaStr2)) ? 'mobile' : ((preg_match('/Tablet|iPad/i', $uaStr2)) ? 'tablet' : 'desktop');
        $browser2 = 'Other'; $browserVer2 = '';
        if (preg_match('/Edg\/(\d+)/i', $uaStr2, $m2))                  { $browser2='Edge';    $browserVer2=$m2[1]; }
        elseif (preg_match('/OPR\/(\d+)/i', $uaStr2, $m2))              { $browser2='Opera';   $browserVer2=$m2[1]; }
        elseif (preg_match('/Firefox\/(\d+)/i', $uaStr2, $m2))          { $browser2='Firefox'; $browserVer2=$m2[1]; }
        elseif (preg_match('/Chrome\/(\d+)/i', $uaStr2, $m2))           { $browser2='Chrome';  $browserVer2=$m2[1]; }
        elseif (preg_match('/Version\/(\d+).*Safari/i', $uaStr2, $m2))  { $browser2='Safari';  $browserVer2=$m2[1]; }
        $os2 = 'Other'; $osVer2 = '';
        if (preg_match('/Windows NT ([\d.]+)/i', $uaStr2, $m2))     { $os2='Windows'; $wv2=['10.0'=>'10','6.3'=>'8.1','6.2'=>'8','6.1'=>'7']; $osVer2=$wv2[$m2[1]]??$m2[1]; }
        elseif (preg_match('/Android ([\d.]+)/i', $uaStr2, $m2))    { $os2='Android'; $osVer2=$m2[1]; }
        elseif (preg_match('/iPhone OS ([\d_]+)/i', $uaStr2, $m2))  { $os2='iOS'; $osVer2=str_replace('_','.',$m2[1]); }
        elseif (preg_match('/iPad.*OS ([\d_]+)/i', $uaStr2, $m2))   { $os2='iPadOS'; $osVer2=str_replace('_','.',$m2[1]); }
        elseif (preg_match('/Mac OS X ([\d_]+)/i', $uaStr2, $m2))   { $os2='macOS'; $osVer2=str_replace('_','.',$m2[1]); }
        elseif (preg_match('/Linux/i', $uaStr2))                    { $os2='Linux'; }
        /* Save as blocked click to /clicks/ so admin can see all data */
        $bId = 'c' . substr(bin2hex(random_bytes(8)), 0, 12);
        $blockData = [
            'offerId'=>$offerId, 'affiliateId'=>$affId, 'timestamp'=>(int)(microtime(true)*1000),
            'converted'=>false, 'test'=>$isTest,
            'ip'=>$ip, 'country'=>$visitorCC, 'countryName'=>$visitorCountryName,
            'city'=>$geoB['city'], 'region'=>$geoB['region'],
            'isp'=>$geoB['isp'], 'org'=>$geoB['org'], 'asn'=>$geoB['asn'],
            'lat'=>$geoB['lat'], 'lon'=>$geoB['lon'], 'ipTimezone'=>$geoB['ipTimezone'],
            'userAgent'=>$uaStr2, 'device'=>$devType2,
            'browser'=>$browser2, 'browserVer'=>$browserVer2,
            'os'=>$os2, 'osVer'=>$osVer2,
            'referer'=>$_SERVER['HTTP_REFERER']??'',
            'landingUrl'=>(isset($_SERVER['HTTPS'])?'https':'http').'://'.($_SERVER['HTTP_HOST']??'').($_SERVER['REQUEST_URI']??''),
            'sub1'=>$sub1, 'sub2'=>$sub2, 'sub3'=>$sub3, 'sub4'=>$sub4, 'sub5'=>$sub5,
            'fbclid'=>$fbclid, 'gclid'=>$gclid, 'ttclid'=>$ttclid,
            'blocked'=>true, 'blockReason'=>$blockReason,
            'geoVotes'=>$votes, 'geoWinningVotes'=>$winningVotes,
            'allowedCountries'=>implode(',',$allowed),
            'source'=>'php'
        ];
        $bCtx = stream_context_create(['http' => ['method'=>'PUT', 'header'=>"Content-Type: application/json\r\n", 'content'=>json_encode($blockData), 'timeout'=>4]]);
        @file_get_contents($FB . '/clicks/' . $bId . '.json', false, $bCtx);
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
