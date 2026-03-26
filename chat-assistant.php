<?php
declare(strict_types=1);

require __DIR__ . '/load_env.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], $jsonFlags);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode((string) $raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json'], $jsonFlags);
    exit;
}

$message = trim((string)($payload['message'] ?? ''));
if ($message === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'empty_message'], $jsonFlags);
    exit;
}

$msgLen = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message);
if ($msgLen > 1200) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'message_too_long'], $jsonFlags);
    exit;
}

$historyIn = $payload['history'] ?? [];
$history = [];
if (is_array($historyIn)) {
    foreach ($historyIn as $item) {
        if (!is_array($item)) {
            continue;
        }
        $role = (string)($item['role'] ?? '');
        $content = trim((string)($item['content'] ?? ''));
        if (($role !== 'user' && $role !== 'assistant') || $content === '') {
            continue;
        }
        $history[] = ['role' => $role, 'content' => $content];
        if (count($history) >= 8) {
            break;
        }
    }
}

$apiKey = trim((string)($_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: ''));
$model = trim((string)($_ENV['OPENAI_MODEL'] ?? getenv('OPENAI_MODEL') ?: 'gpt-4o-mini'));
$waNumberRaw = trim((string)($_ENV['WHATSAPP_NUMBER'] ?? getenv('WHATSAPP_NUMBER') ?: ''));
$waNumber = preg_replace('/[^0-9]/', '', $waNumberRaw) ?? '';
$fallbackBiolink = 'https://iraniu.uk/Biolink/';

$waBase = $waNumber !== '' ? ('https://wa.me/' . $waNumber) : $fallbackBiolink;

$systemPrompt = 'You are IraniU support assistant for Persian-speaking users in the UK. '
    . 'Be concise, practical, and friendly. Prefer Persian language unless user writes English. '
    . 'Ask 1 clarifying question when needed. Avoid legal/medical definitive claims. '
    . 'At the end, suggest they can continue on WhatsApp for human support.';

$messages = [['role' => 'system', 'content' => $systemPrompt]];
foreach ($history as $h) {
    $messages[] = $h;
}
$messages[] = ['role' => 'user', 'content' => $message];

$assistantText = 'ممنون از پیام شما. برای بررسی دقیق‌تر لطفا روی دکمه واتساپ بزنید تا همکاران ما راهنمایی‌تان کنند.';

if ($apiKey !== '') {
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    if ($ch !== false) {
        $req = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.4,
            'max_tokens' => 280,
        ];
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($req, $jsonFlags),
            CURLOPT_TIMEOUT => 18,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (is_string($resp) && $resp !== '' && $code >= 200 && $code < 300) {
            $data = json_decode($resp, true);
            $txt = trim((string)($data['choices'][0]['message']['content'] ?? ''));
            if ($txt !== '') {
                $assistantText = $txt;
            }
        }
    }
}

$waText = "سلام، از چت سایت IraniU پیام می‌دهم.\n\n"
    . "پیام من:\n" . $message . "\n\n"
    . "پاسخ دستیار:\n" . $assistantText;
$waUrl = $waBase;
if (strpos($waBase, 'https://wa.me/') === 0) {
    $waUrl = $waBase . '?text=' . rawurlencode($waText);
}

echo json_encode([
    'ok' => true,
    'reply' => $assistantText,
    'whatsapp_url' => $waUrl,
], $jsonFlags);

