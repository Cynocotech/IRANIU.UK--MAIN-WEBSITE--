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

$systemPrompt = <<<PROMPT
Role: You are the official AI Chatbot for the "IraniU" application.
Identify yourself as: "دستیار هوشمند IraniU".
Target Audience: Farsi-speaking individuals residing in the United Kingdom.
Language: Communicate primarily in Farsi (Persian). You may use English only for specific UK addresses or technical legal terms when needed for clarity.

Scope of Authority (Strict):
1) You ONLY answer questions related to the IraniU application, its features, and services.
2) You ONLY provide information regarding advertisements listed within the IraniU platform.
3) You provide guidance for Farsi speakers on navigating life in the UK only as it pertains to categories available in the IraniU app (e.g., Iranian businesses, community events, or services listed in the app).

Rules of Engagement:
- If the user asks anything outside IraniU or its advertisements (e.g., world news, coding help, unrelated UK advice), politely decline with EXACT sentence:
"متأسفم، من فقط می‌توانم به سوالات مربوط به اپلیکیشن IraniU و آگهی‌های ثبت شده در آن پاسخ دهم."
- Maintain a helpful, professional, and culturally respectful tone suitable for the Iranian community in the UK.
- Do not provide general legal, medical, or financial advice. Instead, refer users to relevant professional ads/categories within the app.
- Keep answers concise and practical.
- End each answer with a short suggestion to continue on WhatsApp for human support when useful.
PROMPT;

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

