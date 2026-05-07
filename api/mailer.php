<?php
/*
 * Server-side SMTP Mailer
 * POST body (JSON): { to, subject, body, [replyTo], [attachment:{name,data}] }
 * attachment.data = base64-encoded file content (no data URI prefix)
 * Reads SMTP config from Firebase REST API (settings/smtp)
 * Sends via raw SMTP with STARTTLS — no external libraries needed
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['success'=>false,'message'=>'Invalid JSON']); exit; }

$to         = isset($input['to'])         ? trim($input['to'])         : '';
$subject    = isset($input['subject'])    ? trim($input['subject'])    : '';
$body       = isset($input['body'])       ? $input['body']             : '';
$replyTo    = isset($input['replyTo'])    ? trim($input['replyTo'])    : '';
$cc         = isset($input['cc'])         ? trim($input['cc'])         : '';
$attachment = isset($input['attachment']) ? $input['attachment']       : null;

if (!$to || !$subject || !$body) {
    echo json_encode(['success'=>false,'message'=>'Missing: to, subject, body']);
    exit;
}

/* Validate attachment if provided */
$attachName = '';
$attachData = '';
if ($attachment && !empty($attachment['data']) && !empty($attachment['name'])) {
    $attachName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $attachment['name']);
    $attachData = $attachment['data'];
    /* Strip data URI prefix if present */
    if (strpos($attachData, 'base64,') !== false) {
        $attachData = substr($attachData, strpos($attachData, 'base64,') + 7);
    }
    $attachData = str_replace(["\r", "\n", ' '], '', $attachData);
}

/* ── Fetch SMTP config from Firebase ── */
$FB = 'https://clima-dashboard-default-rtdb.firebaseio.com';
$ch = curl_init($FB . '/settings/smtp.json');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 6,
    CURLOPT_SSL_VERIFYPEER => false
]);
$smtpJson = curl_exec($ch);
curl_close($ch);

$cfg = $smtpJson ? json_decode($smtpJson, true) : null;
if (!$cfg || empty($cfg['host']) || empty($cfg['username']) || empty($cfg['password'])) {
    echo json_encode(['success'=>false,'message'=>'SMTP not configured in admin settings']);
    exit;
}

$host     = $cfg['host'];
$port     = intval($cfg['port'] ?? 587);
$user     = $cfg['username'];
$pass     = $cfg['password'];
$from     = !empty($cfg['fromEmail']) ? $cfg['fromEmail'] : $user;
$fromName = !empty($cfg['fromName'])  ? $cfg['fromName']  : 'Clima Network';

/* ── SMTP Send via socket ── */
function smtp_read($sock) {
    $data = '';
    while ($line = fgets($sock, 512)) {
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') break;
    }
    return $data;
}
function smtp_cmd($sock, $cmd) {
    fputs($sock, $cmd . "\r\n");
    return smtp_read($sock);
}
function smtp_code($resp) {
    return intval(substr(trim($resp), 0, 3));
}

function sendSMTP($host, $port, $user, $pass, $from, $fromName, $to, $subject, $body, $replyTo='', $attachName='', $attachData='', $cc='') {
    /* Connect */
    $ctx  = stream_context_create(['ssl' => ['verify_peer'=>false,'verify_peer_name'=>false]]);
    $sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) return "Connect failed: {$errstr} ({$errno})";

    stream_set_timeout($sock, 10);
    smtp_read($sock); /* banner */

    /* EHLO */
    $r = smtp_cmd($sock, 'EHLO ' . (gethostname() ?: 'localhost'));
    if (smtp_code($r) !== 250) { fclose($sock); return "EHLO failed: {$r}"; }

    /* STARTTLS */
    $r = smtp_cmd($sock, 'STARTTLS');
    if (smtp_code($r) !== 220) { fclose($sock); return "STARTTLS rejected: {$r}"; }
    if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        fclose($sock); return 'TLS upgrade failed';
    }

    /* Re-EHLO after TLS */
    smtp_cmd($sock, 'EHLO ' . (gethostname() ?: 'localhost'));

    /* AUTH LOGIN */
    smtp_cmd($sock, 'AUTH LOGIN');
    smtp_cmd($sock, base64_encode($user));
    $r = smtp_cmd($sock, base64_encode($pass));
    if (smtp_code($r) !== 235) { fclose($sock); return "Auth failed: {$r}"; }

    /* MAIL FROM */
    $r = smtp_cmd($sock, "MAIL FROM:<{$from}>");
    if (smtp_code($r) !== 250) { fclose($sock); return "MAIL FROM failed: {$r}"; }

    /* RCPT TO — support comma-separated To + CC */
    $recipients = array_map('trim', explode(',', $to));
    if ($cc) {
        foreach (array_map('trim', explode(',', $cc)) as $c) {
            if ($c && !in_array($c, $recipients)) $recipients[] = $c;
        }
    }
    foreach ($recipients as $rcpt) {
        if (!$rcpt) continue;
        $r = smtp_cmd($sock, "RCPT TO:<{$rcpt}>");
        if (smtp_code($r) !== 250 && smtp_code($r) !== 251) {
            fclose($sock); return "RCPT TO failed for {$rcpt}: {$r}";
        }
    }

    /* DATA */
    smtp_cmd($sock, 'DATA');

    $encFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $encSubject  = '=?UTF-8?B?' . base64_encode($subject)  . '?=';
    $msgId       = '<' . time() . '.' . mt_rand() . '@' . (gethostname() ?: 'clima') . '>';

    $msg  = "From: {$encFromName} <{$from}>\r\n";
    $msg .= "To: {$to}\r\n";
    $msg .= "Subject: {$encSubject}\r\n";
    $msg .= "Message-ID: {$msgId}\r\n";
    $msg .= "Date: " . date('r') . "\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "X-Mailer: Clima-Network-Mailer/1.0\r\n";
    if ($replyTo) $msg .= "Reply-To: {$replyTo}\r\n";
    if ($cc) $msg .= "Cc: {$cc}\r\n";
    $msg .= "List-Unsubscribe: <mailto:{$from}?subject=unsubscribe>\r\n";

    if ($attachName && $attachData) {
        /* Multipart/mixed for attachment */
        $boundary = '---=_Part_' . md5(uniqid());
        $msg .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
        $msg .= "\r\n";
        /* HTML part */
        $msg .= "--{$boundary}\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n";
        $msg .= "\r\n";
        $msg .= chunk_split(base64_encode($body));
        $msg .= "\r\n";
        /* Attachment part */
        $msg .= "--{$boundary}\r\n";
        $msg .= "Content-Type: application/pdf; name=\"{$attachName}\"\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n";
        $msg .= "Content-Disposition: attachment; filename=\"{$attachName}\"\r\n";
        $msg .= "\r\n";
        $msg .= chunk_split($attachData);
        $msg .= "\r\n";
        $msg .= "--{$boundary}--\r\n";
    } else {
        /* Simple HTML-only */
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n";
        $msg .= "\r\n";
        $msg .= chunk_split(base64_encode($body));
    }

    $msg .= "\r\n.\r\n";

    fputs($sock, $msg);
    $r = smtp_read($sock);
    smtp_cmd($sock, 'QUIT');
    fclose($sock);

    if (smtp_code($r) !== 250) return "Message rejected: {$r}";
    return true;
}

$result = sendSMTP($host, $port, $user, $pass, $from, $fromName, $to, $subject, $body, $replyTo, $attachName, $attachData, $cc);

if ($result === true) {
    echo json_encode(['success'=>true,'message'=>'Email sent']);
} else {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$result]);
}
