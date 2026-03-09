<?php
/**
 * Clima Tracker — Notification & Email Engine
 *
 * Actions:
 *   (none/default) — Existing sale notification: Telegram + Email
 *   send_email     — Send templated email via dynamic SMTP
 *   test_smtp      — Test SMTP connection and auth
 *   preview        — Build template HTML and return it
 *
 * POST JSON: see per-action docs below.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'error' => 'POST only']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['success' => false, 'error' => 'Invalid JSON']); exit; }

// ============================================================
//  Default SMTP config (used when no smtp object is provided)
// ============================================================
$DEFAULT_SMTP = [
    'host'       => 'mail.climaofficial.com',
    'port'       => 465,
    'user'       => 'support@climaofficial.com',
    'pass'       => 'Labib@12345',
    'from_name'  => 'Clima Tracker',
    'from_email' => 'support@climaofficial.com',
];

// ============================================================
//  Resolve SMTP: merge provided with defaults
// ============================================================
function _resolveSmtp($inputSmtp, $defaults) {
    if (!$inputSmtp || !is_array($inputSmtp)) return $defaults;
    return [
        'host'       => $inputSmtp['host']       ?? $defaults['host'],
        'port'       => intval($inputSmtp['port'] ?? $defaults['port']),
        'user'       => $inputSmtp['user']        ?? $defaults['user'],
        'pass'       => $inputSmtp['pass']        ?? $defaults['pass'],
        'from_name'  => $inputSmtp['from_name']   ?? $defaults['from_name'],
        'from_email' => $inputSmtp['from_email']  ?? $defaults['from_email'],
    ];
}

// ============================================================
//  CAN-SPAM Compliance Footer
// ============================================================
function _complianceFooter($compliance) {
    $company = $compliance['company'] ?? 'Clima Official';
    $address = $compliance['address'] ?? '';
    $website = $compliance['website'] ?? 'https://climaofficial.com';
    $unsub   = $compliance['unsubscribe_url'] ?? '#';

    $html  = '<div style="border-top:1px solid #e5e7eb;padding:20px 28px;text-align:center;color:#9ca3af;font-size:11px;background:#f9fafb;">';
    $html .= '<p style="margin:0 0 4px;">' . htmlspecialchars($company);
    if ($address) $html .= ' | ' . htmlspecialchars($address);
    $html .= '</p>';
    $html .= '<p style="margin:0 0 4px;"><a href="' . htmlspecialchars($website) . '" style="color:#6b7280;">' . htmlspecialchars($website) . '</a></p>';
    $html .= '<p style="margin:0 0 8px;">You received this email because you are a valued customer/affiliate partner.</p>';
    $html .= '<p style="margin:0;"><a href="' . htmlspecialchars($unsub) . '" style="color:#6b7280;text-decoration:underline;">Unsubscribe</a> from future emails</p>';
    $html .= '</div>';
    return $html;
}

// ============================================================
//  Template Builder
// ============================================================
function _buildTemplate($type, $data, $compliance = []) {
    $subject = '';
    $body = '';

    switch ($type) {

        // --------------------------------------------------
        //  PACKING SLIP / ORDER CONFIRMATION
        // --------------------------------------------------
        case 'packing_slip':
            $customerName    = $data['customer_name']     ?? 'Customer';
            $customerEmail   = $data['customer_email']    ?? '';
            $customerAddress = $data['customer_address']  ?? '';
            $productName     = $data['product_name']      ?? 'Your Order';
            $productPrice    = $data['product_price']     ?? '0.00';
            $orderId         = $data['order_id']          ?? '';
            $trackingNumber  = $data['tracking_number']   ?? '';
            $trackingCarrier = strtolower($data['tracking_carrier'] ?? '');
            $buyAgainUrl     = $data['buy_again_url']     ?? '#';
            $thankYouMsg     = $data['thank_you_message'] ?? 'Thank you for your purchase!';

            $subject = 'Order Confirmation — ' . $productName;

            // Tracking URL
            $trackUrl = '#';
            $carrierDisplay = ucfirst($trackingCarrier ?: 'carrier');
            if ($trackingNumber) {
                switch ($trackingCarrier) {
                    case 'usps':
                        $trackUrl = 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . urlencode($trackingNumber);
                        $carrierDisplay = 'USPS';
                        break;
                    case 'fedex':
                        $trackUrl = 'https://www.fedex.com/fedextrack/?trknbr=' . urlencode($trackingNumber);
                        $carrierDisplay = 'FedEx';
                        break;
                    case 'ups':
                        $trackUrl = 'https://www.ups.com/track?tracknum=' . urlencode($trackingNumber);
                        $carrierDisplay = 'UPS';
                        break;
                    default:
                        $trackUrl = '#';
                        break;
                }
            }

            // Carrier icon
            $carrierIcon = '&#128230;'; // package emoji fallback

            $body  = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;background:#fff;">';
            // Header
            $body .= '<div style="background:linear-gradient(135deg,#0A6C80 0%,#085a6b 100%);padding:28px 28px;border-radius:12px 12px 0 0;text-align:center;">';
            $body .= '<h1 style="color:#fff;margin:0;font-size:22px;">Order Confirmation</h1>';
            $body .= '<p style="color:rgba(255,255,255,.75);margin:8px 0 0;font-size:14px;">Thank you for your order, ' . htmlspecialchars($customerName) . '!</p>';
            $body .= '</div>';
            // Body
            $body .= '<div style="padding:24px 28px;border:1px solid #e2e8f0;border-top:none;">';
            // Thank you message
            $body .= '<p style="font-size:14px;color:#334155;margin:0 0 20px;line-height:1.5;">' . htmlspecialchars($thankYouMsg) . '</p>';
            // Product table
            $body .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
            $body .= '<tr style="background:#f8fafc;"><th style="padding:10px 12px;text-align:left;font-size:13px;color:#64748b;border-bottom:1px solid #e2e8f0;">Product</th><th style="padding:10px 12px;text-align:right;font-size:13px;color:#64748b;border-bottom:1px solid #e2e8f0;">Price</th></tr>';
            $body .= '<tr><td style="padding:12px;font-size:14px;color:#1e293b;border-bottom:1px solid #f1f5f9;">' . htmlspecialchars($productName) . '</td><td style="padding:12px;font-size:14px;color:#059669;font-weight:700;text-align:right;border-bottom:1px solid #f1f5f9;">$' . htmlspecialchars($productPrice) . '</td></tr>';
            $body .= '</table>';
            // Order ID
            if ($orderId) {
                $body .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
                $body .= '<tr><td style="padding:8px 0;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:13px;width:130px;">Order ID</td><td style="padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:13px;"><code style="background:#f1f5f9;padding:2px 8px;border-radius:4px;">' . htmlspecialchars($orderId) . '</code></td></tr>';
                $body .= '</table>';
            }
            // Tracking section
            if ($trackingNumber) {
                $body .= '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:16px;margin-bottom:20px;">';
                $body .= '<p style="margin:0 0 8px;font-size:14px;font-weight:700;color:#166534;">' . $carrierIcon . ' Shipping — ' . htmlspecialchars($carrierDisplay) . '</p>';
                $body .= '<p style="margin:0 0 12px;font-size:13px;color:#334155;">Tracking: <code style="background:#dcfce7;padding:2px 8px;border-radius:4px;">' . htmlspecialchars($trackingNumber) . '</code></p>';
                $body .= '<a href="' . htmlspecialchars($trackUrl) . '" style="display:inline-block;padding:10px 24px;background:#16a34a;color:#fff;border-radius:8px;text-decoration:none;font-weight:700;font-size:13px;">Track Package</a>';
                $body .= '</div>';
            }
            // Shipping address
            if ($customerAddress) {
                $body .= '<div style="margin-bottom:20px;">';
                $body .= '<p style="font-size:13px;color:#64748b;margin:0 0 4px;font-weight:700;">Shipping Address</p>';
                $body .= '<p style="font-size:13px;color:#334155;margin:0;line-height:1.5;">' . nl2br(htmlspecialchars($customerAddress)) . '</p>';
                $body .= '</div>';
            }
            // CTA
            $body .= '<div style="text-align:center;margin:24px 0 8px;">';
            $body .= '<a href="' . htmlspecialchars($buyAgainUrl) . '" style="display:inline-block;padding:12px 28px;background:#0A6C80;color:#fff;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;">Order Again</a>';
            $body .= '</div>';
            $body .= '</div>';
            // Compliance
            $body .= _complianceFooter($compliance);
            $body .= '</div>';
            break;

        // --------------------------------------------------
        //  REVIEW REMINDER
        // --------------------------------------------------
        case 'review_reminder':
            $customerName    = $data['customer_name']       ?? 'Customer';
            $productName     = $data['product_name']        ?? 'your product';
            $daysSince       = $data['days_since_purchase']  ?? '';
            $reviewUrl       = $data['review_url']          ?? '#';
            $tipsStr         = $data['tips']                ?? '';

            $subject = "How's your " . $productName . "? We'd love your feedback!";

            $body  = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;background:#fff;">';
            // Header with stars
            $body .= '<div style="background:linear-gradient(135deg,#0A6C80 0%,#085a6b 100%);padding:28px 28px;border-radius:12px 12px 0 0;text-align:center;">';
            $body .= '<p style="font-size:32px;margin:0 0 8px;">&#11088;&#11088;&#11088;&#11088;&#11088;</p>';
            $body .= '<h1 style="color:#fff;margin:0;font-size:20px;">We\'d Love Your Feedback!</h1>';
            $body .= '</div>';
            // Body
            $body .= '<div style="padding:24px 28px;border:1px solid #e2e8f0;border-top:none;">';
            $body .= '<p style="font-size:14px;color:#334155;margin:0 0 16px;line-height:1.6;">Hi ' . htmlspecialchars($customerName) . ',</p>';
            if ($daysSince) {
                $body .= '<p style="font-size:14px;color:#334155;margin:0 0 16px;line-height:1.6;">It\'s been <strong>' . htmlspecialchars($daysSince) . ' days</strong> since you received your <strong>' . htmlspecialchars($productName) . '</strong>. We hope you\'re enjoying it!</p>';
            } else {
                $body .= '<p style="font-size:14px;color:#334155;margin:0 0 16px;line-height:1.6;">We hope you\'re loving your <strong>' . htmlspecialchars($productName) . '</strong>!</p>';
            }
            $body .= '<p style="font-size:14px;color:#334155;margin:0 0 16px;line-height:1.6;">Your review helps other customers make informed decisions and helps us improve. Would you take a moment to share your experience?</p>';
            // Tips
            if ($tipsStr) {
                $tips = array_map('trim', explode(',', $tipsStr));
                $body .= '<div style="background:#f8fafc;border-radius:8px;padding:16px 20px;margin-bottom:20px;">';
                $body .= '<p style="font-size:13px;font-weight:700;color:#334155;margin:0 0 8px;">Tips for a great review:</p>';
                $body .= '<ul style="margin:0;padding:0 0 0 18px;font-size:13px;color:#475569;line-height:1.8;">';
                foreach ($tips as $tip) {
                    $body .= '<li>' . htmlspecialchars($tip) . '</li>';
                }
                $body .= '</ul></div>';
            }
            // CTA
            $body .= '<div style="text-align:center;margin:24px 0 8px;">';
            $body .= '<a href="' . htmlspecialchars($reviewUrl) . '" style="display:inline-block;padding:12px 28px;background:#16a34a;color:#fff;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;">Leave a Review</a>';
            $body .= '</div>';
            $body .= '</div>';
            // Compliance
            $body .= _complianceFooter($compliance);
            $body .= '</div>';
            break;

        // --------------------------------------------------
        //  NEW OFFER
        // --------------------------------------------------
        case 'new_offer':
            $offerName       = $data['offer_name']        ?? 'New Offer';
            $offerDesc       = $data['offer_description'] ?? '';
            $payoutCpa       = $data['payout_cpa']        ?? '0';
            $commissionType  = $data['commission_type']   ?? 'CPA';
            $offerImageUrl   = $data['offer_image_url']   ?? '';
            $landingUrl      = $data['landing_url']       ?? '#';
            $managerName     = $data['manager_name']      ?? '';
            $managerEmail    = $data['manager_email']     ?? '';
            $managerTelegram = $data['manager_telegram']  ?? '';

            $subject = 'New Offer: ' . $offerName . ' — $' . $payoutCpa . ' CPA';

            $body  = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;background:#fff;">';
            // Header
            $body .= '<div style="background:linear-gradient(135deg,#0A6C80 0%,#085a6b 100%);padding:28px 28px;border-radius:12px 12px 0 0;text-align:center;">';
            $body .= '<h1 style="color:#fff;margin:0;font-size:22px;">New Offer Available!</h1>';
            $body .= '<p style="color:rgba(255,255,255,.75);margin:8px 0 0;font-size:14px;">' . htmlspecialchars($offerName) . '</p>';
            $body .= '</div>';
            // Body
            $body .= '<div style="padding:24px 28px;border:1px solid #e2e8f0;border-top:none;">';
            // Offer image
            if ($offerImageUrl) {
                $body .= '<div style="text-align:center;margin-bottom:20px;">';
                $body .= '<img src="' . htmlspecialchars($offerImageUrl) . '" alt="' . htmlspecialchars($offerName) . '" style="max-width:100%;height:auto;border-radius:8px;border:1px solid #e2e8f0;">';
                $body .= '</div>';
            }
            // Description
            if ($offerDesc) {
                $body .= '<p style="font-size:14px;color:#334155;margin:0 0 20px;line-height:1.6;">' . htmlspecialchars($offerDesc) . '</p>';
            }
            // Offer details table
            $body .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
            $body .= '<tr><td style="padding:8px 0;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:13px;width:130px;">Offer Name</td><td style="padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:13px;font-weight:700;">' . htmlspecialchars($offerName) . '</td></tr>';
            $body .= '<tr><td style="padding:8px 0;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:13px;">Commission Type</td><td style="padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:13px;">' . htmlspecialchars($commissionType) . '</td></tr>';
            $body .= '</table>';
            // Payout badge
            $body .= '<div style="text-align:center;margin-bottom:20px;">';
            $body .= '<span style="display:inline-block;background:#dcfce7;color:#166534;font-size:20px;font-weight:700;padding:12px 28px;border-radius:12px;">$' . htmlspecialchars($payoutCpa) . ' <span style="font-size:13px;font-weight:400;color:#16a34a;">' . htmlspecialchars($commissionType) . '</span></span>';
            $body .= '</div>';
            // CTA
            $body .= '<div style="text-align:center;margin:24px 0 20px;">';
            $body .= '<a href="' . htmlspecialchars($landingUrl) . '" style="display:inline-block;padding:12px 28px;background:#f59e0b;color:#fff;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;">Get Your Link</a>';
            $body .= '</div>';
            // Manager contact card
            if ($managerName || $managerEmail || $managerTelegram) {
                $body .= '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px 20px;margin-bottom:8px;">';
                $body .= '<p style="font-size:13px;font-weight:700;color:#334155;margin:0 0 8px;">Your Account Manager</p>';
                if ($managerName) {
                    $body .= '<p style="font-size:13px;color:#475569;margin:0 0 4px;">' . htmlspecialchars($managerName) . '</p>';
                }
                if ($managerEmail) {
                    $body .= '<p style="font-size:13px;color:#475569;margin:0 0 4px;">Email: <a href="mailto:' . htmlspecialchars($managerEmail) . '" style="color:#0A6C80;">' . htmlspecialchars($managerEmail) . '</a></p>';
                }
                if ($managerTelegram) {
                    $tgHandle = ltrim($managerTelegram, '@');
                    $body .= '<p style="font-size:13px;color:#475569;margin:0;">Telegram: <a href="https://t.me/' . htmlspecialchars($tgHandle) . '" style="color:#0A6C80;">@' . htmlspecialchars($tgHandle) . '</a></p>';
                }
                $body .= '</div>';
            }
            $body .= '</div>';
            // Compliance
            $body .= _complianceFooter($compliance);
            $body .= '</div>';
            break;

        // --------------------------------------------------
        //  SALE NOTIFICATION (preview / manual send)
        // --------------------------------------------------
        case 'sale_notification':
            $orderId     = $data['order_id']   ?? 'ORD-00000';
            $amount      = $data['amount']     ?? '0.00';
            $offerName   = $data['offer_name'] ?? 'Offer';
            $custName    = $data['customer_name']  ?? 'John Smith';
            $custEmail   = $data['customer_email'] ?? 'customer@example.com';
            $custPhone   = $data['customer_phone'] ?? '-';
            $affId       = $data['affiliate_id']   ?? 'Direct';
            $platform    = $data['platform']       ?? 'BuyGoods';
            $ipAddr      = $data['ip']             ?? '0.0.0.0';
            $country     = $data['country']        ?? 'US';
            $fraudScore  = $data['fraud_score']    ?? '12';
            $variant     = $data['variant']        ?? '';
            $date        = $data['date']           ?? date('Y-m-d');

            $fs = intval($fraudScore);
            $fraudLabel = $fs <= 15 ? 'Clean' : ($fs <= 40 ? 'Suspect' : 'Fraud');
            $fColor = $fs <= 15 ? '#16a34a' : ($fs <= 40 ? '#d97706' : '#dc2626');
            $fBg    = $fs <= 15 ? '#dcfce7' : ($fs <= 40 ? '#fef9c3' : '#fee2e2');
            $fraudBadge = "<span style=\"display:inline-block;padding:3px 10px;border-radius:20px;background:$fBg;color:$fColor;font-weight:700;font-size:13px;\">$fraudScore/100 — $fraudLabel</span>";

            $subject = "New Sale: \$$amount — $offerName";

            $body = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;background:#fff;">';
            // Header
            $body .= '<div style="background:linear-gradient(135deg,#0A6C80 0%,#085a6b 100%);padding:24px 28px;border-radius:12px 12px 0 0;">';
            $body .= '<h1 style="color:#fff;margin:0;font-size:20px;">💰 New Sale — $' . htmlspecialchars($amount) . '</h1>';
            $body .= '<p style="color:rgba(255,255,255,.7);margin:6px 0 0;font-size:13px;">' . htmlspecialchars($offerName) . ' | ' . htmlspecialchars($platform) . ' | ' . htmlspecialchars($date) . '</p>';
            $body .= '</div>';
            // Body
            $body .= '<div style="padding:24px 28px;border:1px solid #e2e8f0;border-top:none;">';
            // Sale info
            $body .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
            $rows = [
                ['Order ID', '<code style="background:#f1f5f9;padding:2px 8px;border-radius:4px;">' . htmlspecialchars($orderId) . '</code>'],
                ['Affiliate', '<strong>' . htmlspecialchars($affId) . '</strong>'],
                ['Platform', htmlspecialchars($platform)],
                ['Variant', htmlspecialchars($variant ?: '-')],
                ['Amount', '<strong style="color:#059669;font-size:16px;">$' . htmlspecialchars($amount) . '</strong>'],
            ];
            foreach ($rows as $r) {
                $body .= '<tr><td style="padding:8px 0;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:13px;width:130px;">' . $r[0] . '</td><td style="padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:13px;">' . $r[1] . '</td></tr>';
            }
            $body .= '</table>';
            // Customer
            $body .= '<h3 style="font-size:14px;color:#334155;margin:0 0 10px;border-bottom:2px solid #e2e8f0;padding-bottom:6px;">👤 Customer</h3>';
            $body .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
            $custRows = [['Name', $custName], ['Email', $custEmail], ['Phone', $custPhone], ['IP', "$ipAddr ($country)"]];
            foreach ($custRows as $r) {
                $body .= '<tr><td style="padding:6px 0;color:#64748b;font-size:12px;width:80px;">' . $r[0] . '</td><td style="padding:6px 0;font-size:12px;">' . htmlspecialchars($r[1]) . '</td></tr>';
            }
            $body .= '</table>';
            // Fraud
            $body .= '<h3 style="font-size:14px;color:#334155;margin:0 0 10px;border-bottom:2px solid #e2e8f0;padding-bottom:6px;">🛡️ Fraud Check</h3>';
            $body .= '<p style="margin:0 0 16px;">' . $fraudBadge . '</p>';
            $body .= '</div>';
            $body .= _complianceFooter($compliance);
            $body .= '</div>';
            break;

        // --------------------------------------------------
        //  UNKNOWN TEMPLATE
        // --------------------------------------------------
        default:
            return ['subject' => '', 'body' => '', 'error' => 'Unknown template type: ' . $type];
    }

    return ['subject' => $subject, 'body' => $body];
}


// ============================================================
//  SMTP Send Function (kept exactly as original — do not modify)
// ============================================================
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

// ============================================================
//  SMTP Test Function (connect, auth, quit)
// ============================================================
function _testSmtp($host, $port, $user, $pass) {
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $sock = @stream_socket_client("ssl://$host:$port", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) return "Connection failed: $errstr ($errno)";

    $resp = fgets($sock, 512);
    if (substr($resp, 0, 3) !== '220') { fclose($sock); return "Server greeting failed: " . trim($resp); }

    fwrite($sock, "EHLO climaofficial.com\r\n");
    $r = ''; while ($line = fgets($sock, 512)) { $r .= $line; if ($line[3] === ' ') break; }

    fwrite($sock, "AUTH LOGIN\r\n");
    $r = fgets($sock, 512);
    if (substr($r, 0, 3) !== '334') { fclose($sock); return "AUTH command failed: " . trim($r); }

    fwrite($sock, base64_encode($user) . "\r\n");
    $r = fgets($sock, 512);
    if (substr($r, 0, 3) !== '334') { fclose($sock); return "Username rejected: " . trim($r); }

    fwrite($sock, base64_encode($pass) . "\r\n");
    $r = fgets($sock, 512);
    if (substr($r, 0, 3) !== '235') { fclose($sock); return "Authentication failed: " . trim($r); }

    fwrite($sock, "QUIT\r\n");
    fclose($sock);

    return true;
}


// ============================================================
//  ACTION ROUTING
// ============================================================
$action = $input['action'] ?? '';

switch ($action) {

// ==============================================================
//  ACTION: test_smtp
// ==============================================================
case 'test_smtp':
    $smtp = _resolveSmtp($input['smtp'] ?? null, $DEFAULT_SMTP);
    $result = _testSmtp($smtp['host'], $smtp['port'], $smtp['user'], $smtp['pass']);
    if ($result === true) {
        echo json_encode(['success' => true, 'message' => 'SMTP connected and authenticated']);
    } else {
        echo json_encode(['success' => false, 'error' => $result]);
    }
    exit;

// ==============================================================
//  ACTION: preview
// ==============================================================
case 'preview':
    $templateType = $input['template'] ?? '';
    if (!$templateType) { echo json_encode(['success' => false, 'error' => 'Missing template type']); exit; }
    $tpl = _buildTemplate($templateType, $input['data'] ?? [], $input['compliance'] ?? []);
    if (!empty($tpl['error'])) { echo json_encode(['success' => false, 'error' => $tpl['error']]); exit; }
    echo json_encode(['success' => true, 'subject' => $tpl['subject'], 'html' => $tpl['body']]);
    exit;

// ==============================================================
//  ACTION: send_email
// ==============================================================
case 'send_email':
    $templateType = $input['template'] ?? '';
    if (!$templateType) { echo json_encode(['success' => false, 'error' => 'Missing template type']); exit; }
    $recipients = $input['recipients'] ?? [];
    if (empty($recipients)) { echo json_encode(['success' => false, 'error' => 'No recipients provided']); exit; }

    $tpl = _buildTemplate($templateType, $input['data'] ?? [], $input['compliance'] ?? []);
    if (!empty($tpl['error'])) { echo json_encode(['success' => false, 'error' => $tpl['error']]); exit; }

    $smtp = _resolveSmtp($input['smtp'] ?? null, $DEFAULT_SMTP);
    $sent = 0;
    $failed = 0;
    $results = [];

    foreach ($recipients as $rcpt) {
        $email = trim($rcpt['email'] ?? '');
        $name  = $rcpt['name'] ?? '';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $failed++;
            $results[] = ['email' => $email, 'status' => 'failed', 'error' => 'Invalid email address'];
            continue;
        }
        // Personalize: replace {customer_name} with recipient name
        $personalBody = $tpl['body'];
        $personalSubject = $tpl['subject'];
        if ($name) {
            $personalBody = str_replace('{customer_name}', htmlspecialchars($name), $personalBody);
            $personalSubject = str_replace('{customer_name}', $name, $personalSubject);
        }

        $smtpResult = _sendSmtp($smtp['host'], $smtp['port'], $smtp['user'], $smtp['pass'], $smtp['from_email'], $smtp['from_name'], $email, $personalSubject, $personalBody);
        if ($smtpResult === true) {
            $sent++;
            $results[] = ['email' => $email, 'status' => 'sent', 'error' => null];
        } else {
            $failed++;
            $results[] = ['email' => $email, 'status' => 'failed', 'error' => $smtpResult];
        }
    }

    echo json_encode(['success' => true, 'sent' => $sent, 'failed' => $failed, 'results' => $results]);
    exit;

// ==============================================================
//  DEFAULT: Existing sale notification (backward compatible)
// ==============================================================
default:
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
                $status = $sale['upsell' . $num] ?? '';
                /* Check actual amount from sale first, then fall back to offer price */
                $actualAmt = floatval($sale['upsell' . $num . 'Amount'] ?? 0);
                $price = $actualAmt > 0 ? $actualAmt : floatval($up['price'] ?? 0);
                $packName = $sale['upsell' . $num . 'Pack'] ?? '';
                if ($status === 'accept') {
                    $label = "Upsell $num: ✅ \$" . number_format($price, 2);
                    if ($packName) $label .= " ($packName)";
                    $upsellParts[] = $label;
                    $upsellTotal += $price;
                } elseif ($status === 'decline') {
                    $upsellParts[] = "Upsell $num: ❌";
                }
            }
        } else {
            for ($i = 1; $i <= 5; $i++) {
                $status = $sale['upsell' . $i] ?? '';
                $actualAmt = floatval($sale['upsell' . $i . 'Amount'] ?? 0);
                $packName = $sale['upsell' . $i . 'Pack'] ?? '';
                if ($status === 'accept') {
                    $label = "Upsell $i: ✅";
                    if ($actualAmt > 0) $label .= " \$" . number_format($actualAmt, 2);
                    if ($packName) $label .= " ($packName)";
                    $upsellParts[] = $label;
                    $upsellTotal += $actualAmt;
                }
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
        $isUpsellNotify = !empty($sale['_upsellNotify']);
        $upsellNum = intval($sale['_upsellNum'] ?? 0);
        if ($isUpsellNotify && $upsellNum > 0) {
            $tgMessage = "🛒 <b>UPSELL $upsellNum ACCEPTED!</b>\n";
        } else {
            $tgMessage = "💰 <b>NEW SALE!</b>\n";
        }
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
        if ($isUpsellNotify && $upsellNum > 0) {
            $emailSubject = "Upsell $upsellNum Accepted: \$$amount — $offerName";
        } else {
            $emailSubject = "New Sale: \$$amount — $offerName";
        }

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
        if ($isUpsellNotify && $upsellNum > 0) {
            $emailBody .= '<h1 style="color:#fff;margin:0;font-size:20px;">🛒 Upsell ' . $upsellNum . ' Accepted — $' . $amount . '</h1>';
        } else {
            $emailBody .= '<h1 style="color:#fff;margin:0;font-size:20px;">💰 New Sale — $' . $amount . '</h1>';
        }
        $emailBody .= '<p style="color:rgba(255,255,255,.7);margin:6px 0 0;font-size:13px;">' . $offerName . ' | ' . $platName . ' | ' . $date . '</p>';
        $emailBody .= '</div>';
        // Body
        $emailBody .= '<div style="padding:24px 28px;border:1px solid #e2e8f0;border-top:none;">';
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
        $emailBody .= '</div>';
        // CAN-SPAM compliance footer on sale notification emails
        $saleCompliance = $input['compliance'] ?? [];
        $emailBody .= _complianceFooter($saleCompliance);
        $emailBody .= '</div>';
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
        $smtp = _resolveSmtp($input['smtp'] ?? null, $DEFAULT_SMTP);

        $recipients = array_filter(array_map('trim', explode(',', $emailTo)));
        foreach ($recipients as $to) {
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $errors[] = "Bad email: $to"; continue; }
            $smtpErr = _sendSmtp($smtp['host'], $smtp['port'], $smtp['user'], $smtp['pass'], $smtp['from_email'], $smtp['from_name'], $to, $emailSubject, $emailBody);
            if ($smtpErr === true) $emailSent++;
            else $errors[] = "SMTP $to: $smtpErr";
        }
    }

    $debug = ['has_email_body' => !empty($emailBody), 'has_tg_msg' => !empty($tgMessage), 'email_to' => $input['email_to'] ?? '', 'has_sale' => !empty($sale)];
    echo json_encode(['success' => true, 'email_sent' => $emailSent, 'tg_sent' => $tgSent, 'errors' => $errors, 'debug' => $debug]);
    exit;

} // end switch
