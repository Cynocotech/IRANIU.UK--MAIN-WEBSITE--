<?php
declare(strict_types=1);

/**
 * PDO برای اپلیکیشن — از .env / config.php
 * نام‌های لاراولی: DB_HOST، DB_PORT، DB_DATABASE، DB_USERNAME، DB_PASSWORD
 * سازگار با قدیمی: DB_NAME، DB_USER
 */
function app_pdo(): PDO
{
    $host = trim((string)($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost'));
    $port = (int) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);
    if ($port < 1 || $port > 65535) {
        $port = 3306;
    }

    $name = trim((string)($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: ''));
    if ($name === '') {
        $name = trim((string)($_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: ''));
    }

    $user = trim((string)($_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: ''));
    if ($user === '') {
        $user = trim((string)($_ENV['DB_USER'] ?? getenv('DB_USER') ?: ''));
    }

    $pass = (string)($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '');

    if ($name === '' || $user === '') {
        throw new RuntimeException('پایگاه داده پیکربندی نشده (DB_DATABASE و DB_USERNAME یا DB_NAME و DB_USER را در .env بگذارید).');
    }

    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
