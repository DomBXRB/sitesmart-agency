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
You are a professional web developer. Build a complete, single-file HTML contractor website for the following business:

Business Name: {$business}
Trade/Service: {$trade}
City/State: {$location}
Phone: {$phone}

DESIGN REQUIREMENTS - match this exact style:
- Fonts: Import from Google Fonts - Bebas Neue for headlines, Oswald for subheadings, Barlow for body text
- Color scheme: Dark background #1a1a2e, accent color based on trade (green for landscaping/tree, orange for HVAC, blue for plumbing, red for roofing, yellow for electrical, teal for irrigation, gray for concrete)
- Bold impactful hero section with large Bebas Neue headline, parallax scroll effect using JavaScript
- Scroll animations using Intersection Observer - cards fade in on scroll

REQUIRED SECTIONS:
1. Sticky nav with business name logo on left, phone number on right as clickable tel: link, bold and prominent
2. Hero section - full viewport height, dark overlay, large bold headline like "[CITY] [TRADE] | LICENSED & INSURED", subheadline about serving the local area, two CTA buttons - Get Free Quote and Call Now
3. Trust bar - 4 stats like Years Experience, Jobs Completed, Response Time, Satisfaction Rate - make up realistic numbers
4. Services section - 4 service cards specific to their trade with icons, descriptions, and hover effects
5. Cost Calculator section - build a WORKING JavaScript calculator specific to their trade. For tree removal: inputs for tree height, diameter, condition, proximity to structures. For HVAC: inputs for square footage, system type, age of current system. For roofing: inputs for roof size, material type, pitch. For plumbing: inputs for job type, urgency, home age. For all others create relevant inputs. Calculator should output an estimated price range and have a lead capture form asking for name, phone, email to see the full estimate.
6. Why Choose Us section - 4 benefit cards with icons
7. Service areas section - list 6-8 nearby cities around their location
8. Contact section with phone, email placeholder, and a simple contact form
9. Footer with business name, quick links, services list, contact info

SEO REQUIREMENTS:
- Title tag: {$business} | Professional {$trade} Services in {$location}
- Meta description targeting local keywords
- H1 tag with city and trade keywords
- LocalBusiness schema markup with their details
- All images use descriptive alt tags with city and trade keywords

Make it look professional, modern and impressive. This is a preview to sell the contractor on buying the full site. It should look better than what they currently have. Output ONLY the complete HTML file, nothing else.
PROMPT;

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-20250514',
        'max_tokens' => 8192,
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
