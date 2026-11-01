<?php
declare(strict_types=1);
namespace FoodRescue\Services;
use FoodRescue\Core\{HttpException,Input};
use FoodRescue\Middleware\Authorize;
use FoodRescue\Models\Repository;
final class DonationService extends Repository
{
    public function save(array $user,array $data): int
    {
        Authorize::check($user,['donor']);
        $org=(new OrganizationService($this->db))->member(Input::id($data,'organization_id'),(int)$user['id']);
        if ($org['type']!=='donor') { throw new HttpException(422,'Choose a donor organization.'); }
        $category=Input::id($data,'category_id');
        if (!$this->one('SELECT id FROM food_categories WHERE id=? AND active=1',[$category])) { throw new HttpException(422,'Choose an active food category.'); }
        $rule=$this->one('SELECT * FROM safety_rules WHERE category_id=? ORDER BY id DESC LIMIT 1',[$category]);
        if (!$rule) { throw new HttpException(422,'No screening rule is configured for this category.'); }
        $unit=Input::choice($data,'unit',['kg','g','portions','items']);$quantity=round(Input::number($data,'quantity',0.001,100000),3);
        $mass=round(match($unit) {'kg'=>1.0,'g'=>0.001,default=>Input::number($data,'unit_mass_kg',0.001,1000)},4);
        $kg=round($quantity*$mass,3);if ($kg<0.001 || $kg>100000) { throw new HttpException(422,'Converted food weight must be between 0.001 and 100000 kg.'); }
        $food=[
            'name'=>Input::text($data,'name',160),'description'=>Input::text($data,'description',4000),
            'prepared_at'=>Input::date($data,'prepared_at'),'use_by'=>Input::date($data,'use_by'),'deadline'=>Input::date($data,'deadline'),
            'address'=>Input::text($data,'address',1000),'latitude'=>Input::number($data,'latitude',-90,90),'longitude'=>Input::number($data,'longitude',-180,180),
            'storage_method'=>Input::choice($data,'storage_method',['ambient','chilled','frozen','hot']),
            'temperature_c'=>isset($data['temperature_c']) && $data['temperature_c']!=='' ? Input::number($data,'temperature_c',-100,150) : null,
            'packaging'=>Input::choice($data,'packaging',['intact','damaged','unknown']),
            'allergens'=>Input::text($data,'allergens',2000,false),'ingredients'=>Input::text($data,'ingredients',2000,false),
            'handling_declared'=>(int)Input::flag($data,'handling_declared'),'unserved'=>(int)Input::flag($data,'unserved'),'contamination_free'=>(int)Input::flag($data,'contamination_free'),
        ];
        $screen=(new SafetyService())->screen($food,json_decode($rule['parameters'],true,512,JSON_THROW_ON_ERROR),time());
        $values=[$user['id'],$org['id'],$category,$quantity,$unit,$mass,$kg,...array_values($food),$screen['status'],json_encode($screen['reasons'],JSON_THROW_ON_ERROR),$rule['id']];
        return $this->transaction(function () use ($user,$data,$food,$values,$screen,$rule) {
            $columns='donor_id,organization_id,category_id,quantity,unit,unit_mass_kg,quantity_kg,name,description,prepared_at,use_by,deadline,address,latitude,longitude,storage_method,temperature_c,packaging,allergens,ingredients,handling_declared,unserved,contamination_free,status,safety_reasons,safety_rule_id';
            if (!empty($data['id'])) {
                $id=Input::id($data);$old=$this->one('SELECT * FROM donations WHERE id=? FOR UPDATE',[$id]);
                if (!$old || (int)$old['donor_id']!==(int)$user['id']) { throw new HttpException(403,'You do not own this donation.'); }
                if (!in_array($old['status'],['available','review','rejected'],true)) { throw new HttpException(409,'Only unclaimed listings can be edited.'); }
                if ($old['status']!==$screen['status']) { Lifecycle::assert($old['status'],$screen['status']); }
                $set=implode(',',array_map(fn($column)=>$column . '=?',explode(',',$columns)));
                $this->execute('UPDATE donations SET ' . $set . ' WHERE id=?',[...$values,$id]);
            } else { $id=$this->insert('INSERT INTO donations(' . $columns . ') VALUES (' . implode(',',array_fill(0,count($values),'?')) . ')',$values); }
            $this->execute('INSERT INTO food_safety_records(donation_id,rule_id,actor_id,outcome,reasons,inputs) VALUES (?,?,?,?,?,?)',[$id,$rule['id'],$user['id'],$screen['status'],json_encode($screen['reasons'],JSON_THROW_ON_ERROR),json_encode($food,JSON_THROW_ON_ERROR)]);
            $this->execute('INSERT INTO pickup_events(donation_id,actor_id,event,details) VALUES (?,?,?,?)',[$id,$user['id'],'screening.' . $screen['status'],json_encode($screen['reasons'],JSON_THROW_ON_ERROR)]);
            $notify=new NotificationService($this->db);
            $notify->send((int)$user['id'],'screening:' . $this->db->lastInsertId(),'Donation screening recorded',$food['name'] . ': ' . $screen['status'],'/donations/' . $id);
            if ($screen['status']==='available') {
                foreach ($this->all('SELECT DISTINCT r.user_id FROM receivers r JOIN receiver_categories c ON c.receiver_id=r.id JOIN users u ON u.id=r.user_id WHERE c.category_id=? AND r.collection_available=1 AND u.status=\'active\'',[$values[2]]) as $receiver) {
                    $notify->send((int)$receiver['user_id'],'donation:' . $id,'New donation available',$food['name'] . ' is available for collection.','/donations/' . $id);
                }
            }
            return $id;
        });
    }
    public function withdraw(array $user,int $id): void
    {
        $this->transaction(function () use ($user,$id) {
            $food=$this->one('SELECT * FROM donations WHERE id=? FOR UPDATE',[$id]);
            if (!$food || (int)$food['donor_id']!==(int)$user['id']) { throw new HttpException(403,'You do not own this donation.'); }
            Lifecycle::assert($food['status'],'withdrawn');
            $this->execute("UPDATE donations SET status='withdrawn' WHERE id=?",[$id]);
            $this->execute("INSERT INTO pickup_events(donation_id,actor_id,event,details) VALUES (?,?,'withdrawn','{}')",[$id,$user['id']]);
        });
    }
    public function relist(array $user,int $id): void
    {
        $this->transaction(function () use ($user,$id) {
            $food=$this->one('SELECT * FROM donations WHERE id=? FOR UPDATE',[$id]);
            if (!$food || (int)$food['donor_id']!==(int)$user['id']) { throw new HttpException(403,'You do not own this donation.'); }
            $rule=$this->one('SELECT * FROM safety_rules WHERE category_id=? ORDER BY id DESC LIMIT 1',[$food['category_id']]);
            $result=(new SafetyService())->screen($food,json_decode($rule['parameters'],true),time());
            if ($result['status']!=='available') { throw new HttpException(422,'This listing no longer passes current screening: ' . implode(' ',$result['reasons'])); }
            Lifecycle::assert($food['status'],'available');
            $this->execute("UPDATE donations SET status='available',safety_rule_id=?,safety_reasons='[]' WHERE id=?",[$rule['id'],$id]);
            $this->execute("INSERT INTO food_safety_records(donation_id,rule_id,actor_id,outcome,reasons,inputs) VALUES (?,?,?,'available','[]',?)",[$id,$rule['id'],$user['id'],json_encode($food,JSON_THROW_ON_ERROR)]);
            $this->execute("INSERT INTO pickup_events(donation_id,actor_id,event,details) VALUES (?,?,'relisted','{}')",[$id,$user['id']]);
        });
    }
}
