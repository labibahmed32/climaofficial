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

/* ── HTTP 302 Redirect ── */
header('Location: ' . $destUrl, true, 302);
exit;
