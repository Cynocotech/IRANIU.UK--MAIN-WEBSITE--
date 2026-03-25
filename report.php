<?php
/**
 * Report form handler - sends violation report to admin via Zoho SMTP
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

$REASON_LABELS = [
    'Spam' => 'هرزنامه (Spam)',
    'Scam' => 'کلاهبرداری',
    'Harassment' => 'توهین یا مزاحمت',
    'Inappropriate' => 'محتوای غیراخلاقی',
    'Wrong Category' => 'دسته‌بندی اشتباه',
    'Other' => 'سایر موارد',
];

function getReportEmailHtml($reason, $reasonLabel, $link, $email, $message, $ip) {
    $reasonLabel = escapeHtml($reasonLabel);
    $email = escapeHtml($email);
    $msg = nl2br(escapeHtml($message));
    $ip = escapeHtml($ip ?: '—');
    $linkEsc = escapeHtml($link ?: '—');
    $safeUrl = preg_match('#^https?://#i', $link) ? $link : '';
    $linkHtml = $safeUrl ? '<a href="' . escapeHtml($safeUrl) . '" target="_blank">' . $linkEsc . '</a>' : $linkEsc;
    return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <style>
    body { font-family: Tahoma, Arial, sans-serif; background: #fdfdfd; margin: 0; padding: 20px; direction: rtl; text-align: right; }
    .wrap { max-width: 600px; margin: 0 auto; direction: rtl; }
    .header { background: linear-gradient(135deg, #3a0b47, #74208b); color: white; padding: 30px; text-align: center; border-radius: 12px 12px 0 0; }
    .header img { margin: 0 auto; display: block; }
    .header h1 { margin: 0; font-size: 1.5rem; }
    .content { background: white; padding: 30px; border: 1px solid #eee; border-top: none; border-radius: 0 0 12px 12px; }
    .row { margin-bottom: 18px; text-align: right; direction: rtl; }
    .label { font-weight: bold; color: #3a0b47; margin-bottom: 5px; }
    .value { background: #f8f5fa; padding: 12px; border-radius: 8px; border: 1px solid #e8e0ee; text-align: right; direction: rtl; }
    .value a { color: #74208b; word-break: break-all; }
    .footer { text-align: center; color: #777; font-size: 0.85rem; margin-top: 25px; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="header">
      <img src="https://panel.cybercina.co.uk/storage/logos/N0yQlVchcj4ucrQfVJwbXXB13FhWTMFccUBmWLpI.png" alt="IraniU" style="height:50px; margin:0 auto 15px; display:block;">
      <h1>گزارش تخلف جدید از IraniU</h1>
    </div>
    <div class="content">
      <div class="row">
        <div class="label">دلیل گزارش</div>
        <div class="value">$reasonLabel</div>
      </div>
      <div class="row">
        <div class="label">لینک آگهی/پست متخلف</div>
        <div class="value">$linkHtml</div>
      </div>
      <div class="row">
        <div class="label">ایمیل گزارش‌دهنده</div>
        <div class="value">$email</div>
      </div>
      <div class="row">
        <div class="label">IP گزارش‌دهنده</div>
        <div class="value">$ip</div>
      </div>
      <div class="row">
        <div class="label">توضیحات</div>
        <div class="value">$msg</div>
      </div>
    </div>
    <div class="footer">
      <p>IraniU Report Form — © 2026 Iraniu Global Ltd</p>
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
        header('Location: /report');
    }
    exit;
}

$MAX_LEN = ['reason' => 50, 'link' => 2048, 'email' => 254, 'message' => 5000];
$reason = mb_substr(trim($_POST['reason'] ?? ''), 0, $MAX_LEN['reason']);
$link = mb_substr(trim($_POST['link'] ?? ''), 0, $MAX_LEN['link']);
$email = mb_substr(trim($_POST['email'] ?? ''), 0, $MAX_LEN['email']);
$message = mb_substr(trim($_POST['message'] ?? ''), 0, $MAX_LEN['message']);

if (empty($reason) || empty($email) || empty($message)) {
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'missing']);
    } else {
        header('Location: /report?error=missing');
    }
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'missing']);
    } else {
        header('Location: /report?error=missing');
    }
    exit;
}

$reasonLabel = $REASON_LABELS[$reason] ?? $reason;

$turnstileToken = trim($_POST['cf-turnstile-response'] ?? '');
$turnstileSecret = getenv('TURNSTILE_SECRET_KEY') ?: $_ENV['TURNSTILE_SECRET_KEY'] ?? '';
if (!empty($turnstileSecret)) {
    if (empty($turnstileToken) || strlen($turnstileToken) > 2048) {
        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'captcha']);
        } else {
            header('Location: /report?error=captcha');
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
        error_log('IraniU Report Turnstile failed: ' . implode(', ', $errorCodes));
        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'captcha']);
        } else {
            header('Location: /report?error=captcha');
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
        header('Location: /report?error=config');
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
            $mail->setFrom($zohoEmail, 'IraniU Report');
            $mail->addAddress($adminEmail);
            $mail->isHTML(true);
            $safeSubject = str_replace(["\r", "\n"], '', $reasonLabel);
            $mail->Subject = 'گزارش تخلف ایرانیو: ' . mb_substr($safeSubject, 0, 80);
            $mail->Body = getReportEmailHtml($reason, $reasonLabel, $link, $email, $message, getClientIp());
            $mail->send();
            $sent = true;
            break 2;
        } catch (Exception $e) {
            error_log('IraniU Report (' . $host . ':' . $p['port'] . '): ' . $e->getMessage());
            $mail->clearAddresses();
        }
    }
}

if ($sent) {
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    } else {
        header('Location: /thank-you');
    }
} else {
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'send']);
    } else {
        header('Location: /report?error=send');
    }
}
