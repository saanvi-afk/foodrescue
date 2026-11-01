<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
if (is_file(BASE_PATH . '/vendor/autoload.php')) { require_once BASE_PATH . '/vendor/autoload.php'; }
spl_autoload_register(static function (string $class): void {
    $prefix = 'FoodRescue\\';
    if (str_starts_with($class, $prefix)) {
        $path = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) { require $path; }
    }
});
FoodRescue\Core\Environment::load(BASE_PATH . '/.env');
date_default_timezone_set('UTC');
function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
