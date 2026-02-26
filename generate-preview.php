<?php
/**
 * generate-preview.php
 *
 * Called in the background after form submission.
 * 1. Generates a contractor website via the Claude API
 * 2. Deploys it to Netlify
 * 3. Emails the preview link to the contractor and internal team
 */

function generatePreview(string $business, string $trade, string $location, string $phone, string $email): void
{
    require_once __DIR__ . '/config.php';

    $log = __DIR__ . '/leads-log.txt';
    $tag = "{$business} | {$trade} | {$location}";

    $logLine = function (string $msg) use ($log, $tag): void {
        file_put_contents($log, date('Y-m-d H:i:s') . " | PREVIEW | {$tag} | {$msg}\n", FILE_APPEND | LOCK_EX);
    };

    // ── 1. Generate HTML with Claude ──────────────────
    $logLine("Calling Claude API…");
    $html = callClaudeForSite($business, $trade, $location, $phone, ANTHROPIC_API_KEY);

    if (!$html) {
        $logLine("ERROR: Claude returned no content.");
        return;
    }
    $logLine("HTML generated (" . strlen($html) . " bytes).");

    // ── 2. Deploy to Netlify ───────────────────────────
    $slug = makeSlug($business . ' ' . $trade) . '-' . substr(md5(uniqid('', true)), 0, 6);
    $logLine("Deploying to Netlify as: {$slug}");

    $previewUrl = deployToNetlify($html, $slug, NETLIFY_TOKEN);

    if (!$previewUrl) {
        $logLine("ERROR: Netlify deploy failed.");
        return;
    }
    $logLine("Deployed: {$previewUrl}");

    // ── 3. Email contractor ────────────────────────────
    $sent = mailContractor($email, $business, $trade, $location, $previewUrl);
    $logLine($sent ? "Contractor email sent." : "WARNING: Contractor email failed.");

    // ── 4. Email internal team ─────────────────────────
    $sent = mailInternal($business, $trade, $location, $phone, $email, $previewUrl);
    $logLine($sent ? "Internal email sent." : "WARNING: Internal email failed.");
}


// ── Claude API ─────────────────────────────────────────────────────────────

function callClaudeForSite(string $business, string $trade, string $location, string $phone, string $apiKey): ?string
{
    $prompt = <<<PROMPT
Generate a complete, professional single-file HTML contractor website. Return ONLY raw HTML — no markdown, no code fences, no explanation.

BUSINESS DETAILS:
- Business Name: {$business}
- Trade/Service: {$trade}
- Location: {$location}
- Phone: {$phone}

DESIGN:
- Dark background (#0f1117), white body text, orange accent (#f97316)
- Modern, trustworthy feel — think professional contractor company
- Google Fonts: Inter (import in <head>)
- Fully responsive (mobile-first)
- All CSS and JS must be inline in the single file

SECTIONS (in order):

1. <head> — SEO meta tags:
   - <title>{$business} | {$trade} in {$location}</title>
   - Meta description targeting "{$trade} {$location}" and related keywords
   - og:title, og:description, og:type

2. HEADER/NAV — fixed, blurred background
   - Left: business name as logo
   - Right: phone number as tel: link + "Get a Free Quote" button (scrolls to contact)

3. HERO — full-height section with orange radial glow
   - H1: "[City]'s Trusted {$trade} Experts" (use the actual city from {$location})
   - Subheadline: one sentence on reliability, response time, local expertise
   - Two CTAs: "Get a Free Quote" (primary, orange) and "Call Now: {$phone}" (secondary, outline)

4. SERVICES — dark card grid (3 columns desktop, 1 mobile)
   - List exactly 6 services relevant to {$trade}
   - Each card: icon (use relevant emoji), service name, 1-sentence description

5. COST CALCULATOR — this is the most important section
   - Headline: "[Trade] Cost Estimator"
   - Build a WORKING JavaScript calculator specific to {$trade}:
     * 3–4 input fields relevant to how {$trade} jobs are priced (use <select> dropdowns or range sliders)
     * Real price ranges per option (e.g. for Tree Removal: small tree $300–500, medium $500–900, large $900–1500+)
     * On input change, dynamically calculate and display a "Estimated Cost: $X–$Y" range
     * Below the estimate: a lead capture mini-form asking for Name, Phone, Email with a "Send Me My Exact Quote" button
     * The button should show a success message (JS, no backend needed)
   - Dark card background, orange accent for the estimate display

6. WHY CHOOSE US — 3 columns
   - 3 trust signals specific to {$trade}: e.g. licensed & insured, X years experience, local family-owned, 24/7 availability, 5-star rated
   - Icon + heading + 1 sentence each

7. CONTACT — centered section
   - Phone number displayed large (clickable tel: link)
   - Simple contact form: Name, Phone, Email, Message textarea, Submit button
   - On submit: show a "We'll call you within 1 hour!" success message (JS)

8. FOOTER
   - Business name, phone, location
   - "© 2025 {$business}. All rights reserved."

IMPORTANT:
- The cost calculator MUST have real JavaScript logic with actual price calculations — not placeholder text
- All section IDs must exist for scroll navigation: #services, #calculator, #contact
- Return ONLY the complete HTML file starting with <!DOCTYPE html>
PROMPT;

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-20250514',
        'max_tokens' => 8000,
        'messages'   => [['role' => 'user', 'content' => $prompt]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);

    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $logFile = __DIR__ . '/leads-log.txt';

    if ($curlErr) {
        file_put_contents($logFile,
            date('Y-m-d H:i:s') . " | CLAUDE CURL ERROR | {$curlErr}\n",
            FILE_APPEND | LOCK_EX);
        return null;
    }

    if (!$raw) {
        file_put_contents($logFile,
            date('Y-m-d H:i:s') . " | CLAUDE ERROR | HTTP {$httpCode} | Empty response body\n",
            FILE_APPEND | LOCK_EX);
        return null;
    }

    if ($httpCode !== 200) {
        file_put_contents($logFile,
            date('Y-m-d H:i:s') . " | CLAUDE ERROR | HTTP {$httpCode} | " . $raw . "\n",
            FILE_APPEND | LOCK_EX);
        return null;
    }

    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        file_put_contents($logFile,
            date('Y-m-d H:i:s') . " | CLAUDE ERROR | JSON parse failed | " . $raw . "\n",
            FILE_APPEND | LOCK_EX);
        return null;
    }

    $text = $data['content'][0]['text'] ?? null;

    if (!$text) {
        file_put_contents($logFile,
            date('Y-m-d H:i:s') . " | CLAUDE ERROR | No content in response | " . json_encode($data) . "\n",
            FILE_APPEND | LOCK_EX);
        return null;
    }

    // Strip markdown code fences if Claude wrapped the output
    if (preg_match('/```(?:html)?\s*([\s\S]+?)\s*```/i', $text, $m)) {
        return trim($m[1]);
    }

    // Also trim any leading/trailing whitespace or stray text before <!DOCTYPE
    if (preg_match('/(<!DOCTYPE[\s\S]+)/i', $text, $m)) {
        return trim($m[1]);
    }

    return trim($text);
}


// ── Netlify Deploy ─────────────────────────────────────────────────────────

function deployToNetlify(string $html, string $slug, string $token): ?string
{
    // 1. Create the site
    $ch = curl_init('https://api.netlify.com/api/v1/sites');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(['name' => $slug]),
    ]);
    $raw  = curl_exec($ch);
    curl_close($ch);

    $site   = json_decode($raw, true);
    $siteId = $site['id'] ?? null;
    if (!$siteId) return null;

    $siteUrl = $site['ssl_url'] ?? $site['url'] ?? "https://{$slug}.netlify.app";

    // 2. Package HTML into a zip
    // - index.html        : the generated contractor site
    // - _headers          : Netlify plain-text headers file (most reliable content-type fix)
    // - netlify.toml      : belt-and-suspenders toml headers config
    $netlifyHeaders = <<<HEADERS
/index.html
  Content-Type: text/html; charset=UTF-8

/*
  Content-Type: text/html; charset=UTF-8
HEADERS;

    $netlifyToml = <<<TOML
[[headers]]
  for = "/*.html"
  [headers.values]
    Content-Type = "text/html; charset=UTF-8"

[[headers]]
  for = "/"
  [headers.values]
    Content-Type = "text/html; charset=UTF-8"
TOML;

    $tmpZip = sys_get_temp_dir() . '/netlify_' . uniqid('', true) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::CREATE) !== true) return null;
    $zip->addFromString('index.html',   $html);
    $zip->addFromString('_headers',     $netlifyHeaders);
    $zip->addFromString('netlify.toml', $netlifyToml);
    $zip->close();

    // 3. Upload zip as a deploy
    $ch = curl_init("https://api.netlify.com/api/v1/sites/{$siteId}/deploys");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/zip',
        ],
        CURLOPT_POSTFIELDS => file_get_contents($tmpZip),
    ]);
    curl_exec($ch);
    curl_close($ch);

    @unlink($tmpZip);

    return $siteUrl;
}


// ── Emails ─────────────────────────────────────────────────────────────────

function buildMailer(): \PHPMailer\PHPMailer\PHPMailer
{
    require_once __DIR__ . '/phpmailer/Exception.php';
    require_once __DIR__ . '/phpmailer/PHPMailer.php';
    require_once __DIR__ . '/phpmailer/SMTP.php';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.hostinger.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'noreply@sitesmart.agency';
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = 465;
    $mail->setFrom('noreply@sitesmart.agency', 'SiteSmart Agency');
    return $mail;
}

function mailContractor(string $to, string $business, string $trade, string $location, string $previewUrl): bool
{
    $subject = "Your free preview site is ready — {$business}";
    $body    = "Hi {$business},\n\n"
             . "Your free preview website is live and ready to view:\n\n"
             . "  {$previewUrl}\n\n"
             . "This is a real site built specifically for your business — "
             . "complete with a {$trade} cost calculator designed to turn visitors into leads.\n\n"
             . "If you like what you see, we'll optimize it for local SEO and get you "
             . "ranking in the top 3 for {$trade} in {$location} — or you don't pay.\n\n"
             . "Questions? Call or text us:\n"
             . "  (505) 386-1190\n\n"
             . "— The SiteSmart Team\n"
             . "SiteSmart.agency";

    try {
        $mail = buildMailer();
        $mail->addAddress($to, $business);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->send();
        return true;
    } catch (\Exception $e) {
        file_put_contents(__DIR__ . '/leads-log.txt',
            date('Y-m-d H:i:s') . " | MAIL ERROR (contractor) | " . $e->getMessage() . "\n",
            FILE_APPEND | LOCK_EX);
        return false;
    }
}

function mailInternal(string $business, string $trade, string $location, string $phone, string $email, string $previewUrl): bool
{
    $subject = "PREVIEW DEPLOYED — {$business} — {$trade} — {$location}";
    $body    = "PREVIEW DEPLOYED\n"
             . str_repeat('=', 48) . "\n\n"
             . "Business Name : {$business}\n"
             . "Trade/Service : {$trade}\n"
             . "City & State  : {$location}\n"
             . "Phone         : {$phone}\n"
             . "Email         : {$email}\n\n"
             . "Preview URL   : {$previewUrl}\n\n"
             . str_repeat('=', 48) . "\n"
             . "Deployed: " . date('Y-m-d H:i:s T') . "\n";

    try {
        $mail = buildMailer();
        $mail->addAddress('dominicmadridseo@gmail.com');
        $mail->addAddress('dominic.j.madrid.7@gmail.com');
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->send();
        return true;
    } catch (\Exception $e) {
        file_put_contents(__DIR__ . '/leads-log.txt',
            date('Y-m-d H:i:s') . " | MAIL ERROR (internal) | " . $e->getMessage() . "\n",
            FILE_APPEND | LOCK_EX);
        return false;
    }
}


// ── Utilities ───────────────────────────────────────────────────────────────

function makeSlug(string $str): string
{
    $slug = strtolower($str);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return substr($slug, 0, 50);
}
