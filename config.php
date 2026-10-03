<?php
declare(strict_types=1);

return [
    'origin' => 'https://indiba.com',
    'home' => '/asia/',
    'locales' => ['', '/asia', '/us'],
    'asset_hosts' => ['indiba.com', 'www.indiba.com', 'fonts.googleapis.com', 'fonts.gstatic.com', 'i0.wp.com', 'i1.wp.com', 'i2.wp.com'],
    'excluded_paths' => ['wp-admin', 'wp-login.php', 'wp-json', 'xmlrpc.php', 'form_submission', 'form-submission', 'vj-wp-import-export', 'cdn-cgi/l/email-protection'],
    'storage' => __DIR__ . '/storage',
    'public' => __DIR__ . '/public',
];
