<?php
/*
 * Multi-Service Fraud Detection Proxy
 *
 * IPQS:        ?key=KEY&ip=1.2.3.4  |  ?key=KEY&email=x@y.com  |  ?key=KEY&phone=+1...
 * AbuseIPDB:   ?abusekey=KEY&abuseip=1.2.3.4
 * Scamalytics: ?scamkey=KEY&scamip=1.2.3.4
 * ProxyCheck:  ?pcip=1.2.3.4[&pckey=KEY]
 * ip-api.com:  ?ipdata=1.2.3.4   (free, no key needed)
 * IPHub:       ?iphubkey=KEY&iphubip=1.2.3.4
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function doGet($url, $headers = [], $timeout = 10) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);
    return [$response, $httpCode, $error];
}

$key      = isset($_GET['key'])      ? $_GET['key']      : '';
$ip       = isset($_GET['ip'])       ? $_GET['ip']       : '';
$email    = isset($_GET['email'])    ? $_GET['email']    : '';
$phone    = isset($_GET['phone'])    ? $_GET['phone']    : '';
$abuseKey = isset($_GET['abusekey']) ? $_GET['abusekey'] : '';
$abuseIp  = isset($_GET['abuseip'])  ? $_GET['abuseip']  : '';
$scamKey  = isset($_GET['scamkey'])  ? $_GET['scamkey']  : '';
$scamIp   = isset($_GET['scamip'])   ? $_GET['scamip']   : '';
$pcKey    = isset($_GET['pckey'])    ? $_GET['pckey']    : '';
$pcIp     = isset($_GET['pcip'])     ? $_GET['pcip']     : '';
$ipdataIp = isset($_GET['ipdata'])   ? $_GET['ipdata']   : '';
$iphubKey = isset($_GET['iphubkey']) ? $_GET['iphubkey'] : '';
$iphubIp  = isset($_GET['iphubip'])  ? $_GET['iphubip']  : '';

/* ---- ip-api.com (free geolocation + proxy data) ---- */
if (!empty($ipdataIp)) {
    $fields = 'status,message,country,countryCode,region,regionName,city,zip,lat,lon,timezone,isp,org,as,proxy,hosting,query';
    $url = 'http://ip-api.com/json/' . urlencode($ipdataIp) . '?fields=' . $fields;
    list($response, $httpCode, $err) = doGet($url);
    if ($response === false || $response === '') {
        echo json_encode(['error' => 'ip-api.com request failed', 'curl_error' => $err]);
        exit;
    }
    echo $response;
    exit;
}

/* ---- ProxyCheck.io ---- */
if (!empty($pcIp)) {
    $url = 'https://proxycheck.io/v2/' . urlencode($pcIp) . '?vpn=1&asn=1&risk=1&port=1&seen=1&days=7';
    if (!empty($pcKey)) $url .= '&key=' . urlencode($pcKey);
    list($response, $httpCode, $err) = doGet($url);
    if ($response === false || $response === '') {
        echo json_encode(['error' => 'ProxyCheck.io request failed', 'curl_error' => $err]);
        exit;
    }
    echo $response;
    exit;
}

/* ---- Scamalytics ---- */
if (!empty($scamKey) && !empty($scamIp)) {
    $url = 'https://scamalytics.com/ip/api/?key=' . urlencode($scamKey) . '&ip=' . urlencode($scamIp);
    list($response, $httpCode, $err) = doGet($url);
    if ($response === false || $response === '') {
        echo json_encode(['error' => 'Scamalytics request failed', 'curl_error' => $err]);
        exit;
    }
    echo $response;
    exit;
}

/* ---- IPHub ---- */
if (!empty($iphubKey) && !empty($iphubIp)) {
    $url = 'http://v2.api.iphub.info/ip/' . urlencode($iphubIp);
    list($response, $httpCode, $err) = doGet($url, ['X-Key: ' . $iphubKey]);
    if ($response === false || $response === '') {
        echo json_encode(['error' => 'IPHub request failed', 'curl_error' => $err]);
        exit;
    }
    echo $response;
    exit;
}

/* ---- AbuseIPDB ---- */
if (!empty($abuseKey) && !empty($abuseIp)) {
    $url = 'https://api.abuseipdb.com/api/v2/check?ipAddress=' . urlencode($abuseIp) . '&maxAgeInDays=90&verbose';
    list($response, $httpCode, $err) = doGet($url, [
        'Key: ' . $abuseKey,
        'Accept: application/json'
    ]);
    if ($response === false || $httpCode !== 200) {
        echo json_encode(['error' => 'AbuseIPDB request failed', 'http_code' => $httpCode]);
        exit;
    }
    echo $response;
    exit;
}

/* ---- IPQS (ip / email / phone) ---- */
if (empty($key)) {
    echo json_encode(['error' => 'No service matched. Provide: key+ip, abusekey+abuseip, scamkey+scamip, pcip, or ipdata']);
    exit;
}

$url = '';
if (!empty($ip)) {
    $url = 'https://ipqualityscore.com/api/json/ip/' . urlencode($key) . '/' . urlencode($ip)
         . '?strictness=1&allow_public_access_points=true&lighter_penalties=false';
} elseif (!empty($email)) {
    $url = 'https://ipqualityscore.com/api/json/email/' . urlencode($key) . '/' . urlencode($email)
         . '?abuse_strictness=1';
} elseif (!empty($phone)) {
    $url = 'https://ipqualityscore.com/api/json/phone/' . urlencode($key) . '/' . urlencode($phone)
         . '?strictness=1';
} else {
    echo json_encode(['error' => 'Provide ip, email, or phone parameter with IPQS key']);
    exit;
}

list($response, $httpCode, $err) = doGet($url);
if ($response === false || $response === '') {
    echo json_encode(['error' => 'IPQS request failed', 'curl_error' => $err]);
    exit;
}

/* Pass IPQS response through — even if success:false, return full JSON so caller can read message */
echo $response;
