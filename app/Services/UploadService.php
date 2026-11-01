<?php
declare(strict_types=1);
namespace FoodRescue\Services;
use FoodRescue\Models\Repository;
use FoodRescue\Core\{HttpException,Input};
final class UploadService extends Repository
{
    public function upload(array $user,array $data,array $files): void
    {
        $kind=Input::choice($data,'kind',['donation','document','profile']);
        $id=$kind==='profile' ? (int)$user['id'] : Input::id($data);
        if ($kind==='donation' && !$this->one('SELECT id FROM donations WHERE id=? AND donor_id=?',[$id,$user['id']])) { throw new HttpException(403,'You do not own this donation.'); }
        if ($kind==='document' && !$this->one('SELECT id FROM organizations WHERE id=? AND owner_user_id=?',[$id,$user['id']])) { throw new HttpException(403,'You do not own this organization.'); }
        $file=$files['file'] ?? null;
        if (!$file || is_array($file['error']) || $file['error']!==UPLOAD_ERR_OK || $file['size']>5*1024*1024 || !is_uploaded_file($file['tmp_name'])) { throw new HttpException(422,'Choose a file up to 5 MB.'); }
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if ($kind==='document') { $types['application/pdf']='pdf'; }
        if (!isset($types[$mime])) { throw new HttpException(422,'Supported files: JPEG, PNG, WebP, or PDF for documents.'); }
        if (str_starts_with($mime,'image/')) {
            $info=getimagesize($file['tmp_name']);
            if (!$info || $info[0]*$info[1]>20000000) { throw new HttpException(422,'Invalid or oversized image dimensions.'); }
        }
        $path=bin2hex(random_bytes(24)) . '.' . $types[$mime];
        $destination=BASE_PATH . '/storage/private/' . $path;
        if (!move_uploaded_file($file['tmp_name'],$destination)) { throw new \RuntimeException('Upload could not be stored.'); }
        chmod($destination,0600);
        try {
            if ($kind==='donation') { $this->execute('INSERT INTO donation_images(donation_id,storage_path,mime_type) VALUES (?,?,?)',[$id,$path,$mime]); }
            elseif ($kind==='document') { $this->execute('INSERT INTO organization_documents(organization_id,storage_path,original_name,mime_type) VALUES (?,?,?,?)',[$id,$path,substr(basename($file['name']),0,255),$mime]); }
            else { $this->execute('INSERT INTO user_profiles(user_id,photograph_path) VALUES (?,?) ON DUPLICATE KEY UPDATE photograph_path=VALUES(photograph_path)',[$id,$path]); }
        } catch (\Throwable $error) { unlink($destination);throw $error; }
    }
    public function file(array $user,string $kind,int $id): array
    {
        if ($kind==='donation') {
            $file=$this->one('SELECT i.storage_path,i.mime_type,d.id AS donation_id,d.donor_id,d.status FROM donation_images i JOIN donations d ON d.id=i.donation_id WHERE i.id=?',[$id]);
            if ($file && $user['role']!=='super_admin' && (int)$file['donor_id']!==(int)$user['id']) {
                $participant=$this->one('SELECT p.id FROM pickup_schedules p JOIN receivers r ON r.id=p.receiver_id WHERE p.donation_id=? AND (r.user_id=? OR p.volunteer_id=?)',[$file['donation_id'],$user['id'],$user['id']]);
                if (!$participant && !($user['role']==='receiver' && $file['status']==='available')) { $file=null; }
            }
        }
        elseif ($kind==='document') { $file=$this->one('SELECT d.storage_path,d.mime_type FROM organization_documents d JOIN organizations o ON o.id=d.organization_id WHERE d.id=? AND o.owner_user_id=?',[$id,$user['id']]); }
        elseif ($kind==='profile') { $row=$id===(int)$user['id'] ? $this->one('SELECT photograph_path FROM user_profiles WHERE user_id=?',[$id]) : null;$file=$row && $row['photograph_path'] ? ['storage_path'=>$row['photograph_path'],'mime_type'=>(new \finfo(FILEINFO_MIME_TYPE))->file(BASE_PATH . '/storage/private/' . $row['photograph_path'])] : null; }
        else { $file=null; }
        return $file ?? throw new HttpException(404,'File not found.');
    }
}
