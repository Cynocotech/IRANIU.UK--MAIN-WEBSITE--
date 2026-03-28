<?php
/**
 * Advertising package request — notifies admin and sends auto-reply to the user.
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

function jsonFail(string $err): void {
    echo json_encode(['success' => false, 'error' => $err]);
    exit;
}

try {
    require __DIR__ . '/load_env.php';
    require __DIR__ . '/vendor/autoload.php';
    require __DIR__ . '/includes/security_util.php';
} catch (Throwable $e) {
    jsonFail('setup');
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$wantsJson = !empty($_SERVER['HTTP_ACCEPT']) && strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;

function escapeHtml(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/** @return list<string> */
function parse_admin_emails(string $raw, string $fallbackZoho): array {
    $raw = trim($raw);
    if ($raw === '') {
        return $fallbackZoho !== '' && filter_var($fallbackZoho, FILTER_VALIDATE_EMAIL) ? [$fallbackZoho] : [];
    }
    $parts = preg_split('/[,;]+/', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim((string) $p);
        if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) {
            $out[] = $p;
        }
    }
    if ($out !== []) {
        return $out;
    }
    return $fallbackZoho !== '' && filter_var($fallbackZoho, FILTER_VALIDATE_EMAIL) ? [$fallbackZoho] : [];
}

function getClientIp(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        $v = $_SERVER[$k] ?? '';
        if ($v !== '') {
            $ip = trim(explode(',', $v)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/** @return array{label: string, label_en: string}|null */
function package_info(?string $key): ?array {
    $map = [
        'bronze' => ['label' => 'پکیج برنز (ترکیبی — پایه)', 'label_en' => 'Bronze package (combined — entry)'],
        'silver' => ['label' => 'پکیج نقره‌ای (ترکیبی — استاندارد)', 'label_en' => 'Silver package (combined — standard)'],
        'gold' => ['label' => 'پکیج طلا (ترکیبی — ویژه)', 'label_en' => 'Gold package (combined — premium)'],
    ];
    if ($key === null || $key === '') {
        return null;
    }
    return $map[$key] ?? null;
}

function adminEmailHtml(
    string $pkgLabel,
    string $pkgLabelEn,
    string $name,
    string $email,
    string $phone,
    string $message,
    string $ip
): string {
    $name = escapeHtml($name);
    $email = escapeHtml($email);
    $phoneEsc = escapeHtml($phone);
    $phoneHtml = $phoneEsc !== '' ? '<span dir="ltr" style="unicode-bidi:embed;">' . $phoneEsc . '</span>' : '—';
    $msg = $message !== '' ? nl2br(escapeHtml($message)) : '—';
    $ip = escapeHtml($ip);
    $pkgLabel = escapeHtml($pkgLabel);
    $pkgLabelEn = escapeHtml($pkgLabelEn);
    return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head><meta charset="UTF-8">
<style>
body{font-family:Tahoma,Arial,sans-serif;background:#fdfdfd;margin:0;padding:20px;direction:rtl;text-align:right;}
.wrap{max-width:600px;margin:0 auto;}
.header{background:linear-gradient(135deg,#3a0b47,#74208b);color:#fff;padding:28px;text-align:center;border-radius:12px 12px 0 0;}
.content{background:#fff;padding:28px;border:1px solid #eee;border-top:none;border-radius:0 0 12px 12px;}
.row{margin-bottom:16px;}
.label{font-weight:bold;color:#3a0b47;margin-bottom:6px;}
.value{background:#f8f5fa;padding:12px;border-radius:8px;border:1px solid #e8e0ee;}
.pkg-highlight{background:#ede7f6;border:2px solid #74208b;padding:14px;border-radius:10px;margin-bottom:18px;font-size:1.05rem;font-weight:bold;color:#3a0b47;}
.en-note{font-size:0.85rem;color:#555;margin-top:6px;direction:ltr;text-align:left;}
</style></head>
<body>
<div class="wrap">
<div class="header"><h1 style="margin:0;font-size:1.35rem;">درخواست پکیج تبلیغات — IraniU</h1></div>
<div class="content">
<div class="pkg-highlight">پکیج: {$pkgLabel}<div class="en-note">{$pkgLabelEn}</div></div>
<div class="row"><div class="label">نام</div><div class="value">{$name}</div></div>
<div class="row"><div class="label">ایمیل</div><div class="value">{$email}</div></div>
<div class="row"><div class="label">شماره تماس</div><div class="value">{$phoneHtml}</div></div>
<div class="row"><div class="label">IP</div><div class="value">{$ip}</div></div>
<div class="row"><div class="label">پیام / توضیحات</div><div class="value">{$msg}</div></div>
</div></div>
</body></html>
HTML;
}

function thankYouEmailHtml(string $name, string $pkgLabel): string {
    $name = escapeHtml($name);
    $pkgLabel = escapeHtml($pkgLabel);
    return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head><meta charset="UTF-8">
<style>
body{font-family:Tahoma,Arial,sans-serif;background:#fdfdfd;margin:0;padding:20px;direction:rtl;text-align:right;}
.wrap{max-width:600px;margin:0 auto;}
.header{background:#3a0b47;color:#fff;padding:28px;text-align:center;border-radius:12px 12px 0 0;}
.header img{height:50px;margin:0 auto 12px;display:block;}
.content{background:#fff;padding:28px;border:1px solid #eee;border-top:none;border-radius:0 0 12px 12px;line-height:1.85;}
.en{margin-top:16px;padding-top:16px;border-top:1px solid #eee;direction:ltr;text-align:left;color:#333;}
</style></head>
<body>
<div class="wrap">
<div class="header">
<img src="https://panel.cybercina.co.uk/storage/logos/N0yQlVchcj4ucrQfVJwbXXB13FhWTMFccUBmWLpI.png" alt="IraniU">
<h1 style="margin:0;font-size:1.3rem;">درخواست شما ثبت شد</h1>
</div>
<div class="content">
<p>{$name} عزیز،</p>
<p>درخواست شما برای <strong>{$pkgLabel}</strong> دریافت شد. همکاران ما به زودی با شما تماس خواهند گرفت.</p>
<div class="en">
<p><strong>We have received your advertising package request.</strong> We will contact you soon.</p>
</div>
<p style="margin-top:20px;">با احترام،<br>تیم IraniU</p>
</div>
</div>
</body></html>
HTML;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonFail('method');
}

$pkgKey = trim((string) ($_POST['package'] ?? ''));
$info = package_info($pkgKey);
if ($info === null) {
    jsonFail('invalid_package');
}

$MAX_LEN = ['name' => 200, 'email' => 254, 'phone_prefix' => 10, 'phone' => 30, 'message' => 5000];
$name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, $MAX_LEN['name']);
$email = mb_substr(trim((string) ($_POST['email'] ?? '')), 0, $MAX_LEN['email']);
$phonePrefix = mb_substr(trim((string) ($_POST['phone_prefix'] ?? '')), 0, $MAX_LEN['phone_prefix']);
$phoneNum = mb_substr(trim((string) ($_POST['phone'] ?? '')), 0, $MAX_LEN['phone']);
$phone = $phonePrefix && $phoneNum ? $phonePrefix . ' ' . $phoneNum : ($phonePrefix ?: $phoneNum);
$message = mb_substr(trim((string) ($_POST['message'] ?? '')), 0, $MAX_LEN['message']);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonFail('missing');
}
if ($name === '') {
    jsonFail('missing');
}

$turnstileToken = trim((string) ($_POST['cf-turnstile-response'] ?? ''));
$turnstileSecret = getenv('TURNSTILE_SECRET_KEY') ?: $_ENV['TURNSTILE_SECRET_KEY'] ?? '';
if ($turnstileSecret !== '') {
    if ($turnstileToken === '' || strlen($turnstileToken) > 2048) {
        jsonFail('captcha');
    }
    $remoteip = getClientIp();
    $postData = ['secret' => $turnstileSecret, 'response' => $turnstileToken];
    if ($remoteip !== 'unknown') {
        $postData['remoteip'] = $remoteip;
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($postData),
            'timeout' => 10,
        ],
        'ssl' => ['verify_peer' => true],
    ]);
    $verify = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx);
    $verifyJson = $verify ? json_decode($verify, true) : [];
    if (empty($verifyJson['success'])) {
        jsonFail('captcha');
    }
}

$zohoEmail = getenv('ZOHO_EMAIL') ?: $_ENV['ZOHO_EMAIL'] ?? '';
$zohoPass = getenv('ZOHO_PASSWORD') ?: $_ENV['ZOHO_PASSWORD'] ?? '';
$adminEmailRaw = getenv('ADMIN_EMAIL') ?: $_ENV['ADMIN_EMAIL'] ?? '';
$adminRecipients = parse_admin_emails($adminEmailRaw, $zohoEmail);

if ($zohoEmail === '' || $zohoPass === '') {
    jsonFail('config');
}
if ($adminRecipients === []) {
    jsonFail('config');
}

$mail = new PHPMailer(true);
$sent = false;
$hosts = ['smtp.zoho.eu', 'smtp.zoho.com'];
$ports = [
    ['port' => 465, 'secure' => PHPMailer::ENCRYPTION_SMTPS],
    ['port' => 587, 'secure' => PHPMailer::ENCRYPTION_STARTTLS],
];

$pkgLabel = $info['label'];
$pkgLabelEn = $info['label_en'];
$subjectSafe = security_strip_mailer_header_value($name);

foreach ($hosts as $host) {
    foreach ($ports as $p) {
        try {
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->SMTPAuth = true;
            $mail->Username = $zohoEmail;
            $mail->Password = $zohoPass;
            $mail->SMTPSecure = $p['secure'];
            $mail->Port = $p['port'];
            $mail->CharSet = 'UTF-8';

            $mail->setFrom($zohoEmail, 'IraniU Ads');
            foreach ($adminRecipients as $addr) {
                $mail->addAddress($addr);
            }
            $mail->addReplyTo($email, security_strip_mailer_header_value($name));
            $mail->isHTML(true);
            $mail->Subject = 'درخواست پکیج تبلیغات: ' . $pkgLabel . ' — ' . mb_substr($subjectSafe, 0, 80);
            $mail->Body = adminEmailHtml($pkgLabel, $pkgLabelEn, $name, $email, $phone, $message, getClientIp());

            $mail->send();
            $sent = true;
            break 2;
        } catch (Exception $e) {
            error_log('IraniU package-request (' . $host . ':' . $p['port'] . '): ' . $e->getMessage());
            $mail->clearAddresses();
            $mail->clearReplyTos();
        }
    }
}

if ($sent) {
    try {
        $mail->clearAddresses();
        $mail->clearReplyTos();
        $mail->addAddress($email);
        $mail->Subject = 'درخواست پکیج تبلیغات ثبت شد - IraniU';
        $mail->Body = thankYouEmailHtml($name, $pkgLabel);
        $mail->send();
    } catch (Exception $e) {
        error_log('IraniU package-request thank-you: ' . $e->getMessage());
    }
    echo json_encode(['success' => true]);
    exit;
}

jsonFail('send');
