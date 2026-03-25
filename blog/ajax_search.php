<?php
declare(strict_types=1);

/**
 * جستجوی آژاکسی مقالات/اخبار — JSON برای /blog/
 */
require __DIR__ . '/../load_env.php';
require __DIR__ . '/../includes/app_db.php';
require __DIR__ . '/../includes/news_tbl_helpers.php';

const NEWS_TABLE = 'tbl_news';
const SEARCH_LIMIT_DEFAULT = 12;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: strict-origin-when-cross-origin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : SEARCH_LIMIT_DEFAULT;

$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

if ($q === '') {
    echo json_encode(['ok' => true, 'query' => '', 'items' => []], $flags);
    exit;
}

$qlen = function_exists('mb_strlen') ? mb_strlen($q, 'UTF-8') : strlen($q);
if ($qlen < 2) {
    echo json_encode(['ok' => true, 'query' => $q, 'items' => [], 'hint' => 'min_length'], $flags);
    exit;
}

try {
    $pdo = app_pdo();
    $cols = news_table_columns($pdo, NEWS_TABLE);
    $extraWhere = news_deleted_clause($cols);
    $idCol = news_id_column($cols);
    $ctx = news_category_labels_for_rows($pdo, NEWS_TABLE, $cols, $extraWhere);
    $catCol = $ctx['catCol'];
    $categoryLabelByValue = $ctx['labelByValue'];

    $rows = news_search_matching_rows($pdo, NEWS_TABLE, $cols, $q, $limit);
    $items = [];
    foreach ($rows as $r) {
        $title = news_row_title($r);
        $ex = news_row_card_blurb($r, 160);
        $nid = ($idCol !== null && isset($r[$idCol])) ? (int) $r[$idCol] : 0;
        $url = $nid > 0 ? news_article_public_path($nid) : '#';
        $d = news_format_date($r);
        $rowCatRaw = ($catCol !== null && isset($r[$catCol])) ? trim((string) $r[$catCol]) : '';
        $rowCatDisplay = $rowCatRaw !== '' && isset($categoryLabelByValue[$rowCatRaw])
            ? $categoryLabelByValue[$rowCatRaw]
            : $rowCatRaw;
        if ($rowCatRaw !== '' && is_numeric((string) $rowCatDisplay)) {
            $rowCatDisplay = news_fa_digits((string) $rowCatDisplay);
        }
        $items[] = [
            'title' => $title,
            'url' => $url,
            'excerpt' => $ex,
            'date' => $d,
            'category' => $rowCatRaw !== '' ? $rowCatDisplay : '',
        ];
    }

    echo json_encode([
        'ok' => true,
        'query' => $q,
        'items' => $items,
    ], $flags);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error', 'items' => []], $flags);
}
