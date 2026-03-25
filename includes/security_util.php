<?php
declare(strict_types=1);

/**
 * Shared hardening helpers (mail headers, filenames).
 */

/** Strip bytes that can break SMTP headers or enable SMTP header injection. */
function security_strip_mailer_header_value(string $s): string
{
    return str_replace(["\r", "\n", "\0"], '', $s);
}

/**
 * Safe attachment basename for email (ASCII-only, no path segments).
 */
function security_safe_attachment_display_name(string $fallbackBasename = 'cv.pdf'): string
{
    $base = basename($fallbackBasename);
    $base = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $base) ?? $fallbackBasename;
    if ($base === '' || strlen($base) > 120) {
        return 'cv.pdf';
    }
    $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        return preg_replace('/\.[^.]+$/', '', $base) . '.pdf';
    }
    return $base;
}
