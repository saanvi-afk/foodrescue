<?php
declare(strict_types=1);
namespace FoodRescue\Controllers;
use FoodRescue\Core\Database;
use FoodRescue\Core\View;
final class DashboardController
{
    public function show(array $user): void
    {
        $db = Database::connection();
        $query = $db->prepare('SELECT o.name,o.type FROM organizations o JOIN organization_members m ON m.organization_id=o.id WHERE m.user_id=? ORDER BY o.created_at DESC');
        $query->execute([$user['id']]);
        $organizations = $query->fetchAll();
        $query = $db->prepare('SELECT event,created_at FROM audit_logs WHERE user_id=? ORDER BY id DESC LIMIT 8');
        $query->execute([$user['id']]);
        View::render('dashboard/index', ['title'=>'Your workspace','user'=>$user,'organizations'=>$organizations,'activity'=>$query->fetchAll(),'impact'=>(new \FoodRescue\Services\ImpactService($db))->summarize($user)]);
    }
}
