<?php
declare(strict_types=1);
namespace FoodRescue\Services;
use FoodRescue\Models\Repository;
use FoodRescue\Core\{HttpException,Input};
use FoodRescue\Middleware\Authorize;
final class OrganizationService extends Repository
{
    public function member(int $organization,int $user): array
    {
        return $this->one('SELECT o.* FROM organizations o JOIN organization_members m ON m.organization_id=o.id WHERE o.id=? AND m.user_id=?',[$organization,$user]) ?? throw new HttpException(403,'Link this organization to your account first.');
    }
    public function save(array $user,array $data): int
    {
        Authorize::check($user,['donor','receiver']);
        $name=Input::text($data,'name',180);$address=Input::text($data,'address',1000);
        $lat=Input::number($data,'latitude',-90,90);$lng=Input::number($data,'longitude',-180,180);
        $phone=Input::text($data,'contact_phone',30,false);$registration=Input::text($data,'registration_number',100,false);
        return $this->transaction(function () use ($user,$data,$name,$address,$lat,$lng,$phone,$registration) {
            if (!empty($data['id'])) {
                $id=Input::id($data);$org=$this->member($id,(int)$user['id']);
                if ((int)$org['owner_user_id']!==(int)$user['id']) { throw new HttpException(403,'Only the organization creator can edit its details.'); }
                $this->execute('UPDATE organizations SET name=?,address=?,latitude=?,longitude=?,contact_phone=?,registration_number=? WHERE id=?',[$name,$address,$lat,$lng,$phone,$registration,$id]);
            } else {
                $id=$this->insert('INSERT INTO organizations(owner_user_id,name,type,address,latitude,longitude,contact_phone,registration_number) VALUES (?,?,?,?,?,?,?,?)',[$user['id'],$name,$user['role'],$address,$lat,$lng,$phone,$registration]);
                $this->execute('INSERT INTO organization_members(organization_id,user_id) VALUES (?,?)',[$id,$user['id']]);
            }
            (new AuthService($this->db))->audit((int)$user['id'],'organization.saved');
            return $id;
        });
    }
    public function join(array $user,array $data): void
    {
        Authorize::check($user,['donor','receiver']);
        $org=$this->one('SELECT id FROM organizations WHERE id=? AND type=?',[Input::id($data,'organization_id'),$user['role']]);
        if (!$org) { throw new HttpException(422,'Select an organization matching your account type.'); }
        $this->execute('INSERT IGNORE INTO organization_members(organization_id,user_id) VALUES (?,?)',[$org['id'],$user['id']]);
        (new AuthService($this->db))->audit((int)$user['id'],'organization.joined');
    }
    public function receiver(array $user,array $data): void
    {
        Authorize::check($user,['receiver']);
        $organization=Input::id($data,'organization_id');$org=$this->member($organization,(int)$user['id']);
        if ($org['type']!=='receiver') { throw new HttpException(422,'Choose a receiving organization.'); }
        $capacity=round(Input::number($data,'capacity_kg',0.001,100000),3);
        $lat=Input::number($data,'latitude',-90,90);$lng=Input::number($data,'longitude',-180,180);
        $distance=Input::number($data,'max_distance_km',0.1,500);
        $from=Input::date($data,'available_from');$until=Input::date($data,'available_until');
        if ($until<=$from || strtotime($until)<=time()) { throw new HttpException(422,'Provide a future collection availability window.'); }
        $ids=$data['category_ids'] ?? [];
        if (!is_array($ids) || !$ids || count($ids)>100) { throw new HttpException(422,'Select at least one food category.'); }
        $categories=[];
        foreach ($ids as $id) { $id=Input::id(['id'=>$id]); if (!$this->one('SELECT id FROM food_categories WHERE id=? AND active=1',[$id])) { throw new HttpException(422,'Invalid food category.'); }$categories[]=$id; }
        $this->transaction(function () use ($user,$data,$organization,$capacity,$lat,$lng,$distance,$from,$until,$categories) {
            $this->one('SELECT id FROM users WHERE id=? FOR UPDATE',[$user['id']]);
            $receiver=$this->one('SELECT * FROM receivers WHERE user_id=? FOR UPDATE',[$user['id']]);
            if ($receiver && (float)$receiver['reserved_kg']>$capacity) { throw new HttpException(409,'Capacity cannot be lower than currently reserved food.'); }
            $this->execute('INSERT INTO receivers(user_id,organization_id,capacity_kg,transport_available,collection_available,latitude,longitude,max_distance_km) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE organization_id=VALUES(organization_id),capacity_kg=VALUES(capacity_kg),transport_available=VALUES(transport_available),collection_available=VALUES(collection_available),latitude=VALUES(latitude),longitude=VALUES(longitude),max_distance_km=VALUES(max_distance_km)',[$user['id'],$organization,$capacity,(int)Input::flag($data,'transport_available'),(int)Input::flag($data,'collection_available'),$lat,$lng,$distance]);
            $id=(int)$this->one('SELECT id FROM receivers WHERE user_id=?',[$user['id']])['id'];
            $this->execute('DELETE FROM receiver_categories WHERE receiver_id=?',[$id]);
            foreach (array_unique($categories) as $category) { $this->execute('INSERT INTO receiver_categories(receiver_id,category_id) VALUES (?,?)',[$id,$category]); }
            $this->execute('DELETE FROM receiver_availability WHERE receiver_id=?',[$id]);
            $this->execute('INSERT INTO receiver_availability(receiver_id,available_from,available_until) VALUES (?,?,?)',[$id,$from,$until]);
            (new AuthService($this->db))->audit((int)$user['id'],'receiver.preferences_updated');
        });
    }
    public function profile(array $user,array $data): void
    {
        $name=Input::text($data,'name',120);$phone=Input::text($data,'phone',30,false);$timezone=Input::text($data,'timezone',64);
        if (!in_array($timezone,\DateTimeZone::listIdentifiers(),true)) { throw new HttpException(422,'Select a valid timezone.'); }
        $this->transaction(function () use ($user,$data,$name,$phone,$timezone) {
            $this->execute('UPDATE users SET name=? WHERE id=?',[$name,$user['id']]);
            $this->execute('INSERT INTO user_profiles(user_id,phone,timezone) VALUES (?,?,?) ON DUPLICATE KEY UPDATE phone=VALUES(phone),timezone=VALUES(timezone)',[$user['id'],$phone,$timezone]);
            if ($user['role']==='volunteer') {
                $this->execute('INSERT INTO volunteers(user_id,available,transport) VALUES (?,?,?) ON DUPLICATE KEY UPDATE available=VALUES(available),transport=VALUES(transport)',[$user['id'],(int)Input::flag($data,'available'),Input::text($data,'transport',100)]);
            }
            (new AuthService($this->db))->audit((int)$user['id'],'profile.updated');
        });
    }
}
