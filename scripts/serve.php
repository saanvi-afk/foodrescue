<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config/bootstrap.php';
use FoodRescue\Core\{Database,Environment};
use FoodRescue\Services\{JobService,MailService};
if (Environment::get('APP_ENV')!=='local') { fwrite(STDERR,"The development server is restricted to APP_ENV=local.\n");exit(1); }
$port=(int)($argv[1] ?? 8080);if ($port<1024 || $port>65535) { fwrite(STDERR,"Choose a port between 1024 and 65535.\n");exit(1); }
try { Database::connection(); }catch (Throwable) { fwrite(STDERR,"MySQL is not running or .env credentials are incorrect. See README Local setup.\n");exit(1); }
$command=[PHP_BINARY,'-d','display_errors=0','-d','upload_max_filesize=5M','-d','post_max_size=6M','-S','127.0.0.1:' . $port,'-t',BASE_PATH . '/public',BASE_PATH . '/public/router.php'];
$process=proc_open($command,[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes,BASE_PATH);
if (!is_resource($process)) { throw new RuntimeException('Could not start PHP development server.'); }
register_shutdown_function(static function () use ($process): void { if (is_resource($process)) { proc_terminate($process); } });
$running=true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT,static function () use (&$running): void { $running=false; });
    pcntl_signal(SIGTERM,static function () use (&$running): void { $running=false; });
}
echo "FoodRescue: http://localhost:{$port}\nBackground expiry, reminders and email queue run every 30 seconds. Press Ctrl+C to stop.\n";
$last=0;
while ($running && proc_get_status($process)['running']) {
    if (time()-$last>=30) {
        $last=time();
        try {
            $db=Database::connection();$jobs=new JobService($db);$jobs->expire();$jobs->reminders();(new MailService($db))->deliver(10);
        }catch (Throwable $error) { error_log('Background job failed: ' . $error->getMessage()); }
    }
    usleep(250000);
}
proc_terminate($process);proc_close($process);
