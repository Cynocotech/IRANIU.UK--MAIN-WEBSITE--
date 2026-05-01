<?php
declare(strict_types=1);

/**
 * کمک‌توابع مشترک برای tbl_news (فهرست + جزئیات)
 */

function news_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function news_fa_digits(string $s): string
{
    return str_replace(
        ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
        ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
        $s
    );
}

/**
 * Remove Markdown-style heading hashes (###, ##, #) from blog/news text.
 */
function news_strip_markdown_heading_marks(string $text): string
{
    if ($text === '') {
        return $text;
    }
    $text = preg_replace('/^\s{0,3}#{1,6}\s+/m', '', $text) ?? $text;
    $text = preg_replace('/^\s{0,3}#{3,}\s*$/m', '', $text) ?? $text;
    $text = preg_replace('/\s+#{3,}\s+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+#{3,}$/u', '', $text) ?? $text;
    $text = preg_replace('/^#{3,}\s+/u', '', $text) ?? $text;
    return trim(preg_replace('/\s{2,}/u', ' ', $text) ?? $text);
}

/** @return string[] */
function news_table_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) {
        if (!empty($row['Field'])) {
            $out[] = $row['Field'];
        }
    }
    return $out;
}

function news_order_field(array $fieldNames): string
{
    if ($fieldNames === []) {
        throw new RuntimeException('جدول بدون ستون است.');
    }
    $lower = [];
    foreach ($fieldNames as $f) {
        $lower[strtolower($f)] = $f;
    }
    $candidates = [
        'publish_date', 'published_at', 'news_date', 'tarikh', 'date_news',
        'created_at', 'updated_at', 'insert_time', 'id', 'news_id',
    ];
    foreach ($candidates as $c) {
        if (isset($lower[$c])) {
            return $lower[$c];
        }
    }
    return $fieldNames[0];
}

function news_deleted_clause(array $cols): string
{
    foreach ($cols as $c) {
        if (strcasecmp($c, 'deleted_at') === 0) {
            return ' AND `' . str_replace('`', '``', $c) . '` IS NULL';
        }
    }
    return '';
}

function news_pick_first(array $row, array $keys): ?string
{
    foreach ($keys as $k) {
        if (!array_key_exists($k, $row)) {
            continue;
        }
        $v = $row[$k];
        if ($v === null) {
            continue;
        }
        $s = is_string($v) ? trim($v) : (string) $v;
        if ($s !== '') {
            return $s;
        }
    }
    return null;
}

function news_row_title(array $row): string
{
    $t = news_pick_first($row, [
        'title', 'Title', 'subject', 'Subject', 'news_title', 'newsTitle',
        'onvan', 'name', 'Name', 'headline', 'Headline', 'key_title', 'topic',
    ]);
    if ($t === null) {
        return 'بدون عنوان';
    }
    return news_strip_markdown_heading_marks($t);
}

function news_row_excerpt(array $row, int $maxLen = 280): string
{
    $raw = news_pick_first($row, [
        'summary', 'Summary', 'short_text', 'shortText', 'lead', 'intro',
        'description', 'Description', 'excerpt', 'Excerpt', 'kholase', 'subtitle',
        'body', 'Body', 'content', 'Content', 'text', 'Text', 'matn', 'news_text',
        'full_text', 'detail', 'html', 'Html',
    ]);
    if ($raw === null) {
        return '';
    }
    $decoded = html_entity_decode((string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = preg_replace('/\s+/u', ' ', strip_tags($decoded)) ?? '';
    $plain = news_strip_markdown_heading_marks(trim($plain));
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($plain, 'UTF-8') > $maxLen) {
            return rtrim(mb_substr($plain, 0, $maxLen - 1, 'UTF-8')) . '…';
        }
    } elseif (strlen($plain) > $maxLen) {
        return substr($plain, 0, $maxLen - 1) . '…';
    }
    return $plain;
}

/**
 * Parse tags / keywords from DB: comma, Persian comma, JSON array, or single value.
 *
 * @return string[]
 */
function news_parse_tags_from_string(?string $s): array
{
    if ($s === null) {
        return [];
    }
    $s = trim($s);
    if ($s === '') {
        return [];
    }
    if ($s[0] === '[' || $s[0] === '{') {
        $decoded = json_decode($s, true);
        if (is_array($decoded)) {
            $out = [];
            foreach ($decoded as $item) {
                if (is_string($item)) {
                    $t = trim($item);
                    if ($t !== '') {
                        $out[] = $t;
                    }
                } elseif (is_array($item)) {
                    foreach (['name', 'label', 'title', 'tag'] as $nk) {
                        if (!empty($item[$nk]) && is_string($item[$nk])) {
                            $t = trim($item[$nk]);
                            if ($t !== '') {
                                $out[] = $t;
                            }
                            break;
                        }
                    }
                }
            }
            return array_values(array_unique($out));
        }
    }
    $parts = preg_split('/\s*[,،;|]+\s*/u', $s) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') {
            $out[] = $p;
        }
    }
    return array_values(array_unique($out));
}

/**
 * Tags for UI and article:tag — from typical column names (not full meta_keywords blob).
 *
 * @return string[]
 */
function news_row_tags_list(array $row): array
{
    $keys = [
        'tags', 'Tags', 'tag', 'Tag', 'tag_list', 'tagList', 'post_tags', 'postTags',
        'labels', 'Labels', 'hashtags', 'Hashtags', 'topics', 'Topics', 'news_tags',
    ];
    $merged = [];
    foreach ($keys as $k) {
        if (!array_key_exists($k, $row) || $row[$k] === null) {
            continue;
        }
        $v = is_scalar($row[$k]) ? trim((string) $row[$k]) : '';
        if ($v === '') {
            continue;
        }
        $merged = array_merge($merged, news_parse_tags_from_string($v));
    }
    $seen = [];
    $uniq = [];
    foreach ($merged as $t) {
        $t = trim($t);
        if ($t === '') {
            continue;
        }
        $lk = function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t);
        if (isset($seen[$lk])) {
            continue;
        }
        $seen[$lk] = true;
        $uniq[] = $t;
    }
    return array_slice($uniq, 0, 32);
}

/**
 * Tags for جزئیات مقاله: ستون‌های tag + کلمات کلیدی (meta_keywords / keywords و غیره) برای نمایش و article:tag.
 *
 * @return string[]
 */
function news_row_seo_tags(array $row): array
{
    $merged = news_row_tags_list($row);
    $rawKw = news_pick_first($row, [
        'meta_keywords', 'Meta_keywords', 'seo_keywords', 'Seo_keywords', 'keywords', 'Keywords',
        'focus_keyword', 'focus_keywords', 'keyword', 'Keyword',
    ]);
    if ($rawKw !== null && trim((string) $rawKw) !== '') {
        $kw = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $rawKw)) ?? '');
        if ($kw !== '') {
            $merged = array_merge($merged, news_parse_tags_from_string($kw));
        }
    }
    $seen = [];
    $out = [];
    foreach ($merged as $t) {
        $t = trim($t);
        if ($t === '') {
            continue;
        }
        $len = function_exists('mb_strlen') ? mb_strlen($t, 'UTF-8') : strlen($t);
        if ($len < 2 || $len > 96) {
            continue;
        }
        $lk = function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t);
        if (isset($seen[$lk])) {
            continue;
        }
        $seen[$lk] = true;
        $out[] = $t;
        if (count($out) >= 28) {
            break;
        }
    }
    return $out;
}

/**
 * Meta / OG description: prefers dedicated SEO columns, then short fields, then excerpt.
 */
function news_row_seo_description(array $row, int $maxLen = 158): string
{
    $raw = news_pick_first($row, [
        'meta_description', 'Meta_description', 'seo_description', 'seoDescription',
        'og_description', 'ogDescription', 'description_short', 'short_description',
        'summary', 'Summary', 'short_text', 'shortText', 'lead', 'intro',
        'excerpt', 'Excerpt', 'kholase', 'subtitle',
        'description', 'Description',
    ]);
    if ($raw === null) {
        return news_row_excerpt($row, $maxLen);
    }
    $decoded = html_entity_decode((string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = preg_replace('/\s+/u', ' ', strip_tags($decoded)) ?? '';
    $plain = news_strip_markdown_heading_marks(trim($plain));
    if ($plain === '') {
        return news_row_excerpt($row, $maxLen);
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($plain, 'UTF-8') > $maxLen) {
            return rtrim(mb_substr($plain, 0, $maxLen - 1, 'UTF-8')) . '…';
        }
    } elseif (strlen($plain) > $maxLen) {
        return substr($plain, 0, $maxLen - 1) . '…';
    }
    return $plain;
}

/**
 * meta name="keywords" — DB fields + tag list + site defaults, deduped.
 */
function news_row_meta_keywords_combined(array $row): string
{
    $chunks = [];
    $rawKw = news_pick_first($row, [
        'meta_keywords', 'Meta_keywords', 'seo_keywords', 'Seo_keywords', 'keywords', 'Keywords',
        'focus_keyword', 'focus_keywords', 'keyword', 'Keyword',
    ]);
    if ($rawKw !== null && trim((string) $rawKw) !== '') {
        $decoded = html_entity_decode((string) $rawKw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $chunks[] = trim(preg_replace('/\s+/u', ' ', strip_tags($decoded)) ?? '');
    }
    foreach (news_row_tags_list($row) as $t) {
        $chunks[] = $t;
    }
    $chunks[] = 'IraniU, iraniu.uk, Persian UK, اخبار فارسی';
    $seen = [];
    $out = [];
    foreach ($chunks as $c) {
        if ($c === '') {
            continue;
        }
        foreach (preg_split('/\s*[,،;]+\s*/u', $c) ?: [] as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            $lk = function_exists('mb_strtolower') ? mb_strtolower($p, 'UTF-8') : strtolower($p);
            if (isset($seen[$lk])) {
                continue;
            }
            $seen[$lk] = true;
            $out[] = $p;
        }
    }
    $s = implode(', ', $out);
    if (function_exists('mb_strlen') && function_exists('mb_substr') && mb_strlen($s, 'UTF-8') > 400) {
        return rtrim(mb_substr($s, 0, 397, 'UTF-8')) . '…';
    }
    if (strlen($s) > 400) {
        return substr($s, 0, 397) . '…';
    }
    return $s;
}

/** برای کارت لیست: توضیح کوتاه اختصاصی یا خلاصه */
function news_row_card_blurb(array $row, int $maxLen = 200): string
{
    $seo = news_row_seo_description($row, $maxLen);
    if ($seo !== '') {
        return $seo;
    }
    return news_row_excerpt($row, $maxLen);
}

/** متن کامل برای صفحه جزئیات */
function news_row_body_raw(array $row): ?string
{
    return news_pick_first($row, [
        'body', 'Body', 'content', 'Content', 'text', 'Text', 'matn', 'news_text',
        'full_text', 'detail', 'html', 'Html', 'description', 'article',
    ]);
}

function news_format_date(array $row): ?string
{
    $raw = news_pick_first($row, [
        'publish_date', 'published_at', 'news_date', 'tarikh', 'date_news',
        'created_at', 'updated_at', 'insert_time', 'date', 'Date',
    ]);
    if ($raw === null) {
        return null;
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return null;
    }
    return news_fa_digits(date('Y/n/j', $ts));
}

/** ISO 8601 for JSON-LD / Open Graph */
function news_row_date_iso8601(array $row): ?string
{
    $raw = news_pick_first($row, [
        'publish_date', 'published_at', 'news_date', 'tarikh', 'date_news',
        'created_at', 'updated_at', 'insert_time', 'date', 'Date',
    ]);
    if ($raw === null) {
        return null;
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return null;
    }
    return date('c', $ts);
}

/** Safe JSON for <script type="application/ld+json"> */
function news_json_encode_ld(array $data): string
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS;
    return json_encode($data, $flags) ?: '{}';
}

function news_row_image_url(array $row): ?string
{
    $u = news_pick_first($row, [
        'image', 'Image', 'pic', 'picture', 'thumb', 'thumbnail', 'news_image',
        'photo', 'banner', 'img', 'aks', 'image_url', 'ImageUrl', 'cover', 'Cover',
    ]);
    if ($u === null) {
        return null;
    }
    $u = trim($u);
    if ($u === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $u)) {
        return $u;
    }
    if (strpos($u, '//') === 0) {
        return 'https:' . $u;
    }
    $base = rtrim((string)($_ENV['NEWS_IMAGE_BASE_URL'] ?? getenv('NEWS_IMAGE_BASE_URL') ?: ''), '/');
    if ($base === '') {
        $base = 'https://panel.cybercina.co.uk';
    }
    if ($u[0] === '/') {
        return $base . $u;
    }
    return $base . '/' . $u;
}

function news_id_column(array $cols): ?string
{
    foreach (['id', 'news_id', 'ID', 'newsId', 'pk'] as $want) {
        foreach ($cols as $c) {
            if (strcasecmp($c, $want) === 0) {
                return $c;
            }
        }
    }
    return null;
}

/** Escape `%`, `_`, `\` for SQL LIKE patterns. */
function news_escape_like(string $s): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

/**
 * ستون‌های متنی tbl_news که برای جستجوی LIKE مناسب‌اند (عنوان، متن، برچسب، …).
 *
 * @param string[] $fieldNames
 * @return string[]
 */
function news_search_text_columns(array $fieldNames): array
{
    $want = [
        'title', 'subject', 'news_title', 'newstitle', 'headline', 'onvan', 'name', 'topic', 'key_title',
        'summary', 'short_text', 'shorttext', 'lead', 'intro', 'description', 'excerpt', 'kholase', 'subtitle',
        'body', 'content', 'text', 'matn', 'news_text', 'full_text', 'detail', 'html', 'article',
        'tags', 'tag', 'tag_list', 'taglist', 'post_tags', 'posttags', 'labels', 'hashtags', 'topics', 'news_tags',
        'meta_keywords', 'seo_keywords', 'keywords', 'focus_keyword', 'focus_keywords', 'keyword',
        'meta_description', 'seo_description', 'og_description', 'description_short', 'short_description',
    ];
    $wantLower = array_map('strtolower', $want);
    $out = [];
    foreach ($fieldNames as $c) {
        if (in_array(strtolower($c), $wantLower, true)) {
            $out[] = $c;
        }
    }
    return array_values(array_unique($out));
}

/**
 * @return array<int, array<string, mixed>>
 */
function news_search_matching_rows(PDO $pdo, string $newsTable, array $cols, string $q, int $limit): array
{
    $needle = trim($q);
    if ($needle === '') {
        return [];
    }
    $len = function_exists('mb_strlen') ? mb_strlen($needle, 'UTF-8') : strlen($needle);
    if ($len < 2) {
        return [];
    }

    $searchCols = news_search_text_columns($cols);
    if ($searchCols === []) {
        return [];
    }

    $t = str_replace('`', '``', $newsTable);
    $extraWhere = news_deleted_clause($cols);
    $orderCol = news_order_field($cols);
    $orderQuoted = '`' . str_replace('`', '``', $orderCol) . '`';
    $likeVal = '%' . news_escape_like($needle) . '%';

    $holders = [];
    $params = [];
    foreach ($searchCols as $col) {
        $holders[] = '`' . str_replace('`', '``', $col) . '` LIKE ?';
        $params[] = $likeVal;
    }

    $lim = max(1, min(30, $limit));
    $sql = 'SELECT * FROM `' . $t . '` WHERE 1=1' . $extraWhere
        . ' AND (' . implode(' OR ', $holders) . ')'
        . ' ORDER BY ' . $orderQuoted . ' DESC LIMIT ' . $lim;

    $stmt = $pdo->prepare($sql);
    foreach ($params as $i => $v) {
        $stmt->bindValue($i + 1, $v, PDO::PARAM_STR);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $rows === false ? [] : $rows;
}

/**
 * نقشهٔ برچسب نمایشی دسته برای ردیف‌های خبر (مثل فهرست مقالات).
 *
 * @return array{catCol: ?string, labelByValue: array<string, string>}
 */
function news_category_labels_for_rows(PDO $pdo, string $newsTable, array $newsCols, string $extraWhere): array
{
    $labelByValue = [];
    $catCol = null;
    $categoryOptions = [];
    $fkCol = news_category_fk_column($newsCols);
    $catTable = trim((string)($_ENV['NEWS_CATEGORY_TABLE'] ?? getenv('NEWS_CATEGORY_TABLE') ?: 'tbl_category'));

    if ($fkCol !== null) {
        $categoryOptions = news_fetch_categories_from_tbl_category($pdo, $catTable);
        if ($categoryOptions !== []) {
            $catCol = $fkCol;
        } else {
            $categoryOptions = news_distinct_categories($pdo, $newsTable, $fkCol, $extraWhere);
            if ($categoryOptions !== []) {
                $catCol = $fkCol;
            }
        }
    }

    if ($catCol === null) {
        $legacyCol = news_category_column($newsCols);
        if ($legacyCol !== null) {
            $catCol = $legacyCol;
            $categoryOptions = news_distinct_categories($pdo, $newsTable, $legacyCol, $extraWhere);
        }
    }

    foreach ($categoryOptions as $opt) {
        $labelByValue[(string) $opt['value']] = (string) $opt['label'];
    }

    return ['catCol' => $catCol, 'labelByValue' => $labelByValue];
}

/**
 * ستون کلید خارجی دسته در tbl_news (اشاره به tbl_category)
 */
function news_category_fk_column(array $newsCols): ?string
{
    $prefer = ['category_id', 'cat_id', 'categoryId', 'fk_category_id', 'tbl_category_id'];
    foreach ($prefer as $p) {
        foreach ($newsCols as $c) {
            if (strcasecmp($c, $p) === 0) {
                return $c;
            }
        }
    }
    foreach ($newsCols as $c) {
        if (preg_match('/category.*_id$/i', $c) === 1) {
            return $c;
        }
    }
    return null;
}

/**
 * ستون دستهٔ متنی قدیمی در tbl_news (بدون جدول دسته جدا)
 */
function news_category_column(array $cols): ?string
{
    $prefer = [
        'category_name', 'category_slug', 'category', 'Category', 'cat_name',
        'cat_slug', 'news_category', 'type', 'news_type', 'topic', 'section',
    ];
    foreach ($prefer as $p) {
        foreach ($cols as $c) {
            if (strcasecmp($c, $p) === 0) {
                return $c;
            }
        }
    }
    foreach ($cols as $c) {
        if (stripos($c, 'category') !== false && stripos($c, '_id') === false) {
            return $c;
        }
    }
    return null;
}

function news_category_table_id_column(array $catCols): ?string
{
    foreach (['id', 'category_id', 'cat_id', 'ID'] as $w) {
        foreach ($catCols as $c) {
            if (strcasecmp($c, $w) === 0) {
                return $c;
            }
        }
    }
    return null;
}

function news_category_table_name_column(array $catCols): ?string
{
    foreach (['name', 'title', 'title_fa', 'category_name', 'label', 'fa_name', 'onvan', 'Name', 'Title'] as $w) {
        foreach ($catCols as $c) {
            if (strcasecmp($c, $w) === 0) {
                return $c;
            }
        }
    }
    foreach ($catCols as $c) {
        if (stripos($c, 'name') !== false || stripos($c, 'title') !== false) {
            return $c;
        }
    }
    return null;
}

function news_category_table_where_sql(array $catCols): string
{
    foreach ($catCols as $c) {
        if (strcasecmp($c, 'deleted_at') === 0) {
            return ' WHERE `' . str_replace('`', '``', $c) . '` IS NULL ';
        }
    }
    return '';
}

/**
 * خواندن دسته‌ها از tbl_category (یا نام جدول از NEWS_CATEGORY_TABLE)
 *
 * @return array<int, array{value:string,label:string}>
 */
function news_fetch_categories_from_tbl_category(PDO $pdo, string $categoryTable): array
{
    if (preg_match('/^[A-Za-z0-9_]{1,64}$/', trim($categoryTable)) !== 1) {
        return [];
    }
    $safe = str_replace('`', '``', $categoryTable);
    try {
        $catCols = news_table_columns($pdo, $categoryTable);
    } catch (Throwable $e) {
        return [];
    }
    if ($catCols === []) {
        return [];
    }
    $idCol = news_category_table_id_column($catCols);
    $nameCol = news_category_table_name_column($catCols);
    if ($idCol === null || $nameCol === null) {
        return [];
    }
    $idQ = '`' . str_replace('`', '``', $idCol) . '`';
    $nameQ = '`' . str_replace('`', '``', $nameCol) . '`';
    $where = news_category_table_where_sql($catCols);
    $sql = "SELECT $idQ AS cid, $nameQ AS cname FROM `$safe` $where ORDER BY $nameQ ASC";
    $stmt = $pdo->query($sql);
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = isset($row['cid']) ? trim((string) $row['cid']) : '';
        $name = isset($row['cname']) ? trim((string) $row['cname']) : '';
        if ($id === '') {
            continue;
        }
        if ($name === '') {
            $name = $id;
        }
        $out[] = ['value' => $id, 'label' => $name];
    }
    return $out;
}

/**
 * @return array<int, array{value:string,label:string}>
 */
function news_distinct_categories(PDO $pdo, string $table, string $catCol, string $extraWhere): array
{
    $t = str_replace('`', '``', $table);
    $c = str_replace('`', '``', $catCol);
    $sql = "SELECT DISTINCT `$c` AS v FROM `$t` WHERE 1=1 $extraWhere AND `$c` IS NOT NULL AND TRIM(CAST(`$c` AS CHAR)) <> '' ORDER BY v ASC";
    $stmt = $pdo->query($sql);
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $v = isset($row['v']) ? trim((string) $row['v']) : '';
        if ($v === '') {
            continue;
        }
        $out[] = ['value' => $v, 'label' => $v];
    }
    return $out;
}

/**
 * نیمهٔ اول / دوم متن ساده (۵۰٪ پیش‌فرض) برای تیزر وب
 * @return array{visible:string,rest:string,show_gate:bool}
 */
function news_split_teaser_plain(?string $htmlOrText, float $visibleRatio = 0.5): array
{
    if ($htmlOrText === null || trim($htmlOrText) === '') {
        return ['visible' => '', 'rest' => '', 'show_gate' => false];
    }
    $htmlOrText = trim((string) $htmlOrText);
    $decoded = html_entity_decode($htmlOrText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = preg_replace('/\s+/u', ' ', strip_tags($decoded)) ?? '';
    $plain = news_strip_markdown_heading_marks(trim($plain));
    if ($plain === '') {
        return ['visible' => '', 'rest' => '', 'show_gate' => false];
    }

    $len = function_exists('mb_strlen') ? mb_strlen($plain, 'UTF-8') : strlen($plain);
    if ($len < 120) {
        return ['visible' => $plain, 'rest' => '', 'show_gate' => false];
    }

    $cut = (int) max(1, floor($len * $visibleRatio));

    if (function_exists('mb_substr') && function_exists('mb_strrpos')) {
        $visible = mb_substr($plain, 0, $cut, 'UTF-8');
        $rest = mb_substr($plain, $cut, null, 'UTF-8');
        if ($rest !== '' && $visible !== '') {
            $lastSpace = mb_strrpos($visible, ' ', 0, 'UTF-8');
            if ($lastSpace !== false && $lastSpace > (int) ($cut * 0.45)) {
                $rest = mb_substr($visible, $lastSpace + 1, null, 'UTF-8') . $rest;
                $visible = mb_substr($visible, 0, $lastSpace, 'UTF-8');
            }
        }
    } else {
        $visible = substr($plain, 0, $cut);
        $rest = substr($plain, $cut);
    }

    $visible = trim($visible);
    $rest = trim($rest);
    if ($rest === '') {
        return ['visible' => $visible, 'rest' => '', 'show_gate' => false];
    }

    return ['visible' => $visible, 'rest' => $rest, 'show_gate' => true];
}

require_once __DIR__ . '/article_url_ref.php';
