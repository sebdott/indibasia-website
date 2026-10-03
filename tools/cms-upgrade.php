<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/lib/cms-management.php';
try { cmsUpgrade(); echo "CMS page management schema upgraded. Existing pages and accounts preserved.\n"; }
catch (Throwable $e) { fwrite(STDERR, 'CMS upgrade failed (' . $e->getCode() . "). Check database schema permissions.\n"); exit(1); }
