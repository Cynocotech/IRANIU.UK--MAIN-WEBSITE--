<?php
/**
 * Careers application handler - CV upload (PDF only), Turnstile, malware scan
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

function jsonFail($err) {
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
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

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

/** PDF validation: magic bytes, extension, MIME, size, and malicious content check */
function validatePdf($tmpPath, $origName, $maxSize = 5242880) {
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') return ['ok' => false, 'msg' => 'only_pdf'];
    if (!is_uploaded_file($tmpPath)) return ['ok' => false, 'msg' => 'invalid_upload'];
    $size = filesize($tmpPath);
    if ($size > $maxSize || $size <= 0) return ['ok' => false, 'msg' => 'file_too_large'];
    $head = file_get_contents($tmpPath, false, null, 0, 8);
    if (strpos($head, '%PDF') !== 0) return ['ok' => false, 'msg' => 'invalid_pdf'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);
    if ($mime !== 'application/pdf') return ['ok' => false, 'msg' => 'invalid_pdf'];
    $content = file_get_contents($tmpPath);
    $dangerous = ['/JavaScript', '/JS ', '/JS(', '/Launch', 'javascript:', 'vbscript:', '/OpenAction', '/AA ', '/AA('];
    foreach ($dangerous as $p) {
        if (stripos($content, $p) !== false) return ['ok' => false, 'msg' => 'suspicious_pdf'];
    }
    if (function_exists('exec') && strpos(ini_get('disable_functions'), 'exec') === false) {
        foreach (['clamscan', '/usr/bin/clamscan', '/usr/local/bin/clamscan'] as $cmd) {
            if ($cmd === 'clamscan' || (file_exists($cmd) && is_executable($cmd))) {
                $out = [];
                $ret = -1;
                @exec($cmd . ' --no-summary ' . escapeshellarg($tmpPath) . ' 2>/dev/null', $out, $ret);
                if ($ret === 1) return ['ok' => false, 'msg' => 'virus_detected'];
                break;
            }
        }
    }
    return ['ok' => true];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonFail('method');
}

$MAX_LEN = ['name' => 200, 'email' => 254, 'phone' => 30, 'message' => 5000, 'job_id' => 50, 'job_title' => 200];
$name = mb_substr(trim($_POST['name'] ?? ''), 0, $MAX_LEN['name']);
$email = mb_substr(trim($_POST['email'] ?? ''), 0, $MAX_LEN['email']);
$phone = mb_substr(trim($_POST['phone'] ?? ''), 0, $MAX_LEN['phone']);
$message = mb_substr(trim($_POST['message'] ?? ''), 0, $MAX_LEN['message']);
$jobId = mb_substr(trim($_POST['job_id'] ?? ''), 0, $MAX_LEN['job_id']);
$jobTitle = mb_substr(trim($_POST['job_title'] ?? ''), 0, $MAX_LEN['job_title']);

if (empty($name) || empty($email) || empty($jobTitle)) {
    jsonFail('missing');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonFail('missing');
}

$turnstileToken = trim($_POST['cf-turnstile-response'] ?? '');
$turnstileSecret = getenv('TURNSTILE_SECRET_KEY') ?: $_ENV['TURNSTILE_SECRET_KEY'] ?? '';
if (!empty($turnstileSecret)) {
    if (empty($turnstileToken) || strlen($turnstileToken) > 2048) {
        jsonFail('captcha');
    }
    $remoteip = getClientIp();
    $postData = ['secret' => $turnstileSecret, 'response' => $turnstileToken];
    if ($remoteip !== 'unknown') $postData['remoteip'] = $remoteip;
    $ctx = stream_context_create([
        'http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => http_build_query($postData), 'timeout' => 10],
        'ssl' => ['verify_peer' => true],
    ]);
    $verify = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx);
    $verifyJson = $verify ? json_decode($verify, true) : [];
    if (empty($verifyJson['success'])) {
        error_log('IraniU Careers Turnstile failed: ' . implode(', ', $verifyJson['error-codes'] ?? []));
        jsonFail('captcha');
    }
}

if (empty($_FILES['cv']) || $_FILES['cv']['error'] !== UPLOAD_ERR_OK) {
    jsonFail('cv_required');
}

$cv = validatePdf($_FILES['cv']['tmp_name'], $_FILES['cv']['name'] ?? '');
if (!$cv['ok']) {
    $errMap = ['only_pdf' => 'file_type', 'file_too_large' => 'file_too_large', 'invalid_pdf' => 'file_type', 'suspicious_pdf' => 'file_type', 'virus_detected' => 'file_type'];
    jsonFail($errMap[$cv['msg']] ?? 'file_type');
}

$zohoEmail = getenv('ZOHO_EMAIL') ?: $_ENV['ZOHO_EMAIL'] ?? '';
$zohoPass = getenv('ZOHO_PASSWORD') ?: $_ENV['ZOHO_PASSWORD'] ?? '';
$adminEmail = getenv('ADMIN_EMAIL') ?: $_ENV['ADMIN_EMAIL'] ?? $zohoEmail;
$careersEmail = getenv('CAREERS_EMAIL') ?: $_ENV['CAREERS_EMAIL'] ?? $adminEmail;

if (empty($zohoEmail) || empty($zohoPass)) {
    jsonFail('config');
}

$uploadDir = __DIR__ . '/uploads/careers';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0750, true);
}
if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
    jsonFail('config');
}

// Store with random name + forced .pdf so the on-disk extension never comes from user input.
$saveName = date('Ymd_His') . '_' . bin2hex(random_bytes(16)) . '.pdf';
$savePath = $uploadDir . '/' . $saveName;
$attachmentDisplayName = security_safe_attachment_display_name($_FILES['cv']['name'] ?? 'cv.pdf');

if (!move_uploaded_file($_FILES['cv']['tmp_name'], $savePath)) {
    jsonFail('send');
}

$ip = getClientIp();
$phoneDisplay = $phone ? '<span dir="ltr" style="unicode-bidi:embed;">' . escapeHtml($phone) . '</span>' : '—';
$msgHtml = nl2br(escapeHtml($message ?: '—'));
$jobTitleEsc = escapeHtml($jobTitle);
$nameEsc = escapeHtml($name);
$emailEsc = escapeHtml($email);
$ipEsc = escapeHtml($ip);
$cvFileNameEsc = escapeHtml($_FILES['cv']['name'] ?? 'رزومه.pdf');
$phoneEsc = $phone ? escapeHtml($phone) : '—';

$adminBody = <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head><meta charset="UTF-8">
<style>
body{font-family:Tahoma,Arial,sans-serif;background:#fdfdfd;margin:0;padding:20px;direction:rtl;text-align:right;}
.wrap{max-width:600px;margin:0 auto;}
.header{position:relative;overflow:hidden;background:linear-gradient(135deg,#3a0b47,#74208b);color:white;padding:30px;text-align:center;border-radius:12px 12px 0 0;}
.header img{height:50px;margin:0 auto 15px;display:block;}
.header h1{margin:0;font-size:1.5rem;}
.cv-badge{background:rgba(255,255,255,0.2);display:inline-block;padding:8px 16px;border-radius:8px;margin-top:15px;font-size:0.9rem;}
.content{background:white;padding:30px;border:1px solid #eee;border-top:none;border-radius:0 0 12px 12px;}
.row{margin-bottom:18px;text-align:right;direction:rtl;}
.label{font-weight:bold;color:#3a0b47;margin-bottom:5px;}
.value{background:#f8f5fa;padding:12px;border-radius:8px;border:1px solid #e8e0ee;}
.footer{text-align:center;color:#777;font-size:0.85rem;margin-top:25px;padding-top:20px;border-top:1px solid #eee;}
</style></head>
<body>
<div class="wrap">
<div class="header">
<img src="https://panel.cybercina.co.uk/storage/logos/N0yQlVchcj4ucrQfVJwbXXB13FhWTMFccUBmWLpI.png" alt="IraniU">
<h1>رزومه جدید - IraniU Careers</h1>
<p class="cv-badge">📎 فایل رزومه در پیوست ایمیل</p>
</div>
<div class="content">
<div class="row"><div class="label">موقعیت شغلی</div><div class="value">$jobTitleEsc</div></div>
<div class="row"><div class="label">نام و نام خانوادگی</div><div class="value">$nameEsc</div></div>
<div class="row"><div class="label">ایمیل</div><div class="value">$emailEsc</div></div>
<div class="row"><div class="label">شماره تماس</div><div class="value">$phoneDisplay</div></div>
<div class="row"><div class="label">IP (آدرس اینترنت)</div><div class="value"><span dir="ltr">$ipEsc</span></div></div>
<div class="row"><div class="label">پیام / معرفی</div><div class="value">$msgHtml</div></div>
</div>
<div class="footer">IraniU Careers — درخواست همکاری</div>
</div>
</body></html>
HTML;

$nameEscThank = escapeHtml($name);
$thankYouBody = <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head><meta charset="UTF-8">
<style>
@font-face{font-family:'Yekan';src:url('https://cdn.jsdelivr.net/npm/typeface-yekan@1.0.11/dist/Yekan.woff') format('woff');}
body{font-family:'Yekan','Yekan Bakh',Tahoma,Arial,sans-serif;background:#f5f5f5;margin:0;padding:24px;direction:rtl;text-align:right;}
.wrap{max-width:560px;margin:0 auto;background:#fff;border:1px solid #e5e5e5;border-radius:16px;overflow:hidden;line-height:1.8;box-shadow:0 4px 20px rgba(0,0,0,0.06);}
@keyframes gradientFlow{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}
@keyframes waveMove{0%{transform:translate3d(-90px,0,0)}100%{transform:translate3d(85px,0,0)}}
.header{position:relative;background:#3a0b47;color:#fff;text-align:center;padding:24px;overflow:hidden;}
.header-waves{position:absolute;bottom:0;left:0;width:100%;height:50px;pointer-events:none;z-index:1;}
.header-waves .wave-svg{width:100%;height:100%;}
.header-waves .wave-use{animation:waveMove 8s cubic-bezier(0.55,0.5,0.45,0.5) infinite;}
.header-waves .wave-use{fill:rgba(255,255,255,0.08);}
.header-waves .wave-use:nth-child(1){animation-delay:-1s;fill:rgba(255,255,255,0.12);}
.header-waves .wave-use:nth-child(2){animation-delay:-2.5s;fill:rgba(255,255,255,0.1);}
.header-waves .wave-use:nth-child(3){animation-delay:-4s;fill:rgba(255,255,255,0.06);}
.header img{height:48px;margin:0 auto 16px;display:block;position:relative;z-index:2;}
.header h1{font-size:1.3rem;color:white;margin:0;font-weight:bold;position:relative;z-index:2;background:rgba(255,255,255,0.12);padding:8px 16px;border-radius:8px;display:inline-block;}
.content{padding:24px;color:#333;}
.content p{margin:0 0 16px;font-size:0.95rem;}
.content p:last-child{margin-bottom:0;}
.details{background:#f8f5fa;border:1px solid #e8e0ee;border-radius:12px;padding:20px;margin:20px 0;}
.details-title{font-weight:bold;color:#3a0b47;margin-bottom:12px;font-size:0.95rem;}
.detail-row{margin-bottom:10px;text-align:right;direction:rtl;}
.detail-row:last-child{margin-bottom:0;}
.detail-label{font-weight:bold;color:#3a0b47;font-size:0.85rem;background:rgba(58,11,71,0.08);padding:6px 12px;border-radius:6px;display:inline-block;}
.detail-value{color:#333;margin-top:2px;}
.highlight{color:#3a0b47;font-weight:bold;}
.footer{position:relative;background:linear-gradient(135deg,#3a0b47,#74208b);color:white;text-align:center;padding:16px 24px;border-top:none;font-size:0.85rem;overflow:hidden;}
.footer-waves{position:absolute;bottom:0;left:0;width:100%;height:36px;pointer-events:none;}
.footer-waves .wave-svg{width:100%;height:100%;}
.footer-waves .wave-use{animation:waveMove 8s cubic-bezier(0.55,0.5,0.45,0.5) infinite;}
.footer-waves .wave-use{fill:rgba(255,255,255,0.06);}
.footer-waves .wave-use:nth-child(1){animation-delay:-1s;fill:rgba(255,255,255,0.12);}
.footer-waves .wave-use:nth-child(2){animation-delay:-2.5s;fill:rgba(255,255,255,0.08);}
.footer-waves .wave-use:nth-child(3){animation-delay:-3.5s;fill:rgba(255,255,255,0.05);}
.slogan{margin-top:10px;font-style:italic;color:rgba(255,255,255,0.9);font-size:0.85rem;}
</style></head>
<body dir="rtl" style="direction:rtl;">
<div class="wrap" dir="rtl">
<div class="header">
<img src="https://panel.cybercina.co.uk/storage/logos/N0yQlVchcj4ucrQfVJwbXXB13FhWTMFccUBmWLpI.png" alt="IraniU">
<h1>رزومه شما دریافت شد</h1>
<div class="header-waves"><svg class="wave-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 24 150 28" preserveAspectRatio="none"><defs><path id="wave-h" d="M-160 44c30 0 58-18 88-18s 58 18 88 18 58-18 88-18 58 18 88 18 v44h-352z" fill="none"/></defs><use class="wave-use" href="#wave-h" x="48" y="0"/><use class="wave-use" href="#wave-h" x="48" y="3"/><use class="wave-use" href="#wave-h" x="48" y="5"/></svg></div>
</div>
<div class="content">
<p>NAME_PLACEHOLDER عزیز،</p>
<p>از ارسال رزومه شما به تیم IraniU متشکریم. ما رزومه شما را دریافت کرده و در صورت مناسب بودن، برای مراحل بعدی استخدام با شما تماس خواهیم گرفت.</p>
<div class="details">
<p style="font-weight:bold;color:#3a0b47;margin-bottom:16px;font-size:0.95rem;background:rgba(58,11,71,0.1);padding:8px 14px;border-radius:8px;display:inline-block;">جزئیات ارسالی شما:</p>
<div class="detail-row"><div class="detail-label">موقعیت شغلی</div><div class="detail-value">JOB_TITLE_PLACEHOLDER</div></div>
<div class="detail-row"><div class="detail-label">نام و نام خانوادگی</div><div class="detail-value">NAME_PLACEHOLDER</div></div>
<div class="detail-row"><div class="detail-label">ایمیل</div><div class="detail-value" dir="ltr">EMAIL_PLACEHOLDER</div></div>
<div class="detail-row"><div class="detail-label">شماره تماس</div><div class="detail-value" dir="ltr">PHONE_PLACEHOLDER</div></div>
<div class="detail-row"><div class="detail-label">فایل رزومه</div><div class="detail-value">CV_FILE_PLACEHOLDER</div></div>
<div class="detail-row"><div class="detail-label">پیام / معرفی</div><div class="detail-value">MESSAGE_PLACEHOLDER</div></div>
</div>
<p>با احترام،<br>تیم منابع انسانی IraniU</p>
</div>
<div class="footer"><div class="footer-waves"><svg class="wave-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 24 150 28" preserveAspectRatio="none"><defs><path id="wave-f" d="M-160 44c30 0 58-18 88-18s 58 18 88 18 58-18 88-18 58 18 88 18 v44h-352z" fill="none"/></defs><use class="wave-use" href="#wave-f" x="48" y="0"/><use class="wave-use" href="#wave-f" x="48" y="3"/><use class="wave-use" href="#wave-f" x="48" y="5"/></svg></div><div class="footer-content" style="position:relative;z-index:2;">IraniU — پلتفرم دیجیتال ایرانیان بریتانیا<p class="slogan">در کنار هم جامعه ایرانیان بریتانیا را تقویت کنیم</p></div></div>
</div>
</body></html>
HTML;
$thankYouBody = str_replace('NAME_PLACEHOLDER', $nameEscThank, $thankYouBody);
$thankYouBody = str_replace('JOB_TITLE_PLACEHOLDER', $jobTitleEsc, $thankYouBody);
$thankYouBody = str_replace('EMAIL_PLACEHOLDER', $emailEsc, $thankYouBody);
$thankYouBody = str_replace('PHONE_PLACEHOLDER', $phoneEsc, $thankYouBody);
$thankYouBody = str_replace('CV_FILE_PLACEHOLDER', $cvFileNameEsc, $thankYouBody);
$thankYouBody = str_replace('MESSAGE_PLACEHOLDER', $msgHtml, $thankYouBody);

$mail = new PHPMailer(true);
$sent = false;
foreach ([
    ['host' => 'smtp.zoho.eu', 'port' => 465, 'secure' => PHPMailer::ENCRYPTION_SMTPS],
    ['host' => 'smtp.zoho.eu', 'port' => 587, 'secure' => PHPMailer::ENCRYPTION_STARTTLS],
    ['host' => 'smtp.zoho.com', 'port' => 465, 'secure' => PHPMailer::ENCRYPTION_SMTPS],
    ['host' => 'smtp.zoho.com', 'port' => 587, 'secure' => PHPMailer::ENCRYPTION_STARTTLS],
] as $c) {
    try {
        $mail->isSMTP();
        $mail->Host = $c['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $zohoEmail;
        $mail->Password = $zohoPass;
        $mail->SMTPSecure = $c['secure'];
        $mail->Port = $c['port'];
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($zohoEmail, 'IraniU Careers');
        $mail->addAddress($careersEmail);
        $mail->isHTML(true);
        $mail->Subject = 'رزومه: ' . mb_substr(security_strip_mailer_header_value($jobTitle), 0, 80)
            . ' - ' . mb_substr(security_strip_mailer_header_value($name), 0, 80);
        $mail->Body = $adminBody;
        $mail->addAttachment($savePath, $attachmentDisplayName);
        $mail->send();
        $sent = true;
        $mail->clearAttachments();
        $mail->clearAddresses();
        break;
    } catch (Exception $e) {
        error_log('IraniU Careers Admin: ' . $e->getMessage());
        $mail->clearAttachments();
        $mail->clearAddresses();
    }
}

if ($sent) {
    try {
        $mail->clearAllRecipients();
        $mail->addAddress($email);
        $mail->Subject = 'درخواست همکاری شما دریافت شد - IraniU';
        $mail->Body = $thankYouBody;
        $mail->send();
    } catch (Exception $e) {
        error_log('IraniU Careers ThankYou: ' . $e->getMessage());
    }
    echo json_encode(['success' => true]);
} else {
    @unlink($savePath);
    jsonFail('send');
}
