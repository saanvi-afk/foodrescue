<?php
declare(strict_types=1);
namespace FoodRescue\Core;
final class Session
{
    public static function start(): void
    {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('foodrescue_session');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => Environment::get('SESSION_SECURE', 'false') === 'true', 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
        $timeout=max(60,min(86400,(int)Environment::get('SESSION_IDLE_SECONDS','3600')));
        if (isset($_SESSION['user_id'],$_SESSION['last_seen']) && time()-(int)$_SESSION['last_seen']>$timeout) { self::logout(); }
        $_SESSION['last_seen']=time();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }
    public static function verifyCsrf(?string $token): void
    {
        if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) { throw new HttpException(419, 'Your session expired. Refresh the page and try again.'); }
    }
    public static function login(int $id): void
    {
        session_regenerate_id(true);
        $user = (new \FoodRescue\Models\UserRepository(Database::connection()))->find($id);
        $_SESSION = ['user_id' => $id, 'auth_version' => $user['auth_version'], 'csrf' => bin2hex(random_bytes(32))];
    }
    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}
