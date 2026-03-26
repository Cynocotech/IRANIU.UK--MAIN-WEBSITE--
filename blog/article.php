<?php
declare(strict_types=1);

/**
 * جزئیات خبر — حدود ۳۰٪ متن واضح، ۷۰٪ تار + دعوت به دانلود اپ
 * /blog/article/{signed-token} — ?id= قدیمی با ۳۰۱ به همین شکل منتقل می‌شود
 */
require __DIR__ . '/../load_env.php';
require __DIR__ . '/../includes/app_db.php';
require __DIR__ . '/../includes/news_tbl_helpers.php';

const NEWS_TABLE = 'tbl_news';

$siteUrl = rtrim(trim((string)($_ENV['SITE_URL'] ?? getenv('SITE_URL') ?: 'https://iraniu.uk')), '/');
if ($siteUrl === '') {
    $siteUrl = 'https://iraniu.uk';
}

$tParam = isset($_GET['t']) ? trim((string) $_GET['t']) : '';
$idFromGet = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$id = 0;
if ($tParam !== '') {
    $id = article_ref_decode($tParam) ?? 0;
} elseif ($idFromGet !== false && $idFromGet !== null && $idFromGet >= 1) {
    $tok = article_ref_encode((int) $idFromGet);
    if ($tok !== '') {
        header('Location: ' . $siteUrl . news_article_public_path((int) $idFromGet), true, 301);
        exit;
    }
}

$err = null;
$row = null;
$split = ['visible' => '', 'rest' => '', 'show_gate' => false];
$title = '';
$d = null;

try {
    if ($id < 1) {
        throw new RuntimeException('شناسه نامعتبر است.');
    }
    $pdo = app_pdo();
    $cols = news_table_columns($pdo, NEWS_TABLE);
    $idCol = news_id_column($cols);
    if ($idCol === null) {
        throw new RuntimeException('ستون شناسه پیدا نشد.');
    }
    $del = news_deleted_clause($cols);
    $idQ = '`' . str_replace('`', '``', $idCol) . '`';
    $sql = 'SELECT * FROM `' . str_replace('`', '``', NEWS_TABLE) . '` WHERE ' . $idQ . ' = :id' . $del . ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        $row = null;
        throw new RuntimeException('این خبر پیدا نشد یا حذف شده است.');
    }
    $title = news_row_title($row);
    $d = news_format_date($row);
    $body = news_row_body_raw($row);
    $split = news_split_teaser_plain($body, 0.3);
} catch (Throwable $e) {
    $err = $e->getMessage();
}

$appIos = trim((string)($_ENV['APP_STORE_URL'] ?? getenv('APP_STORE_URL') ?: '#'));
$appAndroid = trim((string)($_ENV['GOOGLE_PLAY_URL'] ?? getenv('GOOGLE_PLAY_URL') ?: '#'));

$ogImageDefault = 'https://panel.cybercina.co.uk/storage/logos/N0yQlVchcj4ucrQfVJwbXXB13FhWTMFccUBmWLpI.png';
$articleCanonical = ($row !== null && $id >= 1)
    ? ($siteUrl . news_article_public_path($id))
    : ($siteUrl . '/blog/news');
$seoPageTitle = ($row !== null) ? ($title . ' | IraniU') : 'خبر | IraniU';
$seoDescription = ($row !== null) ? news_row_seo_description($row, 158) : 'IraniU — Persian-language news and guides for Iranians in the UK.';
if ($row !== null && $seoDescription === '') {
    $seoDescription = 'خبر و راهنما برای جامعه ایرانیان بریتانیا — IraniU.';
}
$seoKeywords = ($row !== null) ? news_row_meta_keywords_combined($row) : 'IraniU, Persian UK, iraniu.uk';
$articleTags = ($row !== null) ? news_row_seo_tags($row) : [];
$dateIso = ($row !== null) ? news_row_date_iso8601($row) : null;
$robotsArticle = ($row === null) ? 'noindex, follow' : 'index, follow, max-image-preview:large';

$jsonLdArticle = null;
$jsonLdBreadcrumb = null;
if ($row !== null) {
    $jsonLdArticle = [
        '@context' => 'https://schema.org',
        '@type' => 'NewsArticle',
        'headline' => $title,
        'description' => $seoDescription,
        'inLanguage' => 'fa-IR',
        'author' => ['@type' => 'Organization', 'name' => 'IraniU'],
        'publisher' => [
            '@type' => 'Organization',
            'name' => 'IraniU',
            'logo' => ['@type' => 'ImageObject', 'url' => $ogImageDefault],
        ],
        'image' => [$ogImageDefault],
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $articleCanonical],
    ];
    if ($dateIso !== null) {
        $jsonLdArticle['datePublished'] = $dateIso;
        $jsonLdArticle['dateModified'] = $dateIso;
    }
    if ($articleTags !== []) {
        $jsonLdArticle['keywords'] = implode(', ', $articleTags);
        $jsonLdArticle['about'] = array_map(static function (string $name): array {
            return ['@type' => 'Thing', 'name' => $name];
        }, array_slice($articleTags, 0, 16));
    }
    $jsonLdBreadcrumb = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'صفحه اصلی', 'item' => $siteUrl . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'مقالات', 'item' => $siteUrl . '/blog/'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => 'فهرست مطالب', 'item' => $siteUrl . '/blog/news'],
            ['@type' => 'ListItem', 'position' => 4, 'name' => $title, 'item' => $articleCanonical],
        ],
    ];
}

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
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
    <meta name="keywords" content="<?= news_h($seoKeywords) ?>">
    <meta name="author" content="IraniU">
    <meta name="robots" content="<?= news_h($robotsArticle) ?>">
    <link rel="canonical" href="<?= news_h($articleCanonical) ?>">
    <meta property="og:site_name" content="IraniU">
    <meta property="og:locale" content="fa_IR">
    <meta property="og:type" content="<?= $row !== null ? 'article' : 'website' ?>">
    <meta property="og:url" content="<?= news_h($articleCanonical) ?>">
    <meta property="og:title" content="<?= news_h($seoPageTitle) ?>">
    <meta property="og:description" content="<?= news_h($seoDescription) ?>">
    <meta property="og:image" content="<?= news_h($ogImageDefault) ?>">
    <meta property="og:image:alt" content="IraniU">
    <?php if ($dateIso !== null): ?>
    <meta property="article:published_time" content="<?= news_h($dateIso) ?>">
    <meta property="article:modified_time" content="<?= news_h($dateIso) ?>">
    <?php endif; ?>
    <?php foreach ($articleTags as $tg): ?>
    <meta property="article:tag" content="<?= news_h($tg) ?>">
    <?php endforeach; ?>
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= news_h($seoPageTitle) ?>">
    <meta name="twitter:description" content="<?= news_h($seoDescription) ?>">
    <meta name="twitter:image" content="<?= news_h($ogImageDefault) ?>">
    <?php if ($jsonLdArticle !== null): ?>
    <script type="application/ld+json"><?= news_json_encode_ld($jsonLdArticle) ?></script>
    <?php endif; ?>
    <?php if ($jsonLdBreadcrumb !== null): ?>
    <script type="application/ld+json"><?= news_json_encode_ld($jsonLdBreadcrumb) ?></script>
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
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Yekan Bakh', Tahoma, sans-serif; }
        a { text-decoration: none; color: var(--brand-purple); font-weight: 700; }
        a:hover { color: var(--dark-purple); }
        body {
            background: #fdfdfd; color: #1a1a1a; line-height: 1.85;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
        }
        header { background: var(--dark-purple); padding: 12px 0; position: sticky; top: 0; z-index: 1000; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-container { max-width: 900px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; padding: 0 20px; }
        .logo img { height: 48px; display: block; }
        .desktop-nav a { color: #fff; font-weight: bold; margin-right: 18px; font-size: 0.88rem; opacity: 0.92; }
        .desktop-nav a:hover { color: var(--brand-purple); }
        .hamburger { display: none; color: #fff; font-size: 1.4rem; cursor: pointer; }
        .mobile-nav { display: none; position: fixed; top: 70px; right: 0; left: 0; background: var(--dark-purple); flex-direction: column; align-items: center; padding: 24px 0; z-index: 999; }
        .mobile-nav.open { display: flex; }
        .mobile-nav a { color: #fff; font-weight: bold; margin: 10px 0; padding-bottom: 10px; width: 85%; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .breadcrumb-strip {
            background: linear-gradient(180deg, #faf7fc 0%, #f3eef8 100%);
            border-bottom: 1px solid rgba(116, 32, 139, 0.12);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.85);
        }
        .breadcrumb-inner { max-width: 900px; margin: 0 auto; padding: 14px 20px 16px; }
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
        .breadcrumb-nav li.breadcrumb-current {
            color: var(--dark-purple); font-weight: 800; max-width: 100%; flex: 1 1 200px; min-width: 0;
            padding: 4px 2px; line-height: 1.45;
        }
        .breadcrumb-nav li.breadcrumb-current span {
            display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 3; line-clamp: 3; overflow: hidden;
        }
        @media (min-width: 640px) {
            .breadcrumb-nav li.breadcrumb-current { max-width: 460px; }
        }
        .wrap {
            max-width: 800px; width: 100%; margin: 0 auto; padding: 28px 20px 48px;
            flex: 1 0 auto;
        }
        .article-card { background: #fff; border-radius: 22px; padding: 32px 28px; border: 1px solid #eee; box-shadow: 0 14px 40px rgba(0,0,0,0.07); }
        .article-card .date { font-size: 0.85rem; color: #888; margin-bottom: 12px; }
        .article-card h1 { font-size: 1.55rem; color: var(--dark-purple); line-height: 1.4; margin-bottom: 14px; font-weight: 900; }
        .article-tags-wrap { margin-bottom: 20px; }
        .article-tags-title { font-size: 0.82rem; font-weight: 800; color: var(--dark-purple); margin-bottom: 10px; letter-spacing: 0.02em; }
        .article-tags { display: flex; flex-wrap: wrap; gap: 8px; }
        .article-tags .tag-chip { display: inline-block; font-size: 0.78rem; background: #ede7f6; color: var(--dark-purple); padding: 5px 12px; border-radius: 999px; font-weight: 800; }
        .body-visible { font-size: 1.02rem; color: #222; text-align: justify; white-space: pre-wrap; word-break: break-word; }
        .blur-stack { margin-top: 22px; border-radius: 14px; overflow: hidden; border: 1px solid rgba(116, 32, 139, 0.12); background: #fff; }
        .cta-head {
            padding: 22px 20px 20px; text-align: center;
            background: linear-gradient(180deg, #f5f0fa 0%, #fdfdfd 100%);
            border-bottom: 1px solid rgba(116, 32, 139, 0.14);
        }
        .cta-head p { color: var(--dark-purple); font-weight: 800; font-size: 1.08rem; margin: 0 0 12px; line-height: 1.55; max-width: 420px; margin-left: auto; margin-right: auto; }
        .cta-head .sub { font-weight: 600; font-size: 0.92rem; color: #555; margin-bottom: 18px; }
        .body-blur-wrap { position: relative; }
        .body-blur {
            font-size: 1.02rem; color: #333; text-align: justify; filter: blur(12px); user-select: none; pointer-events: none;
            white-space: pre-wrap; word-break: break-word; padding: 16px 14px 48px; opacity: 0.82;
            min-height: 120px;
        }
        .blur-bottom-fade {
            position: absolute; left: 0; right: 0; bottom: 0; height: 72px;
            background: linear-gradient(180deg, transparent, rgba(253,253,253, 0.96));
            pointer-events: none;
        }
        .store-btns { display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; }
        .store-btns a {
            display: inline-flex; align-items: center; gap: 10px; background: #111; color: #fff !important;
            padding: 12px 22px; border-radius: 12px; font-size: 0.9rem; font-weight: 700;
        }
        .store-btns a:hover { filter: brightness(1.12); color: #fff !important; }
        .back-link { display: inline-flex; align-items: center; gap: 8px; margin-top: 28px; font-size: 0.95rem; }
        .alert { background: #ffebee; color: #b71c1c; padding: 20px; border-radius: 14px; border: 1px solid #ffcdd2; }
        footer {
            background: var(--dark-purple); color: #fff; padding: 40px 20px 48px; text-align: center;
            margin-top: auto; flex-shrink: 0; width: 100%;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }
        .footer-inner { max-width: 900px; margin: 0 auto; }
        .footer-logo img { height: 56px; margin-bottom: 20px; display: inline-block; }
        .footer-links {
            display: flex; flex-wrap: wrap; justify-content: center; align-items: center;
            gap: 10px 18px; margin-bottom: 22px;
        }
        .footer-links a {
            color: #fff; font-size: 0.84rem; font-weight: 600; opacity: 0.82;
            padding: 4px 0; border-bottom: 1px solid transparent;
        }
        .footer-links a:hover { opacity: 1; border-bottom-color: rgba(255, 255, 255, 0.35); }
        .social-links { display: flex; justify-content: center; gap: 14px; margin-top: 8px; }
        .social-links a {
            color: #fff; width: 42px; height: 42px; display: inline-flex; align-items: center; justify-content: center;
            border-radius: 50%; background: rgba(255, 255, 255, 0.12); font-size: 1.05rem;
            transition: background 0.2s, transform 0.2s;
        }
        .social-links a:hover { background: rgba(255, 255, 255, 0.22); transform: translateY(-2px); }
        .footer-copy { margin-top: 22px; opacity: 0.78; font-size: 0.84rem; line-height: 1.6; }
        @media (max-width: 992px) { .desktop-nav { display: none; } .hamburger { display: block; } }
    </style>
    <link rel="stylesheet" href="/assets/css/content-protection.css">
    <noscript>
    <style>
    #page-loader{display:none!important}
    body{margin:0!important;overflow:hidden!important}
    .iraniu-noscript-wall{
      position:fixed!important;
      top:0!important;left:0!important;right:0!important;bottom:0!important;
      width:100%!important;height:100%!important;
      min-height:100vh!important;min-height:100dvh!important;
      margin:0!important;padding:clamp(24px,5vw,48px)!important;box-sizing:border-box!important;
      z-index:2147483647!important;background:#fff!important;
      display:flex!important;align-items:center!important;justify-content:center!important;
      text-align:center!important;direction:rtl!important;font-family:Tahoma,Arial,sans-serif!important;
    }
    .iraniu-noscript-inner{max-width:36rem!important;width:100%!important}
    .iraniu-noscript-title{
      margin:0!important;font-size:clamp(1.2rem,4vw,1.65rem)!important;font-weight:800!important;
      line-height:1.65!important;color:#3a0b47!important
    }
    </style>
    </noscript>
</head>
<body>
<noscript>
<div class="iraniu-noscript-wall" role="alert" aria-live="polite">
  <div class="iraniu-noscript-inner">
    <p class="iraniu-noscript-title">برای مشاهده و استفاده از این سایت، جاوااسکریپت را در مرورگر فعال کنید.</p>
  </div>
</div>
</noscript>

<header>
    <div class="nav-container">
        <a href="/" class="logo"><img src="https://panel.cybercina.co.uk//storage/logos/N0yQlVchcj4ucrQfVJwbXXB13FhWTMFccUBmWLpI.png" alt="IraniU"></a>
        <nav class="desktop-nav">
            <a href="/">صفحه اصلی</a>
            <a href="/blog/">مقالات</a>
            <a href="/blog/news">اخبار</a>
            <a href="/contact">تماس</a>
        </nav>
        <div class="hamburger" onclick="document.getElementById('mnav').classList.toggle('open')"><i class="fas fa-bars"></i></div>
    </div>
    <nav class="mobile-nav" id="mnav">
        <a href="/">صفحه اصلی</a>
        <a href="/blog/">مقالات</a>
        <a href="/blog/news">اخبار</a>
        <a href="/contact">تماس</a>
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
                <li>
                    <a href="/blog/">مقالات</a>
                </li>
                <li class="bc-sep" aria-hidden="true">/</li>
                <li>
                    <a href="/blog/news">فهرست مطالب</a>
                </li>
                <li class="bc-sep" aria-hidden="true">/</li>
                <li class="breadcrumb-current" aria-current="page">
                    <span><?php
                        if (!$row) {
                            echo news_h('جزئیات');
                        } else {
                            echo news_h($title);
                        }
                    ?></span>
                </li>
            </ol>
        </nav>
    </div>
</div>

<div class="wrap">
    <?php if ($err !== null): ?>
        <div class="alert" role="alert"><?= news_h($err) ?></div>
        <a class="back-link" href="/blog/news"><i class="fas fa-arrow-right"></i> بازگشت به فهرست اخبار</a>
    <?php else: ?>
        <article class="article-card" itemscope itemtype="https://schema.org/NewsArticle">
            <meta itemprop="description" content="<?= news_h($seoDescription) ?>">
            <?php if ($articleTags !== []): ?>
            <meta itemprop="keywords" content="<?= news_h(implode(', ', $articleTags)) ?>">
            <?php endif; ?>
            <?php if ($d !== null): ?>
            <div class="date"><i class="far fa-calendar-alt" style="margin-left:8px"></i><?php if ($dateIso !== null): ?><time itemprop="datePublished" datetime="<?= news_h($dateIso) ?>"><?= news_h($d) ?></time><?php else: ?><?= news_h($d) ?><?php endif; ?></div>
            <?php endif; ?>
            <h1 itemprop="headline"><?= news_h($title) ?></h1>
            <?php if ($articleTags !== []): ?>
            <div class="article-tags-wrap">
                <p class="article-tags-title">برچسب‌ها</p>
                <div class="article-tags" aria-label="برچسب‌های مطلب">
                    <?php foreach ($articleTags as $tg): ?>
                        <span class="tag-chip"><?= news_h($tg) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="body-visible" itemprop="articleBody"><?= nl2br(news_h($split['visible'])) ?></div>
            <?php if ($split['show_gate']): ?>
                <div class="blur-stack" aria-hidden="false">
                    <div class="cta-head">
                        <p>برای خواندن ادامهٔ این مطلب، اپلیکیشن ایرانیو را دانلود کنید</p>
                        <p class="sub">متن کامل خبر فقط در اپلیکیشن در دسترس است.</p>
                        <div class="store-btns">
                            <a href="<?= news_h($appIos) ?>" rel="noopener noreferrer"><i class="fab fa-apple fa-lg"></i> App Store</a>
                            <a href="<?= news_h($appAndroid) ?>" rel="noopener noreferrer"><i class="fab fa-google-play fa-lg"></i> Google Play</a>
                        </div>
                    </div>
                    <div class="body-blur-wrap">
                        <div class="body-blur"><?= nl2br(news_h($split['rest'])) ?></div>
                        <div class="blur-bottom-fade" aria-hidden="true"></div>
                    </div>
                </div>
            <?php endif; ?>
        </article>
        <a class="back-link" href="/blog/news"><i class="fas fa-arrow-right"></i> بازگشت به فهرست اخبار</a>
    <?php endif; ?>
</div>

<footer>
    <div class="footer-inner">
        <div class="footer-logo"><img src="https://panel.cybercina.co.uk//storage/logos/N0yQlVchcj4ucrQfVJwbXXB13FhWTMFccUBmWLpI.png" alt="IraniU"></div>
        <div class="footer-links">
            <a href="/">صفحه اصلی</a>
            <a href="/blog/">مقالات</a>
            <a href="/blog/news">فهرست مطالب</a>
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
        <p class="footer-copy">© IraniU — پلتفرم دیجیتال ایرانیان بریتانیا</p>
    </div>
</footer>
<script src="/assets/js/copy-guard.js" defer></script>
</body>
</html>
