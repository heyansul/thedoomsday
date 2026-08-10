<?php
/**
 * thedoomsday.in — contact endpoint
 * Drop this next to index.html. No dependencies, no Composer.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

const TO_ADDRESS = 'anshulsharmacs016@gmail.com';
const SITE_NAME  = 'TheDoomsday.in';

function out(bool $ok, string $error = ''): void {
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $error]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    out(false, 'method');
}

// Honeypot: real people never fill a hidden field.
if (trim((string)($_POST['hp'] ?? '')) !== '') {
    out(true); // pretend it worked, drop it silently
}

$name    = trim((string)($_POST['name'] ?? ''));
$email   = trim((string)($_POST['email'] ?? ''));
$topic   = trim((string)($_POST['topic'] ?? 'General'));
$message = trim((string)($_POST['message'] ?? ''));

if (mb_strlen($name) < 2 || mb_strlen($name) > 80)        out(false, 'name');
if (!filter_var($email, FILTER_VALIDATE_EMAIL))            out(false, 'email');
if (mb_strlen($message) < 10 || mb_strlen($message) > 4000) out(false, 'message');
if (mb_strlen($topic) > 120) $topic = mb_substr($topic, 0, 120);

// Strip anything that could inject extra mail headers.
$clean = static fn(string $v): string => trim(str_replace(["\r", "\n", "%0a", "%0d"], '', $v));
$name  = $clean($name);
$email = $clean($email);
$topic = $clean($topic);

// Light rate limit: one message per IP per 60 seconds.
$ip   = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$lock = sys_get_temp_dir() . '/tdd_' . md5($ip);
if (is_file($lock) && (time() - (int)filemtime($lock)) < 60) {
    http_response_code(429);
    out(false, 'slow down');
}
@touch($lock);

$subject = sprintf('[%s] %s — %s', SITE_NAME, $topic, $name);

$body = "New message from " . SITE_NAME . "\n"
      . str_repeat('=', 46) . "\n\n"
      . "Name   : {$name}\n"
      . "Email  : {$email}\n"
      . "Topic  : {$topic}\n"
      . "IP     : {$ip}\n"
      . "Time   : " . date('D, d M Y H:i:s') . "\n\n"
      . str_repeat('-', 46) . "\n\n"
      . $message . "\n";

$headers = implode("\r\n", [
    'From: ' . SITE_NAME . ' <no-reply@thedoomsday.in>',
    'Reply-To: ' . $name . ' <' . $email . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: PHP/' . phpversion(),
]);

$sent = @mail(TO_ADDRESS, $subject, $body, $headers, '-f no-reply@thedoomsday.in');

if (!$sent) {
    // Keep a copy so nothing is lost if the mail server is being difficult.
    @file_put_contents(__DIR__ . '/contact-fallback.log',
        date('c') . "\n" . $body . "\n\n", FILE_APPEND | LOCK_EX);
    http_response_code(500);
    out(false, 'mail');
}

out(true);
