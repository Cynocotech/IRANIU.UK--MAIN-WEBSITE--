<?php
/**
 * Load SMTP credentials: .env first, then config.php fallback (for cPanel when .env is blocked)
 */
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $val) = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val, " \t\n\r\0\x0B\"'");
        putenv("$key=$val");
        $_ENV[$key] = $val;
    }
}
$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    $cfg = include $configFile;
    if (is_array($cfg)) {
        foreach ([
            'ZOHO_EMAIL', 'ZOHO_PASSWORD', 'ADMIN_EMAIL', 'CAREERS_EMAIL', 'TURNSTILE_SECRET_KEY',
            'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
            'DB_NAME', 'DB_USER',
            'NEWS_IMAGE_BASE_URL', 'NEWS_CATEGORY_TABLE',
            'APP_STORE_URL', 'GOOGLE_PLAY_URL',
        ] as $k) {
            if (!empty($cfg[$k]) && empty($_ENV[$k] ?? '')) {
                $_ENV[$k] = $cfg[$k];
                putenv("$k=" . $cfg[$k]);
            }
        }
    }
}

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/includes/waf_request_gate.php';
    waf_request_gate_run();
}
