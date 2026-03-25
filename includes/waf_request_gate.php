<?php
declare(strict_types=1);

/**
 * Blocks common injection / probe patterns on query string, URI, GET, POST, and upload filenames.
 * On match: logs client IP, responds with 302 to /security-notice.html (or 403 for JSON-looking API requests).
 */

function waf_client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        $v = $_SERVER[$k] ?? '';
        if ($v !== '') {
            $ip = trim(explode(',', $v)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * @param mixed $data
 */
function waf_flatten_strings($data, array &$out, bool $includeKeys = false): void
{
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            if ($includeKeys && is_string($k) && $k !== '') {
                $out[] = $k;
            }
            waf_flatten_strings($v, $out, $includeKeys);
        }
    } elseif (is_string($data) && $data !== '') {
        $out[] = $data;
    }
}

/**
 * @param mixed $files typically $_FILES
 */
function waf_flatten_upload_names($files, array &$out): void
{
    if (!is_array($files)) {
        return;
    }
    foreach ($files as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        if (isset($entry['name'])) {
            if (is_string($entry['name'])) {
                $out[] = $entry['name'];
            } elseif (is_array($entry['name'])) {
                waf_flatten_strings($entry['name'], $out);
            }
        }
    }
}

function waf_normalize_for_scan(string $s): string
{
    $s = str_replace('+', ' ', $s);
    $one = strtolower(rawurldecode($s));
    $two = strtolower(rawurldecode(rawurldecode($s)));
    return $one . "\n" . $two;
}

/**
 * @return array{0:bool,1:string} [blocked, label]
 */
function waf_detect_malicious(string $blob, string $uriBlob): array
{
    $patterns = [
        ['sql_union', '/\bunion\s+(all\s+)?select\b/i'],
        ['sql_info_schema', '/\binformation_schema\b/i'],
        ['sql_mysql_comment', '/(\/\*|--|\#)\s*(select|union|drop|insert|update|delete)/i'],
        ['sql_semicolon', '/;\s*(select|insert|update|delete|drop|create|alter|truncate|exec|execute|grant|revoke)\b/i'],
        ['sql_order_by', '/\border\s+by\b/i'],
        ['sql_group_by_sqli', '/\bgroup\s+by\b.{0,120}\bhaving\b/is'],
        ['sql_or_tautology', '/\b(or|and)\b\s*(\()?\s*[\'\"]?\d+[\'\"]?\s*=\s*[\'\"]?\d+[\'\"]?\s*\)?/i'],
        ['sql_tautology_quote', '/[\'\"]1[\'\"]\s*=\s*[\'\"]1[\'\"]/i'],
        ['sql_hex_quote', '/0x[0-9a-f]{8,}/i'],
        ['sql_sleep', '/\b(sleep|benchmark|waitfor|pg_sleep)\s*\(/i'],
        ['sql_load_file', '/\bload_file\s*\(/i'],
        ['sql_into_file', '/\binto\s+(outfile|dumpfile)\b/i'],
        ['mssql_proc', '/\bxp_cmdshell\b/i'],
        ['mssql_sp', '/\bsp_executesql\b/i'],
        ['php_shell', '/\b(eval|assert|system|shell_exec|passthru|proc_open|popen)\s*\(/i'],
        ['null_byte', "/\x00/"],
    ];

    foreach ($patterns as [$label, $rx]) {
        if (preg_match($rx, $blob) === 1) {
            return [true, $label];
        }
    }

    $uriPatterns = [
        ['path_traversal', '/(\.\.[\/\\\\]|%2e%2e[\/\\\\]|%252e%252e[\/\\\\])/i'],
    ];
    foreach ($uriPatterns as [$label, $rx]) {
        if (preg_match($rx, $uriBlob) === 1) {
            return [true, $label];
        }
    }

    return [false, ''];
}

/**
 * Browsers often send Accept: * / * (not only application/json) for fetch().
 * SCRIPT_NAME may be wrong under rewrites — use REQUEST_URI and SCRIPT_FILENAME too.
 * So search/API endpoints return 403 JSON when blocked, not a 302 to the notice page.
 */
function waf_should_return_json_block(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (stripos($accept, 'application/json') !== false) {
        return true;
    }

    $apiScripts = ['ajax_search.php', 'contact.php', 'report.php', 'careers-apply.php'];
    $hay = [
        $_SERVER['SCRIPT_NAME'] ?? '',
        $_SERVER['SCRIPT_FILENAME'] ?? '',
        $_SERVER['PHP_SELF'] ?? '',
        $_SERVER['REQUEST_URI'] ?? '',
    ];
    foreach ($hay as $h) {
        if ($h === '') {
            continue;
        }
        $path = $h;
        if (strncasecmp($h, 'http', 4) === 0) {
            $p = parse_url($h, PHP_URL_PATH);
            $path = is_string($p) && $p !== '' ? $p : $h;
        } elseif (strpos($h, '?') !== false) {
            $p = parse_url($h, PHP_URL_PATH);
            $path = is_string($p) && $p !== '' ? $p : $h;
        }
        $base = basename(str_replace('\\', '/', $path));
        if (in_array($base, $apiScripts, true)) {
            return true;
        }
    }
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    foreach ($apiScripts as $name) {
        if ($uri !== '' && stripos($uri, '/' . $name) !== false) {
            return true;
        }
    }

    return false;
}

function waf_request_gate_run(): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    if (PHP_SAPI === 'cli') {
        return;
    }

    $disable = trim((string)($_ENV['WAF_DISABLE'] ?? getenv('WAF_DISABLE') ?: ''));
    if ($disable === '1' || strcasecmp($disable, 'true') === 0) {
        return;
    }

    $parts = [];
    $uriParts = [];
    if (!empty($_SERVER['QUERY_STRING'])) {
        $parts[] = $_SERVER['QUERY_STRING'];
        $uriParts[] = $_SERVER['QUERY_STRING'];
    }
    if (!empty($_SERVER['REQUEST_URI'])) {
        $parts[] = $_SERVER['REQUEST_URI'];
        $uriParts[] = $_SERVER['REQUEST_URI'];
    }
    waf_flatten_strings($_GET, $parts, true);
    waf_flatten_strings($_POST, $parts, true);
    waf_flatten_upload_names($_FILES ?? [], $parts);

    $blob = '';
    foreach ($parts as $p) {
        $blob .= waf_normalize_for_scan($p) . "\n";
    }

    $uriBlob = '';
    foreach ($uriParts as $p) {
        $uriBlob .= waf_normalize_for_scan($p) . "\n";
    }

    [$blocked, $label] = waf_detect_malicious($blob, $uriBlob);
    if (!$blocked) {
        return;
    }

    $ip = waf_client_ip();
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    error_log('WAF_BLOCK ip=' . $ip . ' method=' . $method . ' rule=' . $label . ' uri=' . substr($uri, 0, 500));

    if (waf_should_return_json_block()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode([
            'success' => false,
            'ok' => false,
            'error' => 'security_block',
            'message' => 'Request blocked. Suspicious input was detected.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: /security-notice', true, 302);
    exit;
}
