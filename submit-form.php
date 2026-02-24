<?php
require __DIR__ . '/phpmailer/Exception.php';
require __DIR__ . '/phpmailer/PHPMailer.php';
require __DIR__ . '/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.html');
    exit;
}

// ── Collect and sanitize inputs ───────────────────────
$business = strip_tags(trim($_POST['business'] ?? ''));
$trade    = strip_tags(trim($_POST['trade']    ?? ''));
$location = strip_tags(trim($_POST['location'] ?? ''));
$phone    = strip_tags(trim($_POST['phone']    ?? ''));
$email    = trim($_POST['email'] ?? '');

// ── Validate ──────────────────────────────────────────
if (!$business || !$trade || !$location || !$phone || !$email) {
    header('Location: index.html?error=missing');
    exit;
}

$email = filter_var($email, FILTER_SANITIZE_EMAIL);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: index.html?error=invalid_email');
    exit;
}

// Prevent header injection in any field
foreach ([$business, $trade, $location, $phone] as $field) {
    if (preg_match('/[\r\n]/', $field)) {
        header('Location: index.html?error=invalid');
        exit;
    }
}

// ── Build email body ──────────────────────────────────
$subject = "NEW PREVIEW REQUEST - {$business} - {$trade} - {$location}";

$body  = "NEW PREVIEW REQUEST\n";
$body .= str_repeat('=', 48) . "\n\n";
$body .= "Business Name : {$business}\n";
$body .= "Trade/Service : {$trade}\n";
$body .= "City & State  : {$location}\n";
$body .= "Phone         : {$phone}\n";
$body .= "Email         : {$email}\n\n";
$body .= str_repeat('=', 48) . "\n";
$body .= "Submitted     : " . date('Y-m-d H:i:s T') . "\n";
$body .= "IP Address    : " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";

// ── Send via SMTP ─────────────────────────────────────
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.hostinger.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'noreply@sitesmart.agency';
    $mail->Password   = 'SMTP_PASSWORD_HERE'; // ← replace with your email password
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = 465;

    $mail->setFrom('noreply@sitesmart.agency', 'SiteSmart Agency');
    $mail->addReplyTo($email, $business);
    $mail->addAddress('dominicmadridseo@gmail.com');
    $mail->addAddress('dominic.j.madrid.7@gmail.com');

    $mail->Subject = $subject;
    $mail->Body    = $body;

    $mail->send();
} catch (Exception $e) {
    // Log the error but still redirect — don't expose details to the user
    $err = date('Y-m-d H:i:s') . " | MAIL ERROR | " . $mail->ErrorInfo . "\n";
    file_put_contents(__DIR__ . '/leads-log.txt', $err, FILE_APPEND | LOCK_EX);
}

// ── Log submission to file ────────────────────────────
$log_line = implode(' | ', [
    date('Y-m-d H:i:s'),
    $business,
    $trade,
    $location,
    $phone,
    $email,
]) . "\n";

file_put_contents(__DIR__ . '/leads-log.txt', $log_line, FILE_APPEND | LOCK_EX);

// ── Redirect to thank-you page ────────────────────────
header('Location: thank-you.html');
exit;
