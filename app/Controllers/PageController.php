<?php
declare(strict_types=1);
namespace FoodRescue\Controllers;
use FoodRescue\Core\{Database,HttpException,View};
use FoodRescue\Models\Repository;
use FoodRescue\Middleware\Authorize;
use FoodRescue\Services\{MatchingService,ImpactService,ResearchService,ExportService};
final class PageController extends Repository
{
    public function __construct() { parent::__construct(Database::connection()); }
    public function show(string $page,array $user,int $id=0): void
    {
        $data=[];$titles=['organizations'=>'Your organizations','receiving'=>'Receiving preferences','donations'=>'Food network','donation-form'=>'Share surplus food','donation'=>'Donation details','pickups'=>'Collection coordination','notifications'=>'Your notifications','impact'=>'Recorded impact','manage'=>'Platform management','experiments'=>'Research lab','experiment'=>'Experiment results','profile'=>'Your profile'];
        if (!isset($titles[$page])) { throw new HttpException(404,'Page not found.'); }
        $data['categories']=$this->all('SELECT * FROM food_categories WHERE active=1 ORDER BY name');
        $data['organizations']=$this->all('SELECT o.* FROM organizations o JOIN organization_members m ON m.organization_id=o.id WHERE m.user_id=? ORDER BY o.name',[$user['id']]);
        switch ($page) {
            case 'organizations':
                Authorize::check($user,['donor','receiver']);
                $data['directory']=$this->all('SELECT id,name,address FROM organizations WHERE type=? ORDER BY name',[$user['role']]);
                $data['documents']=$this->all('SELECT d.* FROM organization_documents d JOIN organizations o ON o.id=d.organization_id WHERE o.owner_user_id=?',[$user['id']]);break;
            case 'receiving':
                Authorize::check($user,['receiver']);
                $receiver=$this->one('SELECT * FROM receivers WHERE user_id=?',[$user['id']]);
                $data['receiver']=$receiver ?? [];
                $data['availability']=$receiver ? $this->one('SELECT * FROM receiver_availability WHERE receiver_id=? ORDER BY available_from DESC LIMIT 1',[$receiver['id']]) : [];
                $data['selected_categories']=$receiver ? array_column($this->all('SELECT category_id FROM receiver_categories WHERE receiver_id=?',[$receiver['id']]),'category_id') : [];break;
            case 'donations':
                Authorize::check($user,['donor','receiver','super_admin']);
                $where=$user['role']==='donor' ? 'd.donor_id=?' : ($user['role']==='receiver' ? "d.status='available' AND d.deadline>UTC_TIMESTAMP() AND u.status='active'" : '1=1');
                $args=$user['role']==='donor' ? [$user['id']] : [];
                $data['donations']=$this->all('SELECT d.*,c.name AS category_name,o.name AS organization_name FROM donations d JOIN food_categories c ON c.id=d.category_id JOIN organizations o ON o.id=d.organization_id JOIN users u ON u.id=d.donor_id WHERE ' . $where . ' ORDER BY d.deadline',$args);
                if ($user['role']==='receiver') {
                    $data['recommendations']=[];foreach ($data['donations'] as $food) { $match=(new MatchingService($this->db))->recommendations($user,(int)$food['id'],false)['ranked'];$data['recommendations'][$food['id']]=$match[0] ?? null; }
                    usort($data['donations'],fn($a,$b)=>(($data['recommendations'][$b['id']]['score'] ?? -1)<=>($data['recommendations'][$a['id']]['score'] ?? -1)) ?: ($a['deadline']<=>$b['deadline']));
                }break;
            case 'donation-form':
                Authorize::check($user,['donor']);
                $data['food']=$id ? $this->one('SELECT * FROM donations WHERE id=? AND donor_id=?',[$id,$user['id']]) : [];
                if ($id && !$data['food']) { throw new HttpException(404,'Donation not found.'); }break;
            case 'donation':
                Authorize::check($user,['donor','receiver','volunteer','super_admin']);
                $food=$this->one('SELECT d.*,c.name AS category_name,o.name AS organization_name FROM donations d JOIN food_categories c ON c.id=d.category_id JOIN organizations o ON o.id=d.organization_id WHERE d.id=?',[$id]) ?? throw new HttpException(404,'Donation not found.');
                if ($user['role']==='donor' && (int)$food['donor_id']!==(int)$user['id']) { throw new HttpException(403,'This donation belongs to another donor.'); }
                if ($user['role']==='receiver' && $food['status']!=='available' && !$this->one('SELECT p.id FROM pickup_schedules p JOIN receivers r ON r.id=p.receiver_id WHERE p.donation_id=? AND r.user_id=?',[$id,$user['id']])) { throw new HttpException(403,'This listing is no longer available to your account.'); }
                if ($user['role']==='volunteer' && !$this->one('SELECT id FROM pickup_schedules WHERE donation_id=? AND volunteer_id=?',[$id,$user['id']])) { throw new HttpException(403,'This donation is not assigned to you.'); }
                $data['food']=$food;$data['images']=$this->all('SELECT id FROM donation_images WHERE donation_id=?',[$id]);
                $data['safety']=$this->all('SELECT f.*,s.version,s.source_url FROM food_safety_records f JOIN safety_rules s ON s.id=f.rule_id WHERE f.donation_id=? ORDER BY f.id DESC',[$id]);
                $data['events']=$this->all('SELECT event,details,created_at FROM pickup_events WHERE donation_id=? ORDER BY id DESC',[$id]);
                $data['matches']=in_array($user['role'],['donor','receiver','super_admin'],true) ? (new MatchingService($this->db))->recommendations($user,$id) : ['ranked'=>[],'excluded'=>[]];
                $data['reviews']=$this->all('SELECT r.rating,r.comment,r.created_at FROM reviews r JOIN pickup_schedules p ON p.id=r.pickup_id WHERE p.donation_id=?',[$id]);break;
            case 'pickups':
                Authorize::check($user,['donor','receiver','volunteer','super_admin']);
                $scope=match($user['role']) {'donor'=>'d.donor_id=?','receiver'=>'r.user_id=?','volunteer'=>'p.volunteer_id=?',default=>'1=1'};
                $data['pickups']=$this->all('SELECT p.*,d.name,d.donor_id,d.deadline,d.quantity_kg,d.address,d.latitude,d.longitude,r.user_id AS receiver_user,u.name AS receiver_name,v.name AS volunteer_name FROM pickup_schedules p JOIN donations d ON d.id=p.donation_id JOIN receivers r ON r.id=p.receiver_id JOIN users u ON u.id=r.user_id LEFT JOIN users v ON v.id=p.volunteer_id WHERE ' . $scope . ' ORDER BY p.id DESC',$user['role']==='super_admin' ? [] : [$user['id']]);
                $data['volunteers']=$this->all("SELECT u.id,u.name FROM users u JOIN roles r ON r.id=u.role_id JOIN volunteers v ON v.user_id=u.id WHERE r.slug='volunteer' AND v.available=1 AND u.status='active' AND u.deleted_at IS NULL ORDER BY u.name");break;
            case 'notifications':$data['notifications']=$this->all('SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 100',[$user['id']]);break;
            case 'impact':$data['impact']=(new ImpactService($this->db))->summarize($user);break;
            case 'manage':
                Authorize::check($user,['super_admin']);
                $data['accounts']=$this->all('SELECT u.id,u.name,u.email,u.status,r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.deleted_at IS NULL ORDER BY u.id DESC');
                $data['all_categories']=$this->all('SELECT * FROM food_categories ORDER BY name');
                $data['rules']=$this->all('SELECT s.*,c.name AS category_name FROM safety_rules s JOIN food_categories c ON c.id=s.category_id WHERE s.id=(SELECT MAX(s2.id) FROM safety_rules s2 WHERE s2.category_id=s.category_id) ORDER BY c.name');
                $data['audit']=$this->all('SELECT a.event,a.context,a.created_at,u.name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 100');
                $data['mail']=$this->one('SELECT COUNT(*) AS total,SUM(sent_at IS NULL) AS pending,SUM(attempts>=5 AND sent_at IS NULL) AS failed FROM mail_outbox');
                $data['configuration']=(new MatchingService($this->db))->configuration();break;
            case 'experiments':
                Authorize::check($user,['researcher']);
                $data['datasets']=$this->all('SELECT id,name,source,metadata,content_hash FROM datasets WHERE owner_id=? ORDER BY id DESC',[$user['id']]);
                $data['experiments']=$this->all('SELECT e.*,d.name AS dataset_name FROM experiments e JOIN datasets d ON d.id=e.dataset_id WHERE e.owner_id=? ORDER BY e.id DESC',[$user['id']]);
                $data['configurations']=$this->all('SELECT id,version FROM algorithm_configurations ORDER BY id DESC');
                $data['configuration']=(new MatchingService($this->db))->configuration();break;
            case 'experiment':$data['experiment']=(new ResearchService($this->db))->details($user,$id);break;
            case 'profile':$data['profile']=$this->one('SELECT * FROM user_profiles WHERE user_id=?',[$user['id']]) ?? [];$data['volunteer']=$this->one('SELECT * FROM volunteers WHERE user_id=?',[$user['id']]) ?? [];break;
        }
        View::render('workspace',['title'=>$titles[$page],'page'=>$page,'user'=>$user,'data'=>$data]);
    }
    public function export(array $user,string $kind,string $format,int $id=0): void
    {
        $export=new ExportService();
        if ($kind==='impact') { $data=(new ImpactService($this->db))->summarize($user);$title='FoodRescue recorded impact';$rows=[];foreach ($data as $key=>$value) { if (!is_array($value)) { $rows[]=['metric'=>$key,'value'=>$value]; } } }
        else { $data=(new ResearchService($this->db))->details($user,$id);$title='FoodRescue experiment ' . $id . ' (' . $data['source'] . ')';$rows=[];foreach ($data['runs'] as $run) { foreach ($run['decisions'] as $decision) { $rows[]=['algorithm'=>$run['algorithm'],...$decision]; } } }
        header('Content-Disposition: attachment; filename="foodrescue-' . $kind . '-' . $id . '.' . $format . '"');
        if ($format==='csv') { header('Content-Type: text/csv; charset=utf-8');echo $export->csv($rows); }
        elseif ($format==='pdf') { header('Content-Type: application/pdf');echo $export->pdf($title,$data); }
        elseif ($format==='json') { header('Content-Type: application/json');echo json_encode($data,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR); }
        else { throw new HttpException(404,'Unsupported export format.'); }
    }
}
