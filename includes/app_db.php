<?php
declare(strict_types=1);

/**
 * PDO برای اپلیکیشن (مثلاً cybercinaco_app) — مقادیر از .env / config.php
 * DB_HOST، DB_NAME، DB_USER، DB_PASSWORD
 */
function app_pdo(): PDO
{
    $host = trim((string)($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost'));
    $name = trim((string)($_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: ''));
    $user = trim((string)($_ENV['DB_USER'] ?? getenv('DB_USER') ?: ''));
    $pass = (string)($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '');

    if ($name === '' || $user === '') {
        throw new RuntimeException('پایگاه داده پیکربندی نشده (DB_NAME و DB_USER را در .env بگذارید).');
    }

    $dsn = 'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4';

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
