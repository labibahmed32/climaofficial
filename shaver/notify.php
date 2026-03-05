<?php
/**
 * Clima Tracker — Rich Sale Notification Endpoint
 * Receives sale data, fetches offer details from Firebase, optionally runs IPQS fraud check,
 * builds rich Telegram + Email messages, sends both.
 *
 * POST JSON: { sale: {...}, firebase_url, tg_token, tg_chat_ids, email_to, email_from, ipqs_key, proxy_url }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'error' => 'POST only']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['success' => false, 'error' => 'Invalid JSON']); exit; }

$sale = $input['sale'] ?? [];
$fb = rtrim($input['firebase_url'] ?? '', '/');
$emailSent = 0;
$tgSent = 0;
$errors = [];
$tgMessage = '';
$emailBody = '';
$emailSubject = 'New Sale Notification';

// --- Backwards compat: if tg_message/email_body already provided, use them directly ---
if (!empty($input['tg_message']) || !empty($input['email_body'])) {
    $tgMessage = $input['tg_message'] ?? '';
    $emailBody = $input['email_body'] ?? '';
    $emailSubject = $input['email_subject'] ?? 'New Sale Notification';
} else {
    // --- Fetch Offer from Firebase ---
    $offer = null;
    $offerId = $sale['offerId'] ?? '';
    if ($fb && $offerId) {
        $offerJson = @file_get_contents($fb . '/tracker/offers/' . urlencode($offerId) . '.json');
        if ($offerJson) $offer = json_decode($offerJson, true);
    }
    $offerName = $offer['name'] ?? $offerId ?: '-';

    // --- IPQS Fraud Check ---
    $fraudScore = '-';
    $fraudLabel = '';
    $fraudVpn = false;
    $fraudProxy = false;
    $ip = $sale['ip'] ?? $sale['ipv4'] ?? '';
    $ipqsKey = $input['ipqs_key'] ?? '';
    $proxyUrl = $input['proxy_url'] ?? '';
    if ($ip && $ipqsKey && $proxyUrl) {
        $ipqsResult = @file_get_contents($proxyUrl . '?key=' . urlencode($ipqsKey) . '&ip=' . urlencode($ip));
        if ($ipqsResult) {
            $ipqs = json_decode($ipqsResult, true);
            if ($ipqs) {
                $fraudScore = $ipqs['fraud_score'] ?? '-';
                $fraudVpn = !empty($ipqs['vpn']);
                $fraudProxy = !empty($ipqs['proxy']);
                $fs = intval($fraudScore);
                $fraudLabel = $fs <= 15 ? 'Clean' : ($fs <= 40 ? 'Suspect' : 'Fraud');
            }
        }
    }

    // --- Build platform name ---
    $platform = $sale['platform'] ?? '';
    $platName = $platform === 'buygoods' ? 'BuyGoods' : ($platform === 'digistore' ? 'Digistore24' : ($platform === 'clickbank' ? 'ClickBank' : ($platform ?: 'Clima')));

    // --- Amount ---
    $amount = number_format(floatval($sale['amount'] ?? 0), 2);

    // --- Customer info ---
    $custName = $sale['bgName'] ?? $sale['customerName'] ?? '-';
    $custEmail = $sale['email'] ?? $sale['bgEmail'] ?? '-';
    $custPhone = $sale['bgPhone'] ?? $sale['phone'] ?? '-';
    $custAddr = implode(', ', array_filter([$sale['bgAddress'] ?? '', $sale['bgCity'] ?? '', $sale['bgZip'] ?? '', $sale['bgCountry'] ?? $sale['country'] ?? ''])) ?: '-';
    $country = $sale['country'] ?? $sale['bgCountry'] ?? '-';
    $orderId = $sale['orderId'] ?? '-';
    $orderIdGlobal = $sale['orderIdGlobal'] ?? '';
    $affId = $sale['affId'] ?? 'Direct';
    $date = $sale['date'] ?? date('Y-m-d');
    $variant = $sale['variant'] ?? '';

    // --- Upsells ---
    $upsellParts = [];
    $upsellTotal = 0;
    if ($offer && !empty($offer['upsellPages'])) {
        foreach ($offer['upsellPages'] as $up) {
            $num = $up['num'] ?? 0;
            $price = floatval($up['price'] ?? 0);
            $status = $sale['upsell' . $num] ?? '';
            if ($status === 'accept') {
                $upsellParts[] = "Upsell $num: ✅ \$$price";
                $upsellTotal += $price;
            } elseif ($status === 'decline') {
                $upsellParts[] = "Upsell $num: ❌";
            }
        }
    } else {
        for ($i = 1; $i <= 5; $i++) {
            $status = $sale['upsell' . $i] ?? '';
            if ($status === 'accept') $upsellParts[] = "Upsell $i: ✅";
            elseif ($status === 'decline') $upsellParts[] = "Upsell $i: ❌";
        }
    }
    $upsellSummary = $upsellParts ? implode(' | ', $upsellParts) : '';
    $totalRevenue = floatval($sale['amount'] ?? 0) + $upsellTotal;

    // --- Fraud emoji ---
    $fs = intval($fraudScore);
    $fraudEmoji = $fs <= 15 ? '✅' : ($fs <= 40 ? '⚠️' : '🚨');
    $fraudDetails = [];
    if ($fraudScore !== '-') $fraudDetails[] = "IPQS: $fraudScore";
    if ($fraudVpn) $fraudDetails[] = '🔒 VPN';
    if ($fraudProxy) $fraudDetails[] = '🔒 Proxy';

    // ========== BUILD TELEGRAM MESSAGE ==========
    $tgMessage = "💰 <b>NEW SALE!</b>\n";
    $tgMessage .= "━━━━━━━━━━━━━━━\n";
    $tgMessage .= "💵 <b>\$$amount</b>  |  $platName\n";
    if ($upsellTotal > 0) $tgMessage .= "🛒 Upsells: +\$" . number_format($upsellTotal, 2) . "  |  Total: <b>\$" . number_format($totalRevenue, 2) . "</b>\n";
    if ($orderIdGlobal) $tgMessage .= "📦 Global Order: <code>$orderIdGlobal</code>\n";
    if ($orderId && $orderId !== '-' && $orderId !== $orderIdGlobal) $tgMessage .= "🔖 Order ID: <code>$orderId</code>\n";
    $tgMessage .= "📋 Offer: $offerName" . ($variant ? " ($variant)" : "") . "\n";
    $tgMessage .= "👤 Affiliate: <b>$affId</b>\n";
    $tgMessage .= "\n👤 <b>Customer</b>\n";
    $tgMessage .= "━━━━━━━━━━━━━━━\n";
    $tgMessage .= "📛 $custName\n";
    $tgMessage .= "📧 $custEmail\n";
    $tgMessage .= "📱 $custPhone\n";
    $tgMessage .= "📍 $custAddr\n";
    $tgMessage .= "🌐 IP: <code>" . ($ip ?: '-') . "</code>  |  $country\n";
    if ($fraudScore !== '-') {
        $tgMessage .= "\n$fraudEmoji <b>Fraud: $fraudScore/100" . ($fraudLabel ? " ($fraudLabel)" : "") . "</b>\n";
        if ($fraudDetails) $tgMessage .= "🔍 " . implode(' | ', $fraudDetails) . "\n";
    }
    if ($upsellSummary) $tgMessage .= "\n🛒 <b>Upsells:</b> $upsellSummary\n";
    $tgMessage .= "\n📅 $date";

    // ========== BUILD EMAIL ==========
    $emailSubject = "New Sale: \$$amount — $offerName";

    $fraudBadge = '';
    if ($fraudScore !== '-') {
        $fColor = $fs <= 15 ? '#16a34a' : ($fs <= 40 ? '#d97706' : '#dc2626');
        $fBg = $fs <= 15 ? '#dcfce7' : ($fs <= 40 ? '#fef9c3' : '#fee2e2');
        $fraudBadge = "<span style=\"display:inline-block;padding:3px 10px;border-radius:20px;background:$fBg;color:$fColor;font-weight:700;font-size:13px;\">$fraudScore/100 — $fraudLabel</span>";
        if ($fraudVpn) $fraudBadge .= ' <span style="background:#fef9c3;color:#854d0e;padding:2px 8px;border-radius:10px;font-size:11px;">VPN</span>';
        if ($fraudProxy) $fraudBadge .= ' <span style="background:#fef9c3;color:#854d0e;padding:2px 8px;border-radius:10px;font-size:11px;">Proxy</span>';
    }

    $emailBody = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;background:#fff;">';
    // Header
    $emailBody .= '<div style="background:linear-gradient(135deg,#0A6C80 0%,#085a6b 100%);padding:24px 28px;border-radius:12px 12px 0 0;">';
    $emailBody .= '<h1 style="color:#fff;margin:0;font-size:20px;">💰 New Sale — $' . $amount . '</h1>';
    $emailBody .= '<p style="color:rgba(255,255,255,.7);margin:6px 0 0;font-size:13px;">' . $offerName . ' | ' . $platName . ' | ' . $date . '</p>';
    $emailBody .= '</div>';
    // Body
    $emailBody .= '<div style="padding:24px 28px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;">';
    // Sale info table
    $emailBody .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
    $rows = [];
    if ($orderIdGlobal) $rows[] = ['Global Order ID', "<code style=\"background:#f1f5f9;padding:2px 8px;border-radius:4px;\">$orderIdGlobal</code>"];
    if ($orderId && $orderId !== '-' && $orderId !== $orderIdGlobal) $rows[] = ['Order ID', "<code style=\"background:#f1f5f9;padding:2px 8px;border-radius:4px;\">$orderId</code>"];
    $rows = array_merge($rows, [
        ['Affiliate', "<strong>$affId</strong>"],
        ['Platform', $platName],
        ['Variant', $variant ?: '-'],
        ['Amount', "<strong style=\"color:#059669;font-size:16px;\">\$$amount</strong>"],
    ]);
    if ($upsellTotal > 0) {
        $rows[] = ['Upsell Revenue', "+\$" . number_format($upsellTotal, 2)];
        $rows[] = ['Total Revenue', "<strong style=\"color:#059669;\">\$" . number_format($totalRevenue, 2) . "</strong>"];
    }
    foreach ($rows as $r) {
        $emailBody .= '<tr><td style="padding:8px 0;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:13px;width:130px;">' . $r[0] . '</td><td style="padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:13px;">' . $r[1] . '</td></tr>';
    }
    $emailBody .= '</table>';
    // Customer section
    $emailBody .= '<h3 style="font-size:14px;color:#334155;margin:0 0 10px;border-bottom:2px solid #e2e8f0;padding-bottom:6px;">👤 Customer</h3>';
    $emailBody .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
    $custRows = [['Name', $custName], ['Email', $custEmail], ['Phone', $custPhone], ['Address', $custAddr], ['IP', ($ip ?: '-') . " ($country)"]];
    foreach ($custRows as $r) {
        $emailBody .= '<tr><td style="padding:6px 0;color:#64748b;font-size:12px;width:80px;">' . $r[0] . '</td><td style="padding:6px 0;font-size:12px;">' . htmlspecialchars($r[1]) . '</td></tr>';
    }
    $emailBody .= '</table>';
    // Fraud
    if ($fraudScore !== '-') {
        $emailBody .= '<h3 style="font-size:14px;color:#334155;margin:0 0 10px;border-bottom:2px solid #e2e8f0;padding-bottom:6px;">🛡️ Fraud Check</h3>';
        $emailBody .= '<p style="margin:0 0 16px;">' . $fraudBadge . '</p>';
    }
    // Upsells
    if ($upsellSummary) {
        $emailBody .= '<h3 style="font-size:14px;color:#334155;margin:0 0 10px;border-bottom:2px solid #e2e8f0;padding-bottom:6px;">🛒 Upsells</h3>';
        $emailBody .= '<p style="font-size:13px;margin:0 0 16px;">' . str_replace('|', '<br>', $upsellSummary) . '</p>';
    }
    $emailBody .= '<p style="color:#94a3b8;font-size:10px;margin:16px 0 0;text-align:center;">Clima Tracker — Sale Notification</p>';
    $emailBody .= '</div></div>';
}

// ========== SEND TELEGRAM ==========
$tgToken = trim($input['tg_token'] ?? '');
$tgChatIds = $input['tg_chat_ids'] ?? [];

if ($tgToken && !empty($tgChatIds) && $tgMessage) {
    foreach ($tgChatIds as $chatId) {
        $chatId = trim($chatId);
        if (!$chatId) continue;
        $url = 'https://api.telegram.org/bot' . $tgToken . '/sendMessage';
        $postData = http_build_query([
            'chat_id' => $chatId,
            'text' => $tgMessage,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true
        ]);
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $postData,
            'timeout' => 10
        ]]);
        $result = @file_get_contents($url, false, $ctx);
        if ($result) {
            $resp = json_decode($result, true);
            if (!empty($resp['ok'])) $tgSent++;
            else $errors[] = "TG $chatId: " . ($resp['description'] ?? 'Error');
        } else {
            $errors[] = "TG $chatId: Connection failed";
        }
    }
}

// ========== SEND EMAIL VIA SMTP ==========
$emailTo = trim($input['email_to'] ?? '');
if ($emailTo && !empty($emailBody)) {
    $smtpHost = 'mail.climaofficial.com';
    $smtpPort = 465;
    $smtpUser = 'support@climaofficial.com';
    $smtpPass = 'Labib@12345';
    $fromAddr = 'support@climaofficial.com'; // Must match SMTP auth user
    $fromName = 'Clima Tracker';

    $recipients = array_filter(array_map('trim', explode(',', $emailTo)));
    foreach ($recipients as $to) {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $errors[] = "Bad email: $to"; continue; }
        $smtpErr = _sendSmtp($smtpHost, $smtpPort, $smtpUser, $smtpPass, $fromAddr, $fromName, $to, $emailSubject, $emailBody);
        if ($smtpErr === true) $emailSent++;
        else $errors[] = "SMTP $to: $smtpErr";
    }
}

function _sendSmtp($host, $port, $user, $pass, $fromAddr, $fromName, $to, $subject, $htmlBody) {
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $sock = @stream_socket_client("ssl://$host:$port", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) return "Connection failed: $errstr ($errno)";

    $resp = fgets($sock, 512);
    if (substr($resp, 0, 3) !== '220') { fclose($sock); return "Server greeting failed: $resp"; }

    // EHLO
    fwrite($sock, "EHLO climaofficial.com\r\n");
    $r = ''; while ($line = fgets($sock, 512)) { $r .= $line; if ($line[3] === ' ') break; }

    // AUTH LOGIN
    fwrite($sock, "AUTH LOGIN\r\n");
    $r = fgets($sock, 512);
    if (substr($r, 0, 3) !== '334') { fclose($sock); return "AUTH failed: $r"; }

    fwrite($sock, base64_encode($user) . "\r\n");
    $r = fgets($sock, 512);
    if (substr($r, 0, 3) !== '334') { fclose($sock); return "Username rejected: $r"; }

    fwrite($sock, base64_encode($pass) . "\r\n");
    $r = fgets($sock, 512);
    if (substr($r, 0, 3) !== '235') { fclose($sock); return "Auth failed: $r"; }

    // MAIL FROM
    fwrite($sock, "MAIL FROM:<$fromAddr>\r\n");
    $r = fgets($sock, 512);
    if (substr($r, 0, 3) !== '250') { fclose($sock); return "MAIL FROM rejected: $r"; }

    // RCPT TO
    fwrite($sock, "RCPT TO:<$to>\r\n");
    $r = fgets($sock, 512);
    if (substr($r, 0, 3) !== '250') { fclose($sock); return "RCPT TO rejected: $r"; }

    // DATA
    fwrite($sock, "DATA\r\n");
    $r = fgets($sock, 512);
    if (substr($r, 0, 3) !== '354') { fclose($sock); return "DATA rejected: $r"; }

    // Build message with proper headers
    $msgId = '<' . uniqid('clima_', true) . '@climaofficial.com>';
    $date = date('r'); // RFC 2822 date
    $msg  = "Date: $date\r\n";
    $msg .= "From: $fromName <$fromAddr>\r\n";
    $msg .= "Reply-To: $fromAddr\r\n";
    $msg .= "Return-Path: <$fromAddr>\r\n";
    $msg .= "To: $to\r\n";
    $msg .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: base64\r\n";
    $msg .= "Message-ID: $msgId\r\n";
    $msg .= "X-Mailer: ClimaTracker/1.0\r\n";
    $msg .= "\r\n";
    $encoded = chunk_split(base64_encode($htmlBody), 76, "\r\n");
    $msg .= $encoded;
    $msg .= "\r\n.\r\n";

    // Write in chunks to avoid buffer issues
    $len = strlen($msg);
    $pos = 0;
    while ($pos < $len) {
        $chunk = substr($msg, $pos, 8192);
        fwrite($sock, $chunk);
        $pos += 8192;
    }
    $r = fgets($sock, 512);
    fwrite($sock, "QUIT\r\n");
    fclose($sock);

    if (substr($r, 0, 3) === '250') return true;
    return "Send failed: $r";
}

$debug = ['has_email_body' => !empty($emailBody), 'has_tg_msg' => !empty($tgMessage), 'email_to' => $input['email_to'] ?? '', 'has_sale' => !empty($sale)];
echo json_encode(['success' => true, 'email_sent' => $emailSent, 'tg_sent' => $tgSent, 'errors' => $errors, 'debug' => $debug]);
