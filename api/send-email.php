<?php
/*
 * SMTP Email Sender
 * Upload to same PHP hosting as ipqs-proxy.php
 *
 * Accepts POST JSON:
 *   smtp_host, smtp_port, smtp_user, smtp_pass,
 *   from_name, from_email, to_emails[], subject, html_body
 *
 * Returns JSON: { success: true/false, error: "..." }
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST method required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

$host     = isset($input['smtp_host']) ? trim($input['smtp_host']) : '';
$port     = isset($input['smtp_port']) ? intval($input['smtp_port']) : 587;
$user     = isset($input['smtp_user']) ? trim($input['smtp_user']) : '';
$pass     = isset($input['smtp_pass']) ? $input['smtp_pass'] : '';
$fromName = isset($input['from_name']) ? trim($input['from_name']) : 'Clima Network';
$fromEmail= isset($input['from_email'])? trim($input['from_email']) : $user;
$toEmails = isset($input['to_emails']) ? $input['to_emails'] : [];
$subject  = isset($input['subject'])   ? $input['subject'] : '';
$htmlBody = isset($input['html_body']) ? $input['html_body'] : '';

if (!$host || !$user || !$pass || empty($toEmails) || !$subject) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields (smtp_host, smtp_user, smtp_pass, to_emails, subject)']);
    exit;
}

/* Filter valid emails */
$toEmails = array_filter($toEmails, function($e) {
    return filter_var(trim($e), FILTER_VALIDATE_EMAIL);
});
$toEmails = array_values(array_map('trim', $toEmails));

if (empty($toEmails)) {
    echo json_encode(['success' => false, 'error' => 'No valid recipient emails']);
    exit;
}

/* ========== SMTP SEND ========== */
function smtpSend($host, $port, $user, $pass, $fromEmail, $fromName, $toEmails, $subject, $htmlBody) {
    $errors = [];
    $timeout = 30;

    /* Connect */
    if ($port == 465) {
        $socket = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, $timeout);
    } else {
        $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, $timeout);
    }

    if (!$socket) {
        return "Connection failed: $errstr ($errno)";
    }

    stream_set_timeout($socket, $timeout);

    /* Read greeting */
    $greeting = smtpRead($socket);
    if (substr($greeting, 0, 3) != '220') {
        fclose($socket);
        return "Server greeting error: $greeting";
    }

    /* EHLO */
    smtpWrite($socket, "EHLO localhost");
    $ehloResp = smtpReadMulti($socket);

    /* STARTTLS for port 587 */
    if ($port == 587) {
        smtpWrite($socket, "STARTTLS");
        $tlsResp = smtpRead($socket);
        if (substr($tlsResp, 0, 3) != '220') {
            fclose($socket);
            return "STARTTLS failed: $tlsResp";
        }

        $crypto = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if (!$crypto) {
            fclose($socket);
            return "TLS encryption failed";
        }

        /* Re-EHLO after TLS */
        smtpWrite($socket, "EHLO localhost");
        smtpReadMulti($socket);
    }

    /* AUTH LOGIN */
    smtpWrite($socket, "AUTH LOGIN");
    $authResp = smtpRead($socket);
    if (substr($authResp, 0, 3) != '334') {
        fclose($socket);
        return "AUTH LOGIN not supported: $authResp";
    }

    smtpWrite($socket, base64_encode($user));
    $userResp = smtpRead($socket);
    if (substr($userResp, 0, 3) != '334') {
        fclose($socket);
        return "Username rejected: $userResp";
    }

    smtpWrite($socket, base64_encode($pass));
    $passResp = smtpRead($socket);
    if (substr($passResp, 0, 3) != '235') {
        fclose($socket);
        return "Authentication failed: $passResp";
    }

    /* MAIL FROM */
    smtpWrite($socket, "MAIL FROM:<$fromEmail>");
    $fromResp = smtpRead($socket);
    if (substr($fromResp, 0, 3) != '250') {
        fclose($socket);
        return "MAIL FROM rejected: $fromResp";
    }

    /* RCPT TO (multiple recipients) */
    foreach ($toEmails as $to) {
        smtpWrite($socket, "RCPT TO:<$to>");
        $rcptResp = smtpRead($socket);
        if (substr($rcptResp, 0, 3) != '250') {
            $errors[] = "RCPT rejected for $to: $rcptResp";
        }
    }

    if (count($errors) == count($toEmails)) {
        fclose($socket);
        return "All recipients rejected: " . implode('; ', $errors);
    }

    /* DATA */
    smtpWrite($socket, "DATA");
    $dataResp = smtpRead($socket);
    if (substr($dataResp, 0, 3) != '354') {
        fclose($socket);
        return "DATA command failed: $dataResp";
    }

    /* Build email headers + body */
    $boundary = 'B_' . md5(uniqid(time()));
    $messageId = '<' . md5(uniqid(time())) . '@' . $host . '>';
    $date = date('r');

    $msg  = "Date: $date\r\n";
    $msg .= "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <$fromEmail>\r\n";
    $msg .= "To: " . implode(', ', $toEmails) . "\r\n";
    $msg .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $msg .= "Message-ID: $messageId\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: base64\r\n";
    $msg .= "\r\n";
    $msg .= chunk_split(base64_encode($htmlBody));
    $msg .= "\r\n.\r\n";

    fwrite($socket, $msg);
    $sendResp = smtpRead($socket);

    /* QUIT */
    smtpWrite($socket, "QUIT");
    fclose($socket);

    if (substr($sendResp, 0, 3) == '250') {
        return true;
    }
    return "Send failed: $sendResp";
}

function smtpWrite($socket, $data) {
    fwrite($socket, $data . "\r\n");
}

function smtpRead($socket) {
    $response = '';
    while ($line = @fgets($socket, 512)) {
        $response .= $line;
        if (substr($line, 3, 1) == ' ' || strlen($line) < 4) break;
    }
    return trim($response);
}

function smtpReadMulti($socket) {
    $response = '';
    while ($line = @fgets($socket, 512)) {
        $response .= $line;
        if (substr($line, 3, 1) == ' ') break;
    }
    return trim($response);
}

/* Execute */
$result = smtpSend($host, $port, $user, $pass, $fromEmail, $fromName, $toEmails, $subject, $htmlBody);

if ($result === true) {
    echo json_encode([
        'success' => true,
        'message' => 'Email sent to ' . count($toEmails) . ' recipient(s)',
        'recipients' => $toEmails
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => $result
    ]);
}
