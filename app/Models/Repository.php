<?php
declare(strict_types=1);
namespace FoodRescue\Models;
use PDO;
class Repository
{
    public function __construct(protected PDO $db) {}
    public function all(string $sql,array $parameters=[]): array { $q=$this->db->prepare($sql);$q->execute($parameters);return $q->fetchAll(); }
    public function one(string $sql,array $parameters=[]): ?array { $q=$this->db->prepare($sql);$q->execute($parameters);return $q->fetch() ?: null; }
    public function execute(string $sql,array $parameters=[]): int { $q=$this->db->prepare($sql);$q->execute($parameters);return $q->rowCount(); }
    public function insert(string $sql,array $parameters=[]): int { $this->execute($sql,$parameters);return (int)$this->db->lastInsertId(); }
    public function transaction(callable $fn): mixed
    {
        $this->db->beginTransaction();
        try { $result=$fn();$this->db->commit();return $result; }
        catch (\Throwable $error) { $this->db->rollBack();throw $error; }
    }
}
