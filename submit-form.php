<?php
// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.html');
    exit;
}

// ── Collect and sanitize inputs ──────────────────────
$business = strip_tags(trim($_POST['business'] ?? ''));
$trade    = strip_tags(trim($_POST['trade']    ?? ''));
$location = strip_tags(trim($_POST['location'] ?? ''));
$phone    = strip_tags(trim($_POST['phone']    ?? ''));
$email    = trim($_POST['email'] ?? '');

// ── Validate ─────────────────────────────────────────
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

// ── Build email ───────────────────────────────────────
$to      = 'dominicmadridseo@gmail.com, dominic.j.madrid.7@gmail.com';
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

$headers  = "From: SiteSmart Agency <noreply@sitesmart.agency>\r\n";
$headers .= "Reply-To: {$email}\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

mail($to, $subject, $body, $headers);

// ── Log to file ───────────────────────────────────────
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
