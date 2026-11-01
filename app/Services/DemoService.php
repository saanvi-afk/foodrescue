<?php
declare(strict_types=1);
namespace FoodRescue\Services;

use FoodRescue\Core\{Environment, HttpException};
use FoodRescue\Models\UserRepository;
use PDO;

final class DemoService
{
    public const PASSWORD = 'Demo12345678!';
    public const ACCOUNTS = [
        'super_admin' => ['name'=>'Demo Admin', 'email'=>'admin@foodrescue.test', 'label'=>'Super Admin'],
        'donor' => ['name'=>'Demo Donor', 'email'=>'donor@foodrescue.test', 'label'=>'Donor'],
        'receiver' => ['name'=>'Demo Receiver', 'email'=>'receiver@foodrescue.test', 'label'=>'NGO / Receiver'],
        'volunteer' => ['name'=>'Demo Volunteer', 'email'=>'volunteer@foodrescue.test', 'label'=>'Volunteer'],
        'researcher' => ['name'=>'Demo Researcher', 'email'=>'researcher@foodrescue.test', 'label'=>'Researcher'],
    ];

    public function __construct(private PDO $db) {}

    public static function enabled(): bool
    {
        return Environment::get('APP_ENV') === 'local' && Environment::get('DEMO_MODE') === 'true';
    }

    public function seed(): void
    {
        if (!self::enabled()) { throw new HttpException(403, 'Demo accounts require APP_ENV=local and DEMO_MODE=true.'); }
        $users = new UserRepository($this->db);
        $this->db->beginTransaction();
        try {
            foreach (self::ACCOUNTS as $role => $account) {
                $user = $users->byEmail($account['email']);
                if ($user && ($user['role'] !== $role || $user['name'] !== $account['name'])) {
                    throw new \RuntimeException('A demo email belongs to another account. No accounts were changed.');
                }
                $id = $user ? (int) $user['id'] : $users->create($account['name'], $account['email'], self::PASSWORD, $role);
                // Explicit demo provisioning resets only these five reserved accounts.
                $this->db->prepare("UPDATE users SET password_hash=?, email_verified_at=COALESCE(email_verified_at,UTC_TIMESTAMP()), status='active', auth_version=auth_version+1 WHERE id=?")
                    ->execute([password_hash(self::PASSWORD, PASSWORD_DEFAULT), $id]);
                $this->db->prepare('INSERT IGNORE INTO user_profiles (user_id) VALUES (?)')->execute([$id]);
                if ($role==='volunteer') { $this->db->prepare("INSERT IGNORE INTO volunteers(user_id,available,transport) VALUES (?,1,'Demo collection volunteer')")->execute([$id]); }
                (new AuthService($this->db))->audit($id, 'account.demo_seeded');
            }
            $this->db->commit();
        } catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
    }

}
