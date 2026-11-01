<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/bootstrap.php';
use FoodRescue\Core\Database;
use FoodRescue\Models\UserRepository;
use FoodRescue\Validators\RegistrationValidator;
if ($argc !== 4 || !in_array($argv[3], ['super_admin','donor','receiver','volunteer','researcher'], true)) {
    fwrite(STDERR, "Usage: php scripts/create_user.php name email role\nPassword is read from stdin; do not pass it in arguments.\n"); exit(1);
}
fwrite(STDERR, "Password (stdin; use a secure terminal): ");
$password = rtrim(fgets(STDIN), "\r\n");
$errors = RegistrationValidator::errors(['name'=>$argv[1], 'email'=>$argv[2], 'password'=>$password, 'role'=>'donor']);
if ($errors) { fwrite(STDERR, implode("\n", $errors) . "\n"); exit(1); }
$db = Database::connection();
$db->beginTransaction();
try {
    $id = (new UserRepository($db))->create($argv[1], $argv[2], $password, $argv[3]);
    $db->prepare('INSERT INTO user_profiles (user_id) VALUES (?)')->execute([$id]);
    $db->prepare("INSERT INTO audit_logs (user_id,event,context) VALUES (?,'account.created_by_cli',?)")->execute([$id, json_encode(['role'=>$argv[3]], JSON_THROW_ON_ERROR)]);
    $db->commit(); echo "Created account {$id}; email verification is still required.\n";
} catch (Throwable $error) { $db->rollBack(); throw $error; }
