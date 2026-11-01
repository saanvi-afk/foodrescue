<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/bootstrap.php';
use FoodRescue\Core\Database;
use FoodRescue\Services\DemoService;

try {
    (new DemoService(Database::connection()))->seed();
    echo "Five demo accounts are ready (email verified). See README.md for login credentials.\n";
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
