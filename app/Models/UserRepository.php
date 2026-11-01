<?php
declare(strict_types=1);
namespace FoodRescue\Models;
use PDO;
final class UserRepository
{
    public function __construct(private PDO $db) {}
    public function find(int $id): ?array
    {
        $query = $this->db->prepare('SELECT u.id, u.auth_version, u.name, u.email, u.status, u.email_verified_at, u.created_at, r.slug AS role, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.deleted_at IS NULL');
        $query->execute([$id]);
        return $query->fetch() ?: null;
    }
    public function byEmail(string $email): ?array
    {
        $query = $this->db->prepare('SELECT u.*, r.slug AS role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email=? AND u.deleted_at IS NULL');
        $query->execute([strtolower(trim($email))]);
        return $query->fetch() ?: null;
    }
    public function create(string $name, string $email, string $password, string $role): int
    {
        $query = $this->db->prepare('INSERT INTO users (role_id,name,email,password_hash) SELECT id,?,?,? FROM roles WHERE slug=?');
        $query->execute([$name, strtolower(trim($email)), password_hash($password, PASSWORD_DEFAULT), $role]);
        if ($query->rowCount() !== 1) { throw new \RuntimeException('Unknown account role.'); }
        return (int) $this->db->lastInsertId();
    }
}
