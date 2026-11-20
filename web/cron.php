<?php
declare(strict_types=1);
/** Run every 5 minutes:  php /path/to/cron.php   (Plesk > Scheduled Tasks) */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require __DIR__ . '/app/lib/autoload.php';
date_default_timezone_set('UTC');
\HMD\App::installErrorHandlers();
\HMD\Migrate::run();
foreach (\HMD\Jobs::runAll() as $job => $msg) {
    echo str_pad($job, 20) . $msg . PHP_EOL;
}
