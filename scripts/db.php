<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/bootstrap.php';
use FoodRescue\Core\{Database, Environment};

// CLI inspector reuses .env, so database passwords never appear in shell arguments.
if (PHP_SAPI !== 'cli') { exit(1); }
$db = Database::connection();
$tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$table = $argv[1] ?? '';
echo 'Database: ' . Environment::get('DB_DATABASE') . ' at ' . Environment::get('DB_HOST') . ':' . Environment::get('DB_PORT') . "\n";
if ($table === '') {
    foreach ($tables as $name) {
        $escaped = str_replace('`', '``', $name);
        echo $name . ': ' . $db->query("SELECT COUNT(*) FROM `{$escaped}`")->fetchColumn() . " rows\n";
    }
    echo "\nInspect a table: php scripts/db.php users\n";
} else {
    if (!in_array($table, $tables, true)) { fwrite(STDERR, "Unknown table. Run without arguments to list tables.\n"); exit(1); }
    $escaped = str_replace('`', '``', $table);
    $rows = $db->query("SELECT * FROM `{$escaped}` LIMIT 50")->fetchAll();
    foreach ($rows as &$row) {
        foreach (['password_hash','token_hash','body'] as $private) { if (isset($row[$private])) { $row[$private] = '[hidden]'; } }
    }
    unset($row);
    echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    echo "Showing up to 50 records.\n";
}
