<?php
declare(strict_types=1);
namespace FoodRescue\Services;
use FoodRescue\Core\{HttpException,Input};
use FoodRescue\Models\Repository;
use FoodRescue\Middleware\Authorize;
final class MatchingService extends Repository
{
    public function configuration(?int $id=null): array
    {
        $row=$id ? $this->one('SELECT * FROM algorithm_configurations WHERE id=?',[$id]) : $this->one('SELECT * FROM algorithm_configurations ORDER BY id DESC LIMIT 1');
        if (!$row) { throw new HttpException(422,'Matching configuration not found.'); }
        $row['weights']=json_decode($row['weights'],true,512,JSON_THROW_ON_ERROR);$row['parameters']=json_decode($row['parameters'],true,512,JSON_THROW_ON_ERROR);return $row;
    }
    public function receivers(): array
    {
        $rows=$this->all("SELECT r.*,u.name,u.status,(SELECT COUNT(*) FROM pickup_schedules p WHERE p.receiver_id=r.id AND p.status IN ('completed','failed','expired')) AS history_count,(SELECT COUNT(*) FROM pickup_schedules p WHERE p.receiver_id=r.id AND p.status='completed') AS history_success FROM receivers r JOIN users u ON u.id=r.user_id WHERE u.deleted_at IS NULL");
        foreach ($rows as &$row) {
            $row['categories']=array_column($this->all('SELECT category_id FROM receiver_categories WHERE receiver_id=?',[$row['id']]),'category_id');
            $availability=$this->one('SELECT available_from,available_until FROM receiver_availability WHERE receiver_id=? AND available_until>UTC_TIMESTAMP() ORDER BY available_from LIMIT 1',[$row['id']]);
            $row=array_merge($row,$availability ?? []);
        }unset($row);return $rows;
    }
    public function recommendations(array $user,int $id,bool $persist=true): array
    {
        $food=$this->one('SELECT d.*,u.status AS donor_status FROM donations d JOIN users u ON u.id=d.donor_id WHERE d.id=?',[$id]) ?? throw new HttpException(404,'Donation not found.');
        if (!in_array($user['role'],['donor','receiver','super_admin'],true) || ($user['role']==='donor' && (int)$food['donor_id']!==(int)$user['id'])) { throw new HttpException(403,'You cannot access these matching results.'); }
        $rule=$this->one('SELECT * FROM safety_rules WHERE category_id=? ORDER BY id DESC LIMIT 1',[$food['category_id']]);
        $screen=(new SafetyService())->screen($food,json_decode($rule['parameters'],true),time());
        if ($food['donor_status']!=='active' || $screen['status']!=='available') { return ['ranked'=>[],'excluded'=>[['reason'=>'Current mandatory screening or account eligibility does not pass.']]]; }
        $config=$this->configuration();$receivers=$this->receivers();
        if ($user['role']==='receiver') { $receivers=array_values(array_filter($receivers,fn($r)=>(int)$r['user_id']===(int)$user['id'])); }
        $result=(new TAMRAService())->rank($food,$receivers,$config,time());
        if ($persist) { foreach ($result['ranked'] as &$match) {
            $match['match_id']=$this->record($food,$match,$config,$receivers);
        }unset($match); }
        $result['configuration']=['id'=>$config['id'],'version'=>$config['version'],'weights'=>$config['weights'],'parameters'=>$config['parameters']];return $result;
    }
    private function record(array $food,array $match,array $config,array $receivers): int
    {
        $receiver=array_values(array_filter($receivers,fn($r)=>(int)$r['id']===$match['receiver_id']))[0];
        $inputs=['donation'=>$food,'receiver'=>$receiver,'as_of'=>gmdate('c'),'configuration'=>$config];
        $id=$this->insert('INSERT INTO matches(donation_id,receiver_id,configuration_id,score,inputs,explanation) VALUES (?,?,?,?,?,?)',[$food['id'],$match['receiver_id'],$config['id'],$match['score'],json_encode($inputs,JSON_THROW_ON_ERROR),json_encode($match,JSON_THROW_ON_ERROR)]);
        foreach ($match['components'] as $name=>$score) { $this->execute('INSERT INTO match_scores(match_id,component,score) VALUES (?,?,?)',[$id,$name,$score]); }return $id;
    }
    public function accept(array $user,int $donation): int
    {
        Authorize::check($user,['receiver']);
        return $this->transaction(function () use ($user,$donation) {
            $food=$this->one('SELECT * FROM donations WHERE id=? FOR UPDATE',[$donation]) ?? throw new HttpException(404,'Donation not found.');
            if ($food['status']!=='available' || strtotime($food['deadline'])<=time()) { throw new HttpException(409,'This donation is no longer available.'); }
            $receiver=$this->one('SELECT * FROM receivers WHERE user_id=? FOR UPDATE',[$user['id']]) ?? throw new HttpException(422,'Set up receiving capacity and availability first.');
            $ranked=$this->recommendations($user,$donation,true)['ranked'];
            if (!$ranked) { throw new HttpException(409,'This receiver does not currently pass capacity, category, safety or pickup constraints.'); }
            $match=$ranked[0];
            $changed=$this->execute('UPDATE receivers SET reserved_kg=reserved_kg+? WHERE id=? AND capacity_kg-reserved_kg>=?',[$food['quantity_kg'],$receiver['id'],$food['quantity_kg']]);
            if ($changed!==1) { throw new HttpException(409,'Receiving capacity is already reserved.'); }
            Lifecycle::assert($food['status'],'accepted');
            $this->execute("UPDATE donations SET status='accepted' WHERE id=?",[$donation]);
            $pickup=$this->insert('INSERT INTO pickup_schedules(donation_id,receiver_id,match_id) VALUES (?,?,?)',[$donation,$receiver['id'],$match['match_id']]);
            $this->execute("INSERT INTO match_decisions(match_id,actor_id,decision) VALUES (?,?,'accepted')",[$match['match_id'],$user['id']]);
            $this->execute("INSERT INTO pickup_events(donation_id,pickup_id,actor_id,event,details) VALUES (?,?,?,'accepted','{}')",[$donation,$pickup,$user['id']]);
            $notify=new NotificationService($this->db);
            foreach ([(int)$food['donor_id'],(int)$user['id']] as $actor) { $notify->send($actor,'accepted:' . $pickup,'Donation accepted',$food['name'] . ' has been reserved. Schedule a pickup.','/pickups'); }
            return $pickup;
        });
    }
    public function decision(array $user,array $data,string $decision): void
    {
        Authorize::check($user,[$decision==='proposed' ? 'donor' : 'receiver']);
        $row=$this->one('SELECT m.*,d.donor_id,d.name,d.status,r.user_id FROM matches m JOIN donations d ON d.id=m.donation_id JOIN receivers r ON r.id=m.receiver_id WHERE m.id=?',[Input::id($data,'match_id')]) ?? throw new HttpException(404,'Recommendation not found.');
        $actor=$decision==='proposed' ? (int)$row['donor_id'] : (int)$row['user_id'];
        if ($actor!==(int)$user['id']) { throw new HttpException(403,'This recommendation does not belong to you.'); }
        if ($row['status']!=='available') { throw new HttpException(409,'This food is no longer available.'); }
        if ($decision==='proposed') {
            $ranked=$this->recommendations($user,(int)$row['donation_id'],false)['ranked'];
            if (!array_filter($ranked,fn($r)=>(int)$r['receiver_id']===(int)$row['receiver_id'])) { throw new HttpException(409,'This receiver no longer passes matching constraints.'); }
        }
        $this->transaction(function () use ($row,$user,$decision) {
            $this->execute('INSERT INTO match_decisions(match_id,actor_id,decision) VALUES (?,?,?)',[$row['id'],$user['id'],$decision]);
            $recipient=$decision==='proposed' ? (int)$row['user_id'] : (int)$row['donor_id'];
            (new NotificationService($this->db))->send($recipient,$decision . ':' . $row['id'],'Matching recommendation ' . $decision,$row['name'] . ': a matching recommendation was ' . $decision . '.','/donations/' . $row['donation_id']);
        });
    }
}
