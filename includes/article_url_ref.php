<?php
declare(strict_types=1);

/**
 * Opaque article URLs: /blog/article/{token} — no numeric id in the address bar.
 * Tokens are HMAC-signed; tampering is rejected. DB access stays bound parameters only.
 */

function article_url_secret(): string
{
    $s = trim((string)($_ENV['ARTICLE_URL_SECRET'] ?? getenv('ARTICLE_URL_SECRET') ?: ''));
    if ($s !== '') {
        return $s;
    }

    // Fallback to a server-local secret file (NOT derived from DB credentials).
    // This prevents DB credential leaks from also becoming URL-signing secret leaks.
    $secretFile = dirname(__DIR__) . '/data/article_url_secret.txt';
    $existing = @file_get_contents($secretFile);
    if (is_string($existing)) {
        $existing = trim($existing);
        if ($existing !== '') {
            return $existing;
        }
    }

    try {
        $generated = bin2hex(random_bytes(32)); // 64 hex chars
    } catch (Throwable $e) {
        $generated = '';
    }

    if ($generated !== '') {
        @file_put_contents($secretFile, $generated, LOCK_EX);
        // Best-effort permission hardening; may be ignored on some hosts/filesystems.
        @chmod($secretFile, 0600);
        return $generated;
    }

    // Last-resort: disable opaque URL signing if we cannot obtain a secret.
    return '';
}

function article_ref_encode(int $id): string
{
    if ($id < 1) {
        return '';
    }
    $secret = article_url_secret();
    if ($secret === '') {
        return '';
    }
    $payload = (string) $id;
    $mac = substr(hash_hmac('sha256', $payload, $secret, true), 0, 12);
    $bin = $payload . "\0" . $mac;
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function article_ref_decode(string $token): ?int
{
    $token = trim($token);
    if ($token === '' || strlen($token) > 200) {
        return null;
    }
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
        return null;
    }
    $b64 = strtr($token, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad > 0) {
        $b64 .= str_repeat('=', 4 - $pad);
    }
    $bin = base64_decode($b64, true);
    if ($bin === false || strpos($bin, "\0") === false) {
        return null;
    }
    $parts = explode("\0", $bin, 2);
    if (count($parts) !== 2) {
        return null;
    }
    [$idStr, $mac] = $parts;
    if (!preg_match('/^[1-9][0-9]{0,18}$/', $idStr)) {
        return null;
    }
    $id = (int) $idStr;
    $secret = article_url_secret();
    if ($secret === '') {
        return null;
    }
    $expected = substr(hash_hmac('sha256', (string) $id, $secret, true), 0, 12);
    if (strlen($mac) !== 12 || !hash_equals($expected, $mac)) {
        return null;
    }
    return $id;
}

/** Path only, e.g. /blog/article/AbC_x9... — safe for href */
function news_article_public_path(int $id): string
{
    $t = article_ref_encode($id);
    return $t === '' ? '#' : ('/blog/article/' . $t);
}
