<?php
declare(strict_types=1);
namespace FoodRescue\Controllers;
use FoodRescue\Core\{Database,HttpException,Input};
use FoodRescue\Models\Repository;
use FoodRescue\Middleware\Authorize;
use FoodRescue\Services\{OrganizationService,DonationService,MatchingService,PickupService,AdminService,ResearchService,ImpactService,UploadService,AuthService};
final class OperationsController
{
    public function dispatch(string $path,string $method,array $user,array $data): array
    {
        Authorize::check($user);$db=Database::connection();$repo=new Repository($db);
        (new AuthService($db))->throttle('operations:' . $method,(string)$user['id'],$_SERVER['REMOTE_ADDR'] ?? 'cli',240);
        if ($method==='GET') {
            if ($path==='/api/analytics/impact') { return (new ImpactService($db))->summarize($user); }
            if ($path==='/api/notifications') { return ['notifications'=>$repo->all('SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 100',[$user['id']])]; }
            if ($path==='/api/donations') { Authorize::check($user,['donor','receiver','super_admin']);return ['donations'=>$repo->all("SELECT id,name,quantity_kg,deadline,status FROM donations WHERE (status='available' AND deadline>UTC_TIMESTAMP()) OR donor_id=? ORDER BY deadline",[$user['id']])]; }
            if (preg_match('#^/api/donations/(\d+)$#',$path,$m)) {
                Authorize::check($user,['donor','receiver','super_admin']);
                $food=$repo->one('SELECT * FROM donations WHERE id=?',[(int)$m[1]]) ?? throw new HttpException(404,'Donation not found.');
                if ($user['role']==='donor' && (int)$food['donor_id']!==(int)$user['id']) { throw new HttpException(403,'You do not own this listing.'); }
                if ($user['role']==='receiver' && $food['status']!=='available' && !$repo->one('SELECT p.id FROM pickup_schedules p JOIN receivers r ON r.id=p.receiver_id WHERE p.donation_id=? AND r.user_id=?',[$food['id'],$user['id']])) { throw new HttpException(403,'This listing is not available to your account.'); }
                return ['donation'=>$food];
            }
            if ($path==='/api/receivers') { Authorize::check($user,['donor','super_admin']);return ['receivers'=>(new MatchingService($db))->receivers()]; }
            if (preg_match('#^/api/matching/recommendations/(\d+)$#',$path,$m)) { return (new MatchingService($db))->recommendations($user,(int)$m[1]); }
            if (preg_match('#^/api/research/experiments/(\d+)$#',$path,$m)) { return (new ResearchService($db))->details($user,(int)$m[1]); }
            throw new HttpException(404,'Endpoint not found.');
        }
        if (!in_array($method,['POST','PUT'],true)) { throw new HttpException(405,'Use GET, POST or PUT.'); }
        $organizations=new OrganizationService($db);$donations=new DonationService($db);$pickups=new PickupService($db);$admin=new AdminService($db);$research=new ResearchService($db);
        if ($path==='/api/organizations/save') { $organizations->save($user,$data);return ['redirect'=>'/organizations']; }
        if ($path==='/api/organizations/join') { $organizations->join($user,$data);return ['redirect'=>'/organizations']; }
        if ($path==='/api/receivers/save') { $organizations->receiver($user,$data);return ['redirect'=>'/receiving']; }
        if ($path==='/api/profile/save') { $organizations->profile($user,$data);return ['redirect'=>'/profile']; }
        if ($path==='/api/uploads') { (new UploadService($db))->upload($user,$data,$_FILES);return ['reload'=>true,'message'=>'File uploaded.']; }
        if ($path==='/api/donations' || preg_match('#^/api/donations/(\d+)$#',$path,$m)) {
            if (isset($m[1])) { $data['id']=$m[1]; }
            $id=$donations->save($user,$data);return ['redirect'=>'/donations/' . $id,'id'=>$id];
        }
        if (preg_match('#^/api/donations/(\d+)/(withdraw|relist)$#',$path,$m)) {
            $donations->{$m[2]}($user,(int)$m[1]);return ['redirect'=>'/donations/' . $m[1]];
        }
        if ($path==='/api/matching/accept') { $id=(new MatchingService($db))->accept($user,Input::id($data,'donation_id'));return ['redirect'=>'/pickups','id'=>$id]; }
        if (in_array($path,['/api/matching/propose','/api/matching/reject'],true)) { (new MatchingService($db))->decision($user,$data,$path==='/api/matching/propose' ? 'proposed' : 'declined');return ['message'=>$path==='/api/matching/propose' ? 'Receiver notified. The donation remains available until accepted.' : 'Recommendation declined. The donor has been notified.']; }
        if ($path==='/api/pickups' || $path==='/api/pickups/schedule') { return $pickups->schedule($user,$data); }
        if ($path==='/api/pickups/verify') { $pickups->verify($user,$data);return ['redirect'=>'/pickups']; }
        if ($path==='/api/pickups/complete') { $pickups->confirm($user,$data);return ['redirect'=>'/pickups']; }
        if (in_array($path,['/api/pickups/fail','/api/pickups/cancel'],true)) { $pickups->terminate($user,$data,$path==='/api/pickups/fail' ? 'failed' : 'cancelled');return ['redirect'=>'/pickups']; }
        if ($path==='/api/reviews') { $pickups->review($user,$data);return ['message'=>'Feedback saved.']; }
        if ($path==='/api/pickups/distributed') { $pickups->distributed($user,$data);return ['redirect'=>'/pickups']; }
        if ($path==='/api/notifications/read') { $repo->execute('UPDATE notifications SET read_at=COALESCE(read_at,UTC_TIMESTAMP()) WHERE user_id=? AND id=?',[$user['id'],Input::id($data)]);return ['reload'=>true,'message'=>'Notification marked as read.']; }
        if ($path==='/api/admin/account') { $admin->account($user,$data);return ['redirect'=>'/admin/manage']; }
        if ($path==='/api/admin/category') { $admin->category($user,$data);return ['redirect'=>'/admin/manage']; }
        if ($path==='/api/admin/safety') { $admin->safety($user,$data);return ['redirect'=>'/admin/manage']; }
        if ($path==='/api/settings/algorithm') { $admin->configuration($user,$data);return ['message'=>'New immutable matching configuration saved.','reload'=>true]; }
        if ($path==='/api/admin/settings') { $admin->settings($user,$data);return ['message'=>'Impact conversion factor saved.']; }
        if ($path==='/api/research/datasets') { $id=$research->generate($user,$data);return ['redirect'=>'/research/experiments','id'=>$id]; }
        if ($path==='/api/research/datasets/import') { $id=$research->import($user,$data);return ['redirect'=>'/research/experiments','id'=>$id]; }
        if (in_array($path,['/api/research/experiments','/api/research/experiments/run'],true)) { $id=$research->experiment($user,$data);return ['redirect'=>'/experiments/' . $id,'id'=>$id]; }
        throw new HttpException(404,'Endpoint not found.');
    }
}
