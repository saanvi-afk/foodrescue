<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/bootstrap.php';
use FoodRescue\Core\{Database,Environment,HttpException,Session};
use FoodRescue\Validators\RegistrationValidator;
use FoodRescue\Middleware\Authorize;
use FoodRescue\Services\AuthService;
use FoodRescue\Models\UserRepository;
$passed = 0;
function test(string $name, callable $callback): void {
    global $passed;
    try { $callback(); ++$passed; echo "PASS {$name}\n"; }
    catch (Throwable $error) { fwrite(STDERR,"FAIL {$name}: {$error->getMessage()}\n"); exit(1); }
}
function check(bool $condition): void { if (!$condition) { throw new RuntimeException('Assertion failed'); } }
function denied(callable $callback, int $status): void {
    try { $callback(); } catch (HttpException $error) { check($error->status === $status); return; }
    throw new RuntimeException('Expected rejection');
}
$valid = ['name'=>'Test Donor','email'=>'donor@example.test','password'=>'a-long-test-password','role'=>'donor'];
test('Registration validation accepts a valid donor', fn()=>check(RegistrationValidator::errors($valid) === []));
test('Public registration rejects privileged roles', fn()=>check(count(RegistrationValidator::errors(array_replace($valid,['role'=>'super_admin']))) === 1));
test('Invalid email and short password rejected', fn()=>check(count(RegistrationValidator::errors(array_replace($valid,['email'=>'bad','password'=>'short']))) === 2));
test('Anonymous access denied', fn()=>denied(fn()=>Authorize::check(null),401));
test('Wrong role denied', fn()=>denied(fn()=>Authorize::check(['status'=>'active','role'=>'donor'],['researcher']),403));
test('Suspended account denied', fn()=>denied(fn()=>Authorize::check(['status'=>'suspended','role'=>'donor']),403));
test('All five roles can enter their own workspace', function () { foreach (['super_admin','donor','receiver','volunteer','researcher'] as $role) { check(Authorize::check(['status'=>'active','role'=>$role],[$role])['role'] === $role); } });
test('Missing and forged CSRF tokens rejected', function () { $_SESSION['csrf'] = str_repeat('a',64); denied(fn()=>Session::verifyCsrf(null),419); denied(fn()=>Session::verifyCsrf('bad'),419); Session::verifyCsrf(str_repeat('a',64)); });
test('TAMRA produces the specified deterministic components and weighted score', function () {
    $now=strtotime('2026-01-01 08:00:00 UTC');
    $config=['weights'=>['urgency'=>0.25,'distance'=>0.20,'capacity'=>0.15,'compatibility'=>0.15,'feasibility'=>0.15,'reliability'=>0.10],'parameters'=>['speed_kmh'=>25,'handling_minutes'=>20,'urgency_horizon_minutes'=>1440,'cold_start_reliability'=>0.5,'minimum_history'=>5]];
    $donation=['status'=>'available','deadline'=>'2026-01-01 10:00:00','category_id'=>2,'quantity_kg'=>5,'latitude'=>0,'longitude'=>0];
    $receiver=['id'=>1,'user_id'=>1,'name'=>'Synthetic receiver','status'=>'active','collection_available'=>true,'transport_available'=>true,'latitude'=>0,'longitude'=>0,'capacity_kg'=>20,'reserved_kg'=>0,'max_distance_km'=>30,'available_from'=>'2026-01-01 08:00:00','available_until'=>'2026-01-01 12:00:00','categories'=>[2],'history_count'=>0,'history_success'=>0];
    $service=new \FoodRescue\Services\TAMRAService();$match=$service->rank($donation,[$receiver],$config,$now)['ranked'][0];
    check(abs($match['components']['urgency']-11/12)<1e-9 && abs($match['components']['distance']-1)<1e-9 && $match['components']['capacity']===0.25);
    check(abs($match['score']-19/24)<1e-9);
    check(!$service->rank($donation,[array_replace($receiver,['reserved_kg'=>18])],$config,$now)['ranked']);
    check(!$service->rank(array_replace($donation,['status'=>'rejected']),[$receiver],$config,$now)['ranked']);
});
test('Donation lifecycle rejects reclaiming collected food', function () {
    \FoodRescue\Services\Lifecycle::assert('scheduled','collected');
    denied(fn()=>\FoodRescue\Services\Lifecycle::assert('collected','available'),409);
});
test('Invalid calendar dates are rejected instead of normalized', fn()=>denied(fn()=>\FoodRescue\Core\Input::date(['date'=>'2026-02-30T08:00:00Z'],'date'),422));
if (!in_array('--integration',$argv,true)) { echo "{$passed} tests passed. MySQL integration tests: php tests/run.php --integration\n"; exit; }
$database = Environment::get('TEST_DB_DATABASE','foodrescue_test');
if (!preg_match('/^[a-zA-Z0-9_]+_test$/D',$database)) { throw new RuntimeException('Integration database must end in _test'); }
putenv('DB_DATABASE=' . $database);
$db = Database::connection();
// Only the explicitly named disposable test database is cleared.
$db->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) { $db->exec('DROP TABLE `' . str_replace('`','``',$table) . '`'); }
$db->exec('SET FOREIGN_KEY_CHECKS=1');
require BASE_PATH . '/scripts/migrate.php';
$service = new AuthService($db);
$users = new UserRepository($db);
test('Donor receiver and volunteer registrations are immediately active without admin approval', function () use ($service,$users) {
    foreach (['donor','receiver','volunteer'] as $role) {
        $email = 'immediate-' . $role . '@example.test';
        $id = $service->register(['name'=>'New ' . $role,'email'=>$email,'password'=>'immediate-test-password','role'=>$role]);
        $user = $users->find($id);
        check($user['status'] === 'active' && $user['email_verified_at'] === null);
        check($service->login($email,'immediate-test-password') === $id);
        check(Authorize::check($user,[$role])['id'] === $id);
    }
});
test('Organization schema has no administrator approval fields', function () use ($db) {
    $columns = $db->query('SHOW COLUMNS FROM organizations')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['verification_status','verified_by','verified_at'] as $column) { check(!in_array($column,$columns,true)); }
});
$id = 0;
test('Registration stores a hashed password and ignores injected privileges', function () use ($service,$valid,$users,&$id) {
    $id = $service->register($valid + ['status'=>'suspended','email_verified_at'=>'2020-01-01','role_id'=>1]);
    $user = $users->byEmail($valid['email']);
    check($user['role']==='donor' && $user['status']==='active' && $user['email_verified_at']===null);
    check($user['password_hash']!==$valid['password'] && password_verify($valid['password'],$user['password_hash']));
});
test('Privilege escalation rejected by service', fn()=>denied(fn()=> $service->register(array_replace($valid,['email'=>'admin@example.test','role'=>'super_admin'])),422));
test('Duplicate registration rolls back cleanly', fn()=>denied(fn()=> $service->register($valid),422));
test('Invalid and unknown login rejected', function () use ($service) { denied(fn()=> $service->login('donor@example.test','wrong'),401); denied(fn()=> $service->login('unknown@example.test','wrong'),401); });
test('Correct login succeeds', fn()=>check($service->login($valid['email'],$valid['password'])===$id));
test('Suspension blocks login immediately', function () use ($db,$service,$id,$valid) {
    $db->prepare("UPDATE users SET status='suspended' WHERE id=?")->execute([$id]);
    denied(fn()=> $service->login($valid['email'],$valid['password']),401);
    $db->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$id]);
});
function outboxToken(PDO $db, string $subject): string {
    $query = $db->prepare('SELECT body FROM mail_outbox WHERE subject=? ORDER BY id DESC LIMIT 1'); $query->execute([$subject]);
    preg_match('/#token=([a-f0-9]{64})/',$query->fetchColumn(),$match);
    return $match[1];
}
test('Verification token is hashed and single-use', function () use ($db,$service,$users,$id) {
    $token = outboxToken($db,'Verify your FoodRescue email');
    check($db->query('SELECT token_hash FROM account_tokens LIMIT 1')->fetchColumn()!==$token);
    $service->consumeToken($token,'verify_email'); check($users->find($id)['email_verified_at']!==null);
    denied(fn()=> $service->consumeToken($token,'verify_email'),422);
});
test('Reset replaces password, revokes sessions and consumes the token', function () use ($db,$service,$users,$valid,$id) {
    $service->requestReset($valid['email']); $token = outboxToken($db,'Reset your FoodRescue password');
    $service->consumeToken($token,'reset_password','new-long-test-password');
    denied(fn()=> $service->login($valid['email'],$valid['password']),401);
    check($service->login($valid['email'],'new-long-test-password')===$id);
    check((int)$users->find($id)['auth_version']===2);
    denied(fn()=> $service->consumeToken($token,'reset_password','other-long-password'),422);
});
test('Expired tokens fail', function () use ($db,$service,$valid) {
    $service->requestReset($valid['email']); $token = outboxToken($db,'Reset your FoodRescue password');
    $db->exec('UPDATE account_tokens SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND');
    denied(fn()=> $service->consumeToken($token,'reset_password','new-long-test-password'),422);
});
test('Unknown reset email does not queue a message', function () use ($db,$service) {
    $before = $db->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn(); $service->requestReset('missing@example.test');
    check($db->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn()===$before);
});
test('Rate limiting rejects excess requests', function () use ($service) { for ($i=0;$i<10;$i++) { $service->throttle('test','x@example.test','127.0.0.1'); } denied(fn()=> $service->throttle('test','x@example.test','127.0.0.1'),429); });
test('Migration rerun is idempotent', function () use ($db) { require BASE_PATH . '/scripts/migrate.php'; check((int)$db->query('SELECT COUNT(*) FROM roles')->fetchColumn()===5); });
$demo = new \FoodRescue\Services\DemoService($db);
$originalEnvironment = getenv('APP_ENV');
$originalDemo = getenv('DEMO_MODE');
putenv('APP_ENV=local');
putenv('DEMO_MODE=true');
test('Demo accounts are verified and repeated seeding creates no duplicates', function () use ($demo,$db) {
    $demo->seed();
    $before = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $demo->seed();
    check((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === $before);
});
test('All five demo credentials work and accounts are verified', function () use ($users,$service) {
    foreach (\FoodRescue\Services\DemoService::ACCOUNTS as $role => $account) {
        $user = $users->byEmail($account['email']);
        check($service->login($account['email'], \FoodRescue\Services\DemoService::PASSWORD) === (int) $user['id']);
        check($user['role'] === $role && $user['email_verified_at'] !== null);
        denied(fn()=> $service->login($account['email'], 'incorrect-password'),401);
    }
});
test('Demo credentials are disabled outside local demo mode', function () use ($demo,$service) {
    putenv('APP_ENV=production');
    denied(fn()=> $demo->seed(),403);
    denied(fn()=> $service->login('admin@foodrescue.test', \FoodRescue\Services\DemoService::PASSWORD),401);
    putenv('APP_ENV=local');
    putenv('DEMO_MODE=false');
    denied(fn()=> $service->login('admin@foodrescue.test', \FoodRescue\Services\DemoService::PASSWORD),401);
    putenv('DEMO_MODE=true');
});
test('Suspended demo account cannot sign in', function () use ($users,$service,$db) {
    $id = $users->byEmail('donor@foodrescue.test')['id'];
    $db->prepare("UPDATE users SET status='suspended' WHERE id=?")->execute([$id]);
    denied(fn()=> $service->login('donor@foodrescue.test', \FoodRescue\Services\DemoService::PASSWORD),401);
    $db->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$id]);
});
putenv($originalEnvironment === false ? 'APP_ENV' : 'APP_ENV=' . $originalEnvironment);
putenv($originalDemo === false ? 'DEMO_MODE' : 'DEMO_MODE=' . $originalDemo);
echo "{$passed} tests passed, including MySQL integration.\n";
