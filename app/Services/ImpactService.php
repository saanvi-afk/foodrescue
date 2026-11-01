<?php
declare(strict_types=1);
namespace FoodRescue\Services;
use FoodRescue\Models\Repository;
final class ImpactService extends Repository
{
    public function summarize(array $user): array
    {
        $scope='';$args=[];
        if ($user['role']==='donor') { $scope=' AND d.donor_id=?';$args=[$user['id']]; }
        elseif ($user['role']==='receiver') { $scope=' AND EXISTS(SELECT 1 FROM pickup_schedules ps JOIN receivers rr ON rr.id=ps.receiver_id WHERE ps.donation_id=d.id AND rr.user_id=?)';$args=[$user['id']]; }
        elseif ($user['role']==='volunteer') { $scope=' AND EXISTS(SELECT 1 FROM pickup_schedules ps WHERE ps.donation_id=d.id AND ps.volunteer_id=?)';$args=[$user['id']]; }
        $pickupScope=match($user['role']) {
            'donor'=>' AND d.donor_id=?',
            'receiver'=>' AND p.receiver_id IN (SELECT id FROM receivers WHERE user_id=?)',
            'volunteer'=>' AND p.volunteer_id=?',
            default=>'',
        };
        $totals=$this->one("SELECT COUNT(*) AS listing_count,COALESCE(SUM(quantity_kg),0) AS offered_kg,COALESCE(SUM(IF(status='rejected',quantity_kg,0)),0) AS rejected_kg,SUM(status='expired') AS expired_count FROM donations d WHERE 1=1" . $scope,$args);
        $accepted=$this->one('SELECT COALESCE(SUM(d.quantity_kg),0) AS accepted_kg FROM donations d WHERE EXISTS(SELECT 1 FROM pickup_schedules p WHERE p.donation_id=d.id)' . $scope,$args);
        $impact=$this->one('SELECT COALESCE(SUM(i.collected_kg),0) AS collected_kg,COALESCE(SUM(i.distributed_kg),0) AS distributed_kg FROM impact_records i JOIN pickup_schedules p ON p.id=i.pickup_id JOIN donations d ON d.id=p.donation_id WHERE 1=1' . $pickupScope,$args);
        $outcomes=$this->one("SELECT SUM(p.status='completed') AS successful_pickups,SUM(p.status='failed') AS failed_pickups,SUM(p.status='expired') AS expired_pickups FROM pickup_schedules p JOIN donations d ON d.id=p.donation_id WHERE 1=1" . $pickupScope,$args);
        $factor=(float)json_decode($this->one("SELECT setting_value FROM platform_settings WHERE setting_key='meal_mass_kg'")['setting_value'],true);
        $result=array_merge($totals,$accepted,$impact,$outcomes);
        foreach ($result as &$value) { $value=(float)($value ?? 0); }unset($value);
        $denominator=$result['successful_pickups']+$result['failed_pickups']+$result['expired_pickups'];
        $result['pickup_success_rate']=$denominator ? $result['successful_pickups']/$denominator : null;
        $result['estimated_meals']=$factor>0 ? $result['distributed_kg']/$factor : 0;
        $result['meal_mass_kg']=$factor;
        $result['estimated_waste_prevented_kg']=$result['collected_kg'];
        $result['monthly']=$this->all("SELECT DATE_FORMAT(d.created_at,'%Y-%m') AS month,SUM(d.quantity_kg) AS offered_kg FROM donations d WHERE 1=1" . $scope . " GROUP BY month ORDER BY month",$args);
        $result['categories']=$this->all('SELECT c.name,SUM(d.quantity_kg) AS offered_kg FROM donations d JOIN food_categories c ON c.id=d.category_id WHERE 1=1' . $scope . ' GROUP BY c.id,c.name',$args);
        $result['donors']=$this->all("SELECT u.name,COALESCE(SUM(i.collected_kg),0) AS collected_kg FROM impact_records i JOIN pickup_schedules p ON p.id=i.pickup_id JOIN donations d ON d.id=p.donation_id JOIN users u ON u.id=d.donor_id WHERE 1=1" . $pickupScope . ' GROUP BY u.id,u.name ORDER BY collected_kg DESC LIMIT 10',$args);
        $result['receivers']=$this->all("SELECT u.name,COALESCE(SUM(i.collected_kg),0) AS collected_kg FROM impact_records i JOIN pickup_schedules p ON p.id=i.pickup_id JOIN donations d ON d.id=p.donation_id JOIN receivers r ON r.id=p.receiver_id JOIN users u ON u.id=r.user_id WHERE 1=1" . $pickupScope . ' GROUP BY u.id,u.name ORDER BY collected_kg DESC LIMIT 10',$args);
        $points=[];
        foreach ($this->all("SELECT p.status,m.explanation FROM pickup_schedules p JOIN donations d ON d.id=p.donation_id JOIN matches m ON m.id=p.match_id WHERE p.status IN ('completed','failed','expired')" . $pickupScope,$args) as $row) {
            $match=json_decode($row['explanation'],true);if (isset($match['feasibility']['score'])) { $points[]=['score'=>(float)$match['feasibility']['score'],'actual'=>(int)($row['status']==='completed')]; }
        }
        $result['prediction_quality']=(new PredictionMetrics())->evaluate($points);
        $result['geography']=$user['role']==='researcher' ? [] : $this->all('SELECT ROUND(d.latitude,2) AS latitude,ROUND(d.longitude,2) AS longitude,SUM(d.quantity_kg) AS offered_kg FROM donations d WHERE 1=1' . $scope . ' GROUP BY ROUND(d.latitude,2),ROUND(d.longitude,2)',$args);
        if ($user['role']==='researcher') { $result['donors']=[];$result['receivers']=[]; }
        return $result;
    }
}
