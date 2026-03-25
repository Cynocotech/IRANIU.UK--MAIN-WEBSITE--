<?php
declare(strict_types=1);

/**
 * صفحه مقالات — مطالب از tbl_news (۶ تا در هر صفحه) + راهنماهای ثابت در صفحهٔ اول
 * /blog/?page=2
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

    $t = str_replace('`', '``', NEWS_TABLE);
    $countSql = 'SELECT COUNT(*) FROM `' . $t . '` WHERE 1=1' . $extraWhere;
    $total = (int) $pdo->query($countSql)->fetchColumn();
    $totalPages = $total > 0 ? (int) ceil($total / PER_PAGE) : 0;
    if ($totalPages > 0 && $page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * PER_PAGE;

    $sql = 'SELECT * FROM `' . $t . '` WHERE 1=1' . $extraWhere
        . ' ORDER BY ' . $orderQuoted . ' DESC LIMIT :lim OFFSET :off';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':lim', PER_PAGE, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $latestRows = $stmt->fetchAll();
} catch (Throwable $e) {
    $err = 'اتصال به پایگاه داده برقرار نشد یا جدول «tbl_news» در دسترس نیست. تنظیمات .env را بررسی کنید.';
}

$blogIndexPath = '/blog/';
$buildPageUrl = static function (int $p) use ($blogIndexPath): string {
    if ($p <= 1) {
        return $blogIndexPath;
    }
    return $blogIndexPath . '?page=' . $p;
};

$siteUrl = 'https://iraniu.uk';
$ogImageDefault = 'https://panel.cybercina.co.uk/storage/logos/N0yQlVchcj4ucrQfVJwbXXB13FhWTMFccUBmWLpI.png';
$canonical = $siteUrl . $blogIndexPath . ($page > 1 ? ('?page=' . $page) : '');

$seoPageTitle = 'مقالات و راهنما | ایرانیان بریتانیا | IraniU';
if ($err === null && $page > 1 && $totalPages > 0) {
    $seoPageTitle = 'مقالات — صفحه ' . news_fa_digits((string) $page) . ' از ' . news_fa_digits((string) $totalPages) . ' | IraniU';
}

$seoDescription = 'مقالات فارسی درباره زندگی، کار، مسکن، درمان و بانک در بریتانیا؛ در هر صفحه ' . (string) PER_PAGE . ' مطلب از پایگاه داده IraniU.';
if ($err === null) {
    if ($totalPages > 0) {
        $seoDescription .= ' صفحه ' . $page . ' از ' . $totalPages . ' — مجموع ' . $total . ' مطلب.';
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
    <meta name="keywords" content="راهنمای ایرانیان UK, زندگی در بریتانیا, کار فارسی زبانان, NHS, اجاره لندن, IraniU blog, اخبار فارسی">
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
        @font-face { font-family: 'Yekan Bakh'; src: url('../fonts/YekanBakh-Regular.woff') format('woff'); font-weight: normal; }
        @font-face { font-family: 'Yekan Bakh'; src: url('../fonts/YekanBakh-Bold.woff') format('woff'); font-weight: bold; }
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
        .breadcrumb-wrap { max-width: 1100px; margin: 0 auto; padding: 14px 20px 2px; }
        .breadcrumb-nav { display: inline-flex; max-width: 100%; }
        .breadcrumb-nav ol { display: flex; flex-wrap: wrap; align-items: center; gap: 0; list-style: none; margin: 0; padding: 9px 20px 9px 16px; background: linear-gradient(145deg, #faf6fc 0%, #fff 55%, #fdf8ff 100%); border: 1px solid rgba(116, 32, 139, 0.14); border-radius: 999px; box-shadow: 0 2px 14px rgba(58, 11, 71, 0.07), inset 0 1px 0 rgba(255, 255, 255, 0.9); }
        .breadcrumb-nav li { display: flex; align-items: center; font-size: 0.8125rem; color: #5a5a5a; }
        .breadcrumb-nav li + li::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: linear-gradient(135deg, var(--brand-purple), rgba(116, 32, 139, 0.5)); margin: 0 12px; flex-shrink: 0; opacity: 0.9; }
        .breadcrumb-nav a { color: var(--brand-purple); font-weight: 700; transition: color 0.2s; display: inline-flex; align-items: center; }
        .breadcrumb-nav a:hover { color: var(--dark-purple); }
        .breadcrumb-nav .bc-home { font-size: 0.95rem; line-height: 1; }
        .breadcrumb-nav li.breadcrumb-current { color: var(--dark-purple); font-weight: 700; }
        .hero { background: linear-gradient(135deg, var(--dark-purple), var(--brand-purple)); color: #fff; padding: 56px 20px; text-align: center; }
        .hero h1 { font-size: 2rem; margin-bottom: 12px; font-weight: 900; }
        .hero p { max-width: 640px; margin: 0 auto; opacity: 0.95; font-size: 1.05rem; }
        .section-block { max-width: 1100px; margin: 0 auto; padding: 0 20px; }
        .section-heading { font-size: 1.35rem; color: var(--dark-purple); font-weight: 900; margin: 28px 0 16px; padding-bottom: 8px; border-bottom: 2px solid rgba(116, 32, 139, 0.2); }
        .section-lead { color: #555; font-size: 0.95rem; margin: -8px 0 18px; }
        .alert { background: #ffebee; color: #b71c1c; padding: 16px 18px; border-radius: 14px; margin-bottom: 18px; border: 1px solid #ffcdd2; text-align: right; }
        .all-news-link { text-align: center; margin: 8px 0 36px; }
        .all-news-link a { color: var(--brand-purple); font-weight: 800; font-size: 0.98rem; }
        .all-news-link a:hover { color: var(--dark-purple); }
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
        .section-block .grid { margin: 0 auto 72px; padding: 0; }
        .card { background: #fff; border-radius: 20px; padding: 26px; border: 1px solid #eee; box-shadow: 0 12px 36px rgba(0,0,0,0.06); transition: 0.25s; }
        .card:hover { border-color: var(--brand-purple); transform: translateY(-4px); }
        .card .tag { display: inline-block; background: #f3e8f5; color: var(--dark-purple); font-size: 0.78rem; padding: 4px 12px; border-radius: 20px; margin-bottom: 12px; font-weight: bold; }
        .card .tag.db { background: linear-gradient(135deg, #ede7f6, #f3e8f5); }
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
            <li class="breadcrumb-current" aria-current="page">مقالات</li>
        </ol>
    </nav>
</div>

<section class="hero">
    <h1>راهنما برای جامعه ایرانی بریتانیا</h1>
    <p>در این بخش دربارهٔ چالش‌های رایج زندگی، کار، مسکن، درمان و امور مالی در UK می‌خوانید؛ مطالب پایگاه داده در هر صفحه <?= news_h(news_fa_digits((string) PER_PAGE)) ?> مورد نمایش داده می‌شود. برای <strong>فیلتر دسته‌بندی</strong> به بخش اخبار بروید.</p>
</section>

<div class="section-block">
    <h2 class="section-heading">مطالب پایگاه داده</h2>
    <p class="section-lead">در هر صفحه <?= news_h(news_fa_digits((string) PER_PAGE)) ?> مطلب؛ صفحه‌بندی زیر برای بقیه مطالب است. فیلتر بر اساس دسته فقط در بخش اخبار.</p>
    <?php if ($err !== null): ?>
        <div class="alert" role="alert"><?= news_h($err) ?></div>
    <?php elseif ($latestRows === [] && $total === 0): ?>
        <p class="section-lead" style="margin-bottom:24px">هنوز مطلبی در پایگاه داده ثبت نشده است.</p>
    <?php endif; ?>
    <?php if ($err === null): ?>
        <div class="meta-bar">
            مجموع مطالب: <?= news_fa_digits((string) (int) $total) ?>
            <?php if ($totalPages > 0): ?>
                — صفحه <?= news_fa_digits((string) (int) $page) ?> از <?= news_fa_digits((string) (int) $totalPages) ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($err === null && $latestRows !== []): ?>
        <div class="grid" style="margin:0 0 8px;max-width:none;padding:0">
            <?php foreach ($latestRows as $r): ?>
                <?php
                $title = news_row_title($r);
                $ex = news_row_excerpt($r);
                $d = news_format_date($r);
                $rowCatRaw = ($catCol !== null && isset($r[$catCol])) ? trim((string) $r[$catCol]) : '';
                $rowCatDisplay = $rowCatRaw !== '' && isset($categoryLabelByValue[$rowCatRaw])
                    ? $categoryLabelByValue[$rowCatRaw]
                    : $rowCatRaw;
                if ($rowCatRaw !== '' && is_numeric((string) $rowCatDisplay)) {
                    $rowCatDisplay = news_fa_digits((string) $rowCatDisplay);
                }
                $nid = ($idCol !== null && isset($r[$idCol])) ? (int) $r[$idCol] : 0;
                $articleHref = $nid > 0 ? ('/blog/article?id=' . $nid) : '#';
                ?>
                <article class="card">
                    <span class="tag db"><?= news_h($rowCatRaw !== '' ? $rowCatDisplay : 'مطلب') ?></span>
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
    <div class="all-news-link">
        <a href="/blog/news"><i class="fas fa-filter" style="margin-left:8px"></i> اخبار با فیلتر دسته‌بندی</a>
    </div>
</div>

<?php if ($page === 1): ?>
<div class="section-block">
    <h2 class="section-heading">راهنماهای موضوعی</h2>
    <p class="section-lead">مقالات ثابت (HTML) درباره کار، مسکن، NHS و مالیات.</p>
</div>

<div class="grid" style="margin-top:0;margin-bottom:72px">
    <article class="card">
        <span class="tag">کار و حقوق</span>
        <h2>کار، قرارداد و حقوق پایه در بریتانیا</h2>
        <p>از قرارداد کاری و حداقل حقوق تا مرخصی استعلاجی و اهمیت ثبت ساعات کاری — نکاتی که بسیاری از تازه‌مهاجران با آن مواجه می‌شوند.</p>
        <a class="more" href="/blog/mohajerat-kar-uk"><i class="fas fa-arrow-left"></i> ادامه مطلب</a>
    </article>
    <article class="card">
        <span class="tag">مسکن</span>
        <h2>مسکن و اجاره: چالش‌های رایج برای خانواده‌های فارسی‌زبان</h2>
        <p>Right to Rent، ودیعه، قرارداد اجاره و کلاهبرداری‌های رایج — بدون جایگزین مشاوره تخصصی، اما با چک‌لیستی عملی.</p>
        <a class="more" href="/blog/masaken-ajare-uk"><i class="fas fa-arrow-left"></i> ادامه مطلب</a>
    </article>
    <article class="card">
        <span class="tag">سلامت</span>
        <h2>NHS و دسترسی به درمان: نکات اولیه</h2>
        <p>ثبت‌نام پزشک عمومی، A&amp;E، نسخه و بیمه تکمیلی — مسیری که برای ناآشنایان با سیستم می‌تواند گیج‌کننده باشد.</p>
        <a class="more" href="/blog/nhs-darman-uk"><i class="fas fa-arrow-left"></i> ادامه مطلب</a>
    </article>
    <article class="card">
        <span class="tag">مالی</span>
        <h2>بانک، کد ملیتی و مالیات: شروعی ساده</h2>
        <p>افتتاح حساب، National Insurance، کد مالیاتی و اهمیت نگه‌داری مدارک برای اظهارنامه — خطاهایی که بهتر است از ابتدا از آن‌ها دوری کنید.</p>
        <a class="more" href="/blog/bank-maliyat-uk"><i class="fas fa-arrow-left"></i> ادامه مطلب</a>
    </article>
    <article class="card">
        <span class="tag">ایرانیو</span>
        <h2>اپلیکیشن ایرانیو چه کمکی به شما می‌کند؟</h2>
        <p>تجمیع نیازمندی‌ها، رادیو فارسی، پیوندهای مهم و ارتباط با جامعه — در یک اپ برای ایرانیان بریتانیا.</p>
        <a class="more" href="/blog/iraniu-app-rahnamay"><i class="fas fa-arrow-left"></i> ادامه مطلب</a>
    </article>
</div>
<?php endif; ?>

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
        <a href="https://instagram.com/iraniu.uk" target="_blank" rel="noopener" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
        <a href="https://t.me/iraniu_uk" target="_blank" rel="noopener" aria-label="Telegram"><i class="fab fa-telegram-plane"></i></a>
        <a href="mailto:hello@iraniu.uk" aria-label="Email"><i class="fas fa-envelope"></i></a>
    </div>
    <p style="margin-top:20px;opacity:0.75;font-size:0.85rem;">© IraniU — پلتفرم دیجیتال ایرانیان بریتانیا</p>
</footer>
</body>
</html>
