<?php
/**
 * Example config – copy to config.php and fill with real credentials
 * config.php is gitignored; never commit secrets
 */
return [
    'ZOHO_EMAIL' => 'your-zoho@example.com',
    'ZOHO_PASSWORD' => 'your-app-password',
    'ADMIN_EMAIL' => 'admin@example.com',
    'CAREERS_EMAIL' => 'hr@example.com',
    'TURNSTILE_SECRET_KEY' => 'your-turnstile-secret-key',
    // MySQL — tbl_news در /blog/news (یا از .env) — معادل لاراول
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_DATABASE' => 'cybercinaco_app',
    'DB_USERNAME' => 'cybercinaco_app',
    'DB_PASSWORD' => 'your-db-password',
    'NEWS_CATEGORY_TABLE' => 'tbl_category',
    'APP_STORE_URL' => 'https://apps.apple.com/app/iraniu/id000000000',
    'GOOGLE_PLAY_URL' => 'https://play.google.com/store/apps/details?id=uk.iraniu.app',
];
