<?php
declare(strict_types=1);

/**
 * صفحه مقالات — tbl_news، صفحه‌بندی، فیلتر دسته
 * /blog/?page=2&cat=...
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
$latestRows = [];
$total = 0;
$totalPages = 0;
$offset = 0;
$idCol = null;
$catCol = null;
$categoryLabelByValue = [];

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

    if (!isset($categoryOptions)) {
        $categoryOptions = [];
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
    $latestRows = $stmt->fetchAll();
} catch (Throwable $e) {
    $err = 'اتصال به پایگاه داده برقرار نشد یا جدول «tbl_news» در دسترس نیست. تنظیمات .env را بررسی کنید.';
}

$blogIndexPath = '/blog/';
$buildPageUrl = static function (int $p) use ($blogIndexPath, $catFilter): string {
    $q = [];
    if ($p > 1) {
        $q['page'] = $p;
    }
    if ($catFilter !== '') {
        $q['cat'] = $catFilter;
    }
    if ($q === []) {
        return $blogIndexPath;
    }
    return $blogIndexPath . '?' . http_build_query($q);
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
$canonical = $siteUrl . $blogIndexPath . ($canonicalQuery !== [] ? ('?' . http_build_query($canonicalQuery)) : '');

$filterLabelSeo = '';
if ($catFilter !== '' && isset($categoryLabelByValue[$catFilter])) {
    $filterLabelSeo = (string) $categoryLabelByValue[$catFilter];
}

if ($err === null && $page > 1 && $totalPages > 0) {
    $seoPageTitle = 'مقالات' . ($filterLabelSeo !== '' ? (' ' . $filterLabelSeo) : '') . ' — صفحه ' . news_fa_digits((string) $page) . ' از ' . news_fa_digits((string) $totalPages) . ' | IraniU';
} elseif ($err === null && $filterLabelSeo !== '') {
    $seoPageTitle = 'مقالات ' . $filterLabelSeo . ' | IraniU';
} else {
    $seoPageTitle = 'مقالات | ایرانیان بریتانیا | IraniU';
}

$seoDescription = 'مقالات فارسی برای جامعه ایرانیان بریتانیا — IraniU.';
if ($err === null) {
    if ($totalPages > 1) {
        $seoDescription .= ' صفحه ' . $page . ' از ' . $totalPages . '.';
    }
    if ($filterLabelSeo !== '') {
        $seoDescription .= ' دسته: ' . $filterLabelSeo . '.';
    }
    if ($total > 0) {
        $seoDescription .= ' مجموع ' . $total . ' مطلب.';
    }
}
$seoDescription = function_exists('mb_substr')
    ? mb_substr($seoDescription, 0, 158, 'UTF-8')
    : substr($seoDescription, 0, 158);

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
    <meta name="keywords" content="راهنمای ایرانیان UK, زندگی در بریتانیا, مقالات فارسی, IraniU blog, iraniu.uk">
    <meta name="author" content="IraniU">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <link rel="canonical" href="<?= news_h($canonical) ?>">
    <meta property="og:site_name" content="IraniU">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="fa_IR">
    <meta property="og:url" content="<?= news_h($canonical) ?>">
    <meta property="og:title" content="<?= news_h($seoPageTitle) ?>">
    <meta property="og:description" content="<?= news_h($seoDescription) ?>">
    <meta property="og:image" content="<?= news_h($ogImageDefault) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= news_h($seoPageTitle) ?>">
    <meta name="twitter:description" content="<?= news_h($seoDescription) ?>">
    <meta name="twitter:image" content="<?= news_h($ogImageDefault) ?>">
    <?php if ($err === null && $totalPages > 1): ?>
    <?php if ($page > 1): ?>
    <link rel="prev" href="<?= news_h($siteUrl . $buildPageUrl($page - 1)) ?>">
    <?php endif; ?>
    <?php if ($page < $totalPages): ?>
    <link rel="next" href="<?= news_h($siteUrl . $buildPageUrl($page + 1)) ?>">
    <?php endif; ?>
    <?php endif; ?>
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
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Yekan Bakh', Tahoma, sans-serif; text-decoration: none; }
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
        .breadcrumb-strip {
            background: linear-gradient(180deg, #faf7fc 0%, #f3eef8 100%);
            border-bottom: 1px solid rgba(116, 32, 139, 0.12);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.85);
        }
        .breadcrumb-inner { max-width: 1100px; margin: 0 auto; padding: 14px 20px 16px; }
        .breadcrumb-nav ol.bc-list {
            display: flex; flex-wrap: wrap; align-items: baseline; gap: 0; list-style: none; margin: 0; padding: 0;
        }
        .breadcrumb-nav ol.bc-list > li {
            display: inline-flex; align-items: center; font-size: 0.8125rem; color: #5c5c5c; line-height: 1.5;
        }
        .breadcrumb-nav ol.bc-list > li.bc-sep {
            color: rgba(116, 32, 139, 0.35); font-weight: 300; user-select: none; padding: 0 10px; font-size: 0.75rem;
        }
        .breadcrumb-nav ol.bc-list a {
            color: var(--brand-purple); font-weight: 700; display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 2px; border-radius: 8px; transition: color 0.2s, background 0.2s;
        }
        .breadcrumb-nav ol.bc-list a:hover { color: var(--dark-purple); background: rgba(116, 32, 139, 0.08); }
        .breadcrumb-nav ol.bc-list a .bc-home-icon { font-size: 0.9rem; opacity: 0.9; }
        .breadcrumb-nav li.breadcrumb-current { color: var(--dark-purple); font-weight: 800; padding: 4px 2px; line-height: 1.45; }
        .hero { background: linear-gradient(135deg, var(--dark-purple), var(--brand-purple)); color: #fff; padding: 56px 20px; text-align: center; }
        .hero h1 { font-size: 2rem; margin-bottom: 12px; font-weight: 900; }
        .hero p { max-width: 640px; margin: 0 auto; opacity: 0.95; font-size: 1.05rem; }
        .section-block { max-width: 1100px; margin: 0 auto; padding: 0 20px 64px; }
        .alert { background: #ffebee; color: #b71c1c; padding: 16px 18px; border-radius: 14px; margin-bottom: 18px; border: 1px solid #ffcdd2; text-align: right; }
        .filter-bar { background: #fff; border: 1px solid #eee; border-radius: 16px; padding: 16px 18px; margin-bottom: 18px; display: flex; flex-wrap: wrap; align-items: center; gap: 14px; box-shadow: 0 8px 28px rgba(0,0,0,0.04); }
        .filter-bar label { font-weight: 800; color: var(--dark-purple); font-size: 0.9rem; }
        .filter-bar select { min-width: 200px; max-width: 100%; padding: 10px 14px; border-radius: 12px; border: 1px solid rgba(116,32,139,0.25); font-family: inherit; font-size: 0.92rem; background: #faf8fb; color: #1a1a1a; }
        .filter-bar .btn-apply { background: var(--brand-purple); color: #fff !important; border: none; padding: 10px 20px; border-radius: 12px; font-weight: 800; cursor: pointer; font-family: inherit; font-size: 0.9rem; }
        .filter-bar .btn-apply:hover { filter: brightness(1.08); color: #fff !important; }
        .filter-bar .btn-clear { color: #666 !important; font-size: 0.88rem; font-weight: 700; }
        .blog-search-wrap { margin-bottom: 18px; position: relative; z-index: 50; }
        .blog-search-label { display: block; font-weight: 800; color: var(--dark-purple); font-size: 0.9rem; margin-bottom: 8px; }
        .blog-search-ctrl { position: relative; width: 100%; max-width: 100%; }
        .blog-search-icon { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: rgba(116,32,139,0.45); font-size: 0.95rem; pointer-events: none; z-index: 1; }
        .blog-search-input {
            width: 100%; padding: 12px 42px 12px 14px; border-radius: 14px;
            border: 1px solid rgba(116,32,139,0.22); font-family: inherit; font-size: 0.95rem;
            background: #faf8fb; color: #1a1a1a; transition: border-color 0.2s, box-shadow 0.2s;
        }
        .blog-search-input:focus { outline: none; border-color: var(--brand-purple); box-shadow: 0 0 0 3px rgba(116,32,139,0.12); background: #fff; }
        .blog-search-panel {
            position: absolute; top: calc(100% + 8px); left: 0; right: 0;
            max-height: min(62vh, 380px); overflow-y: auto;
            background: #fff; border: 1px solid rgba(116,32,139,0.14); border-radius: 16px;
            box-shadow: 0 16px 48px rgba(58,11,71,0.12); padding: 8px; text-align: right;
        }
        .blog-search-status { font-size: 0.82rem; color: #666; padding: 8px 10px; margin: 0; }
        .blog-search-status--err { color: #b71c1c; }
        .blog-search-list { display: flex; flex-direction: column; gap: 4px; }
        .blog-search-empty { font-size: 0.88rem; color: #777; padding: 14px 12px; margin: 0; text-align: center; }
        .blog-search-item {
            display: block; padding: 12px 14px; border-radius: 12px; color: inherit !important; text-decoration: none !important;
            border: 1px solid transparent; transition: background 0.15s, border-color 0.15s;
        }
        .blog-search-item:hover { background: #f7f1fa; border-color: rgba(116,32,139,0.12); }
        .blog-search-item-title { display: block; font-weight: 800; color: var(--dark-purple); font-size: 0.9rem; line-height: 1.4; margin-bottom: 4px; }
        .blog-search-item-meta { display: flex; flex-wrap: wrap; gap: 6px 10px; font-size: 0.72rem; color: #888; margin-bottom: 4px; }
        .blog-search-item-cat { background: #ede7f6; color: var(--dark-purple); padding: 2px 8px; border-radius: 999px; font-weight: 700; }
        .blog-search-item-date { font-weight: 600; }
        .blog-search-item-ex { display: block; font-size: 0.8rem; color: #555; line-height: 1.45; font-weight: 500; }
        .meta-bar { font-size: 0.82rem; color: #666; margin-bottom: 18px; padding: 12px 16px; background: #fff; border-radius: 14px; border: 1px solid #eee; }
        .pager { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 10px; margin-top: 8px; margin-bottom: 28px; padding: 20px; background: #fff; border-radius: 16px; border: 1px solid #eee; }
        .pager a, .pager span { display: inline-flex; align-items: center; justify-content: center; min-width: 42px; height: 42px; padding: 0 12px; border-radius: 10px; font-size: 0.88rem; font-weight: 700; }
        .pager a { background: #f3e8f5; color: var(--dark-purple); border: 1px solid rgba(116,32,139,0.2); }
        .pager a:hover { background: var(--brand-purple); color: #fff; border-color: var(--brand-purple); }
        .pager span.current { background: var(--dark-purple); color: #fff; }
        .pager span.ell { border: none; background: transparent; color: #888; min-width: auto; }
        .pager .disabled { opacity: 0.45; pointer-events: none; }
        .grid { max-width: 1100px; margin: -32px auto 0; padding: 0 20px; display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 22px; }
        .grid + .section-block { margin-top: 8px; }
        .section-block .grid { margin: 0 auto; padding: 0; }
        .card { background: #fff; border-radius: 20px; padding: 26px; border: 1px solid #eee; box-shadow: 0 12px 36px rgba(0,0,0,0.06); transition: 0.25s; }
        .card:hover { border-color: var(--brand-purple); transform: translateY(-4px); }
        .card .tag { display: inline-block; background: #f3e8f5; color: var(--dark-purple); font-size: 0.78rem; padding: 4px 12px; border-radius: 20px; margin-bottom: 12px; font-weight: bold; }
        .card .post-tags { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 10px; }
        .card .post-tags span { font-size: 0.68rem; background: #f5f0fa; color: var(--brand-purple); padding: 2px 8px; border-radius: 999px; font-weight: 700; }
        .card .date { font-size: 0.78rem; color: #888; margin-bottom: 8px; }
        .card h2 { font-size: 1.2rem; color: var(--dark-purple); margin-bottom: 10px; line-height: 1.45; }
        .card h2 a { color: inherit; font-weight: 900; }
        .card p { color: #555; font-size: 0.95rem; margin-bottom: 16px; text-align: justify; }
        .card a.more { color: var(--brand-purple); font-weight: bold; font-size: 0.95rem; display: inline-flex; align-items: center; gap: 8px; }
        .card a.more i { margin-right: 0; }
        footer { background: var(--dark-purple); color: #fff; padding: 48px 20px 64px; text-align: center; }
        .footer-links a { color: #fff; margin: 0 10px; font-size: 0.82rem; opacity: 0.75; }
        .footer-links a:hover { opacity: 1; }
        .footer-logo img { height: 56px; margin-bottom: 16px; }
        .social-links { display: flex; justify-content: center; gap: 12px; margin-top: 16px; }
        .social-links a { color: #fff; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 50%; background: rgba(255,255,255,0.12); }
        @media (max-width: 992px) { .desktop-nav { display: none; } .hamburger { display: block; } }
    </style>
    <link rel="stylesheet" href="/assets/css/content-protection.css">
</head>
<body>
<header>
    <div class="nav-container">
        <a href="/" class="logo"><img src="https://panel.cybercina.co.uk//storage/logos/N0yQlVchcj4ucrQfVJwbXXB13FhWTMFccUBmWLpI.png" alt="IraniU"></a>
        <nav class="desktop-nav">
            <a href="/">صفحه اصلی</a>
            <a href="/blog/">مقالات</a>
            <a href="/careers">فرصت‌های شغلی</a>
            <a href="/report">گزارش تخلف</a>
            <a href="/contact">تماس با ما</a>
        </nav>
        <div class="hamburger" onclick="document.getElementById('mnav').classList.toggle('open')"><i class="fas fa-bars"></i></div>
    </div>
    <nav class="mobile-nav" id="mnav">
        <a href="/" onclick="document.getElementById('mnav').classList.remove('open')">صفحه اصلی</a>
        <a href="/blog/" onclick="document.getElementById('mnav').classList.remove('open')">مقالات</a>
        <a href="/careers" onclick="document.getElementById('mnav').classList.remove('open')">فرصت‌های شغلی</a>
        <a href="/report" onclick="document.getElementById('mnav').classList.remove('open')">گزارش تخلف</a>
        <a href="/contact" onclick="document.getElementById('mnav').classList.remove('open')">تماس با ما</a>
    </nav>
</header>

<div class="breadcrumb-strip">
    <div class="breadcrumb-inner">
        <nav class="breadcrumb-nav" aria-label="مسیر صفحه">
            <ol class="bc-list">
                <li>
                    <a href="/"><i class="fas fa-house bc-home-icon" aria-hidden="true"></i><span>خانه</span></a>
                </li>
                <li class="bc-sep" aria-hidden="true">/</li>
                <li class="breadcrumb-current" aria-current="page"><span>مقالات</span></li>
            </ol>
        </nav>
    </div>
</div>

<section class="hero">
    <h1>مقالات برای جامعه ایرانی بریتانیا</h1>
</section>

<div class="section-block">
    <?php if ($err !== null): ?>
        <div class="alert" role="alert"><?= news_h($err) ?></div>
    <?php else: ?>
        <div class="blog-search-wrap" data-blog-search>
            <label class="blog-search-label" for="blog-search-q">جستجو در مطالب</label>
            <div class="blog-search-ctrl">
                <i class="fas fa-magnifying-glass blog-search-icon" aria-hidden="true"></i>
                <input type="search" id="blog-search-q" class="blog-search-input" name="blog_q" autocomplete="off" placeholder="عنوان یا متن…" aria-label="جستجو در مطالب" aria-autocomplete="list" aria-controls="blog-search-results" aria-expanded="false">
                <div id="blog-search-results" class="blog-search-panel" role="region" aria-label="نتایج جستجو" hidden aria-hidden="true">
                    <p class="blog-search-status" role="status" aria-live="polite" hidden></p>
                    <div class="blog-search-list"></div>
                </div>
            </div>
        </div>
        <?php if ($catCol !== null): ?>
            <form class="filter-bar" method="get" action="<?= news_h($blogIndexPath) ?>">
                <label for="cat">دسته‌بندی</label>
                <select name="cat" id="cat" aria-label="انتخاب دسته">
                    <option value="">همه دسته‌ها</option>
                    <?php foreach ($categoryOptions as $opt): ?>
                        <?php
                        $val = $opt['value'];
                        $lab = $opt['label'];
                        if (is_numeric($lab)) {
                            $lab = news_fa_digits((string) $lab);
                        }
                        ?>
                        <option value="<?= news_h((string) $val) ?>"<?= (string) $catFilter === (string) $val ? ' selected' : '' ?>><?= news_h((string) $lab) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="page" value="1">
                <button type="submit" class="btn-apply">اعمال فیلتر</button>
                <?php if ($catFilter !== ''): ?>
                    <a class="btn-clear" href="<?= news_h($blogIndexPath) ?>">حذف فیلتر</a>
                <?php endif; ?>
            </form>
        <?php else: ?>
            <div class="meta-bar" style="margin-bottom:18px">ستون دسته یا جدول دسته شناسایی نشد؛ فیلتر غیرفعال است.</div>
        <?php endif; ?>

        <?php if ($idCol === null): ?>
            <div class="meta-bar" style="border-color:#ffe082;background:#fffde7;margin-bottom:18px">ستون شناسه پیدا نشد؛ لینک جزئیات غیرفعال است.</div>
        <?php endif; ?>

        <?php if ($latestRows === [] && $total === 0): ?>
            <p class="meta-bar" style="margin-bottom:24px">هنوز مطلبی ثبت نشده یا با این فیلتر مطابقی نیست.</p>
        <?php endif; ?>

        <div class="meta-bar">
            مجموع: <?= news_fa_digits((string) (int) $total) ?>
            <?php if ($totalPages > 0): ?>
                — صفحه <?= news_fa_digits((string) (int) $page) ?> از <?= news_fa_digits((string) (int) $totalPages) ?>
            <?php endif; ?>
            <?php if ($catFilter !== '' && $catCol !== null): ?>
                <?php $filterLabel = $categoryLabelByValue[$catFilter] ?? $catFilter; ?>
                — فیلتر: <?= news_h((string) $filterLabel) ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($err === null && $latestRows !== []): ?>
        <div class="grid" style="margin:0 0 8px;max-width:none;padding:0">
            <?php foreach ($latestRows as $r): ?>
                <?php
                $title = news_row_title($r);
                $ex = news_row_card_blurb($r, 220);
                $rowTags = array_slice(news_row_tags_list($r), 0, 5);
                $d = news_format_date($r);
                $rowCatRaw = ($catCol !== null && isset($r[$catCol])) ? trim((string) $r[$catCol]) : '';
                $rowCatDisplay = $rowCatRaw !== '' && isset($categoryLabelByValue[$rowCatRaw])
                    ? $categoryLabelByValue[$rowCatRaw]
                    : $rowCatRaw;
                if ($rowCatRaw !== '' && is_numeric((string) $rowCatDisplay)) {
                    $rowCatDisplay = news_fa_digits((string) $rowCatDisplay);
                }
                $nid = ($idCol !== null && isset($r[$idCol])) ? (int) $r[$idCol] : 0;
                $articleHref = $nid > 0 ? news_article_public_path($nid) : '#';
                ?>
                <article class="card">
                    <span class="tag"><?= news_h($rowCatRaw !== '' ? $rowCatDisplay : 'مطلب') ?></span>
                    <?php if ($d !== null): ?>
                        <div class="date"><i class="far fa-calendar-alt" style="margin-left:6px;opacity:.8"></i><?= news_h($d) ?></div>
                    <?php endif; ?>
                    <h2>
                        <?php if ($nid > 0): ?>
                            <a href="<?= news_h($articleHref) ?>"><?= news_h($title) ?></a>
                        <?php else: ?>
                            <?= news_h($title) ?>
                        <?php endif; ?>
                    </h2>
                    <?php if ($rowTags !== []): ?>
                    <div class="post-tags" aria-label="برچسب‌ها"><?php foreach ($rowTags as $tg): ?><span><?= news_h($tg) ?></span><?php endforeach; ?></div>
                    <?php endif; ?>
                    <?php if ($ex !== ''): ?><p><?= news_h($ex) ?></p><?php endif; ?>
                    <?php if ($nid > 0): ?>
                        <a class="more" href="<?= news_h($articleHref) ?>"><i class="fas fa-arrow-left"></i> مشاهده جزئیات</a>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if ($totalPages > 1): ?>
            <nav class="pager" aria-label="صفحه‌بندی مطالب">
                <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $page <= 1 ? '#' : news_h($buildPageUrl($page - 1)) ?>" aria-label="صفحه قبل"><i class="fas fa-chevron-right"></i></a>
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
                        echo '<a href="' . news_h($buildPageUrl($i)) . '">' . news_h(news_fa_digits((string) $i)) . '</a>';
                    }
                    $lastPrinted = $i;
                }
                ?>
                <a class="<?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= $page >= $totalPages ? '#' : news_h($buildPageUrl($page + 1)) ?>" aria-label="صفحه بعد"><i class="fas fa-chevron-left"></i></a>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<footer>
    <div class="footer-logo"><img src="https://panel.cybercina.co.uk//storage/logos/N0yQlVchcj4ucrQfVJwbXXB13FhWTMFccUBmWLpI.png" alt="IraniU"></div>
    <div class="footer-links">
        <a href="/">صفحه اصلی</a>
        <a href="/blog/">مقالات</a>
        <a href="/careers">فرصت‌های شغلی</a>
        <a href="/report">گزارش تخلف</a>
        <a href="/contact">تماس با ما</a>
        <a href="/terms">شرایط و ضوابط</a>
        <a href="/privacy">حریم خصوصی</a>
    </div>
    <div class="social-links">
        <a href="https://instagram.com/iraniu.uk" target="_blank" rel="noopener" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
        <a href="https://t.me/iraniu_uk" target="_blank" rel="noopener" aria-label="Telegram"><i class="fab fa-telegram-plane"></i></a>
        <a href="mailto:hello@iraniu.uk" aria-label="Email"><i class="fas fa-envelope"></i></a>
    </div>
    <p style="margin-top:20px;opacity:0.75;font-size:0.85rem;">© IraniU — پلتفرم دیجیتال ایرانیان بریتانیا</p>
</footer>
<script src="/assets/js/copy-guard.js" defer></script>
<script src="/blog/blog-search.js" defer></script>
</body>
</html>
