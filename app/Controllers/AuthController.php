<?php
declare(strict_types=1);
namespace FoodRescue\Controllers;
use FoodRescue\Core\Database;
use FoodRescue\Core\Session;
use FoodRescue\Core\View;
use FoodRescue\Services\AuthService;
final class AuthController
{
    public function page(string $mode): void { View::render('auth/form', ['title'=>ucwords(str_replace('-', ' ', $mode)), 'mode'=>$mode]); }
    public function handle(string $action, array $data): array
    {
        $service = new AuthService(Database::connection());
        $service->throttle($action, (string) ($data['email'] ?? ($_SESSION['user_id'] ?? 'anonymous')), $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        switch ($action) {
            case 'register':
                $id = $service->register($data);
                Session::login($id);
                return ['redirect'=>'/dashboard'];
            case 'login':
                $id=$service->login((string) ($data['email'] ?? ''), (string) ($data['password'] ?? ''));
                $service->releaseSuccessfulLogin((string)($data['email'] ?? ''),$_SERVER['REMOTE_ADDR'] ?? 'unknown');
                Session::login($id);
                return ['redirect'=>'/dashboard'];
            case 'logout':
                $service->audit((int) $_SESSION['user_id'], 'auth.logout');
                Session::logout();
                return ['redirect'=>'/login'];
            case 'forgot-password':
                $service->requestReset((string) ($data['email'] ?? ''));
                return ['message'=>'If an active account exists, a reset message has been queued.'];
            case 'reset-password':
                $service->consumeToken((string) ($data['token'] ?? ''), 'reset_password', (string) ($data['password'] ?? ''));
                Session::logout();
                return ['redirect'=>'/login?reset=1'];
            case 'verify-email':
                $service->consumeToken((string) ($data['token'] ?? ''), 'verify_email');
                return ['message'=>'Email verified. You can now return to your workspace.'];
            case 'resend-verification':
                $user = (new \FoodRescue\Models\UserRepository(Database::connection()))->find((int) $_SESSION['user_id']);
                $service->requestVerification($user);
                return ['message'=>'A verification message has been queued.'];
        }
        throw new \FoodRescue\Core\HttpException(404, 'Endpoint not found.');
    }
}
