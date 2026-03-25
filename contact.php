<?php
/**
 * Contact form handler - sends email via Zoho SMTP
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
$wantsJson = true;

function jsonFail($err) {
    echo json_encode(['success' => false, 'error' => $err]);
    exit;
}

try {
    require __DIR__ . '/load_env.php';
    require __DIR__ . '/vendor/autoload.php';
} catch (Throwable $e) {
    jsonFail('setup');
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$wantsJson = !empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

function escapeHtml($text) {
    if (empty($text)) return '';
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function getClientIp() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        $v = $_SERVER[$k] ?? '';
        if (!empty($v)) {
            $ip = trim(explode(',', $v)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function getEmailHtml($name, $email, $phone, $message, $ip = '') {
    $name = escapeHtml($name);
    $email = escapeHtml($email);
    $phoneEsc = escapeHtml($phone);
    $phone = $phoneEsc ? '<span dir="ltr" style="unicode-bidi:embed;">' . $phoneEsc . '</span>' : '—';
    $msg = nl2br(escapeHtml($message));
    $ip = escapeHtml($ip ?: '—');
    return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <style>
    body { font-family: Tahoma, Arial, sans-serif; background: #fdfdfd; margin: 0; padding: 20px; direction: rtl; text-align: right; }
    .wrap { max-width: 600px; margin: 0 auto; direction: rtl; }
    .header { background: linear-gradient(135deg, #3a0b47, #74208b); color: white; padding: 30px; text-align: center; border-radius: 12px 12px 0 0; direction: rtl; }
    .header img { margin: 0 auto; display: block; }
    .header h1 { margin: 0; font-size: 1.5rem; }
    .contact-phone { margin-top: 20px; padding: 12px 0; border-top: 1px solid rgba(255,255,255,0.2); font-size: 0.9rem; }
    .phone-row { margin: 6px 0; direction: ltr; text-align: center; }
    .content { background: white; padding: 30px; border: 1px solid #eee; border-top: none; border-radius: 0 0 12px 12px; direction: rtl; text-align: right; }
    .row { margin-bottom: 18px; text-align: right; direction: rtl; }
    .label { font-weight: bold; color: #3a0b47; margin-bottom: 5px; }
    .value { background: #f8f5fa; padding: 12px; border-radius: 8px; border: 1px solid #e8e0ee; text-align: right; direction: rtl; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="header">
      <img src="https://panel.cybercina.co.uk/storage/logos/N0yQlVchcj4ucrQfVJwbXXB13FhWTMFccUBmWLpI.png" alt="IraniU" style="height:50px; margin:0 auto 15px; display:block;">
      <h1>پیام جدید از فرم تماس ایرانیو</h1>
    </div>
    <div class="content">
      <div class="row">
        <div class="label">نام و نام خانوادگی</div>
        <div class="value">$name</div>
      </div>
      <div class="row">
        <div class="label">آدرس ایمیل</div>
        <div class="value">$email</div>
      </div>
      <div class="row">
        <div class="label">شماره تماس</div>
        <div class="value">$phone</div>
      </div>
      <div class="row">
        <div class="label">IP (آدرس اینترنت)</div>
        <div class="value">$ip</div>
      </div>
      <div class="row">
        <div class="label">پیام</div>
        <div class="value">$msg</div>
      </div>
    </div>
  </div>
</body>
</html>
HTML;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'method']);
    } else {
        header('Location: contact.html');
    }
    exit;
}

$returnTo = trim($_POST['return_to'] ?? 'contact.html');
if (!in_array($returnTo, ['contact.html', 'contactus.html'], true)) $returnTo = 'contact.html';

$MAX_LEN = ['name' => 200, 'email' => 254, 'phone_prefix' => 10, 'phone' => 30, 'message' => 10000];
$name = mb_substr(trim($_POST['name'] ?? ''), 0, $MAX_LEN['name']);
$email = mb_substr(trim($_POST['email'] ?? ''), 0, $MAX_LEN['email']);
$phonePrefix = mb_substr(trim($_POST['phone_prefix'] ?? ''), 0, $MAX_LEN['phone_prefix']);
$phoneNum = mb_substr(trim($_POST['phone'] ?? ''), 0, $MAX_LEN['phone']);
$phone = $phonePrefix && $phoneNum ? $phonePrefix . ' ' . $phoneNum : ($phonePrefix ?: $phoneNum);
$message = mb_substr(trim($_POST['message'] ?? ''), 0, $MAX_LEN['message']);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'missing']);
    } else {
        header('Location: ' . $returnTo . '?error=missing');
    }
    exit;
}

if (empty($name) || empty($email) || empty($message)) {
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'missing']);
    } else {
        header('Location: ' . $returnTo . '?error=missing');
    }
    exit;
}

$turnstileToken = trim($_POST['cf-turnstile-response'] ?? '');
$turnstileSecret = getenv('TURNSTILE_SECRET_KEY') ?: $_ENV['TURNSTILE_SECRET_KEY'] ?? '';
if (!empty($turnstileSecret)) {
    if (empty($turnstileToken) || strlen($turnstileToken) > 2048) {
        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'captcha']);
        } else {
            header('Location: ' . $returnTo . '?error=captcha');
        }
        exit;
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
        $errorCodes = $verifyJson['error-codes'] ?? ['internal-error'];
        error_log('IraniU Turnstile validation failed: ' . implode(', ', $errorCodes));
        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'captcha']);
        } else {
            header('Location: ' . $returnTo . '?error=captcha');
        }
        exit;
    }
}

$zohoEmail = getenv('ZOHO_EMAIL') ?: $_ENV['ZOHO_EMAIL'] ?? '';
$zohoPass = getenv('ZOHO_PASSWORD') ?: $_ENV['ZOHO_PASSWORD'] ?? '';
$adminEmail = getenv('ADMIN_EMAIL') ?: $_ENV['ADMIN_EMAIL'] ?? $zohoEmail;

if (empty($zohoEmail) || empty($zohoPass)) {
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'config']);
    } else {
        header('Location: ' . $returnTo . '?error=config');
    }
    exit;
}

$mail = new PHPMailer(true);
$sent = false;
$hosts = ['smtp.zoho.eu', 'smtp.zoho.com'];
$ports = [
    ['port' => 465, 'secure' => PHPMailer::ENCRYPTION_SMTPS],
    ['port' => 587, 'secure' => PHPMailer::ENCRYPTION_STARTTLS],
];

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

        $mail->setFrom($zohoEmail, 'IraniU Contact');
        $mail->addAddress($adminEmail);
        $mail->isHTML(true);
        $safeSubject = str_replace(["\r", "\n"], '', $name);
        $mail->Subject = 'پیام تماس ایرانیو از ' . mb_substr($safeSubject, 0, 100);
        $mail->Body = getEmailHtml($name, $email, $phone, $message, getClientIp());

        $mail->send();
        $sent = true;
        break 2;
    } catch (Exception $e) {
        error_log('IraniU Contact (' . $host . ':' . $p['port'] . '): ' . $e->getMessage());
        $mail->clearAddresses();
    }
    }
}

if ($sent) {
    $nameEsc = escapeHtml($name);
    $thankYouBody = <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head><meta charset="UTF-8">
<style>
body{font-family:Tahoma,Arial,sans-serif;background:#fdfdfd;margin:0;padding:20px;direction:rtl;text-align:right;}
.wrap{max-width:600px;margin:0 auto;direction:rtl;text-align:right;}
.header{background:#3a0b47;color:#fff;padding:30px;text-align:center;border-radius:12px 12px 0 0;}
.header img{height:50px;margin:0 auto 15px;display:block;}
.header h1{margin:0;font-size:1.4rem;}
.content{background:white;padding:30px;border:1px solid #eee;border-top:none;border-radius:0 0 12px 12px;line-height:1.9;direction:rtl;text-align:right;}
.content p{margin-bottom:15px;}
.footer{text-align:center;color:#777;font-size:0.85rem;margin-top:25px;direction:rtl;}
</style></head>
<body dir="rtl">
<div class="wrap" dir="rtl">
<div class="header">
<img src="https://panel.cybercina.co.uk/storage/logos/N0yQlVchcj4ucrQfVJwbXXB13FhWTMFccUBmWLpI.png" alt="IraniU">
<h1>پیام شما دریافت شد</h1>
</div>
<div class="content">
<p>Hi NAME_PLACEHOLDER،</p>
<p>از ارسال پیام شما به IraniU متشکریم. ما به زودی با شما تماس خواهیم گرفت.</p>
<p><strong>Thank you for your message. We will be in touch soon.</strong></p>
<p>با احترام،<br>تیم IraniU</p>
</div>
<div class="footer">IraniU — پلتفرم دیجیتال ایرانیان بریتانیا</div>
</div>
</body></html>
HTML;
    $thankYouBody = str_replace('NAME_PLACEHOLDER', $nameEsc, $thankYouBody);
    try {
        $mail->clearAddresses();
        $mail->addAddress($email);
        $mail->Subject = 'پیام شما دریافت شد - IraniU';
        $mail->Body = $thankYouBody;
        $mail->send();
    } catch (Exception $e) {
        error_log('IraniU Contact ThankYou: ' . $e->getMessage());
    }
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    } else {
        header('Location: thank-you.html');
    }
} else {
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'send']);
    } else {
        header('Location: ' . $returnTo . '?error=send');
    }
}
