<?php
header('Content-Type: text/plain');

$FB = 'https://clima-dashboard-default-rtdb.firebaseio.com';

function fbGet($path) {
    global $FB;
    $url = $FB . '/' . $path . '.json';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function fbPut($path, $data) {
    global $FB;
    $url = $FB . '/' . $path . '.json';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function fbPatch($path, $data) {
    global $FB;
    $url = $FB . '/' . $path . '.json';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function genId() {
    return base_convert(time(), 10, 36) . substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 6);
}

// Get parameters
$clickId = isset($_GET['click_id']) ? $_GET['click_id'] : '';
$secret = isset($_GET['secret']) ? $_GET['secret'] : '';
$customPayout = isset($_GET['payout']) ? $_GET['payout'] : '';
$customRevenue = isset($_GET['revenue']) ? $_GET['revenue'] : '';
$txnId = isset($_GET['txn_id']) ? $_GET['txn_id'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : 'approved';
$isTest = isset($_GET['test']) && $_GET['test'] === '1';

// Validate required params
if (!$clickId || !$secret) {
    echo 'ERROR: Missing click_id or secret';
    exit;
}

// Detect BuyGoods test (macros not replaced = test call)
if (strpos($clickId, '{') !== false || strpos($clickId, 'SUBID') !== false) {
    echo 'SUCCESS: Test postback received. Macros will be replaced with real values on actual sales.';
    exit;
}

// Validate secret key
$settings = fbGet('settings/postback');
if (!$settings || !isset($settings['secretKey']) || $settings['secretKey'] !== $secret) {
    // Log failed attempt
    $logId = genId();
    fbPut('postback-log/' . $logId, [
        'timestamp' => round(microtime(true) * 1000),
        'clickId' => $clickId,
        'offerId' => '',
        'affiliateId' => '',
        'revenue' => 0,
        'payout' => 0,
        'processed' => false,
        'detail' => 'Invalid secret key',
        'rawQuery' => $_SERVER['QUERY_STRING']
    ]);
    echo 'ERROR: Invalid secret key';
    exit;
}

// Look up the click
$click = fbGet('clicks/' . $clickId);
if (!$click) {
    $logId = genId();
    fbPut('postback-log/' . $logId, [
        'timestamp' => round(microtime(true) * 1000),
        'clickId' => $clickId,
        'offerId' => '',
        'affiliateId' => '',
        'revenue' => 0,
        'payout' => 0,
        'processed' => false,
        'detail' => 'Click not found',
        'rawQuery' => $_SERVER['QUERY_STRING']
    ]);
    echo 'ERROR: Click not found: ' . $clickId;
    exit;
}

// ===== EVENT / PARTIAL handling =====
// If event_id is present and not the base (0), this is a post/pre-conversion
// EVENT — not a sale. Stored in a separate `events` node so the conversion,
// earnings, cap and invoice logic stays untouched. A click can have many events
// plus one CV, so events bypass the "already converted" gate.
$eventId   = isset($_GET['event_id'])   ? trim($_GET['event_id'])   : '';
$eventName = isset($_GET['event_name']) ? trim($_GET['event_name']) : '';
$isEvent   = ($eventId !== '' && $eventId !== '0');

if ($isEvent) {
    $offerId       = $click['offerId'] ?? '';
    $affiliateId   = $click['affiliateId'] ?? '';
    $friendlyAffId = $click['affId'] ?? '';

    // Dedup: same click + event_id + transaction_id already recorded
    if ($txnId !== '') {
        $allEvents = fbGet('events') ?? [];
        if (is_array($allEvents)) {
            foreach ($allEvents as $e) {
                if (is_array($e)
                    && ($e['clickId'] ?? '') === $clickId
                    && (string)($e['eventId'] ?? '') === (string)$eventId
                    && ($e['transactionId'] ?? '') === $txnId) {
                    echo 'SUCCESS: Duplicate event ignored (' . $eventId . ')';
                    exit;
                }
            }
        }
    }

    $evId  = 'evt_' . genId();
    $event = [
        'clickId'       => $clickId,
        'offerId'       => $offerId,
        'affiliateId'   => $affiliateId,
        'affId'         => $friendlyAffId,
        'eventId'       => $eventId,
        'eventName'     => $eventName,
        'type'          => 'event',
        'revenue'       => $customRevenue ? floatval($customRevenue) : 0,
        'payout'        => $customPayout ? floatval($customPayout) : 0,
        'status'        => $status ?: 'approved',
        'source'        => 'postback',
        'transactionId' => $txnId,
        'sub1'          => $click['sub1'] ?? '',
        'sub2'          => $click['sub2'] ?? '',
        'ip'            => $click['ip'] ?? '',
        'landingPageId'   => $click['landingPageId'] ?? '',
        'landingPageName' => $click['landingPageName'] ?? 'Default',
        'isTest'        => $isTest,
        'createdAt'     => round(microtime(true) * 1000)
    ];
    fbPut('events/' . $evId, $event);

    $logId = genId();
    fbPut('postback-log/' . $logId, [
        'timestamp'   => round(microtime(true) * 1000),
        'clickId'     => $clickId,
        'offerId'     => $offerId,
        'affiliateId' => $affiliateId,
        'revenue'     => $event['revenue'],
        'payout'      => $event['payout'],
        'processed'   => true,
        'detail'      => 'EVENT ' . $eventId . ($eventName ? ' (' . $eventName . ')' : '') . ' -> ' . $evId,
        'rawQuery'    => $_SERVER['QUERY_STRING']
    ]);

    echo 'SUCCESS: Event recorded (' . $eventId . ')';
    exit;
}

// Check if already converted
if (!empty($click['converted'])) {
    $logId = genId();
    fbPut('postback-log/' . $logId, [
        'timestamp' => round(microtime(true) * 1000),
        'clickId' => $clickId,
        'offerId' => $click['offerId'] ?? '',
        'affiliateId' => $click['affiliateId'] ?? '',
        'revenue' => 0,
        'payout' => 0,
        'processed' => false,
        'detail' => 'Already converted',
        'rawQuery' => $_SERVER['QUERY_STRING']
    ]);
    echo 'ERROR: Click already converted';
    exit;
}

$offerId = $click['offerId'] ?? '';
$affiliateId = $click['affiliateId'] ?? '';

// Get offer details
$offer = $offerId ? fbGet('offers/' . $offerId) : null;

// Get assignment for cap checking and payout override
$assignment = ($offerId && $affiliateId) ? fbGet('offerAssignments/' . $offerId . '/' . $affiliateId) : null;

// Determine payout
// Priority: lockPayout offer rate > assignment override > postback payout > offer default
$lockPayout = $offer && !empty($offer['lockPayout']);
$finalPayout = 0;
if ($lockPayout) {
    // Lock mode: ignore postback payout, use assignment override or offer default
    if ($assignment && !empty($assignment['payoutOverride'])) {
        $finalPayout = floatval($assignment['payoutOverride']);
    } else if ($offer) {
        $finalPayout = floatval($offer['payout'] ?? 0);
    }
} else {
    // Dynamic mode: trust postback payout, fall back to override/offer
    $finalPayout = $customPayout ? floatval($customPayout) : 0;
    if (!$finalPayout && $assignment && !empty($assignment['payoutOverride'])) {
        $finalPayout = floatval($assignment['payoutOverride']);
    }
    if (!$finalPayout && $offer) {
        $finalPayout = floatval($offer['payout'] ?? 0);
    }
}

$finalRevenue = $customRevenue ? floatval($customRevenue) : 0;
if (!$finalRevenue && $assignment && !empty($assignment['revenueOverride'])) {
    $finalRevenue = floatval($assignment['revenueOverride']);
}
if (!$finalRevenue && $offer) {
    $finalRevenue = floatval($offer['revenue'] ?? 0);
}

// Check daily cap (Pacific Time)
$isOverCap = false;
$capNote = '';
$dailyCap = ($assignment && !empty($assignment['dailyCap'])) ? intval($assignment['dailyCap']) : 0;

if ($assignment && ($assignment['status'] ?? '') === 'active' && $dailyCap > 0) {
    $pacificTz = new DateTimeZone('America/Los_Angeles');
    $now = new DateTime('now', $pacificTz);
    $dayStart = (clone $now)->setTime(0, 0, 0)->getTimestamp() * 1000;
    $dayEnd = (clone $now)->setTime(23, 59, 59)->getTimestamp() * 1000;

    $allConvs = fbGet('conversions') ?? [];
    $todayCount = 0;
    foreach ($allConvs as $c) {
        if (is_array($c) &&
            ($c['offerId'] ?? '') === $offerId &&
            ($c['affiliateId'] ?? '') === $affiliateId &&
            empty($c['overCap']) &&
            ($c['createdAt'] ?? 0) >= $dayStart &&
            ($c['createdAt'] ?? 0) <= $dayEnd) {
            $todayCount++;
        }
    }
    if ($todayCount >= $dailyCap) {
        $isOverCap = true;
        $capNote = 'Daily cap (' . $dailyCap . ') hit. Conv #' . ($todayCount + 1) . ' today (Pacific).';
    }
}

// Get friendly affiliate ID from click data
$friendlyAffId = $click['affId'] ?? '';

// Create conversion
$convId = 'conv_' . genId();
$conv = [
    'clickId' => $clickId,
    'offerId' => $offerId,
    'affiliateId' => $affiliateId,
    'affId' => $friendlyAffId,
    'revenue' => $finalRevenue,
    'payout' => $finalPayout,
    'status' => $status,
    'source' => 'postback',
    'transactionId' => $txnId,
    'sub1' => $click['sub1'] ?? '',
    'sub2' => $click['sub2'] ?? '',
    'ip' => $click['ip'] ?? '',
    'landingPageId'   => $click['landingPageId'] ?? '',
    'landingPageName' => $click['landingPageName'] ?? 'Default',
    'overCap' => $isOverCap,
    'capNote' => $capNote,
    'isTest' => $isTest,
    'createdAt' => round(microtime(true) * 1000)
];

fbPut('conversions/' . $convId, $conv);
fbPatch('clicks/' . $clickId, ['converted' => true]);

// Log success
$logId = genId();
fbPut('postback-log/' . $logId, [
    'timestamp' => round(microtime(true) * 1000),
    'clickId' => $clickId,
    'offerId' => $offerId,
    'affiliateId' => $affiliateId,
    'revenue' => $finalRevenue,
    'payout' => $finalPayout,
    'processed' => true,
    'detail' => $convId . ($isOverCap ? ' [OVER-CAP]' : ''),
    'rawQuery' => $_SERVER['QUERY_STRING']
]);

// Output success (BuyGoods requires output)
$affDisplay = $friendlyAffId ? $friendlyAffId : $affiliateId;
echo 'SUCCESS: ' . $convId . ' | Offer: ' . $offerId . ' | Affiliate: ' . $affDisplay . ' | Revenue: $' . number_format($finalRevenue, 2) . ' | Payout: $' . number_format($finalPayout, 2);
