<?php
declare(strict_types=1);
namespace FoodRescue\Services;
use FoodRescue\Models\Repository;
final class NotificationService extends Repository
{
    public function send(int $user,string $key,string $title,string $message,string $link='/notifications'): void
    {
        $inserted=$this->execute('INSERT IGNORE INTO notifications(user_id,event_key,title,message,link) VALUES (?,?,?,?,?)',[$user,$key . ':' . $user,$title,$message,$link]);
        if ($inserted) {
            $account=$this->one('SELECT email FROM users WHERE id=?',[$user]);
            $this->execute('INSERT INTO mail_outbox(recipient,subject,body) VALUES (?,?,?)',[$account['email'],$title,$message]);
        }
    }
}
