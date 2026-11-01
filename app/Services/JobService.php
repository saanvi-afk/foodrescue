<?php
declare(strict_types=1);
namespace FoodRescue\Services;
use FoodRescue\Models\Repository;
final class JobService extends Repository
{
    public function expire(): int
    {
        $count=0;
        foreach ($this->all("SELECT id FROM donations WHERE deadline<=UTC_TIMESTAMP() AND status IN ('available','review','accepted','scheduled','failed','cancelled') ORDER BY id") as $candidate) {
            $changed=$this->transaction(function () use ($candidate) {
                $food=$this->one('SELECT * FROM donations WHERE id=? FOR UPDATE',[$candidate['id']]);
                if (strtotime($food['deadline'])>time() || !in_array($food['status'],['available','review','accepted','scheduled','failed','cancelled'],true)) { return false; }
                $reference=$this->one("SELECT receiver_id,id FROM pickup_schedules WHERE donation_id=? AND status IN ('accepted','scheduled')",[$food['id']]);
                if ($reference) {
                    $this->one('SELECT id FROM receivers WHERE id=? FOR UPDATE',[$reference['receiver_id']]);
                    $pickup=$this->one("SELECT * FROM pickup_schedules WHERE id=? AND status IN ('accepted','scheduled') FOR UPDATE",[$reference['id']]);
                    if ($pickup) {
                        $this->execute("UPDATE pickup_schedules SET status='expired',token_hash=NULL WHERE id=?",[$pickup['id']]);
                        $this->execute('UPDATE receivers SET reserved_kg=reserved_kg-? WHERE id=?',[$food['quantity_kg'],$pickup['receiver_id']]);
                        $receiver=$this->one('SELECT user_id FROM receivers WHERE id=?',[$pickup['receiver_id']]);
                        (new NotificationService($this->db))->send((int)$receiver['user_id'],'expired:' . $food['id'],'Pickup expired',$food['name'] . ' passed its collection deadline.','/pickups');
                    }
                }
                Lifecycle::assert($food['status'],'expired');
                $this->execute("UPDATE donations SET status='expired' WHERE id=?",[$food['id']]);
                $this->execute("INSERT INTO pickup_events(donation_id,event,details) VALUES (?,'expired','{}')",[$food['id']]);
                (new NotificationService($this->db))->send((int)$food['donor_id'],'expired:' . $food['id'],'Donation expired',$food['name'] . ' passed its collection deadline.','/donations/' . $food['id']);return true;
            });
            if ($changed) { $count++; }
        }return $count;
    }
    public function reminders(): int
    {
        $before=(int)$this->one('SELECT COUNT(*) AS n FROM notifications')['n'];$notify=new NotificationService($this->db);
        foreach ($this->all("SELECT p.*,d.donor_id,d.name,r.user_id FROM pickup_schedules p JOIN donations d ON d.id=p.donation_id JOIN receivers r ON r.id=p.receiver_id WHERE p.status='scheduled' AND p.scheduled_at BETWEEN UTC_TIMESTAMP() AND UTC_TIMESTAMP()+INTERVAL 30 MINUTE") as $pickup) {
            foreach (array_unique([(int)$pickup['donor_id'],(int)$pickup['user_id'],(int)($pickup['volunteer_id'] ?? 0)]) as $actor) {
                if ($actor) { $notify->send($actor,'reminder:' . $pickup['id'] . ':' . strtotime($pickup['scheduled_at']),'Pickup reminder',$pickup['name'] . ' is scheduled within the next 30 minutes.','/pickups'); }
            }
        }
        foreach ($this->all("SELECT * FROM donations WHERE status='available' AND deadline BETWEEN UTC_TIMESTAMP() AND UTC_TIMESTAMP()+INTERVAL 60 MINUTE") as $food) {
            $notify->send((int)$food['donor_id'],'deadline:' . $food['id'] . ':' . strtotime($food['deadline']),'Collection deadline approaching',$food['name'] . ' has less than one hour remaining.','/donations/' . $food['id']);
        }
        return (int)$this->one('SELECT COUNT(*) AS n FROM notifications')['n']-$before;
    }
}
