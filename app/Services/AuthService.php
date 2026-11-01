<?php
declare(strict_types=1);
namespace FoodRescue\Services;
use FoodRescue\Core\Environment;
use FoodRescue\Core\HttpException;
use FoodRescue\Models\UserRepository;
use FoodRescue\Validators\RegistrationValidator;
use PDO;
final class AuthService
{
    private UserRepository $users;
    public function __construct(private PDO $db) { $this->users = new UserRepository($db); }
    public function throttle(string $action, string $identity, string $ip, int $limit = 10): void
    {
        // Fixed windows, two atomic counters: identity and source IP. No forwarded headers are trusted.
        foreach (['identity:' . strtolower(trim($identity)), 'ip:' . $ip] as $key) {
            $bucket = hash('sha256', $action . ':' . $key);
            $query = $this->db->prepare('INSERT INTO auth_rate_limits (bucket,attempts,window_start) VALUES (?,1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE attempts=IF(window_start < UTC_TIMESTAMP()-INTERVAL 15 MINUTE,1,attempts+1), window_start=IF(window_start < UTC_TIMESTAMP()-INTERVAL 15 MINUTE,UTC_TIMESTAMP(),window_start)');
            $query->execute([$bucket]);
            $query = $this->db->prepare('SELECT attempts FROM auth_rate_limits WHERE bucket=?');
            $query->execute([$bucket]);
            if ((int) $query->fetchColumn() > $limit) { throw new HttpException(429, 'Too many attempts. Please try again in 15 minutes.'); }
        }
    }
    public function register(array $data): int
    {
        $errors = RegistrationValidator::errors($data);
        if ($errors) { throw new HttpException(422, implode(' ', $errors)); }
        $this->db->beginTransaction();
        try {
            $id = $this->users->create(trim($data['name']), $data['email'], $data['password'], $data['role']);
            $this->db->prepare('INSERT INTO user_profiles (user_id) VALUES (?)')->execute([$id]);
            $this->audit($id, 'account.registered');
            $this->issueToken($id, $data['email'], 'verify_email');
            $this->db->commit();
            return $id;
        } catch (\Throwable $error) {
            $this->db->rollBack();
            if ($error instanceof \PDOException && $error->getCode() === '23000') { throw new HttpException(422, 'This email cannot be registered. Try signing in or resetting your password.'); }
            throw $error;
        }
    }
    public function releaseSuccessfulLogin(string $email,string $ip): void
    {
        foreach (['identity:' . strtolower(trim($email)), 'ip:' . $ip] as $key) {
            $this->db->prepare('UPDATE auth_rate_limits SET attempts=GREATEST(attempts-1,0) WHERE bucket=?')->execute([hash('sha256','login:' . $key)]);
        }
    }
    public function login(string $email, string $password): int
    {
        if (!DemoService::enabled() && in_array(strtolower(trim($email)), array_column(DemoService::ACCOUNTS, 'email'), true)) {
            throw new HttpException(401, 'Demo accounts are available only in local demo mode.');
        }
        $user = $this->users->byEmail($email);
        // A valid fixed hash keeps unknown-account checks comparable to known-account checks.
        $valid = password_verify($password, $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if (!$user || !$valid || $user['status'] !== 'active') {
            $this->audit($user ? (int) $user['id'] : null, 'auth.login_failed');
            throw new HttpException(401, 'Email or password is incorrect, or the account is unavailable.');
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        $this->audit((int) $user['id'], 'auth.login');
        return (int) $user['id'];
    }
    public function requestReset(string $email): void
    {
        $user = $this->users->byEmail($email);
        if (!$user || $user['status'] !== 'active') { return; }
        $this->db->beginTransaction();
        try { $this->issueToken((int) $user['id'], $user['email'], 'reset_password'); $this->db->commit(); }
        catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
    }
    public function requestVerification(array $user): void
    {
        if ($user['email_verified_at']) { return; }
        $this->db->beginTransaction();
        try { $this->issueToken((int) $user['id'], $user['email'], 'verify_email'); $this->db->commit(); }
        catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
    }
    private function issueToken(int $id, string $email, string $purpose): void
    {
        // Serialize issuance and consumption for each account.
        $this->db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE')->execute([$id]);
        $this->db->prepare('UPDATE account_tokens SET consumed_at=UTC_TIMESTAMP() WHERE user_id=? AND purpose=? AND consumed_at IS NULL')->execute([$id,$purpose]);
        $token = bin2hex(random_bytes(32));
        $expiry = gmdate('Y-m-d H:i:s', time() + ($purpose === 'reset_password' ? 3600 : 86400));
        $this->db->prepare('INSERT INTO account_tokens (user_id,purpose,token_hash,expires_at) VALUES (?,?,?,?)')->execute([$id,$purpose,hash('sha256',$token),$expiry]);
        $path = $purpose === 'reset_password' ? '/reset-password' : '/verify-email';
        // Fragments keep bearer secrets out of HTTP access logs and referrer URLs.
        $url = rtrim(Environment::get('APP_URL', 'http://localhost:8080'), '/') . $path . '#token=' . $token;
        $this->db->prepare('INSERT INTO mail_outbox (recipient,subject,body) VALUES (?,?,?)')->execute([strtolower(trim($email)), $purpose === 'reset_password' ? 'Reset your FoodRescue password' : 'Verify your FoodRescue email', "Open this link to continue: {$url}\nExpires at {$expiry} UTC. Ignore this message if you did not request it."]);
    }
    public function consumeToken(string $token, string $purpose, ?string $password = null): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) { throw new HttpException(422, 'This link is invalid or expired.'); }
        if (!in_array($purpose, ['reset_password','verify_email'], true)) { throw new HttpException(422, 'Invalid token purpose.'); }
        if ($purpose === 'reset_password' && (strlen($password ?? '') < 12 || strlen($password ?? '') > 72)) { throw new HttpException(422, 'Use a password between 12 and 72 bytes.'); }
        $this->db->beginTransaction();
        try {
            $hash = hash('sha256',$token);
            $query = $this->db->prepare('SELECT user_id FROM account_tokens WHERE token_hash=? AND purpose=?');
            $query->execute([$hash,$purpose]);
            $id = $query->fetchColumn();
            $lock = $this->db->prepare('SELECT id FROM users WHERE id=? AND status=\'active\' AND deleted_at IS NULL FOR UPDATE');
            $lock->execute([$id ?: 0]);
            if (!$lock->fetchColumn()) { throw new HttpException(422, 'This link is invalid or expired.'); }
            $query = $this->db->prepare('SELECT id FROM account_tokens WHERE token_hash=? AND purpose=? AND consumed_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE');
            $query->execute([$hash,$purpose]);
            if (!$query->fetch()) { throw new HttpException(422, 'This link is invalid or expired.'); }
            if ($purpose === 'reset_password') {
                $this->db->prepare('UPDATE users SET password_hash=?, auth_version=auth_version+1 WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
            } else {
                $this->db->prepare('UPDATE users SET email_verified_at=COALESCE(email_verified_at,UTC_TIMESTAMP()) WHERE id=?')->execute([$id]);
            }
            $this->db->prepare('UPDATE account_tokens SET consumed_at=UTC_TIMESTAMP() WHERE user_id=? AND purpose=? AND consumed_at IS NULL')->execute([$id,$purpose]);
            $this->audit((int) $id, 'auth.' . $purpose);
            $this->db->commit();
        } catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
    }
    public function audit(?int $id, string $event): void
    {
        $this->db->prepare('INSERT INTO audit_logs (user_id,event,context) VALUES (?,?,?)')->execute([$id,$event,'{}']);
    }
}
