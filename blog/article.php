<?php
declare(strict_types=1);

/**
 * جزئیات خبر — نیمی از متن واضح، نیمی تار + دعوت به دانلود اپ
 * /blog/article?id=123
 */
require __DIR__ . '/../load_env.php';
require __DIR__ . '/../includes/app_db.php';
require __DIR__ . '/../includes/news_tbl_helpers.php';

const NEWS_TABLE = 'tbl_news';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

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
    $split = news_split_teaser_plain($body, 0.5);
} catch (Throwable $e) {
    $err = $e->getMessage();
}

$appIos = trim((string)($_ENV['APP_STORE_URL'] ?? getenv('APP_STORE_URL') ?: '#'));
$appAndroid = trim((string)($_ENV['GOOGLE_PLAY_URL'] ?? getenv('GOOGLE_PLAY_URL') ?: '#'));

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
    <title><?= $row ? news_h($title) . ' | IraniU' : 'خبر | IraniU' ?></title>
    <meta name="description" content="<?= news_h($row !== null ? news_row_excerpt($row, 160) : 'جزئیات خبر ایرانیو') ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://iraniu.uk/blog/article?id=<?= (int) $id ?>">
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
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Yekan Bakh', Tahoma, sans-serif; }
        a { text-decoration: none; color: var(--brand-purple); font-weight: 700; }
        a:hover { color: var(--dark-purple); }
        body { background: #fdfdfd; color: #1a1a1a; line-height: 1.85; }
        header { background: var(--dark-purple); padding: 12px 0; position: sticky; top: 0; z-index: 1000; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-container { max-width: 900px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; padding: 0 20px; }
        .logo img { height: 48px; display: block; }
        .desktop-nav a { color: #fff; font-weight: bold; margin-right: 18px; font-size: 0.88rem; opacity: 0.92; }
        .desktop-nav a:hover { color: var(--brand-purple); }
        .hamburger { display: none; color: #fff; font-size: 1.4rem; cursor: pointer; }
        .mobile-nav { display: none; position: fixed; top: 70px; right: 0; left: 0; background: var(--dark-purple); flex-direction: column; align-items: center; padding: 24px 0; z-index: 999; }
        .mobile-nav.open { display: flex; }
        .mobile-nav a { color: #fff; font-weight: bold; margin: 10px 0; padding-bottom: 10px; width: 85%; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .breadcrumb-wrap { max-width: 900px; margin: 0 auto; padding: 14px 20px 2px; }
        .breadcrumb-nav { display: inline-flex; max-width: 100%; }
        .breadcrumb-nav ol { display: flex; flex-wrap: wrap; align-items: center; gap: 0; list-style: none; margin: 0; padding: 9px 20px 9px 16px; background: linear-gradient(145deg, #faf6fc 0%, #fff 55%, #fdf8ff 100%); border: 1px solid rgba(116, 32, 139, 0.14); border-radius: 999px; box-shadow: 0 2px 14px rgba(58, 11, 71, 0.07), inset 0 1px 0 rgba(255, 255, 255, 0.9); }
        .breadcrumb-nav li { display: flex; align-items: center; font-size: 0.8125rem; color: #5a5a5a; }
        .breadcrumb-nav li + li::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: linear-gradient(135deg, var(--brand-purple), rgba(116, 32, 139, 0.5)); margin: 0 12px; flex-shrink: 0; opacity: 0.9; }
        .breadcrumb-nav a { color: var(--brand-purple); font-weight: 700; display: inline-flex; align-items: center; }
        .breadcrumb-nav .bc-home { font-size: 0.95rem; line-height: 1; }
        .breadcrumb-nav li.breadcrumb-current { color: var(--dark-purple); font-weight: 700; max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .wrap { max-width: 800px; margin: 0 auto 64px; padding: 28px 20px 0; }
        .article-card { background: #fff; border-radius: 22px; padding: 32px 28px; border: 1px solid #eee; box-shadow: 0 14px 40px rgba(0,0,0,0.07); }
        .article-card .date { font-size: 0.85rem; color: #888; margin-bottom: 12px; }
        .article-card h1 { font-size: 1.55rem; color: var(--dark-purple); line-height: 1.4; margin-bottom: 20px; font-weight: 900; }
        .body-visible { font-size: 1.02rem; color: #222; text-align: justify; white-space: pre-wrap; word-break: break-word; }
        .blur-stack { position: relative; margin-top: 22px; min-height: 140px; border-radius: 14px; overflow: hidden; }
        .body-blur { font-size: 1.02rem; color: #333; text-align: justify; filter: blur(8px); user-select: none; pointer-events: none; white-space: pre-wrap; word-break: break-word; padding: 8px 0 48px; opacity: 0.88; }
        .cta-overlay {
            position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center;
            text-align: center; padding: 28px 20px;
            background: linear-gradient(180deg, rgba(253,253,253,0.05) 0%, rgba(253,253,253,0.75) 28%, rgba(253,253,253,0.96) 55%, #fdfdfd 100%);
        }
        .cta-overlay p { color: var(--dark-purple); font-weight: 800; font-size: 1.1rem; margin-bottom: 18px; line-height: 1.6; max-width: 340px; }
        .cta-overlay .sub { font-weight: 600; font-size: 0.92rem; color: #555; margin-bottom: 22px; }
        .store-btns { display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; }
        .store-btns a {
            display: inline-flex; align-items: center; gap: 10px; background: #111; color: #fff !important;
            padding: 12px 22px; border-radius: 12px; font-size: 0.9rem; font-weight: 700;
        }
        .store-btns a:hover { filter: brightness(1.12); color: #fff !important; }
        .back-link { display: inline-flex; align-items: center; gap: 8px; margin-top: 28px; font-size: 0.95rem; }
        .alert { background: #ffebee; color: #b71c1c; padding: 20px; border-radius: 14px; border: 1px solid #ffcdd2; }
        footer { background: var(--dark-purple); color: #fff; padding: 44px 20px; text-align: center; margin-top: 48px; }
        .footer-links a { color: #fff; margin: 0 10px; font-size: 0.82rem; opacity: 0.75; }
        .footer-logo img { height: 52px; margin-bottom: 14px; }
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

<div class="breadcrumb-wrap">
    <nav class="breadcrumb-nav" aria-label="مسیر صفحه">
        <ol>
            <li><a href="/" aria-label="صفحه اصلی"><i class="fas fa-house bc-home" aria-hidden="true"></i></a></li>
            <li><a href="/blog/">مقالات</a></li>
            <li><a href="/blog/news">اخبار</a></li>
            <li class="breadcrumb-current" aria-current="page"><?php
                if (!$row) {
                    echo 'جزئیات';
                } elseif (function_exists('mb_strlen') && function_exists('mb_substr') && mb_strlen($title, 'UTF-8') > 40) {
                    echo news_h(mb_substr($title, 0, 37, 'UTF-8') . '…');
                } else {
                    echo news_h(strlen($title) > 40 ? substr($title, 0, 37) . '…' : $title);
                }
            ?></li>
        </ol>
    </nav>
</div>

<div class="wrap">
    <?php if ($err !== null): ?>
        <div class="alert" role="alert"><?= news_h($err) ?></div>
        <a class="back-link" href="/blog/news"><i class="fas fa-arrow-right"></i> بازگشت به فهرست اخبار</a>
    <?php else: ?>
        <article class="article-card" itemscope itemtype="https://schema.org/NewsArticle">
            <?php if ($d !== null): ?><div class="date"><i class="far fa-calendar-alt" style="margin-left:8px"></i><?= news_h($d) ?></div><?php endif; ?>
            <h1 itemprop="headline"><?= news_h($title) ?></h1>
            <div class="body-visible"><?= nl2br(news_h($split['visible'])) ?></div>
            <?php if ($split['show_gate']): ?>
                <div class="blur-stack" aria-hidden="false">
                    <div class="body-blur"><?= nl2br(news_h($split['rest'])) ?></div>
                    <div class="cta-overlay">
                        <p>برای خواندن ادامهٔ این مطلب، اپلیکیشن ایرانیو را دانلود کنید</p>
                        <p class="sub">متن کامل خبر فقط در اپلیکیشن در دسترس است.</p>
                        <div class="store-btns">
                            <a href="<?= news_h($appIos) ?>" rel="noopener noreferrer"><i class="fab fa-apple fa-lg"></i> App Store</a>
                            <a href="<?= news_h($appAndroid) ?>" rel="noopener noreferrer"><i class="fab fa-google-play fa-lg"></i> Google Play</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </article>
        <a class="back-link" href="/blog/news"><i class="fas fa-arrow-right"></i> بازگشت به فهرست اخبار</a>
    <?php endif; ?>
</div>

<footer>
    <div class="footer-logo"><img src="https://panel.cybercina.co.uk//storage/logos/N0yQlVchcj4ucrQfVJwbXXB13FhWTMFccUBmWLpI.png" alt=""></div>
    <div class="footer-links">
        <a href="/">صفحه اصلی</a>
        <a href="/blog/news">اخبار</a>
        <a href="/privacy">حریم خصوصی</a>
    </div>
</footer>
</body>
</html>
