<?php
declare(strict_types=1);

/**
 * PDO connection for iraniu_memory (credentials from .env / config.php via load_env.php).
 */
function memory_pdo(): PDO
{
    $host = trim((string)($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost'));
    $name = trim((string)($_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: ''));
    $user = trim((string)($_ENV['DB_USER'] ?? getenv('DB_USER') ?: ''));
    $pass = (string)($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '');

    if ($name === '' || $user === '') {
        throw new RuntimeException('Database not configured (set DB_NAME and DB_USER in .env or config.php).');
    }

    $dsn = 'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4';

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
