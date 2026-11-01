<?php
declare(strict_types=1);
namespace FoodRescue\Core;
use PDO;
final class Database
{
    private static ?PDO $connection = null;
    public static function connection(): PDO
    {
        if (self::$connection === null) {
            $config = require BASE_PATH . '/config/database.php';
            self::$connection = new PDO($config['dsn'], $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$connection->exec("SET time_zone = '+00:00'");
        }
        return self::$connection;
    }
}
