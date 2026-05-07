<?php
/*
 * Async Fraud Check Trigger
 * Called via navigator.sendBeacon() right before redirect.
 * Closes HTTP connection immediately, then runs all fraud APIs in background.
 * Saves combined result to Firebase REST API.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Content-Type: application/json');
header('Content-Length: 2');
header('Connection: close');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$clickId = isset($_POST['clickId']) ? $_POST['clickId'] : (isset($_GET['clickId']) ? $_GET['clickId'] : '');
$ip      = isset($_POST['ip'])      ? $_POST['ip']      : (isset($_GET['ip'])      ? $_GET['ip']      : '');

if (!$clickId || !$ip) { echo '{}'; exit; }

/* Close connection immediately so user gets redirected without waiting */
echo '{}';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    ob_start();
    ob_end_flush();
    flush();
}

/* ── Everything below runs in background after connection is closed ── */

$FB_URL = 'https://clima-dashboard-default-rtdb.firebaseio.com';

function fbRest($path, $data = null, $method = 'GET') {
    global $FB_URL;
    $url = $FB_URL . $path . '.json';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    if ($method === 'PATCH' && $data !== null) {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $res = curl_exec($ch);
    curl_close($ch);
    return $res ? json_decode($res, true) : null;
}

function apiGet($url, $headers = [], $timeout = 6) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    return curl_exec($ch);
}

/* Load fraud settings from Firebase */
$settings = fbRest('/settings/fraud') ?: [];
$ipqsKey   = isset($settings['ipqsKey'])   ? $settings['ipqsKey']   : '';
$abuseKey  = isset($settings['abuseKey'])  ? $settings['abuseKey']  : '';
$scamUser  = isset($settings['scamUser'])  ? $settings['scamUser']  : '';
$scamKey   = isset($settings['scamKey'])   ? $settings['scamKey']   : '';
$pcKey     = isset($settings['pcKey'])     ? $settings['pcKey']     : '';
$strictness= isset($settings['strictness'])? $settings['strictness']: '1';

/* ── Run all APIs in parallel via curl_multi ── */
$mh = curl_multi_init();
$handles = [];

/* ip-api.com (always) */
$fields = 'status,country,countryCode,regionName,city,isp,org,as,proxy,hosting,query,lat,lon,timezone';
$ch0 = curl_init('http://ip-api.com/json/' . urlencode($ip) . '?fields=' . $fields);
curl_setopt($ch0, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch0, CURLOPT_TIMEOUT, 5);
curl_multi_add_handle($mh, $ch0); $handles['ipapi'] = $ch0;

/* IPQS */
if ($ipqsKey) {
    $ch1 = curl_init('https://ipqualityscore.com/api/json/ip/' . urlencode($ipqsKey) . '/' . urlencode($ip) . '?strictness=' . $strictness . '&allow_public_access_points=true');
    curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch1, CURLOPT_TIMEOUT, 6);
    curl_multi_add_handle($mh, $ch1); $handles['ipqs'] = $ch1;
}

/* AbuseIPDB */
if ($abuseKey) {
    $ch2 = curl_init('https://api.abuseipdb.com/api/v2/check?ipAddress=' . urlencode($ip) . '&maxAgeInDays=90');
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch2, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Key: ' . $abuseKey, 'Accept: application/json']);
    curl_multi_add_handle($mh, $ch2); $handles['abuse'] = $ch2;
}

/* Scamalytics */
if ($scamKey && $scamUser) {
    $ch3 = curl_init('https://api11.scamalytics.com/v3/' . urlencode($scamUser) . '/?key=' . urlencode($scamKey) . '&ip=' . urlencode($ip));
    curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch3, CURLOPT_TIMEOUT, 6);
    curl_multi_add_handle($mh, $ch3); $handles['scam'] = $ch3;
}

/* ProxyCheck.io */
$pcUrl = 'https://proxycheck.io/v2/' . urlencode($ip) . '?vpn=1&asn=1&risk=1';
if ($pcKey) $pcUrl .= '&key=' . urlencode($pcKey);
$ch4 = curl_init($pcUrl);
curl_setopt($ch4, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch4, CURLOPT_TIMEOUT, 6);
curl_multi_add_handle($mh, $ch4); $handles['pc'] = $ch4;

/* Execute all in parallel */
$running = null;
do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running > 0);

/* Parse results */
$results = [];
foreach ($handles as $key => $ch) {
    $body = curl_multi_getcontent($ch);
    $results[$key] = $body ? json_decode($body, true) : null;
    curl_multi_remove_handle($mh, $ch);
}
curl_multi_close($mh);

/* ── Build combined fraud object ── */
$fraud = ['checkedAt' => round(microtime(true) * 1000)];

$ipapi = $results['ipapi'] ?? null;
if ($ipapi && isset($ipapi['status']) && $ipapi['status'] === 'success') {
    $fraud['ipapi'] = [
        'country'     => $ipapi['countryCode'] ?? '',
        'countryName' => $ipapi['country']     ?? '',
        'region'      => $ipapi['regionName']  ?? '',
        'city'        => $ipapi['city']        ?? '',
        'isp'         => $ipapi['isp']         ?? '',
        'org'         => $ipapi['org']         ?? '',
        'timezone'    => $ipapi['timezone']    ?? '',
        'lat'         => $ipapi['lat']         ?? 0,
        'lon'         => $ipapi['lon']         ?? 0,
        'proxy'       => $ipapi['proxy']       ?? false,
        'hosting'     => $ipapi['hosting']     ?? false
    ];
}

$ipqsData = $results['ipqs'] ?? null;
if ($ipqsData && isset($ipqsData['fraud_score'])) {
    $fraud['ipqs'] = [
        'fraudScore'     => $ipqsData['fraud_score']    ?? 0,
        'vpn'            => $ipqsData['vpn']            ?? false,
        'tor'            => $ipqsData['tor']            ?? false,
        'proxy'          => $ipqsData['proxy']          ?? false,
        'botStatus'      => $ipqsData['bot_status']     ?? false,
        'isCrawler'      => $ipqsData['is_crawler']     ?? false,
        'mobileDevice'   => $ipqsData['mobile']         ?? false,
        'recentAbuse'    => $ipqsData['recent_abuse']   ?? false,
        'connectionType' => $ipqsData['connection_type']?? '',
        'isp'            => $ipqsData['ISP']            ?? '',
        'organization'   => $ipqsData['organization']  ?? ''
    ];
}

$abuseData = $results['abuse'] ?? null;
if ($abuseData && isset($abuseData['data'])) {
    $ad = $abuseData['data'];
    $fraud['abuse'] = [
        'abuseScore'   => $ad['abuseConfidenceScore'] ?? 0,
        'totalReports' => $ad['totalReports']         ?? 0,
        'usageType'    => $ad['usageType']            ?? '',
        'isTor'        => $ad['isTor']               ?? false
    ];
}

$scamData = $results['scam'] ?? null;
$scamInner = isset($scamData['scamalytics']) ? $scamData['scamalytics'] : $scamData;
if ($scamInner && isset($scamInner['scamalytics_score'])) {
    $fraud['scam'] = [
        'score'     => intval($scamInner['scamalytics_score'] ?? 0),
        'risk'      => $scamInner['scamalytics_risk'] ?? '',
        'vpn'       => ($scamInner['vpn']        ?? '') === 'yes',
        'tor'       => ($scamInner['tor']        ?? '') === 'yes',
        'dchost'    => ($scamInner['datacenter'] ?? '') === 'yes',
        'anonProxy' => ($scamInner['anonymous_proxy'] ?? '') === 'yes'
    ];
}

$pcData = $results['pc'] ?? null;
$pcInfo = isset($pcData[$ip]) ? $pcData[$ip] : null;
if ($pcData && isset($pcData['status']) && $pcData['status'] === 'ok' && $pcInfo) {
    $fraud['pc'] = [
        'proxy'    => $pcInfo['proxy']    ?? 'no',
        'type'     => $pcInfo['type']     ?? '',
        'provider' => $pcInfo['provider'] ?? '',
        'risk'     => $pcInfo['risk']     ?? 0
    ];
}

/* Combined score */
$scores = []; $weights = [];
if (isset($fraud['ipqs']))  { $scores[] = $fraud['ipqs']['fraudScore'];  $weights[] = 3; }
if (isset($fraud['scam']))  { $scores[] = $fraud['scam']['score'];       $weights[] = 2; }
if (isset($fraud['abuse'])) { $scores[] = $fraud['abuse']['abuseScore']; $weights[] = 2; }
if (isset($fraud['pc']) && $fraud['pc']['risk']) { $scores[] = $fraud['pc']['risk']; $weights[] = 1; }

$combinedScore = 0;
if (count($scores) > 0) {
    $wSum = 0; $wTotal = 0;
    for ($i = 0; $i < count($scores); $i++) { $wSum += $scores[$i] * $weights[$i]; $wTotal += $weights[$i]; }
    $combinedScore = round($wSum / $wTotal);
}

$isProxy = (
    (isset($fraud['ipqs'])  && ($fraud['ipqs']['proxy']  || $fraud['ipqs']['vpn']))  ||
    (isset($fraud['scam'])  && ($fraud['scam']['vpn']    || $fraud['scam']['tor']))  ||
    (isset($fraud['pc'])    && $fraud['pc']['proxy'] !== 'no')                       ||
    (isset($fraud['ipapi']) && ($fraud['ipapi']['proxy'] || $fraud['ipapi']['hosting']))
);

$fraud['combinedScore'] = $combinedScore;
$fraud['isProxy']       = $isProxy;
$fraud['level']         = $combinedScore >= 85 ? 'fraud' : ($combinedScore >= 60 ? 'risky' : ($combinedScore >= 30 ? 'suspect' : 'clean'));

/* Save to Firebase */
fbRest('/clicks/' . $clickId . '/fraud', $fraud, 'PATCH');
