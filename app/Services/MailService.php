<?php
declare(strict_types=1);
namespace FoodRescue\Services;
use FoodRescue\Core\Environment as Env;
use FoodRescue\Models\Repository;
final class MailService extends Repository
{
    public function deliver(int $limit=50): array
    {
        $key='foodrescue_mail_' . Env::get('DB_DATABASE');
        $lock=$this->one('SELECT GET_LOCK(?,0) AS acquired',[$key]);
        if (!(int)$lock['acquired']) { return ['sent'=>0,'failed'=>0]; }
        $sent=0;$failed=0;
        try {
            foreach ($this->all('SELECT * FROM mail_outbox WHERE sent_at IS NULL AND attempts<5 ORDER BY id LIMIT ' . max(1,min(100,$limit))) as $row) {
                try {
                    $mail=new \PHPMailer\PHPMailer\PHPMailer(true);
                    $mail->CharSet='UTF-8';$mail->setFrom(Env::get('MAIL_FROM','noreply@foodrescue.test'),'FoodRescue');
                    $mail->addAddress($row['recipient']);$mail->Subject=$row['subject'];$mail->Body=$row['body'];
                    $mail->MessageID='<foodrescue-' . $row['id'] . '-' . hash('sha256',Env::get('DB_DATABASE')) . '@foodrescue.local>';
                    $transport=Env::get('MAIL_TRANSPORT','log');
                    if ($transport==='smtp') {
                        $mail->isSMTP();$mail->Host=Env::get('SMTP_HOST');$mail->Port=(int)Env::get('SMTP_PORT','587');
                        $mail->SMTPAuth=Env::get('SMTP_USERNAME')!=='';$mail->Username=Env::get('SMTP_USERNAME');$mail->Password=Env::get('SMTP_PASSWORD');
                        $mail->SMTPSecure=Env::get('SMTP_ENCRYPTION','tls');$mail->Timeout=15;$mail->send();
                    } elseif ($transport==='log') {
                        if (Env::get('APP_ENV')!=='local') { throw new \RuntimeException('Log delivery requires local mode. Configure SMTP for production.'); }
                        $mail->preSend();$path=BASE_PATH . '/storage/private/mail-' . $row['id'] . '.eml';
                        if (file_put_contents($path,$mail->getSentMIMEMessage(),LOCK_EX)===false) { throw new \RuntimeException('Cannot write local email.'); }chmod($path,0600);
                    } else { throw new \RuntimeException('Unknown mail transport.'); }
                    $this->execute('UPDATE mail_outbox SET sent_at=UTC_TIMESTAMP(),attempts=attempts+1,last_error=NULL WHERE id=?',[$row['id']]);$sent++;
                } catch (\Throwable $error) {
                    $this->execute('UPDATE mail_outbox SET attempts=attempts+1,last_error=? WHERE id=?',[substr($error->getMessage(),0,500),$row['id']]);$failed++;
                }
            }
        } finally { $this->one('SELECT RELEASE_LOCK(?)',[$key]); }
        return ['sent'=>$sent,'failed'=>$failed];
    }
}
