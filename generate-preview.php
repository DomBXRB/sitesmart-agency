<?php
/**
 * generate-preview.php
 *
 * Called in the background after form submission.
 * 1. Generates a contractor website via the Claude API
 * 2. Deploys it to Netlify
 * 3. Emails the preview link to the contractor and internal team
 */

function generatePreview(string $business, string $trade, string $location, string $phone, string $email): ?string
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
        return null;
    }
    $logLine("HTML generated (" . strlen($html) . " bytes).");

    // ── 2. Deploy to Netlify ───────────────────────────
    $slug = makeSlug($business . ' ' . $trade) . '-' . substr(md5(uniqid('', true)), 0, 6);
    $logLine("Deploying to Netlify as: {$slug}");

    $previewUrl = deployToNetlify($html, $slug, NETLIFY_TOKEN);

    if (!$previewUrl) {
        $logLine("ERROR: Netlify deploy failed.");
        return null;
    }
    $logLine("Deployed: {$previewUrl}");

    // ── 3. Email contractor ────────────────────────────
    $sent = mailContractor($email, $business, $trade, $location, $previewUrl);
    $logLine($sent ? "Contractor email sent." : "WARNING: Contractor email failed.");

    // ── 4. Email internal team ─────────────────────────
    $sent = mailInternal($business, $trade, $location, $phone, $email, $previewUrl);
    $logLine($sent ? "Internal email sent." : "WARNING: Internal email failed.");

    return $previewUrl;
}


// ── Claude API ─────────────────────────────────────────────────────────────

function callClaudeForSite(string $business, string $trade, string $location, string $phone, string $apiKey): ?string
{
    $prompt = <<<PROMPT
You are a professional contractor website designer. Build a complete, single-file HTML website for the following business:

Business Name: {$business}
Trade/Service: {$trade}
City/State: {$location}
Phone: {$phone}

CRITICAL RULES:
- Output ONLY raw HTML. No markdown, no explanation, no code fences.
- NO external images whatsoever. Use only CSS gradients, shapes, and inline SVG icons.
- NO JavaScript animations that hide content on load. NO Intersection Observer. NO opacity:0 on load. Everything must be visible immediately.
- NO scroll-triggered animations. Content must show without scrolling.
- All cards and sections must be fully visible and filled with content immediately.

PREVIEW BAR - Add this as the very first element inside body, position sticky top 0, z-index 9999:
A full-width bar above the nav. Background #1a1a1a, border-bottom 2px solid [accent color]. Text centered: '✦ This is your free site preview built by SiteSmart.agency' on the left, and on the right a bold button that says 'Claim This Site →' linking to https://sitesmart.agency with target blank. Make it look clean and professional, not spammy.

TRADE-SPECIFIC COLOR SCHEMES - Use exactly these colors based on the trade:
- Tree Removal or Landscaping: primary #1a3a1a, secondary #2d5a27, accent #4a9e3f, text #f0f7ee, hero gradient from #0f2010 to #1a3a1a, card bg #1e3d1e
- HVAC: primary #0d1b2a, secondary #1b3a5c, accent #2196f3, text #e8f4fd, hero gradient from #0a1628 to #1b3a5c, card bg #162840
- Roofing: primary #1a1a1a, secondary #2d2d2d, accent #c0392b, text #f5f5f5, hero gradient from #0f0f0f to #2d2d2d, card bg #222222
- Concrete or Flatwork: primary #1c1c1c, secondary #3d3530, accent #8b7355, text #f0ede8, hero gradient from #141210 to #3d3530, card bg #2a2520
- Plumbing: primary #0a1628, secondary #1a3a5c, accent #0077b6, text #e8f4fd, hero gradient from #071020 to #1a3a5c, card bg #102030
- Electrical: primary #0f0f0f, secondary #1a1a1a, accent #f0c419, text #ffffff, hero gradient from #0a0a0a to #1f1f1f, card bg #161616
- Solar: primary #0d1b2a, secondary #1a3a4a, accent #f4a61c, text #e8f4fd, hero gradient from #081420 to #1a3a4a, card bg #142030
- Fencing: primary #1e1a14, secondary #3d3020, accent #8b6914, text #f5f0e8, hero gradient from #140f08 to #3d3020, card bg #2a2218
- Irrigation: primary #0a2018, secondary #1a4030, accent #00b894, text #e8fdf5, hero gradient from #061510 to #1a4030, card bg #102820
- Pressure Washing: primary #0a1628, secondary #1a3a5c, accent #00b4d8, text #e8f8ff, hero gradient from #071020 to #1a3a5c, card bg #102030
- Painting: primary #1a1510, secondary #2d2018, accent #e07b39, text #faf5f0, hero gradient from #120e08 to #2d2018, card bg #221a12
- Pest Control: primary #0f1a0f, secondary #1a2d1a, accent #5a8a3c, text #f0f5f0, hero gradient from #0a120a to #1a2d1a, card bg #152015
- Pool Service: primary #0a1e2d, secondary #0d3a5c, accent #00c6fb, text #e8f8ff, hero gradient from #061520 to #0d3a5c, card bg #0f2840
- Garage Doors: primary #0f1520, secondary #1a2535, accent #4a90d9, text #e8eef5, hero gradient from #0a1018 to #1a2535, card bg #141e2d
- Foundation Repair: primary #1a1510, secondary #2d2018, accent #8b6914, text #f5f0e8, hero gradient from #120e08 to #2d2018, card bg #221a12
- General Contractor: primary #0f1520, secondary #1a2535, accent #d4af37, text #f5f0e8, hero gradient from #0a1018 to #1a2535, card bg #141e2d

REQUIRED SECTIONS - build all of these:

1. STICKY NAV: Background primary color, business name as logo on left in bold accent color, phone number on right as clickable green pill button with phone icon. Height 60px.

2. HERO SECTION: Full viewport height minus nav. Background uses hero gradient with 3-4 large blurred CSS blob shapes for visual depth (use border-radius 50%, filter blur 80px, opacity 0.3). Large headline in Bebas Neue or Impact font: '[CITY] [TRADE] | LICENSED & INSURED'. Subheadline in normal weight about serving the local area. Two CTA buttons: solid accent color 'GET FREE QUOTE' and outlined 'CALL NOW'. Add a trust row of 4 badges below buttons: Licensed, Insured, Free Estimates, Same-Day Service.

3. STATS BAR: Full width solid accent color background. 4 stats in white: '15+ Years Experience', '500+ Jobs Completed', '4.9★ Rating', '24/7 Emergency Service'. Large bold numbers, small label underneath.

4. SERVICES SECTION: Background secondary color. Section title 'OUR [TRADE] SERVICES' in Bebas Neue. 4 service cards in a 2x2 grid. Each card: background card bg color, 1px accent border, inline SVG icon relevant to the service in accent color, bold service name, 2 sentence description. Services should be specific and realistic for the trade. Cards must be fully visible with no animation classes.

5. COST CALCULATOR: Background primary color. Title 'INSTANT PRICE ESTIMATE'. Build a fully working JavaScript calculator specific to the trade:
- Tree Removal: dropdowns for tree height (under 20ft/20-40ft/40-60ft/over 60ft), tree diameter (under 6in/6-12in/12-24in/over 24in), condition (healthy/stressed/dead/hazardous), proximity (open area/near fence/near structure/near powerlines). Output price range.
- HVAC: dropdowns for service type (repair/replacement/new install/maintenance), system type (central AC/heat pump/mini split/furnace), home size (under 1000sqft/1000-2000sqft/2000-3500sqft/over 3500sqft). Output price range.
- Roofing: dropdowns for service type (repair/full replacement/inspection/new install), roof size (under 1000sqft/1000-2000sqft/2000-3500sqft/over 3500sqft), material (asphalt shingle/metal/tile/flat). Output price range.
- Plumbing: dropdowns for service type (drain cleaning/leak repair/pipe replacement/water heater/fixture install), urgency (standard/urgent/emergency). Output price range.
- Electrical: dropdowns for service type (panel upgrade/outlet install/wiring/lighting/EV charger), home age (new/1-20yrs/20-40yrs/40+yrs). Output price range.
- For all other trades create 3 relevant dropdowns with realistic options and price ranges.
Calculator output: Show a price range like '$450 - $800' in large accent color text. Below the result show a lead capture form: Name, Phone, Email fields and 'GET MY FULL ESTIMATE' button in accent color. Form submits to # for now.

6. WHY CHOOSE US: Background secondary color. 4 benefit cards in a row. Each card has inline SVG icon, bold title, short description. Benefits specific to the trade and city.

7. SERVICE AREAS: Background primary color. Title 'AREAS WE SERVE'. List 8 nearby cities as styled pills/badges in accent color border. Base the cities on the location provided.

8. CONTACT SECTION: Background card bg color. Two columns: left side has business name, phone as large clickable number, email placeholder, hours. Right side has a simple contact form with Name, Phone, Message fields and a submit button.

9. FOOTER: Background #0a0a0a. Business name, tagline, quick links, services list, contact info. Copyright line at bottom.

FONTS: Import from Google Fonts - Bebas Neue for all headlines and section titles, Barlow for body text.

SEO: Proper title tag, meta description with city and trade keywords, H1 with city and trade, LocalBusiness schema markup with all their details.

Output ONLY the complete HTML. Nothing else. No explanation.
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
