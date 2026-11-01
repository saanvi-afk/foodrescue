<?php
declare(strict_types=1);
namespace FoodRescue\Services;
use FoodRescue\Core\{HttpException,Input};
use FoodRescue\Models\Repository;
final class PickupService extends Repository
{
    private function screen(int $id): void
    {
        $food=$this->one('SELECT * FROM donations WHERE id=?',[$id]);
        $rule=$this->one('SELECT parameters FROM safety_rules WHERE category_id=? ORDER BY id DESC LIMIT 1',[$food['category_id']]);
        $result=(new SafetyService())->screen($food,json_decode($rule['parameters'],true),time());
        if ($result['status']!=='available') { throw new HttpException(422,'Current safety screening prevents collection: ' . implode(' ',$result['reasons'])); }
    }
    private function lock(int $id,array $user,bool $allowVolunteer=true): array
    {
        // Use donation -> receiver -> pickup lock order consistently with acceptance and expiry.
        $reference=$this->one('SELECT donation_id,receiver_id FROM pickup_schedules WHERE id=?',[$id]) ?? throw new HttpException(404,'Pickup not found.');
        $this->one('SELECT id FROM donations WHERE id=? FOR UPDATE',[$reference['donation_id']]);
        $this->one('SELECT id FROM receivers WHERE id=? FOR UPDATE',[$reference['receiver_id']]);
        $row=$this->one('SELECT p.*,d.donor_id,d.name,d.quantity_kg,d.deadline,d.use_by,d.status AS donation_status,r.user_id AS receiver_user FROM pickup_schedules p JOIN donations d ON d.id=p.donation_id JOIN receivers r ON r.id=p.receiver_id WHERE p.id=? FOR UPDATE',[$id]);
        $allowed=[(int)$row['donor_id'],(int)$row['receiver_user']];
        if ($allowVolunteer && $row['volunteer_id']) { $allowed[]=(int)$row['volunteer_id']; }
        if (!in_array((int)$user['id'],$allowed,true)) { throw new HttpException(403,'This pickup is not assigned to you.'); }return $row;
    }
    private function event(array $row,array $user,string $event,array $details=[]): void
    {
        $this->execute('INSERT INTO pickup_events(donation_id,pickup_id,actor_id,event,details) VALUES (?,?,?,?,?)',[$row['donation_id'],$row['id'],$user['id'],$event,json_encode($details,JSON_THROW_ON_ERROR)]);
        foreach (array_unique([(int)$row['donor_id'],(int)$row['receiver_user'],(int)($row['volunteer_id'] ?? 0)]) as $actor) { if ($actor) { (new NotificationService($this->db))->send($actor,$event . ':' . $row['id'] . ':' . $this->db->lastInsertId(),'Pickup ' . str_replace('_',' ',$event),$row['name'] . ': ' . str_replace('_',' ',$event),'/pickups'); } }
    }
    public function schedule(array $user,array $data): array
    {
        return $this->transaction(function () use ($user,$data) {
            $row=$this->lock(Input::id($data),$user,false);
            if (!in_array($row['status'],['accepted','scheduled'],true)) { throw new HttpException(409,'This pickup cannot be scheduled.'); }
            $this->screen((int)$row['donation_id']);
            $at=Input::date($data,'scheduled_at');
            if (strtotime($at)<time() || $at>=$row['deadline']) { throw new HttpException(422,'Schedule within the remaining pickup window.'); }
            $receiver=array_values(array_filter((new MatchingService($this->db))->receivers(),fn($r)=>(int)$r['id']===(int)$row['receiver_id']))[0];
            $donation=$this->one('SELECT * FROM donations WHERE id=?',[$row['donation_id']]);
            $config=(new MatchingService($this->db))->configuration();
            $estimate=(new FeasibilityService())->estimate($donation,$receiver,TAMRAService::distance((float)$donation['latitude'],(float)$donation['longitude'],(float)$receiver['latitude'],(float)$receiver['longitude']),strtotime($at),$config['parameters']);
            if (!$estimate['eligible']) { throw new HttpException(422,'That pickup time leaves insufficient estimated travel/handling time.'); }
            $volunteer=empty($data['volunteer_id']) ? null : Input::id($data,'volunteer_id');
            if ($volunteer && !$this->one("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id JOIN volunteers v ON v.user_id=u.id WHERE u.id=? AND r.slug='volunteer' AND u.status='active' AND u.deleted_at IS NULL AND v.available=1",[$volunteer])) { throw new HttpException(422,'Choose an available volunteer.'); }
            if ($row['donation_status']!=='scheduled') { Lifecycle::assert($row['donation_status'],'scheduled'); }
            $token=bin2hex(random_bytes(32));
            $this->execute("UPDATE pickup_schedules SET status='scheduled',scheduled_at=?,volunteer_id=?,token_hash=?,token_consumed_at=NULL WHERE id=?",[$at,$volunteer,hash('sha256',$token),$row['id']]);
            $this->execute("UPDATE donations SET status='scheduled' WHERE id=?",[$row['donation_id']]);
            $row['volunteer_id']=$volunteer;$this->event($row,$user,'scheduled',['scheduled_at'=>$at]);
            $qr=new \Endroid\QrCode\QrCode(data: $token, size: 280, margin: 12);
            $image=(new \Endroid\QrCode\Writer\PngWriter())->write($qr)->getDataUri();
            return ['message'=>'Pickup scheduled. Save this one-use token or QR; rescheduling replaces it.','token'=>$token,'qr'=>$image];
        });
    }
    public function verify(array $user,array $data): void
    {
        $this->transaction(function () use ($user,$data) {
            $row=$this->lock(Input::id($data),$user);
            if (!in_array((int)$user['id'],[(int)$row['receiver_user'],(int)($row['volunteer_id'] ?? 0)],true)) { throw new HttpException(403,'The receiver or assigned volunteer must record collection.'); }
            $token=Input::text($data,'token',64);
            if ($row['status']!=='scheduled' || $row['token_consumed_at'] || strtotime($row['deadline'])<=time() || !hash_equals($row['token_hash'] ?? '',hash('sha256',$token))) { throw new HttpException(422,'Invalid, expired or already-used pickup token.'); }
            $quantity=round(Input::number($data,'collected_kg',0.001,(float)$row['quantity_kg']),3);
            $this->screen((int)$row['donation_id']);
            Lifecycle::assert($row['donation_status'],'collected');
            $this->execute("UPDATE pickup_schedules SET status='collected',token_consumed_at=UTC_TIMESTAMP(),collected_at=UTC_TIMESTAMP(),collected_kg=? WHERE id=?",[$quantity,$row['id']]);
            $this->execute("UPDATE donations SET status='collected' WHERE id=?",[$row['donation_id']]);
            $this->execute('INSERT INTO pickup_verifications(pickup_id,verified_by) VALUES (?,?)',[$row['id'],$user['id']]);
            $this->execute('INSERT INTO impact_records(pickup_id,collected_kg) VALUES (?,?)',[$row['id'],$quantity]);
            $this->event($row,$user,'collected',['collected_kg'=>$quantity]);
        });
    }
    public function confirm(array $user,array $data): void
    {
        $this->transaction(function () use ($user,$data) {
            $row=$this->lock(Input::id($data),$user,false);
            if ($row['status']!=='collected') { throw new HttpException(409,'Collection must be recorded before confirmation.'); }
            if ((int)$user['id']===(int)$row['donor_id']) { $this->execute('UPDATE pickup_schedules SET donor_confirmed=1 WHERE id=?',[$row['id']]);$row['donor_confirmed']=1; }
            else {
                $distributed=Input::number($data,'distributed_kg',0,(float)$row['collected_kg']);
                $this->execute('UPDATE pickup_schedules SET receiver_confirmed=1 WHERE id=?',[$row['id']]);$row['receiver_confirmed']=1;
                $this->execute('UPDATE impact_records SET distributed_kg=? WHERE pickup_id=?',[$distributed,$row['id']]);
            }
            if ($row['receiver_confirmed'] && $row['donor_confirmed']) {
                Lifecycle::assert($row['donation_status'],'completed');
                $this->execute("UPDATE pickup_schedules SET status='completed',completed_at=UTC_TIMESTAMP() WHERE id=?",[$row['id']]);
                $this->execute("UPDATE donations SET status='completed' WHERE id=?",[$row['donation_id']]);
                $this->execute('UPDATE receivers SET reserved_kg=reserved_kg-? WHERE id=?',[$row['quantity_kg'],$row['receiver_id']]);
                $this->event($row,$user,'completed');
            } else { $this->event($row,$user,'confirmation_recorded'); }
        });
    }
    public function terminate(array $user,array $data,string $status): void
    {
        if (!in_array($status,['failed','cancelled'],true)) { throw new HttpException(422,'Invalid pickup outcome.'); }
        $this->transaction(function () use ($user,$data,$status) {
            $row=$this->lock(Input::id($data),$user,$status==='failed');
            if (!in_array($row['status'],['accepted','scheduled'],true)) { throw new HttpException(409,'This pickup is already terminal or collected.'); }
            $reason=Input::text($data,'reason',2000);Lifecycle::assert($row['donation_status'],$status);
            $this->execute('UPDATE pickup_schedules SET status=?,token_hash=NULL WHERE id=?',[$status,$row['id']]);
            $this->execute('UPDATE donations SET status=? WHERE id=?',[$status,$row['donation_id']]);
            $this->execute('UPDATE receivers SET reserved_kg=reserved_kg-? WHERE id=?',[$row['quantity_kg'],$row['receiver_id']]);
            if ($status==='failed') { $this->execute('INSERT INTO pickup_failures(pickup_id,reason) VALUES (?,?)',[$row['id'],$reason]); }
            $this->event($row,$user,$status,['reason'=>$reason]);
        });
    }
    public function review(array $user,array $data): void
    {
        $row=$this->one('SELECT p.id,d.donor_id,r.user_id AS receiver_user,p.status FROM pickup_schedules p JOIN donations d ON d.id=p.donation_id JOIN receivers r ON r.id=p.receiver_id WHERE p.id=?',[Input::id($data)]);
        if (!$row || $row['status']!=='completed' || !in_array((int)$user['id'],[(int)$row['donor_id'],(int)$row['receiver_user']],true)) { throw new HttpException(403,'You can review only your completed pickups.'); }
        $rating=Input::number($data,'rating',1,5);if (floor($rating)!==$rating) { throw new HttpException(422,'Rating must be an integer.'); }
        $this->execute('INSERT INTO reviews(pickup_id,author_id,rating,comment) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE rating=VALUES(rating),comment=VALUES(comment)',[$row['id'],$user['id'],$rating,Input::text($data,'comment',2000,false)]);
    }
    public function distributed(array $user,array $data): void
    {
        $this->transaction(function () use ($user,$data) {
            $row=$this->lock(Input::id($data),$user,false);
            if ((int)$user['id']!==(int)$row['receiver_user'] || !in_array($row['status'],['collected','completed'],true)) { throw new HttpException(403,'Only the receiving party can record distribution after collection.'); }
            $kg=Input::number($data,'distributed_kg',0,(float)$row['collected_kg']);
            $this->execute('UPDATE impact_records SET distributed_kg=? WHERE pickup_id=?',[$kg,$row['id']]);
            $this->event($row,$user,'distribution_updated',['distributed_kg'=>$kg]);
        });
    }
}
