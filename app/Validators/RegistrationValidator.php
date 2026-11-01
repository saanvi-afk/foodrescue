<?php
declare(strict_types=1);
namespace FoodRescue\Validators;
final class RegistrationValidator
{
    public const PUBLIC_ROLES = ['donor', 'receiver', 'volunteer'];
    public static function errors(array $data): array
    {
        $errors = [];
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || strlen($name) > 120) { $errors[] = 'Enter a name of up to 120 characters.'; }
        $email = (string) ($data['email'] ?? '');
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Enter a valid email address.'; }
        $password = (string) ($data['password'] ?? '');
        if (strlen($password) < 12 || strlen($password) > 72) { $errors[] = 'Use a password between 12 and 72 bytes.'; }
        if (!in_array($data['role'] ?? '', self::PUBLIC_ROLES, true)) { $errors[] = 'Select a permitted account type.'; }
        return $errors;
    }
}
