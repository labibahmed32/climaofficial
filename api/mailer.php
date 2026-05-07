<?php
/*
 * Server-side SMTP Mailer
 * POST body (JSON): { to, subject, body, [replyTo] }
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

$to      = isset($input['to'])      ? trim($input['to'])      : '';
$subject = isset($input['subject']) ? trim($input['subject']) : '';
$body    = isset($input['body'])    ? $input['body']          : '';
$replyTo = isset($input['replyTo']) ? trim($input['replyTo']) : '';

if (!$to || !$subject || !$body) {
    echo json_encode(['success'=>false,'message'=>'Missing: to, subject, body']);
    exit;
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

function sendSMTP($host, $port, $user, $pass, $from, $fromName, $to, $subject, $body, $replyTo='') {
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

    /* RCPT TO — support comma-separated */
    $recipients = array_map('trim', explode(',', $to));
    foreach ($recipients as $rcpt) {
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
    $encodedBody = chunk_split(base64_encode($body));

    $msg  = "From: {$encFromName} <{$from}>\r\n";
    $msg .= "To: {$to}\r\n";
    $msg .= "Subject: {$encSubject}\r\n";
    $msg .= "Message-ID: {$msgId}\r\n";
    $msg .= "Date: " . date('r') . "\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: base64\r\n";
    $msg .= "X-Mailer: Clima-Network-Mailer/1.0\r\n";
    if ($replyTo) $msg .= "Reply-To: {$replyTo}\r\n";
    /* Anti-spam: List-Unsubscribe */
    $msg .= "List-Unsubscribe: <mailto:{$from}?subject=unsubscribe>\r\n";
    $msg .= "\r\n";
    $msg .= $encodedBody;
    $msg .= "\r\n.\r\n";

    fputs($sock, $msg);
    $r = smtp_read($sock);
    smtp_cmd($sock, 'QUIT');
    fclose($sock);

    if (smtp_code($r) !== 250) return "Message rejected: {$r}";
    return true;
}

$result = sendSMTP($host, $port, $user, $pass, $from, $fromName, $to, $subject, $body, $replyTo);

if ($result === true) {
    echo json_encode(['success'=>true,'message'=>'Email sent']);
} else {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$result]);
}
