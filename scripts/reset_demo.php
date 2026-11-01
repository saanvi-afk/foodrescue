<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config/bootstrap.php';
use FoodRescue\Core\Database;
use FoodRescue\Models\UserRepository;
use FoodRescue\Services\DemoService;

if (PHP_SAPI!=='cli' || !DemoService::enabled() || !in_array('--confirm-reset',$argv,true)) {
    fwrite(STDERR,"Local demo reset only. This clears entered accounts, organizations, food, pickups, experiments and uploads, and restores five default accounts/settings.\nUsage: php scripts/reset_demo.php --confirm-reset\nRequires APP_ENV=local and DEMO_MODE=true.\n");exit(1);
}
$db=Database::connection();
// Reverse schema creation order respects foreign keys; schema and migration history remain intact.
$tables=[];
foreach (glob(BASE_PATH . '/database/migrations/*.sql') as $file) {
    preg_match_all('/CREATE TABLE\s+([a-z_]+)/i',file_get_contents($file),$matches);
    $tables=array_merge($tables,$matches[1]);
}
$actual=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if (array_diff($actual,[...$tables,'migrations'])) { throw new RuntimeException('Unrecognized database tables. Reset stopped before changing records.'); }
$defaultSql=file_get_contents(BASE_PATH . '/database/migrations/003_operations.sql');
$defaults=[];
foreach (['food_categories','safety_rules','algorithm_configurations','platform_settings'] as $table) {
    if (!preg_match('/INSERT INTO ' . $table . '\b.*?;/s',$defaultSql,$match)) { throw new RuntimeException('Default configuration missing. No records changed.'); }
    $defaults[]=$match[0];
}
$db->beginTransaction();
try {
    foreach (array_reverse(array_unique($tables)) as $table) { if ($table!=='roles') { $db->exec('DELETE FROM `' . $table . '`'); } }
    foreach ($defaults as $sql) { $db->exec($sql); }
    $users=new UserRepository($db);$admin=null;
    foreach (DemoService::ACCOUNTS as $role=>$account) {
        $id=$users->create($account['name'],$account['email'],DemoService::PASSWORD,$role);
        $db->prepare('UPDATE users SET email_verified_at=UTC_TIMESTAMP() WHERE id=?')->execute([$id]);
        $db->prepare('INSERT INTO user_profiles(user_id) VALUES (?)')->execute([$id]);
        if ($role==='volunteer') { $db->prepare("INSERT INTO volunteers(user_id,available,transport) VALUES (?,1,'Demo collection volunteer')")->execute([$id]); }
        if ($role==='super_admin') { $admin=$id; }
    }
    $db->prepare("INSERT INTO audit_logs(user_id,event,context) VALUES (?,'demo.reset',?)")->execute([$admin,'{"default_accounts":5}']);
    $db->commit();
} catch (Throwable $error) { $db->rollBack();throw $error; }
// Random uploaded files, local delivery messages, logs and generated exports are all non-public.
$deleted=0;
foreach (['private','exports','logs'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/storage/' . $directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->getFilename()==='.gitkeep' || $file->isDir()) { continue; }
        if (!unlink($file->getPathname())) { throw new RuntimeException('Database reset succeeded, but a stored file could not be removed.'); }$deleted++;
    }
}
echo "Cleared entered records and {$deleted} stored files. Restored five verified default accounts and default configuration.\nCredentials remain in README.md. Sign in again; old sessions no longer identify an account.\n";
