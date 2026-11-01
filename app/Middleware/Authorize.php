<?php
declare(strict_types=1);
namespace FoodRescue\Middleware;
use FoodRescue\Core\HttpException;
final class Authorize
{
    public static function check(?array $user, array $roles = []): array
    {
        if (!$user) { throw new HttpException(401, 'Please sign in to continue.'); }
        if ($user['status'] !== 'active') { throw new HttpException(403, 'This account is suspended.'); }
        if ($roles && !in_array($user['role'], $roles, true)) { throw new HttpException(403, 'You do not have access to this workspace.'); }
        return $user;
    }
}
