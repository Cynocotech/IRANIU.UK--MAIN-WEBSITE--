<?php
declare(strict_types=1);

require __DIR__ . '/load_env.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$fallbackUrl = 'https://iraniu.uk/Biolink/';
$appStoreUrl = trim((string)($_ENV['APP_STORE_URL'] ?? getenv('APP_STORE_URL') ?: 'https://apps.apple.com/us/app/iraniu/id6760209069'));
$playStoreUrl = trim((string)($_ENV['GOOGLE_PLAY_URL'] ?? getenv('GOOGLE_PLAY_URL') ?: ''));

$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$uaLower = function_exists('mb_strtolower') ? mb_strtolower($ua, 'UTF-8') : strtolower($ua);

$isAndroid = strpos($uaLower, 'android') !== false;
$isIos = strpos($uaLower, 'iphone') !== false
    || strpos($uaLower, 'ipad') !== false
    || strpos($uaLower, 'ipod') !== false
    || (strpos($uaLower, 'macintosh') !== false && strpos($uaLower, 'mobile') !== false);

$target = $fallbackUrl;
if ($isIos && $appStoreUrl !== '') {
    $target = $appStoreUrl;
} elseif ($isAndroid && $playStoreUrl !== '') {
    $target = $playStoreUrl;
}

if (!preg_match('#^https?://#i', $target)) {
    $target = $fallbackUrl;
}

header('Location: ' . $target, true, 302);
exit;

