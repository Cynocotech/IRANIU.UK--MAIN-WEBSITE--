<?php
declare(strict_types=1);

/**
 * Paginated listing from MySQL table GovUK_Content (6 per page).
 * URL: /govuk-content?page=2
 */
require __DIR__ . '/load_env.php';
require __DIR__ . '/includes/memory_db.php';

const GOVUK_TABLE = 'GovUK_Content';
const PER_PAGE = 6;

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
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

function order_field_for_table(array $fieldNames): string
{
    if ($fieldNames === []) {
        throw new RuntimeException('Table has no columns.');
    }
    $lower = [];
    foreach ($fieldNames as $f) {
        $lower[strtolower($f)] = $f;
    }
    foreach (['published_at', 'public_updated_at', 'updated_at', 'created_at', 'recorded_at', 'inserted_at', 'id'] as $candidate) {
        if (isset($lower[$candidate])) {
            return $lower[$candidate];
        }
    }
    return $fieldNames[0];
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
    $t = pick_first_nonempty($row, ['title', 'Title', 'headline', 'Headline', 'name', 'Name', 'heading', 'Heading']);
    return $t ?? 'بدون عنوان';
}

function row_excerpt(array $row, int $maxLen = 260): string
{
    $raw = pick_first_nonempty($row, [
        'summary', 'Summary', 'description', 'Description', 'excerpt', 'Excerpt',
        'body', 'Body', 'content', 'Content', 'html', 'Html', 'HTML', 'text', 'Text',
        'markdown', 'Markdown', 'article_body', 'ArticleBody',
    ]);
    if ($raw === null) {
        return '';
    }
    $plain = preg_replace('/\s+/u', ' ', strip_tags($raw)) ?? '';
    $plain = trim($plain);
    if (function_exists('mb_substr')) {
        if (mb_strlen($plain, 'UTF-8') > $maxLen) {
            return rtrim(mb_substr($plain, 0, $maxLen - 1, 'UTF-8')) . '…';
        }
    } elseif (strlen($plain) > $maxLen) {
        return substr($plain, 0, $maxLen - 1) . '…';
    }
    return $plain;
}

function row_link(array $row): ?string
{
    $u = pick_first_nonempty($row, ['url', 'Url', 'URL', 'link', 'Link', 'uri', 'Uri', 'govuk_url', 'web_url', 'WebUrl']);
    if ($u === null) {
        return null;
    }
    if (!preg_match('#^https?://#i', $u)) {
        return null;
    }
    return $u;
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
    $pdo = memory_pdo();
    $cols = table_columns($pdo, GOVUK_TABLE);
    $orderCol = order_field_for_table($cols);
    $orderQuoted = '`' . str_replace('`', '``', $orderCol) . '`';

    $countStmt = $pdo->query('SELECT COUNT(*) AS c FROM `' . str_replace('`', '``', GOVUK_TABLE) . '`');
    $total = (int) $countStmt->fetchColumn();
    $totalPages = $total > 0 ? (int) ceil($total / PER_PAGE) : 0;
    if ($totalPages > 0 && $page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * PER_PAGE;

    $sql = 'SELECT * FROM `' . str_replace('`', '``', GOVUK_TABLE) . '` ORDER BY ' . $orderQuoted . ' DESC LIMIT :lim OFFSET :off';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':lim', PER_PAGE, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $err = 'امکان خواندن از پایگاه داده نیست. تنظیمات اتصال یا نام جدول را بررسی کنید.';
}

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
    <title>محتوای Gov.UK | IraniU</title>
    <meta name="description" content="فهرست به‌روزرسانی‌شده از جدول GovUK_Content — صفحه‌بندی ۶ مورد در هر صفحه.">
    <meta name="robots" content="noindex, follow">
    <link rel="canonical" href="https://iraniu.uk/govuk-content">
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
        @font-face { font-family: 'Yekan Bakh'; src: url('fonts/YekanBakh-Regular.woff') format('woff'); font-weight: normal; }
        :root { --b: #74208b; --d: #3a0b47; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Yekan Bakh', Tahoma, sans-serif; background: #fdfdfd; color: #1a1a1a; line-height: 1.75; }
        a { text-decoration: none; color: var(--b); font-weight: 700; }
        a:hover { color: var(--d); }
        header { background: var(--d); padding: 12px 0; position: sticky; top: 0; z-index: 1000; border-bottom: 1px solid rgba(255,255,255,.1); }
        .nav-container { max-width: 900px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; padding: 0 20px; }
        .logo img { height: 46px; display: block; }
        .desktop-nav a { color: #fff; font-weight: bold; margin-right: 18px; font-size: .86rem; opacity: .92; }
        .desktop-nav a:hover { color: var(--b); }
        .hamburger { display: none; color: #fff; font-size: 1.35rem; cursor: pointer; }
        .mobile-nav { display: none; position: fixed; top: 68px; right: 0; left: 0; background: var(--d); flex-direction: column; align-items: center; padding: 24px 0; z-index: 999; }
        .mobile-nav.open { display: flex; }
        .mobile-nav a { color: #fff; font-weight: bold; margin: 8px 0; padding-bottom: 8px; width: 85%; text-align: center; border-bottom: 1px solid rgba(255,255,255,.1); }
        .hero { background: linear-gradient(135deg, var(--d), var(--b)); color: #fff; padding: 40px 20px 44px; text-align: center; }
        .hero h1 { font-size: 1.65rem; font-weight: 900; margin-bottom: 10px; line-height: 1.4; }
        .hero .sub { opacity: .92; font-size: .95rem; }
        .wrap { max-width: 900px; margin: -24px auto 64px; padding: 0 20px; }
        .meta-bar { font-size: .8rem; color: #666; margin-bottom: 18px; padding: 10px 14px; background: #fff; border-radius: 12px; border: 1px solid #eee; }
        .alert { background: #ffebee; color: #b71c1c; padding: 16px 18px; border-radius: 14px; margin-bottom: 20px; border: 1px solid #ffcdd2; }
        .grid { display: flex; flex-direction: column; gap: 18px; }
        .card { background: #fff; border-radius: 18px; padding: 22px 24px; border: 1px solid #eee; box-shadow: 0 10px 32px rgba(0,0,0,.06); }
        .card h2 { font-size: 1.08rem; color: var(--d); margin-bottom: 10px; line-height: 1.45; }
        .card p.ex { color: #555; font-size: .92rem; text-align: justify; margin-bottom: 12px; }
        .card .actions { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-top: 8px; }
        .card .ext { font-size: .82rem; display: inline-flex; align-items: center; gap: 6px; }
        .pager { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 10px; margin-top: 32px; padding: 18px; background: #fff; border-radius: 16px; border: 1px solid #eee; }
        .pager a, .pager span { display: inline-flex; align-items: center; justify-content: center; min-width: 40px; height: 40px; padding: 0 12px; border-radius: 10px; font-size: .88rem; font-weight: 700; }
        .pager a { background: #f3e8f5; color: var(--d); border: 1px solid rgba(116,32,139,.2); }
        .pager a:hover { background: var(--b); color: #fff; border-color: var(--b); }
        .pager span.current { background: var(--d); color: #fff; }
        .pager span.ell { border: none; background: transparent; color: #888; min-width: auto; }
        .pager .disabled { opacity: .45; pointer-events: none; }
        footer { background: var(--d); color: #fff; padding: 40px 20px; text-align: center; }
        .footer-links a { color: #fff; margin: 0 10px; font-size: .82rem; opacity: .75; }
        .footer-logo img { height: 48px; margin-bottom: 12px; }
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
            <a href="/govuk-content">Gov.UK</a>
            <a href="/contact">تماس</a>
        </nav>
        <div class="hamburger" onclick="document.getElementById('mnav').classList.toggle('open')"><i class="fas fa-bars"></i></div>
    </div>
    <nav class="mobile-nav" id="mnav">
        <a href="/">صفحه اصلی</a>
        <a href="/blog/">مقالات</a>
        <a href="/govuk-content">Gov.UK</a>
        <a href="/contact">تماس</a>
    </nav>
</header>

<section class="hero">
    <h1>محتوای Gov.UK</h1>
    <p class="sub">خوانده‌شده از پایگاه داده — <?= (int) PER_PAGE ?> مورد در هر صفحه<?php if (!$err): ?> · مرتب‌سازی: <?= h($orderCol) ?><?php endif; ?></p>
</section>

<div class="wrap">
    <?php if ($err !== null): ?>
        <div class="alert" role="alert"><?= h($err) ?></div>
    <?php else: ?>
        <div class="meta-bar">مجموع ردیف‌ها: <?= (int) $total ?> · صفحه <?= (int) $page ?> از <?= $totalPages > 0 ? (int) $totalPages : 1 ?></div>
        <div class="grid">
            <?php if ($rows === []): ?>
                <div class="card"><p>هنوز ردیفی در جدول نیست.</p></div>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $title = row_title($r);
                    $ex = row_excerpt($r);
                    $link = row_link($r);
                    ?>
                    <article class="card">
                        <h2><?= h($title) ?></h2>
                        <?php if ($ex !== ''): ?><p class="ex"><?= h($ex) ?></p><?php endif; ?>
                        <div class="actions">
                            <?php if ($link !== null): ?>
                                <a class="ext" href="<?= h($link) ?>" target="_blank" rel="noopener noreferrer"><i class="fas fa-arrow-up-left-from-center"></i> مشاهده در Gov.UK</a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pager" aria-label="صفحه‌بندی">
                <?php
                $buildUrl = static function (int $p): string {
                    return '/govuk-content?' . http_build_query(['page' => $p]);
                };
                ?>
                <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $page <= 1 ? '#' : h($buildUrl($page - 1)) ?>" aria-label="قبلی"><i class="fas fa-chevron-right"></i></a>
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
                        echo '<span class="current" aria-current="page">' . (int) $i . '</span>';
                    } else {
                        echo '<a href="' . h($buildUrl($i)) . '">' . (int) $i . '</a>';
                    }
                    $lastPrinted = $i;
                }
                ?>
                <a class="<?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= $page >= $totalPages ? '#' : h($buildUrl($page + 1)) ?>" aria-label="بعدی"><i class="fas fa-chevron-left"></i></a>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<footer>
    <div class="footer-logo"><img src="https://panel.cybercina.co.uk//storage/logos/N0yQlVchcj4ucrQfVJwbXXB13FhWTMFccUBmWLpI.png" alt=""></div>
    <div class="footer-links">
        <a href="/">صفحه اصلی</a>
        <a href="/blog/">مقالات</a>
        <a href="/govuk-content">Gov.UK</a>
        <a href="/privacy">حریم خصوصی</a>
    </div>
</footer>
</body>
</html>
