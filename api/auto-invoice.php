<?php
/*
 * Auto Weekly Invoice Generator (Server-side)
 *
 * Trigger: GitHub Actions cron at Monday 04:10 + 05:10 UTC (covers EST + EDT).
 * Logic: Server-side replica of admin.html's checkAutoWeeklyInvoices().
 *   1. Compute last completed week (Mon → Sun) in America/New_York (Florida).
 *   2. Skip if settings/autoInvoice/lastWeek already matches this period.
 *   3. For each active affiliate, group approved conversions in that range
 *      by offer and create an invoice with line items.
 *   4. Send email to affiliate, CC support@climaofficial.com.
 *   5. Persist settings/autoInvoice/lastWeek for idempotency.
 *
 * Required env / config:
 *   - GET param ?secret=... must match settings/postback/secretKey in Firebase
 *     (re-use existing postback secret so we don't introduce a new one)
 *
 * Manual trigger (admin): same URL with ?secret=... — safe to call repeatedly,
 * idempotent.
 */

header('Content-Type: application/json');

$FB = 'https://clima-dashboard-default-rtdb.firebaseio.com';
$BILLING_CC = 'support@climaofficial.com';
$MAILER_URL = 'https://plan1.climaofficial.com/api/mailer.php';

/* ── Utility: simple HTTP helpers ── */
function fb_get($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => false]);
    $r = curl_exec($ch);
    curl_close($ch);
    return $r ? json_decode($r, true) : null;
}
function fb_put($url, $data) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    return $r;
}
function fb_patch($url, $data) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CUSTOMREQUEST => 'PATCH', CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    return $r;
}
function objToArr($obj) {
    $out = [];
    if (!is_array($obj)) return $out;
    foreach ($obj as $k => $v) {
        if (is_array($v)) { $v['_id'] = $k; $out[] = $v; }
    }
    return $out;
}
function fmt_money($n) { return '$' . number_format(floatval($n), 2); }
function html_esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ── Validate secret ── */
$secret = isset($_GET['secret']) ? $_GET['secret'] : '';
$settings = fb_get($FB . '/settings/postback.json');
if (!$settings || empty($settings['secretKey']) || $settings['secretKey'] !== $secret) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid secret']);
    exit;
}

/* ── Compute last completed Mon→Sun in America/New_York ── */
$tz = new DateTimeZone('America/New_York');
$nowET = new DateTime('now', $tz);
$dow = (int)$nowET->format('w'); /* 0=Sun..6=Sat */
$daysBackToSun = $dow === 0 ? 7 : $dow;
$lastSunET = (clone $nowET)->modify('-' . $daysBackToSun . ' days')->setTime(23, 59, 59);
$lastMonET = (clone $lastSunET)->modify('-6 days')->setTime(0, 0, 0);

$weekKey   = $lastMonET->format('Y-m-d');
$weekToStr = $lastSunET->format('Y-m-d');
$fromTs    = $lastMonET->getTimestamp() * 1000;
$toTs      = $lastSunET->getTimestamp() * 1000 + 999;

/* ── Idempotency ── */
$autoCfg = fb_get($FB . '/settings/autoInvoice.json') ?: [];
$forced = isset($_GET['force']) && $_GET['force'] == '1';
if (!$forced && isset($autoCfg['lastWeek']) && $autoCfg['lastWeek'] === $weekKey) {
    echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'Already processed week ' . $weekKey, 'period' => $weekKey . ' → ' . $weekToStr]);
    exit;
}

/* ── Fetch data ── */
$usersRaw = fb_get($FB . '/users.json') ?: [];
$convsRaw = fb_get($FB . '/conversions.json') ?: [];
$offersRaw = fb_get($FB . '/offers.json') ?: [];
$invoicesRaw = fb_get($FB . '/payments/invoices.json') ?: [];
$counterRaw = fb_get($FB . '/settings/invoiceCounter.json') ?: [];
$networkCfg = fb_get($FB . '/settings/network.json') ?: [];

$users = objToArr($usersRaw);
$convs = objToArr($convsRaw);
$offers = objToArr($offersRaw);
$invoices = objToArr($invoicesRaw);

$offerNameById = [];
foreach ($offers as $o) { $offerNameById[$o['_id']] = isset($o['name']) ? $o['name'] : $o['_id']; }

/* Build set of conversionIds already in an invoice — defensive duplicate guard */
$invoicedConvIds = [];
foreach ($invoices as $inv) {
    if (!empty($inv['items']) && is_array($inv['items'])) {
        foreach ($inv['items'] as $it) {
            if (!empty($it['conversionIds']) && is_array($it['conversionIds'])) {
                foreach ($it['conversionIds'] as $cid) $invoicedConvIds[$cid] = true;
            }
        }
    }
}

$affiliates = array_filter($users, function($u) {
    return isset($u['role'], $u['status']) && $u['role'] === 'affiliate' && $u['status'] === 'active';
});

$ym = $nowET->format('Ym');
$ctr = isset($counterRaw[$ym]) ? (int)$counterRaw[$ym] : 0;
$created = 0;
$skipped = 0;
$errors = [];
$createdInvoiceLog = [];

foreach ($affiliates as $aff) {
    $affId = $aff['_id'];
    $affConvs = array_filter($convs, function($c) use ($affId, $fromTs, $toTs, $invoicedConvIds) {
        if (!isset($c['status'], $c['affiliateId'], $c['createdAt'])) return false;
        if ($c['status'] !== 'approved') return false;
        if ($c['affiliateId'] !== $affId) return false;
        if ($c['createdAt'] < $fromTs || $c['createdAt'] > $toTs) return false;
        if (!empty($c['overCap']) || !empty($c['isTest'])) return false;
        if (isset($invoicedConvIds[$c['_id']])) return false;
        return true;
    });
    if (!count($affConvs)) { $skipped++; continue; }

    /* Group by offer */
    $offerMap = [];
    foreach ($affConvs as $c) {
        $oid = isset($c['offerId']) ? $c['offerId'] : 'unknown';
        if (!isset($offerMap[$oid])) $offerMap[$oid] = ['count' => 0, 'amount' => 0.0, 'ids' => []];
        $offerMap[$oid]['count']++;
        $offerMap[$oid]['amount'] += isset($c['payout']) ? floatval($c['payout']) : 0;
        $offerMap[$oid]['ids'][] = $c['_id'];
    }

    $items = [];
    $totalBilled = 0;
    foreach ($offerMap as $oid => $d) {
        $unit = $d['count'] ? round($d['amount'] / $d['count'], 4) : 0;
        $amt = round($d['amount'], 2);
        $items[] = [
            'offerId'        => $oid,
            'offerName'      => isset($offerNameById[$oid]) ? $offerNameById[$oid] : $oid,
            'quantity'       => $d['count'],
            'unitPrice'      => $unit,
            'amount'         => $amt,
            'conversionIds'  => array_values($d['ids'])
        ];
        $totalBilled += $d['amount'];
    }
    $totalBilled = round($totalBilled, 2);

    $ctr++;
    $invNum = 'INV-' . $ym . '-' . str_pad((string)$ctr, 3, '0', STR_PAD_LEFT);
    $invId = 'inv_' . substr(bin2hex(random_bytes(6)), 0, 12);
    $nowMs = (int)(microtime(true) * 1000);

    $inv = [
        'invoiceNumber' => $invNum,
        'affiliateId'   => $affId,
        'status'        => 'unpaid',
        'startDate'     => $fromTs,
        'endDate'       => $toTs,
        'dateFrom'      => $weekKey,
        'dateTo'        => $weekToStr,
        'billedAmount'  => $totalBilled,
        'paidAmount'    => 0,
        'currency'      => 'USD',
        'notes'         => 'Auto-generated weekly invoice (' . $weekKey . ' → ' . $weekToStr . ')',
        'createdAt'     => $nowMs,
        'updatedAt'     => $nowMs,
        'period'        => $weekKey . ' to ' . $weekToStr,
        'amount'        => $totalBilled,
        'convCount'     => count($affConvs),
        'items'         => $items,
        'autoGenerated' => true,
        'payment'       => ['method' => 'wire', 'status' => 'pending', 'approvedAt' => null, 'completedAt' => null, 'transactionFees' => 0, 'amountPaid' => 0, 'notes' => '']
    ];

    $putRes = fb_put($FB . '/payments/invoices/' . $invId . '.json', $inv);
    if (!$putRes) { $errors[] = 'PUT failed for ' . $affId; $ctr--; continue; }
    fb_put($FB . '/settings/invoiceCounter/' . $ym . '.json', $ctr);

    $created++;
    $createdInvoiceLog[] = ['invId' => $invId, 'invNum' => $invNum, 'aff' => isset($aff['name']) ? $aff['name'] : $affId, 'amount' => $totalBilled];

    /* Send email to affiliate */
    if (!empty($aff['email'])) {
        send_invoice_email($aff, $inv, $networkCfg);
    }
}

/* Idempotency stamp */
fb_patch($FB . '/settings/autoInvoice.json', ['lastWeek' => $weekKey, 'lastRunAt' => (int)(microtime(true) * 1000), 'lastRunCount' => $created]);

echo json_encode([
    'ok'       => true,
    'period'   => $weekKey . ' → ' . $weekToStr,
    'created'  => $created,
    'skipped'  => $skipped,
    'errors'   => $errors,
    'invoices' => $createdInvoiceLog,
    'runAt'    => $nowET->format('Y-m-d H:i:s T')
]);

/* ── Email helper ── */
function send_invoice_email($aff, $inv, $networkCfg) {
    global $MAILER_URL, $BILLING_CC;
    $netName = !empty($networkCfg['networkName']) ? $networkCfg['networkName'] : 'Clima Network';
    $logo    = !empty($networkCfg['logo'])        ? $networkCfg['logo']        : 'https://i.ibb.co/ZzqT56H0/image.png';

    $itemsHtml = '';
    foreach ($inv['items'] as $it) {
        $itemsHtml .= '<tr style="border-bottom:1px solid #e2e8f0;">' .
            '<td style="padding:10px 12px;">' . html_esc($it['offerName']) . '</td>' .
            '<td style="padding:10px 12px;text-align:center;">' . (int)$it['quantity'] . '</td>' .
            '<td style="padding:10px 12px;text-align:right;">' . fmt_money($it['unitPrice']) . '</td>' .
            '<td style="padding:10px 12px;text-align:right;font-weight:700;">' . fmt_money($it['amount']) . '</td>' .
            '</tr>';
    }

    $subject = 'Invoice ' . $inv['invoiceNumber'] . ' Created — ' . fmt_money($inv['billedAmount']) . ' Due';

    $body = '<!DOCTYPE html><html><body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;background:#f5f7fa;color:#1e293b;">'
        . '<div style="max-width:640px;margin:0 auto;background:#fff;">'
        . '<div style="background:linear-gradient(135deg,#0a6c80,#085a6b);padding:30px 28px;color:#fff;">'
        . '<img src="' . html_esc($logo) . '" alt="Logo" style="height:42px;margin-bottom:12px;display:block;">'
        . '<div style="font-size:22px;font-weight:800;">New Invoice Created</div>'
        . '<div style="font-size:13px;opacity:.85;margin-top:4px;">' . html_esc($inv['invoiceNumber']) . '</div>'
        . '</div>'
        . '<div style="padding:24px 28px;">'
        . '<div style="font-size:14px;color:#475569;margin-bottom:18px;">Hi ' . html_esc(isset($aff['name']) ? $aff['name'] : 'Affiliate') . ',</div>'
        . '<div style="font-size:14px;color:#475569;margin-bottom:18px;line-height:1.6;">Your weekly invoice has been automatically generated for the period <strong>' . html_esc($inv['dateFrom']) . '</strong> to <strong>' . html_esc($inv['dateTo']) . '</strong>.</div>'
        . '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:18px 20px;margin-bottom:20px;">'
        . '<div style="display:flex;justify-content:space-between;margin-bottom:8px;"><span style="color:#64748b;font-size:12px;text-transform:uppercase;letter-spacing:.5px;font-weight:600;">Total Due</span><span style="font-size:22px;font-weight:800;color:#0A6C80;">' . fmt_money($inv['billedAmount']) . '</span></div>'
        . '<div style="display:flex;justify-content:space-between;font-size:12.5px;color:#64748b;"><span>Conversions</span><span>' . (int)$inv['convCount'] . '</span></div>'
        . '</div>'
        . '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:22px;">'
        . '<thead><tr style="background:#f1f5f9;"><th style="padding:10px 12px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#64748b;">Offer</th><th style="padding:10px 12px;text-align:center;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#64748b;">Qty</th><th style="padding:10px 12px;text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#64748b;">Rate</th><th style="padding:10px 12px;text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#64748b;">Amount</th></tr></thead>'
        . '<tbody>' . $itemsHtml . '</tbody>'
        . '<tfoot><tr style="background:#f8fafc;font-weight:700;border-top:2px solid #e2e8f0;"><td colspan="3" style="padding:11px 12px;text-align:right;color:#64748b;">Total</td><td style="padding:11px 12px;text-align:right;color:#0A6C80;">' . fmt_money($inv['billedAmount']) . '</td></tr></tfoot>'
        . '</table>'
        . '<div style="font-size:12.5px;color:#64748b;line-height:1.6;">Login to your affiliate portal to view full details and download the invoice PDF. Payment will be processed according to your selected payout method and the network\'s payment terms.</div>'
        . '<div style="margin-top:20px;text-align:center;"><a href="https://plan1.climaofficial.com" style="display:inline-block;background:#0A6C80;color:#fff;padding:11px 26px;border-radius:8px;text-decoration:none;font-weight:600;font-size:13.5px;">View Invoice</a></div>'
        . '</div>'
        . '<div style="padding:18px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:11px;color:#94a3b8;text-align:center;">'
        . html_esc($netName) . ' · Automated weekly invoice · For questions contact support@climaofficial.com'
        . '</div></div></body></html>';

    $payload = [
        'to'      => $aff['email'],
        'cc'      => $BILLING_CC,
        'subject' => $subject,
        'body'    => $body
    ];

    $ch = curl_init($MAILER_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    curl_exec($ch);
    curl_close($ch);
}
