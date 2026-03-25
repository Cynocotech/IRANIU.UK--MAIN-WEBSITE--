<?php
declare(strict_types=1);

/**
 * اخبار فارسی از جدول tbl_news — ۶ خبر در هر صفحه
 * آدرس: /blog/news?page=2
 */
require __DIR__ . '/../load_env.php';
require __DIR__ . '/../includes/app_db.php';

const NEWS_TABLE = 'tbl_news';
const PER_PAGE = 6;

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function fa_digits(string $s): string
{
    return str_replace(
        ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
        ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
        $s
    );
}

/** @return string[] */
function table_columns(PDO $pdo, string $table): array
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

function order_field_for_news(array $fieldNames): string
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

function deleted_at_clause(array $cols): string
{
    foreach ($cols as $c) {
        if (strcasecmp($c, 'deleted_at') === 0) {
            return ' AND `' . str_replace('`', '``', $c) . '` IS NULL';
        }
    }
    return '';
}

function pick_first_nonempty(array $row, array $keys): ?string
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

function row_title(array $row): string
{
    $t = pick_first_nonempty($row, [
        'title', 'Title', 'subject', 'Subject', 'news_title', 'newsTitle',
        'onvan', 'name', 'Name', 'headline', 'Headline', 'key_title', 'topic',
    ]);
    return $t ?? 'بدون عنوان';
}

function row_excerpt(array $row, int $maxLen = 280): string
{
    $raw = pick_first_nonempty($row, [
        'summary', 'Summary', 'short_text', 'shortText', 'lead', 'intro',
        'description', 'Description', 'excerpt', 'Excerpt', 'kholase', 'subtitle',
        'body', 'Body', 'content', 'Content', 'text', 'Text', 'matn', 'news_text',
        'full_text', 'detail', 'html', 'Html',
    ]);
    if ($raw === null) {
        return '';
    }
    $plain = preg_replace('/\s+/u', ' ', strip_tags($raw)) ?? '';
    $plain = trim($plain);
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($plain, 'UTF-8') > $maxLen) {
            return rtrim(mb_substr($plain, 0, $maxLen - 1, 'UTF-8')) . '…';
        }
    } elseif (strlen($plain) > $maxLen) {
        return substr($plain, 0, $maxLen - 1) . '…';
    }
    return $plain;
}

function format_news_date(array $row): ?string
{
    $raw = pick_first_nonempty($row, [
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
    return fa_digits(date('Y/n/j', $ts));
}

function row_image_url(array $row): ?string
{
    $u = pick_first_nonempty($row, [
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

$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}

$err = null;
$rows = [];
$total = 0;
$totalPages = 0;
$orderCol = 'id';

try {
    $pdo = app_pdo();
    $cols = table_columns($pdo, NEWS_TABLE);
    $orderCol = order_field_for_news($cols);
    $orderQuoted = '`' . str_replace('`', '``', $orderCol) . '`';
    $extraWhere = deleted_at_clause($cols);

    $countSql = 'SELECT COUNT(*) FROM `' . str_replace('`', '``', NEWS_TABLE) . '` WHERE 1=1' . $extraWhere;
    $total = (int) $pdo->query($countSql)->fetchColumn();
    $totalPages = $total > 0 ? (int) ceil($total / PER_PAGE) : 0;
    if ($totalPages > 0 && $page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * PER_PAGE;

    $sql = 'SELECT * FROM `' . str_replace('`', '``', NEWS_TABLE) . '` WHERE 1=1' . $extraWhere
        . ' ORDER BY ' . $orderQuoted . ' DESC LIMIT :lim OFFSET :off';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':lim', PER_PAGE, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $err = 'اتصال به پایگاه داده برقرار نشد یا جدول «tbl_news» در دسترس نیست. تنظیمات .env و دسترسی کاربر MySQL را بررسی کنید.';
}

$basePath = '/blog/news';

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
    <title>اخبار | مقالات IraniU</title>
    <meta name="description" content="آخرین اخبار و مطالب فارسی از پایگاه داده — برای جامعه ایرانیان بریتانیا.">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://iraniu.uk/blog/news">
    <meta property="og:locale" content="fa_IR">
    <meta property="og:url" content="https://iraniu.uk/blog/news">
    <meta property="og:title" content="اخبار | IraniU">
    <meta property="og:type" content="website">
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
        .meta-bar { font-size: 0.82rem; color: #666; margin-bottom: 20px; padding: 12px 16px; background: #fff; border-radius: 14px; border: 1px solid #eee; }
        .alert { background: #ffebee; color: #b71c1c; padding: 18px 20px; border-radius: 14px; margin-bottom: 22px; border: 1px solid #ffcdd2; text-align: right; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 22px; }
        .card { background: #fff; border-radius: 20px; overflow: hidden; border: 1px solid #eee; box-shadow: 0 12px 36px rgba(0,0,0,0.06); transition: 0.25s; display: flex; flex-direction: column; }
        .card:hover { border-color: var(--brand-purple); transform: translateY(-3px); }
        .card-img { width: 100%; aspect-ratio: 16/10; object-fit: cover; background: #f3e8f5; }
        .card-body { padding: 22px; flex: 1; display: flex; flex-direction: column; }
        .card .date { font-size: 0.78rem; color: #888; margin-bottom: 8px; }
        .card h2 { font-size: 1.12rem; color: var(--dark-purple); margin-bottom: 10px; line-height: 1.45; }
        .card p.ex { color: #555; font-size: 0.92rem; text-align: justify; flex: 1; margin-bottom: 0; }
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
    <p>مطالب فارسی به‌روز از پایگاه داده — در هر صفحه <?= (int) PER_PAGE ?> خبر نمایش داده می‌شود.</p>
</section>

<div class="wrap">
    <?php if ($err !== null): ?>
        <div class="alert" role="alert"><?= h($err) ?></div>
    <?php else: ?>
        <div class="meta-bar">
            مجموع خبرها: <?= fa_digits((string) (int) $total) ?>
            <?php if ($totalPages > 0): ?>
                — صفحه <?= fa_digits((string) (int) $page) ?> از <?= fa_digits((string) (int) $totalPages) ?>
            <?php endif; ?>
            <?php if ($orderCol !== ''): ?>
                <span style="opacity:.85"> — مرتب‌سازی بر اساس: <?= h($orderCol) ?></span>
            <?php endif; ?>
        </div>
        <div class="grid">
            <?php if ($rows === []): ?>
                <div class="card"><div class="card-body"><h2>خبری نیست</h2><p class="ex">در جدول هنوز ردیفی ثبت نشده یا فیلتر حذف‌زمان‌دار همه را پنهان کرده است.</p></div></div>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $title = row_title($r);
                    $ex = row_excerpt($r);
                    $img = row_image_url($r);
                    $d = format_news_date($r);
                    ?>
                    <article class="card">
                        <?php if ($img !== null): ?>
                            <?php
                            $imgAlt = $title;
                            if (function_exists('mb_substr') && mb_strlen($imgAlt, 'UTF-8') > 100) {
                                $imgAlt = mb_substr($imgAlt, 0, 97, 'UTF-8') . '…';
                            }
                            ?>
                            <img class="card-img" src="<?= h($img) ?>" alt="<?= h($imgAlt) ?>" loading="lazy" width="640" height="400">
                        <?php endif; ?>
                        <div class="card-body">
                            <?php if ($d !== null): ?><div class="date"><i class="far fa-calendar-alt" style="margin-left:6px;opacity:.8"></i><?= h($d) ?></div><?php endif; ?>
                            <h2><?= h($title) ?></h2>
                            <?php if ($ex !== ''): ?><p class="ex"><?= h($ex) ?></p><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pager" aria-label="صفحه‌بندی">
                <?php
                $buildUrl = static function (int $p) use ($basePath): string {
                    return $basePath . '?' . http_build_query(['page' => $p]);
                };
                ?>
                <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $page <= 1 ? '#' : h($buildUrl($page - 1)) ?>" aria-label="صفحه قبل"><i class="fas fa-chevron-right"></i></a>
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
                        echo '<span class="current" aria-current="page">' . h(fa_digits((string) $i)) . '</span>';
                    } else {
                        echo '<a href="' . h($buildUrl($i)) . '">' . h(fa_digits((string) $i)) . '</a>';
                    }
                    $lastPrinted = $i;
                }
                ?>
                <a class="<?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= $page >= $totalPages ? '#' : h($buildUrl($page + 1)) ?>" aria-label="صفحه بعد"><i class="fas fa-chevron-left"></i></a>
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
