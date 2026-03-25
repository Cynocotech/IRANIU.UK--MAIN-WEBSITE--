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
    $dbp = (string)($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '');
    $user = (string)($_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: '');
    return 'iraniu-article-ref|v1|' . $user . '|' . $dbp;
}

function article_ref_encode(int $id): string
{
    if ($id < 1) {
        return '';
    }
    $secret = article_url_secret();
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
