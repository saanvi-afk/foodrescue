<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/bootstrap.php';
use FoodRescue\Core\Database;
$db = Database::connection();
$db->exec('CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(190) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
$files = array_merge(glob(BASE_PATH . '/database/migrations/*.sql'), glob(BASE_PATH . '/database/seeds/*.sql'));
foreach ($files as $file) {
    $name = basename(dirname($file)) . '/' . basename($file);
    $query = $db->prepare('SELECT name FROM migrations WHERE name=?');
    $query->execute([$name]);
    if ($query->fetch()) { continue; }
    // MySQL DDL commits implicitly: repair a partially failed migration before rerunning.
    $db->exec(file_get_contents($file));
    $db->prepare('INSERT INTO migrations (name) VALUES (?)')->execute([$name]);
    echo "Applied {$name}\n";
}
