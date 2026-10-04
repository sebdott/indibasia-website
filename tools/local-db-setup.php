<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;

// Generates local-only credentials. Remote settings in pw.txt are untouched.
try {
    $file = dirname(__DIR__) . '/.env';
    $text = is_file($file) ? file_get_contents($file) : "# Local Docker development settings. Keep this file private.\n";
    if ($text === false) throw new RuntimeException('The local environment file could not be read.');
    $original = $text;
    $defaults = [
        'PORT' => '9000',
        'MYSQL_PORT' => '3307',
        'LOCAL_DB_DATABASE' => 'indiba_local',
        'LOCAL_DB_USERNAME' => 'indiba',
        'LOCAL_DB_PASSWORD' => bin2hex(random_bytes(24)),
        'LOCAL_DB_ROOT_PASSWORD' => bin2hex(random_bytes(24)),
    ];
    foreach ($defaults as $name => $value) {
        $pattern = '/^\s*(?:export\s+)?' . $name . '\s*=([^\r\n]*)/m';
        if (preg_match($pattern, $text, $match)) {
            if (!in_array(trim($match[1]), ['', '""', "''"], true)) continue;
            $text = preg_replace_callback($pattern, static fn() => $name . '=' . $value, $text);
        } else {
            $text = rtrim($text, "\r\n") . "\n$name=$value\n";
        }
    }
    if ($text !== $original || !is_file($file)) {
        $temporary = tempnam(dirname($file), '.env.');
        if ($temporary === false) throw new RuntimeException('A local environment file could not be created.');
        try {
            if (file_put_contents($temporary, $text, LOCK_EX) === false) throw new RuntimeException('Local settings could not be saved.');
            @chmod($temporary, 0600);
            if (!rename($temporary, $file)) throw new RuntimeException('Local settings could not be installed.');
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }
    echo "Local database settings ready in .env. Existing values and pw.txt preserved.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Local database setup failed. Check .env and directory permissions.\n");
    exit(1);
}
