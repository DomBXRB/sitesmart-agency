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
You are a professional web developer. Build a complete, single-file HTML contractor website. Follow every rule below exactly.

BUSINESS DETAILS:
Business Name: {$business}
Trade/Service: {$trade}
City/State: {$location}
Phone: {$phone}

══════════════════════════════════════
ABSOLUTE RULES — NEVER VIOLATE THESE
══════════════════════════════════════
1. Output ONLY the raw HTML file. No markdown, no code fences, no explanation before or after.
2. ZERO external images. No <img> tags pointing to any URL. Use ONLY inline SVG and CSS for all visuals.
3. ZERO Intersection Observer. ZERO scroll-triggered animations. Do not write any JavaScript that adds classes on scroll or watches for elements entering the viewport.
4. ZERO opacity:0 on any element at page load. Every element must be fully visible the instant the page loads. Do not use animation classes that start hidden.
5. ZERO transform:translateY or fade-in effects that require JavaScript to trigger. CSS hover transitions are fine.
6. The only external resources allowed are Google Fonts via a single <link> tag.
7. All CSS and JavaScript must be inline in the single file.

══════════════════════════════════════
FONTS & COLORS
══════════════════════════════════════
Google Fonts import (put in <head>):
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Oswald:wght@400;600;700&family=Barlow:wght@400;500;600&display=swap" rel="stylesheet">

- Headlines: Bebas Neue
- Subheadings: Oswald
- Body: Barlow
- Page background: #1a1a2e
- Card backgrounds: #16213e
- Text: #f1f5f9 primary, #94a3b8 secondary
- Accent color — choose based on trade:
  * Tree Removal / Landscaping / Irrigation: #22c55e
  * HVAC / Heating / Cooling: #f97316
  * Plumbing: #3b82f6
  * Roofing: #ef4444
  * Electrical / Solar: #eab308
  * Concrete / Fencing / Foundation: #9ca3af
  * Painting: #8b5cf6
  * Pest Control / Pool Service: #84cc16
  * Garage Doors / General Contractor: #f97316
  * All others: #f97316

══════════════════════════════════════
SECTION 1 — <head>
══════════════════════════════════════
- <title>{$business} | Professional {$trade} Services in {$location}</title>
- Meta description: 150-160 chars targeting "{$trade} {$location}" and 2-3 related local keywords
- JSON-LD LocalBusiness schema: name, telephone, address (use {$location}), serviceType: {$trade}
- Google Fonts <link>
- viewport meta

══════════════════════════════════════
SECTION 2 — STICKY NAV
══════════════════════════════════════
- position:fixed, top:0, full width, background:#0f0f1a, border-bottom: 1px solid rgba(255,255,255,0.08)
- Left: business name in Bebas Neue 22px, accent color, no underline
- Right: phone as <a href="tel:..."> in Oswald 700, 18px, accent color, no underline — make it large and obvious
- Padding so body content starts below nav (use padding-top on body or a spacer div)

══════════════════════════════════════
SECTION 3 — HERO (full viewport height)
══════════════════════════════════════
IMPORTANT: Build the background entirely with CSS — no images.
- Base: background-color #0f0f1a
- Layer a radial-gradient spotlight in the accent color at 12% opacity behind the text
- Add 2-3 absolutely positioned blobs: div elements with border-radius:50%, accent color at 6-10% opacity, different sizes (300px, 500px, 200px), positioned at corners/edges
- Add a subtle CSS grid overlay: background-image with two repeating linear-gradients at 1px width in rgba(255,255,255,0.03)

Hero text (all centered, vertically centered using flexbox):
- Small uppercase label above h1: "[{$trade} Services]" in Oswald, accent color, letter-spacing:4px
- H1 in Bebas Neue, 90px desktop/48px mobile, white, letter-spacing:3px — text: "[CITY] {$trade} | LICENSED & INSURED" (extract just the city name from {$location})
- Subheadline in Oswald 20px, #94a3b8: one sentence about serving the local area with fast response
- Two buttons: solid accent-color "Get Free Quote" (scrolls to #calculator) + outline white "Call Now: {$phone}" as a tel: link

══════════════════════════════════════
SECTION 4 — TRUST BAR
══════════════════════════════════════
- Full-width band, background: solid accent color (no transparency, no gradient)
- Single row of 4 stats, evenly spaced, all white text
- Each stat: number in Bebas Neue 42px, label in Barlow 13px uppercase letter-spacing:2px
- Stats: "15+" / "Years Experience", "500+" / "Jobs Completed", "Same Day" / "Response Time", "100%" / "Satisfaction Rate"
- No gaps, no empty space, flush edge to edge

══════════════════════════════════════
SECTION 5 — SERVICES (4 cards)
══════════════════════════════════════
- Section heading in Bebas Neue 52px, centered
- 2×2 grid, gap:24px, max-width:900px centered
- Each card: background:#16213e, border: 1px solid accent color at 40% opacity, border-radius:12px, padding:28px
- Card contains:
  * Inline SVG icon (56×56, stroke accent color, trade-relevant — draw actual SVG paths, not emoji)
  * Service name in Oswald 700 20px, white, margin-top:16px
  * 2 sentence description in Barlow 15px, #94a3b8
- CSS hover only: border-color brightens to full accent, translateY(-4px) — no JavaScript
- All 4 cards fully visible immediately, no hidden state

Choose 4 services highly relevant to {$trade}.

══════════════════════════════════════
SECTION 6 — COST CALCULATOR (id="calculator")
══════════════════════════════════════
- Section heading in Bebas Neue 52px, centered
- Large card: background:#16213e, border: 2px solid accent, border-radius:16px, max-width:800px centered, padding:40px

Build a FULLY WORKING JavaScript calculator. Use <select> dropdowns (no sliders). All dropdowns visible immediately.

For TREE REMOVAL — 4 dropdowns:
  * Tree Height: Under 20ft ($200 base) / 20-40ft ($450 base) / 40-60ft ($750 base) / Over 60ft ($1200 base)
  * Trunk Diameter: Under 12in (×1.0) / 12-24in (×1.4) / Over 24in (×1.8)
  * Condition: Healthy (×1.0) / Leaning (×1.2) / Dead/Diseased (×1.1) / Emergency (×1.5)
  * Near Structures: No (×1.0) / Yes (×1.3)
  * Low estimate = base × multipliers × 0.85, High = base × multipliers × 1.15

For HVAC — 4 dropdowns:
  * Home Size: Under 1000sf ($2800 base) / 1000-1500sf ($3800 base) / 1500-2500sf ($5200 base) / Over 2500sf ($7500 base)
  * System Type: Central AC (×1.0) / Heat Pump (×1.2) / Mini-Split (×0.85) / Furnace (×0.9)
  * Job Type: Replace Existing (×1.0) / New Installation (×1.3) / Repair Only ($350-$850 flat, override base)
  * Urgency: Scheduled (×1.0) / Same Day (×1.25)

For ROOFING — 4 dropdowns:
  * Roof Squares: Under 15 ($4500 base) / 15-25 ($7000 base) / 25-35 ($10000 base) / Over 35 ($14000 base)
  * Material: 3-Tab Shingle (×1.0) / Architectural Shingle (×1.3) / Metal (×1.9) / Tile (×2.2)
  * Pitch: Low/Flat (×1.0) / Medium (×1.15) / Steep (×1.35)
  * Layers to Remove: 1 Layer (×1.0) / 2 Layers (×1.2)

For PLUMBING — 4 dropdowns:
  * Job Type: Leak Repair ($250 base) / Drain Cleaning ($175 base) / Water Heater ($900 base) / Pipe Replacement ($1800 base) / Fixture Install ($350 base)
  * Urgency: Scheduled (×1.0) / Same Day (×1.3) / Emergency (×1.65)
  * Home Age: Under 10 years (×1.0) / 10-30 years (×1.15) / Over 30 years (×1.35)
  * Scope: Single Location (×1.0) / Multiple Areas (×1.6)

For ELECTRICAL — 4 dropdowns:
  * Job Type: Outlet/Switch ($150 base) / Panel Upgrade ($1800 base) / Whole Home Rewire ($8000 base) / Lighting Install ($400 base) / EV Charger ($650 base)
  * Urgency: Scheduled (×1.0) / Same Day (×1.3) / Emergency (×1.6)
  * Home Age: Under 10yr (×1.0) / 10-30yr (×1.1) / Over 30yr (×1.25)
  * Scope: Single Room (×1.0) / Multiple Rooms (×1.5) / Whole Home (×2.2)

For LANDSCAPING, IRRIGATION, CONCRETE, FENCING, PAINTING, SOLAR, POOL SERVICE, PEST CONTROL, GARAGE DOORS, FOUNDATION REPAIR, or any other trade — create 4 realistic dropdowns with price logic appropriate to how that trade bills work.

CALCULATOR OUTPUT: On any dropdown change, immediately recalculate and update:
- A large box with background: accent color at 15%, border: 1px solid accent, showing:
  "Estimated Cost" label in Oswald 14px uppercase
  Price range in Bebas Neue 52px accent color: "$X,XXX – $Y,YYY"
- Disclaimer in Barlow 12px #94a3b8: "Final price depends on site conditions. Get your exact quote below."

BELOW THE ESTIMATE — Lead capture form:
- Heading in Oswald: "Get Your Exact Quote — Free"
- 3 inputs side by side (stack on mobile): Name, Phone, Email — styled dark inputs with accent border on focus
- Submit button: solid accent, full width, Oswald bold
- On click: hide the form, show a success message: "✓ We'll call you within the hour with your exact quote!"

══════════════════════════════════════
SECTION 7 — WHY CHOOSE US (4 cards)
══════════════════════════════════════
- Section heading Bebas Neue 52px centered
- 4 cards in a row (2×2 on mobile), same card style as services
- Each card: inline SVG icon (48×48), heading Oswald 700 18px, 1 sentence Barlow 14px #94a3b8
- Suggested trust signals: Licensed & Insured / Local Family-Owned / Free Estimates / Satisfaction Guaranteed
- All cards visible immediately

══════════════════════════════════════
SECTION 8 — SERVICE AREAS
══════════════════════════════════════
- Simple section, dark bg, centered
- Heading Bebas Neue 52px: "Areas We Serve"
- List 7 cities near {$location} as pill badges: border: 1px solid accent, color: accent, background: accent at 8%, border-radius:99px, padding:8px 20px, Oswald 14px
- Pills in a flex-wrap row, centered

══════════════════════════════════════
SECTION 9 — CONTACT (id="contact")
══════════════════════════════════════
- Centered section
- Phone in Bebas Neue 56px, accent color, as <a href="tel:...">
- Simple form: Name, Phone, Email, Message (textarea 4 rows), Submit button
- Dark inputs, accent focus border
- On submit: JS shows success message "We received your message and will call you within 1 hour!"

══════════════════════════════════════
SECTION 10 — FOOTER
══════════════════════════════════════
- Background #0f0f1a, top border: 1px solid rgba(255,255,255,0.08)
- 3-column grid: [Logo + tagline + phone] [Quick Links: Services, Calculator, Areas, Contact] [Contact Info: address/location, phone, tagline]
- Business name in Bebas Neue accent color
- Copyright line at very bottom, centered, Barlow 13px #4a5568

══════════════════════════════════════
OUTPUT RULES
══════════════════════════════════════
- Output starts with <!DOCTYPE html> — nothing before it
- Output ends with </html> — nothing after it
- No placeholder comments like "<!-- add content here -->"
- Fill every section with real, specific content for {$trade} in {$location}
- Make it dense: no section should have large empty whitespace areas
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
