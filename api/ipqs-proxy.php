<?php
/*
 * IPQS + AbuseIPDB Proxy
 * Upload to any PHP hosting (shared hosting works fine)
 *
 * IPQS Usage:
 *   ?key=IPQS_KEY&ip=1.2.3.4
 *   ?key=IPQS_KEY&email=test@example.com
 *   ?key=IPQS_KEY&phone=+15551234567
 *
 * AbuseIPDB Usage:
 *   ?abusekey=ABUSEIPDB_KEY&abuseip=1.2.3.4
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$key      = isset($_GET['key'])      ? $_GET['key']      : '';
$ip       = isset($_GET['ip'])       ? $_GET['ip']       : '';
$email    = isset($_GET['email'])    ? $_GET['email']    : '';
$phone    = isset($_GET['phone'])    ? $_GET['phone']    : '';
$abuseKey = isset($_GET['abusekey']) ? $_GET['abusekey'] : '';
$abuseIp  = isset($_GET['abuseip']) ? $_GET['abuseip']  : '';

// AbuseIPDB check
if (!empty($abuseKey) && !empty($abuseIp)) {
    $url = 'https://api.abuseipdb.com/api/v2/check?ipAddress=' . urlencode($abuseIp) . '&maxAgeInDays=90&verbose';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Key: ' . $abuseKey,
        'Accept: application/json'
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        echo json_encode(['error' => 'AbuseIPDB request failed', 'http_code' => $httpCode]);
        exit;
    }
    echo $response;
    exit;
}

// IPQS checks
if (empty($key)) {
    echo json_encode(['error' => 'Missing API key']);
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
    echo json_encode(['error' => 'Provide ip, email, phone, or abuseip parameter']);
    exit;
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $httpCode !== 200) {
    echo json_encode(['error' => 'IPQS request failed', 'http_code' => $httpCode]);
    exit;
}

echo $response;
