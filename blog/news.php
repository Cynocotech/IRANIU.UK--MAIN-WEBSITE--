<?php
declare(strict_types=1);

/**
 * اخبار فارسی از tbl_news — ۶ خبر در هر صفحه، فیلتر دسته، لینک جزئیات
 * /blog/news?page=2&cat=...
 */
require __DIR__ . '/../load_env.php';
require __DIR__ . '/../includes/app_db.php';
require __DIR__ . '/../includes/news_tbl_helpers.php';

const NEWS_TABLE = 'tbl_news';
const PER_PAGE = 6;

$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}
$catFilter = isset($_GET['cat']) ? trim((string) $_GET['cat']) : '';

$err = null;
$rows = [];
$total = 0;
$totalPages = 0;
$orderCol = 'id';
$categoryOptions = [];
$catCol = null;
$idCol = null;
$categoryLabelByValue = [];
$catTableUsed = '';

try {
    $pdo = app_pdo();
    $cols = news_table_columns($pdo, NEWS_TABLE);
    $orderCol = news_order_field($cols);
    $orderQuoted = '`' . str_replace('`', '``', $orderCol) . '`';
    $extraWhere = news_deleted_clause($cols);
    $idCol = news_id_column($cols);

    $fkCol = news_category_fk_column($cols);
    $catTable = trim((string)($_ENV['NEWS_CATEGORY_TABLE'] ?? getenv('NEWS_CATEGORY_TABLE') ?: 'tbl_category'));

    if ($fkCol !== null) {
        $categoryOptions = news_fetch_categories_from_tbl_category($pdo, $catTable);
        if ($categoryOptions !== []) {
            $catCol = $fkCol;
            $catTableUsed = $catTable;
        } else {
            $categoryOptions = news_distinct_categories($pdo, NEWS_TABLE, $fkCol, $extraWhere);
            if ($categoryOptions !== []) {
                $catCol = $fkCol;
            }
        }
    }

    if ($catCol === null) {
        $legacyCol = news_category_column($cols);
        if ($legacyCol !== null) {
            $catCol = $legacyCol;
            $categoryOptions = news_distinct_categories($pdo, NEWS_TABLE, $legacyCol, $extraWhere);
        }
    }

    foreach ($categoryOptions as $opt) {
        $categoryLabelByValue[(string) $opt['value']] = $opt['label'];
    }

    $catSql = '';
    if ($catCol !== null && $catFilter !== '') {
        $catSql = ' AND `' . str_replace('`', '``', $catCol) . '` = :newscat ';
    }

    $t = str_replace('`', '``', NEWS_TABLE);
    $countSql = 'SELECT COUNT(*) FROM `' . $t . '` WHERE 1=1' . $extraWhere . $catSql;
    $stmt = $pdo->prepare($countSql);
    if ($catSql !== '') {
        $stmt->bindValue(':newscat', $catFilter);
    }
    $stmt->execute();
    $total = (int) $stmt->fetchColumn();
    $totalPages = $total > 0 ? (int) ceil($total / PER_PAGE) : 0;
    if ($totalPages > 0 && $page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * PER_PAGE;

    $sql = 'SELECT * FROM `' . $t . '` WHERE 1=1' . $extraWhere . $catSql
        . ' ORDER BY ' . $orderQuoted . ' DESC LIMIT :lim OFFSET :off';
    $stmt = $pdo->prepare($sql);
    if ($catSql !== '') {
        $stmt->bindValue(':newscat', $catFilter);
    }
    $stmt->bindValue(':lim', PER_PAGE, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $err = 'اتصال به پایگاه داده برقرار نشد یا جدول «tbl_news» در دسترس نیست. تنظیمات .env و دسترسی کاربر MySQL را بررسی کنید.';
}

$basePath = '/blog/news';

$buildUrl = static function (int $p) use ($basePath, $catFilter): string {
    $q = ['page' => $p];
    if ($catFilter !== '') {
        $q['cat'] = $catFilter;
    }
    return $basePath . '?' . http_build_query($q);
};

$siteUrl = 'https://iraniu.uk';
$ogImageDefault = 'https://panel.cybercina.co.uk/storage/logos/N0yQlVchcj4ucrQfVJwbXXB13FhWTMFccUBmWLpI.png';

$canonicalQuery = [];
if ($page > 1) {
    $canonicalQuery['page'] = $page;
}
if ($catFilter !== '') {
    $canonicalQuery['cat'] = $catFilter;
}
$listCanonical = $siteUrl . $basePath . ($canonicalQuery !== [] ? ('?' . http_build_query($canonicalQuery)) : '');

$filterLabelSeo = '';
if ($catFilter !== '' && isset($categoryLabelByValue[$catFilter])) {
    $filterLabelSeo = (string) $categoryLabelByValue[$catFilter];
}

$seoPageTitle = 'اخبار | مقالات IraniU';
if ($filterLabelSeo !== '') {
    $seoPageTitle = 'اخبار ' . $filterLabelSeo . ' | IraniU';
}
if ($page > 1) {
    $seoPageTitle .= ' — صفحه ' . $page;
}

$seoDescription = 'آخرین اخبار و راهنماها برای جامعه ایرانیان بریتانیا — IraniU.';
if ($err === null) {
    if ($totalPages > 1) {
        $seoDescription .= ' صفحه ' . $page . ' از ' . $totalPages . '.';
    }
    if ($filterLabelSeo !== '') {
        $seoDescription .= ' دسته: ' . $filterLabelSeo . '.';
    }
    $seoDescription = function_exists('mb_substr')
        ? mb_substr($seoDescription, 0, 158, 'UTF-8')
        : substr($seoDescription, 0, 158);
}
if ($err !== null) {
    $seoDescription = 'فهرست اخبار فارسی IraniU؛ در صورت خطا، تنظیمات پایگاه داده را بررسی کنید.';
}

$robotsNews = ($err !== null) ? 'noindex, follow' : 'index, follow, max-image-preview:large';

$jsonLdCollection = null;
if ($err === null && $idCol !== null && $rows !== []) {
    $listItems = [];
    $globalPos = $offset;
    foreach ($rows as $r) {
        $globalPos++;
        $nid = isset($r[$idCol]) ? (int) $r[$idCol] : 0;
        if ($nid < 1) {
            continue;
        }
        $listItems[] = [
            '@type' => 'ListItem',
            'position' => $globalPos,
            'url' => $siteUrl . news_article_public_path($nid),
            'name' => news_row_title($r),
        ];
    }
    if ($listItems !== []) {
        $jsonLdCollection = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => 'اخبار IraniU',
            'url' => $listCanonical,
            'description' => function_exists('mb_substr')
                ? mb_substr($seoDescription, 0, 300, 'UTF-8')
                : substr($seoDescription, 0, 300),
            'inLanguage' => 'fa-IR',
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => 'IraniU',
                'url' => $siteUrl,
            ],
            'mainEntity' => [
                '@type' => 'ItemList',
                'numberOfItems' => count($listItems),
                'itemListElement' => $listItems,
            ],
        ];
    }
}

$jsonLdBreadcrumbNews = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'صفحه اصلی', 'item' => $siteUrl . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'مقالات', 'item' => $siteUrl . '/blog/'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'اخبار', 'item' => $listCanonical],
    ],
];

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#3a0b47">
    <title><?= news_h($seoPageTitle) ?></title>
    <meta name="description" content="<?= news_h($seoDescription) ?>">
    <meta name="keywords" content="<?= news_h('IraniU, Persian news UK, Iranian community Britain, اخبار فارسی, ایرانیان بریتانیا, iraniu.uk') ?>">
    <meta name="author" content="IraniU">
    <meta name="robots" content="<?= news_h($robotsNews) ?>">
    <link rel="canonical" href="<?= news_h($listCanonical) ?>">
    <meta property="og:site_name" content="IraniU">
    <meta property="og:locale" content="fa_IR">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= news_h($listCanonical) ?>">
    <meta property="og:title" content="<?= news_h($seoPageTitle) ?>">
    <meta property="og:description" content="<?= news_h($seoDescription) ?>">
    <meta property="og:image" content="<?= news_h($ogImageDefault) ?>">
    <meta property="og:image:alt" content="IraniU">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= news_h($seoPageTitle) ?>">
    <meta name="twitter:description" content="<?= news_h($seoDescription) ?>">
    <meta name="twitter:image" content="<?= news_h($ogImageDefault) ?>">
    <?php if ($jsonLdCollection !== null): ?>
    <script type="application/ld+json"><?= news_json_encode_ld($jsonLdCollection) ?></script>
    <?php endif; ?>
    <script type="application/ld+json"><?= news_json_encode_ld($jsonLdBreadcrumbNews) ?></script>
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-4R1H98RJ7J"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());
      gtag('config', 'G-4R1H98RJ7J');
    </script>
    <link rel="icon" type="image/gif" href="https://panel.cybercina.co.uk//storage/logos/1JAs6UIE0Qiq5OwDODGsueoPVVNh7S1VtHriltIa.gif">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
    <style>
        @font-face { font-family: 'Yekan Bakh'; src: url('/fonts/YekanBakh-Regular.woff') format('woff'); font-weight: normal; font-style: normal; font-display: swap; }
        @font-face { font-family: 'Yekan Bakh'; src: url('/fonts/YekanBakh-Bold.woff') format('woff'); font-weight: bold; font-style: normal; font-display: swap; }
        @font-face { font-family: 'Yekan Bakh'; src: url('/fonts/YekanBakh-ExtraBlack.woff') format('woff'); font-weight: 800; font-style: normal; font-display: swap; }
        @font-face { font-family: 'Yekan Bakh'; src: url('/fonts/YekanBakh-ExtraBlack.woff') format('woff'); font-weight: 900; font-style: normal; font-display: swap; }
        :root { --brand-purple: #74208b; --dark-purple: #3a0b47; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Yekan Bakh', Tahoma, sans-serif; }
        a { text-decoration: none; color: var(--brand-purple); font-weight: 700; }
        a:hover { color: var(--dark-purple); }
        body { background: #fdfdfd; color: #1a1a1a; line-height: 1.75; }
        header { background: var(--dark-purple); padding: 12px 0; position: sticky; top: 0; z-index: 1000; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-container { max-width: 1100px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; padding: 0 20px; }
        .logo img { height: 48px; display: block; }
        .desktop-nav a { color: #fff; font-weight: bold; margin-right: 20px; font-size: 0.88rem; opacity: 0.92; }
        .desktop-nav a:hover { color: var(--brand-purple); }
        .hamburger { display: none; color: #fff; font-size: 1.4rem; cursor: pointer; }
        .mobile-nav { display: none; position: fixed; top: 70px; right: 0; left: 0; background: var(--dark-purple); flex-direction: column; align-items: center; padding: 28px 0; z-index: 999; border-top: 1px solid rgba(255,255,255,0.08); }
        .mobile-nav.open { display: flex; }
        .mobile-nav a { color: #fff; font-weight: bold; margin: 10px 0; padding-bottom: 10px; width: 85%; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .breadcrumb-wrap { max-width: 1100px; margin: 0 auto; padding: 14px 20px 2px; }
        .breadcrumb-nav { display: inline-flex; max-width: 100%; }
        .breadcrumb-nav ol { display: flex; flex-wrap: wrap; align-items: center; gap: 0; list-style: none; margin: 0; padding: 9px 20px 9px 16px; background: linear-gradient(145deg, #faf6fc 0%, #fff 55%, #fdf8ff 100%); border: 1px solid rgba(116, 32, 139, 0.14); border-radius: 999px; box-shadow: 0 2px 14px rgba(58, 11, 71, 0.07), inset 0 1px 0 rgba(255, 255, 255, 0.9); }
        .breadcrumb-nav li { display: flex; align-items: center; font-size: 0.8125rem; color: #5a5a5a; }
        .breadcrumb-nav li + li::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: linear-gradient(135deg, var(--brand-purple), rgba(116, 32, 139, 0.5)); margin: 0 12px; flex-shrink: 0; opacity: 0.9; }
        .breadcrumb-nav a { color: var(--brand-purple); font-weight: 700; transition: color 0.2s; display: inline-flex; align-items: center; }
        .breadcrumb-nav a:hover { color: var(--dark-purple); }
        .breadcrumb-nav .bc-home { font-size: 0.95rem; line-height: 1; }
        .breadcrumb-nav li.breadcrumb-current { color: var(--dark-purple); font-weight: 700; }
        .hero { background: linear-gradient(135deg, var(--dark-purple), var(--brand-purple)); color: #fff; padding: 48px 20px; text-align: center; }
        .hero h1 { font-size: 1.85rem; margin-bottom: 10px; font-weight: 900; }
        .hero p { max-width: 640px; margin: 0 auto; opacity: 0.95; font-size: 1rem; }
        .wrap { max-width: 1100px; margin: -28px auto 64px; padding: 0 20px; }
        .filter-bar { background: #fff; border: 1px solid #eee; border-radius: 16px; padding: 16px 18px; margin-bottom: 18px; display: flex; flex-wrap: wrap; align-items: center; gap: 14px; box-shadow: 0 8px 28px rgba(0,0,0,0.04); }
        .filter-bar label { font-weight: 800; color: var(--dark-purple); font-size: 0.9rem; }
        .filter-bar select { min-width: 200px; max-width: 100%; padding: 10px 14px; border-radius: 12px; border: 1px solid rgba(116,32,139,0.25); font-family: inherit; font-size: 0.92rem; background: #faf8fb; color: #1a1a1a; }
        .filter-bar .btn-apply { background: var(--brand-purple); color: #fff !important; border: none; padding: 10px 20px; border-radius: 12px; font-weight: 800; cursor: pointer; font-family: inherit; font-size: 0.9rem; }
        .filter-bar .btn-apply:hover { filter: brightness(1.08); color: #fff !important; }
        .filter-bar .btn-clear { color: #666 !important; font-size: 0.88rem; font-weight: 700; }
        .meta-bar { font-size: 0.82rem; color: #666; margin-bottom: 20px; padding: 12px 16px; background: #fff; border-radius: 14px; border: 1px solid #eee; }
        .alert { background: #ffebee; color: #b71c1c; padding: 18px 20px; border-radius: 14px; margin-bottom: 22px; border: 1px solid #ffcdd2; text-align: right; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 22px; }
        .card { background: #fff; border-radius: 20px; overflow: hidden; border: 1px solid #eee; box-shadow: 0 12px 36px rgba(0,0,0,0.06); transition: 0.25s; display: flex; flex-direction: column; }
        .card:hover { border-color: var(--brand-purple); transform: translateY(-3px); }
        .card-body { padding: 22px; flex: 1; display: flex; flex-direction: column; }
        .card .date { font-size: 0.78rem; color: #888; margin-bottom: 8px; }
        .card .cat-tag { display: inline-block; font-size: 0.72rem; background: #ede7f6; color: var(--dark-purple); padding: 3px 10px; border-radius: 999px; margin-bottom: 8px; font-weight: 800; }
        .card .post-tags { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 10px; }
        .card .post-tags span { font-size: 0.68rem; background: #f5f0fa; color: var(--brand-purple); padding: 2px 8px; border-radius: 999px; font-weight: 700; }
        .card h2 { font-size: 1.12rem; color: var(--dark-purple); margin-bottom: 10px; line-height: 1.45; }
        .card p.ex { color: #555; font-size: 0.92rem; text-align: justify; flex: 1; margin-bottom: 14px; }
        .card .read-more { margin-top: auto; display: inline-flex; align-items: center; gap: 8px; font-size: 0.95rem; }
        .pager { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 10px; margin-top: 36px; padding: 20px; background: #fff; border-radius: 16px; border: 1px solid #eee; }
        .pager a, .pager span { display: inline-flex; align-items: center; justify-content: center; min-width: 42px; height: 42px; padding: 0 12px; border-radius: 10px; font-size: 0.88rem; font-weight: 700; }
        .pager a { background: #f3e8f5; color: var(--dark-purple); border: 1px solid rgba(116,32,139,0.2); }
        .pager a:hover { background: var(--brand-purple); color: #fff; border-color: var(--brand-purple); }
        .pager span.current { background: var(--dark-purple); color: #fff; }
        .pager span.ell { border: none; background: transparent; color: #888; min-width: auto; }
        .pager .disabled { opacity: 0.45; pointer-events: none; }
        footer { background: var(--dark-purple); color: #fff; padding: 48px 20px 64px; text-align: center; }
        .footer-links a { color: #fff; margin: 0 10px; font-size: 0.82rem; opacity: 0.75; }
        .footer-links a:hover { opacity: 1; }
        .footer-logo img { height: 56px; margin-bottom: 16px; }
        .social-links { display: flex; justify-content: center; gap: 12px; margin-top: 16px; }
        .social-links a { color: #fff; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 50%; background: rgba(255,255,255,0.12); }
        @media (max-width: 992px) { .desktop-nav { display: none; } .hamburger { display: block; } }
    </style>
</head>
<body>
<header>
    <div class="nav-container">
        <a href="/" class="logo"><img src="https://panel.cybercina.co.uk//storage/logos/N0yQlVchcj4ucrQfVJwbXXB13FhWTMFccUBmWLpI.png" alt="IraniU"></a>
        <nav class="desktop-nav">
            <a href="/">صفحه اصلی</a>
            <a href="/blog/">مقالات</a>
            <a href="/blog/news">اخبار</a>
            <a href="/careers">فرصت‌های شغلی</a>
            <a href="/report">گزارش تخلف</a>
            <a href="/contact">تماس با ما</a>
        </nav>
        <div class="hamburger" onclick="document.getElementById('mnav').classList.toggle('open')"><i class="fas fa-bars"></i></div>
    </div>
    <nav class="mobile-nav" id="mnav">
        <a href="/" onclick="document.getElementById('mnav').classList.remove('open')">صفحه اصلی</a>
        <a href="/blog/" onclick="document.getElementById('mnav').classList.remove('open')">مقالات</a>
        <a href="/blog/news" onclick="document.getElementById('mnav').classList.remove('open')">اخبار</a>
        <a href="/careers" onclick="document.getElementById('mnav').classList.remove('open')">فرصت‌های شغلی</a>
        <a href="/report" onclick="document.getElementById('mnav').classList.remove('open')">گزارش تخلف</a>
        <a href="/contact" onclick="document.getElementById('mnav').classList.remove('open')">تماس با ما</a>
    </nav>
</header>

<div class="breadcrumb-wrap">
    <nav class="breadcrumb-nav" aria-label="مسیر صفحه">
        <ol>
            <li><a href="/" aria-label="صفحه اصلی"><i class="fas fa-house bc-home" aria-hidden="true"></i></a></li>
            <li><a href="/blog/">مقالات</a></li>
            <li class="breadcrumb-current" aria-current="page">اخبار</li>
        </ol>
    </nav>
</div>

<section class="hero">
    <h1>اخبار</h1>
    <p><?= (int) PER_PAGE ?> خبر در هر صفحه. برای متن کامل هر خبر، وارد جزئیات شوید؛ ادامه مطلب در اپلیکیشن.</p>
</section>

<div class="wrap">
    <?php if ($err !== null): ?>
        <div class="alert" role="alert"><?= news_h($err) ?></div>
    <?php else: ?>
        <?php if ($catCol !== null): ?>
            <form class="filter-bar" method="get" action="<?= news_h($basePath) ?>">
                <label for="cat">دسته‌بندی</label>
                <select name="cat" id="cat" aria-label="انتخاب دسته">
                    <option value="">همه دسته‌ها</option>
                    <?php foreach ($categoryOptions as $opt): ?>
                        <?php
                        $val = $opt['value'];
                        $lab = $opt['label'];
                        if (is_numeric($lab)) {
                            $lab = news_fa_digits($lab);
                        }
                        ?>
                        <option value="<?= news_h($val) ?>"<?= (string) $catFilter === (string) $val ? ' selected' : '' ?>><?= news_h($lab) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="page" value="1">
                <button type="submit" class="btn-apply">اعمال فیلتر</button>
                <?php if ($catFilter !== ''): ?>
                    <a class="btn-clear" href="<?= news_h($basePath) ?>">حذف فیلتر</a>
                <?php endif; ?>
            </form>
        <?php elseif ($catCol === null && $err === null): ?>
            <div class="meta-bar" style="margin-bottom:18px">ستون دسته (مثل category_id) یا جدول <code>tbl_category</code> شناسایی نشد؛ فیلتر غیرفعال است.</div>
        <?php endif; ?>

        <?php if ($idCol === null): ?>
            <div class="meta-bar" style="border-color:#ffe082;background:#fffde7">ستون شناسه (id) پیدا نشد؛ لینک جزئیات غیرفعال است.</div>
        <?php endif; ?>
        <div class="meta-bar">
            مجموع خبرها: <?= news_fa_digits((string) (int) $total) ?>
            <?php if ($totalPages > 0): ?>
                — صفحه <?= news_fa_digits((string) (int) $page) ?> از <?= news_fa_digits((string) (int) $totalPages) ?>
            <?php endif; ?>
            <?php if ($catFilter !== '' && $catCol !== null): ?>
                <?php
                $filterLabel = $categoryLabelByValue[$catFilter] ?? $catFilter;
                ?>
                — فیلتر: <?= news_h($filterLabel) ?>
            <?php endif; ?>
        </div>
        <div class="grid">
            <?php if ($rows === []): ?>
                <div class="card"><div class="card-body"><h2>خبری نیست</h2><p class="ex">با این فیلتر خبری پیدا نشد یا هنوز ردیفی ثبت نشده است.</p></div></div>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $title = news_row_title($r);
                    $ex = news_row_card_blurb($r, 220);
                    $rowTags = array_slice(news_row_tags_list($r), 0, 5);
                    $d = news_format_date($r);
                    $rowCatRaw = ($catCol !== null && isset($r[$catCol])) ? trim((string) $r[$catCol]) : '';
                    $rowCatDisplay = $rowCatRaw !== '' && isset($categoryLabelByValue[$rowCatRaw])
                        ? $categoryLabelByValue[$rowCatRaw]
                        : $rowCatRaw;
                    $nid = ($idCol !== null && isset($r[$idCol])) ? (int) $r[$idCol] : 0;
                    $articleHref = $nid > 0 ? news_article_public_path($nid) : '#';
                    ?>
                    <article class="card">
                        <div class="card-body">
                            <?php if ($rowCatRaw !== ''): ?>
                                <span class="cat-tag"><?= news_h($rowCatDisplay) ?></span>
                            <?php endif; ?>
                            <?php if ($d !== null): ?><div class="date"><i class="far fa-calendar-alt" style="margin-left:6px;opacity:.8"></i><?= news_h($d) ?></div><?php endif; ?>
                            <h2><a href="<?= news_h($articleHref) ?>" style="color:inherit;font-weight:900"><?= news_h($title) ?></a></h2>
                            <?php if ($rowTags !== []): ?>
                            <div class="post-tags" aria-label="برچسب‌ها"><?php foreach ($rowTags as $tg): ?><span><?= news_h($tg) ?></span><?php endforeach; ?></div>
                            <?php endif; ?>
                            <?php if ($ex !== ''): ?><p class="ex"><?= news_h($ex) ?></p><?php endif; ?>
                            <?php if ($nid > 0): ?>
                                <a class="read-more" href="<?= news_h($articleHref) ?>"><i class="fas fa-arrow-left"></i> مشاهده جزئیات</a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pager" aria-label="صفحه‌بندی">
                <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $page <= 1 ? '#' : news_h($buildUrl($page - 1)) ?>" aria-label="صفحه قبل"><i class="fas fa-chevron-right"></i></a>
                <?php
                $window = 2;
                $show = [];
                for ($i = 1; $i <= $totalPages; $i++) {
                    if ($i === 1 || $i === $totalPages || abs($i - $page) <= $window) {
                        $show[] = $i;
                    }
                }
                $show = array_values(array_unique($show));
                $lastPrinted = 0;
                foreach ($show as $i) {
                    if ($lastPrinted && $i - $lastPrinted > 1) {
                        echo '<span class="ell" aria-hidden="true">…</span>';
                    }
                    if ($i === $page) {
                        echo '<span class="current" aria-current="page">' . news_h(news_fa_digits((string) $i)) . '</span>';
                    } else {
                        echo '<a href="' . news_h($buildUrl($i)) . '">' . news_h(news_fa_digits((string) $i)) . '</a>';
                    }
                    $lastPrinted = $i;
                }
                ?>
                <a class="<?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= $page >= $totalPages ? '#' : news_h($buildUrl($page + 1)) ?>" aria-label="صفحه بعد"><i class="fas fa-chevron-left"></i></a>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<footer>
    <div class="footer-logo"><img src="https://panel.cybercina.co.uk//storage/logos/N0yQlVchcj4ucrQfVJwbXXB13FhWTMFccUBmWLpI.png" alt="IraniU"></div>
    <div class="footer-links">
        <a href="/">صفحه اصلی</a>
        <a href="/blog/">مقالات</a>
        <a href="/blog/news">اخبار</a>
        <a href="/careers">فرصت‌های شغلی</a>
        <a href="/report">گزارش تخلف</a>
        <a href="/contact">تماس با ما</a>
        <a href="/terms">شرایط و ضوابط</a>
        <a href="/privacy">حریم خصوصی</a>
    </div>
    <div class="social-links">
        <a href="https://instagram.com/iraniu.uk" target="_blank" rel="noopener" aria-label="اینستاگرام"><i class="fab fa-instagram"></i></a>
        <a href="https://t.me/iraniu_uk" target="_blank" rel="noopener" aria-label="تلگرام"><i class="fab fa-telegram-plane"></i></a>
        <a href="mailto:hello@iraniu.uk" aria-label="ایمیل"><i class="fas fa-envelope"></i></a>
    </div>
    <p style="margin-top:20px;opacity:0.75;font-size:0.85rem;">© IraniU — پلتفرم دیجیتال ایرانیان بریتانیا</p>
</footer>
</body>
</html>
