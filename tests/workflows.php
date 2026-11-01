<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config/bootstrap.php';
use FoodRescue\Core\{Database,Environment,HttpException};
use FoodRescue\Models\{Repository,UserRepository};
use FoodRescue\Services\{OrganizationService,DonationService,MatchingService,PickupService,JobService,ImpactService,AdminService,ResearchService,Simulator,SafetyService,TAMRAService,ExportService,MailService};
$name=Environment::get('TEST_DB_DATABASE','foodrescue_test');
if (!preg_match('/^[a-zA-Z0-9_]+_test$/D',$name)) { throw new RuntimeException('Use a disposable test database.'); }
putenv('DB_DATABASE=' . $name);$db=Database::connection();$repo=new Repository($db);$users=new UserRepository($db);$count=0;
function verify(bool $condition,string $label): void { global $count;if (!$condition) { throw new RuntimeException($label); }echo 'PASS ' . $label . "\n";$count++; }
function reject(callable $fn,int $status,string $label): void { try { $fn(); }catch (HttpException $error) { verify($error->status===$status,$label);return; }throw new RuntimeException('Expected rejection: ' . $label); }
$suffix=bin2hex(random_bytes(5));$actors=[];
foreach (['donor','receiver','volunteer','super_admin','researcher'] as $role) { $id=$users->create('Workflow ' . $role,$suffix . '-' . $role . '@example.test','workflow-long-password',$role);$actors[$role]=$users->find($id); }
$orgs=new OrganizationService($db);$donations=new DonationService($db);$matching=new MatchingService($db);$pickups=new PickupService($db);
$orgBase=['name'=>'Workflow organization','address'=>'Test kitchen','latitude'=>'28.61','longitude'=>'77.21','contact_phone'=>'','registration_number'=>''];
$donorOrg=$orgs->save($actors['donor'],$orgBase);$receiverOrg=$orgs->save($actors['receiver'],$orgBase);
verify($orgs->member($donorOrg,$actors['donor']['id'])['id']===$donorOrg,'Organization creation links its creator immediately');
reject(fn()=> $orgs->member($donorOrg,$actors['receiver']['id']),403,'Unlinked organization access is rejected');
reject(fn()=> $orgs->join($actors['receiver'],['organization_id'=>$donorOrg]),422,'Organization linking enforces donor/receiver type');
$otherDonorId=$users->create('Other donor',$suffix . '-other@example.test','workflow-long-password','donor');$otherDonor=$users->find($otherDonorId);
$orgs->join($otherDonor,['organization_id'=>$donorOrg]);verify($orgs->member($donorOrg,$otherDonorId)['id']===$donorOrg,'Existing organization linking requires no approval');
reject(fn()=> $orgs->save($otherDonor,$orgBase+['id'=>$donorOrg]),403,'Linked members cannot edit another creator organization');
$category=(int)$repo->one("SELECT id FROM food_categories WHERE name='Bakery'")['id'];
$preferences=['organization_id'=>$receiverOrg,'capacity_kg'=>'25','latitude'=>'28.61','longitude'=>'77.21','max_distance_km'=>'30','transport_available'=>'1','collection_available'=>'1','category_ids'=>[$category],'available_from'=>gmdate('c',time()-60),'available_until'=>gmdate('c',time()+86400)];
$orgs->receiver($actors['receiver'],$preferences);verify((float)$repo->one('SELECT capacity_kg FROM receivers WHERE user_id=?',[$actors['receiver']['id']])['capacity_kg']===25.0,'Receiver preferences persist');
$orgs->profile($actors['volunteer'],['name'=>'Workflow volunteer','phone'=>'','timezone'=>'Asia/Kolkata','available'=>'1','transport'=>'Bicycle']);
$food=['organization_id'=>$donorOrg,'category_id'=>$category,'name'=>'Workflow bread','description'=>'Test-only food listing','quantity'=>'10','unit'=>'kg','prepared_at'=>gmdate('c',time()-1800),'use_by'=>gmdate('c',time()+14400),'deadline'=>gmdate('c',time()+10800),'address'=>'Test kitchen','latitude'=>'28.61','longitude'=>'77.21','storage_method'=>'ambient','temperature_c'=>'25','packaging'=>'intact','allergens'=>'Wheat','ingredients'=>'Flour, water','handling_declared'=>'1','unserved'=>'1','contamination_free'=>'1'];
reject(fn()=> $donations->save($actors['donor'],array_replace($food,['quantity'=>'-1'])),422,'Negative food quantity is rejected');
$bad=$donations->save($actors['donor'],array_replace($food,['packaging'=>'damaged']));verify($repo->one('SELECT status FROM donations WHERE id=?',[$bad])['status']==='rejected','Damaged packaging creates a traceable rejection');
verify(!$matching->recommendations($actors['receiver'],$bad)['ranked'],'Rejected food cannot enter matching');
$review=$donations->save($actors['donor'],array_replace($food,['allergens'=>'']));verify($repo->one('SELECT status FROM donations WHERE id=?',[$review])['status']==='review','Missing safety information is held for review');
$donation=$donations->save($actors['donor'],$food);verify($repo->one('SELECT status FROM donations WHERE id=?',[$donation])['status']==='available','Eligible donation becomes available');
reject(fn()=> $donations->save($otherDonor,$food+['id'=>$donation]),403,'Another donor cannot edit the listing');
$matches=$matching->recommendations($actors['receiver'],$donation);verify(count($matches['ranked'])===1,'Compatible receiver receives a recommendation');
$donorMatches=$matching->recommendations($actors['donor'],$donation);
$target=array_values(array_filter($donorMatches['ranked'],fn($match)=>(int)$match['user_id']===(int)$actors['receiver']['id']))[0];
$matching->decision($actors['donor'],['match_id'=>$target['match_id']],'proposed');
$matching->decision($actors['receiver'],['match_id'=>$matches['ranked'][0]['match_id']],'declined');
verify((int)$repo->one("SELECT COUNT(*) AS n FROM match_decisions WHERE match_id=? AND decision='proposed'",[$target['match_id']])['n']===1,'Donor can propose an eligible match without reserving capacity');
verify($repo->one('SELECT status FROM donations WHERE id=?',[$donation])['status']==='available','Declining a recommendation keeps food available for other requests');
$components=$matches['ranked'][0]['components'];verify($components['compatibility']===1.0 && $components['reliability']===0.5,'Matching components use compatibility and neutral cold-start reliability');
verify((int)$repo->one('SELECT COUNT(*) AS n FROM match_scores WHERE match_id=?',[$matches['ranked'][0]['match_id']])['n']===6,'Six explainable component scores are persisted');
$pickup=$matching->accept($actors['receiver'],$donation);verify((float)$repo->one('SELECT reserved_kg FROM receivers WHERE user_id=?',[$actors['receiver']['id']])['reserved_kg']===10.0,'Acceptance reserves receiver capacity');
reject(fn()=> $matching->accept($actors['receiver'],$donation),409,'Duplicate acceptance is rejected');
reject(fn()=> $orgs->receiver($actors['receiver'],array_replace($preferences,['capacity_kg'=>'5'])),409,'Capacity cannot be reduced below reservations');
reject(fn()=> $donations->withdraw($actors['donor'],$donation),409,'Claimed food cannot be withdrawn');
$scheduled=$pickups->schedule($actors['donor'],['id'=>$pickup,'scheduled_at'=>gmdate('c',time()+300),'volunteer_id'=>$actors['volunteer']['id']]);
verify(str_starts_with($scheduled['qr'],'data:image/png;base64,'),'Scheduling produces a real PNG QR image');
verify(!str_contains($repo->one('SELECT token_hash FROM pickup_schedules WHERE id=?',[$pickup])['token_hash'],$scheduled['token']),'Only pickup-token hash is stored');
reject(fn()=> $pickups->verify($actors['receiver'],['id'=>$pickup,'token'=>str_repeat('0',64),'collected_kg'=>'9']),422,'Invalid pickup token is rejected');
$pickups->verify($actors['volunteer'],['id'=>$pickup,'token'=>$scheduled['token'],'collected_kg'=>'9']);
verify($repo->one('SELECT status FROM donations WHERE id=?',[$donation])['status']==='collected','Assigned volunteer can record collection');
reject(fn()=> $pickups->verify($actors['receiver'],['id'=>$pickup,'token'=>$scheduled['token'],'collected_kg'=>'9']),422,'Reused pickup token is rejected');
$pickups->confirm($actors['donor'],['id'=>$pickup]);verify($repo->one('SELECT status FROM pickup_schedules WHERE id=?',[$pickup])['status']==='collected','One confirmation does not complete pickup');
$pickups->confirm($actors['receiver'],['id'=>$pickup,'distributed_kg'=>'8']);verify($repo->one('SELECT status FROM donations WHERE id=?',[$donation])['status']==='completed','Both confirmations complete pickup');
verify((float)$repo->one('SELECT reserved_kg FROM receivers WHERE user_id=?',[$actors['receiver']['id']])['reserved_kg']===0.0,'Completion releases reserved capacity exactly once');
$impact=(new ImpactService($db))->summarize($actors['donor']);verify($impact['collected_kg']===9.0 && $impact['distributed_kg']===8.0 && $impact['estimated_meals']===20.0,'Impact uses actual collected and distributed quantities only');
$pickups->distributed($actors['receiver'],['id'=>$pickup,'distributed_kg'=>'9']);verify((new ImpactService($db))->summarize($actors['donor'])['distributed_kg']===9.0,'Receiver can update actual distribution without duplicating collection');
$pickups->review($actors['receiver'],['id'=>$pickup,'rating'=>'5','comment'=>'Test-only feedback']);verify((int)$repo->one('SELECT COUNT(*) AS n FROM reviews WHERE pickup_id=?',[$pickup])['n']===1,'Completed pickup supports feedback');
$failureFood=$donations->save($actors['donor'],$food);$failed=$matching->accept($actors['receiver'],$failureFood);$pickups->terminate($actors['receiver'],['id'=>$failed,'reason'=>'Transport unavailable'],'failed');
verify($repo->one('SELECT reason FROM pickup_failures WHERE pickup_id=?',[$failed])['reason']==='Transport unavailable','Failed pickup stores its reason');
$donations->relist($actors['donor'],$failureFood);verify($repo->one('SELECT status FROM donations WHERE id=?',[$failureFood])['status']==='available','Eligible failed pickup can be re-screened and relisted');
$expiryFood=$donations->save($actors['donor'],$food);$expiredPickup=$matching->accept($actors['receiver'],$expiryFood);$repo->execute('UPDATE donations SET deadline=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id=?',[$expiryFood]);
$jobs=new JobService($db);$jobs->expire();$before=$repo->one('SELECT reserved_kg FROM receivers WHERE user_id=?',[$actors['receiver']['id']])['reserved_kg'];$jobs->expire();
verify($repo->one('SELECT status FROM pickup_schedules WHERE id=?',[$expiredPickup])['status']==='expired' && $before===$repo->one('SELECT reserved_kg FROM receivers WHERE user_id=?',[$actors['receiver']['id']])['reserved_kg'],'Expiry releases capacity and is idempotent');
$reminderFood=$donations->save($actors['donor'],array_replace($food,['deadline'=>gmdate('c',time()+3000)]));$jobs->reminders();$n=$repo->one('SELECT COUNT(*) AS n FROM notifications')['n'];$jobs->reminders();verify($n===$repo->one('SELECT COUNT(*) AS n FROM notifications')['n'],'Reminder jobs do not duplicate notifications');
// True concurrent acceptance, in two independent PHP processes/connections.
$secondId=$users->create('Second receiver',$suffix . '-second@example.test','workflow-long-password','receiver');$second=$users->find($secondId);$orgs->join($second,['organization_id'=>$receiverOrg]);$orgs->receiver($second,$preferences);
$raceFood=$donations->save($actors['donor'],$food);$workers=[];
foreach ([$actors['receiver']['id'],$secondId] as $actor) { $pipes=[];$process=proc_open([PHP_BINARY,BASE_PATH . '/tests/accept_worker.php',(string)$actor,(string)$raceFood],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH);fclose($pipes[0]);$workers[]=[$process,$pipes]; }
$outcomes=[];foreach ($workers as [$process,$pipes]) { $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if ($exit) { throw new RuntimeException($error); }$outcomes[]=json_decode($output,true,512,JSON_THROW_ON_ERROR); }
verify(count(array_filter($outcomes,fn($outcome)=>$outcome['accepted']))===1,'Concurrent receivers produce exactly one valid allocation');
verify((int)$repo->one("SELECT COUNT(*) AS n FROM pickup_schedules WHERE donation_id=? AND status='accepted'",[$raceFood])['n']===1,'Database preserves one active allocation');
$reassigned=$matching->accept($second,$failureFood);
$handoff=$pickups->schedule($actors['donor'],['id'=>$reassigned,'scheduled_at'=>gmdate('c',time()+300)]);
$pickups->verify($second,['id'=>$reassigned,'token'=>$handoff['token'],'collected_kg'=>'7']);
$pickups->confirm($actors['donor'],['id'=>$reassigned]);$pickups->confirm($second,['id'=>$reassigned,'distributed_kg'=>'7']);
verify((new ImpactService($db))->summarize($actors['receiver'])['collected_kg']===9.0,'Failed receiver is not credited with another receiver’s later collection');
$capacityId=$users->create('Capacity receiver',$suffix . '-capacity@example.test','workflow-long-password','receiver');$capacityUser=$users->find($capacityId);$orgs->join($capacityUser,['organization_id'=>$receiverOrg]);$orgs->receiver($capacityUser,array_replace($preferences,['capacity_kg'=>'15']));
$capacityFoods=[$donations->save($actors['donor'],$food),$donations->save($actors['donor'],$food)];$workers=[];
foreach ($capacityFoods as $foodId) { $pipes=[];$process=proc_open([PHP_BINARY,BASE_PATH . '/tests/accept_worker.php',(string)$capacityId,(string)$foodId],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH);fclose($pipes[0]);$workers[]=[$process,$pipes]; }
$outcomes=[];foreach ($workers as [$process,$pipes]) { $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)) { throw new RuntimeException($error); }$outcomes[]=json_decode($output,true,512,JSON_THROW_ON_ERROR); }
verify(count(array_filter($outcomes,fn($outcome)=>$outcome['accepted']))===1,'Concurrent different donations cannot over-allocate one receiver');
verify((float)$repo->one('SELECT reserved_kg FROM receivers WHERE user_id=?',[$capacityId])['reserved_kg']===10.0,'Receiver row lock preserves remaining capacity across simultaneous requests');
$config=$matching->configuration();$scenario=(new Simulator())->generate(30,8,42);$again=(new Simulator())->generate(30,8,42);verify($scenario===$again,'Dataset generation is deterministic for identical seeds');
$one=(new Simulator())->run($scenario,$config,'tamra',8);$two=(new Simulator())->run($scenario,$config,'tamra',8);verify($one===$two,'Seeded allocation decisions and outcome metrics are deterministic');
foreach (['nearest','earliest_deadline','highest_capacity','tamra'] as $algorithm) { $result=(new Simulator())->run($scenario,$config,$algorithm,8);verify($result['metrics']['eligible_donations']===$one['metrics']['eligible_donations'],'Shared eligibility denominator: ' . $algorithm); }
$research=new ResearchService($db);$dataset=$research->generate($actors['researcher'],['name'=>'Workflow synthetic dataset','donations'=>'20','receivers'=>'5','seed'=>'42']);$experiment=$research->experiment($actors['researcher'],['dataset_id'=>$dataset,'configuration_id'=>$config['id'],'name'=>'Workflow comparison','seed'=>'8']);
verify(count($research->details($actors['researcher'],$experiment)['runs'])===4,'Experiment persists all four baseline/comparison runs');
$anonymous=['donations'=>array_map(fn($row)=>array_intersect_key($row,array_flip(['id','category_id','quantity_kg','latitude','longitude','created_at','deadline','status'])),$scenario['donations']),'receivers'=>array_map(fn($row)=>array_intersect_key($row,array_flip(['id','capacity_kg','latitude','longitude','collection_available','transport_available','max_distance_km','available_from','available_until','categories','history_count','history_success'])),$scenario['receivers'])];
$import=['name'=>'Anonymous imported fixture','source'=>'anonymized','collection_period'=>'2026-01-01 synthetic test fixture','permission_status'=>'Generated test fixture, no human records','limitations'=>'Test data only','preprocessing'=>'No missing fields','payload'=>json_encode($anonymous,JSON_THROW_ON_ERROR)];
$imported=$research->import($actors['researcher'],$import);verify($repo->one('SELECT source FROM datasets WHERE id=?',[$imported])['source']==='anonymized','Anonymous historical-input import validates and stores source metadata');
$private=$anonymous;$private['receivers'][0]['email']='must-not-export@example.test';reject(fn()=> $research->import($actors['researcher'],array_replace($import,['payload'=>json_encode($private)])),422,'Research import rejects identifying fields');
$admin=new AdminService($db);reject(fn()=> $admin->settings($actors['donor'],['meal_mass_kg'=>'0.4']),403,'Donor cannot change platform settings');
$admin->account($actors['super_admin'],['id'=>$otherDonorId,'status'=>'suspended']);verify($users->find($otherDonorId)['status']==='suspended','Administrator can suspend an account without an onboarding approval workflow');
$admin->account($actors['super_admin'],['id'=>$otherDonorId,'status'=>'active']);
$admin->safety($actors['super_admin'],['category_id'=>$category,'source_url'=>'https://www.fssai.gov.in/upload/uploadfiles/files/Gazette_Notification_Surplus_Food_06_08_2019.pdf','max_temperature'=>'10']);
verify(!$matching->recommendations($actors['receiver'],$failureFood)['ranked'],'New mandatory safety limits are enforced against existing available listings');
$admin->safety($actors['super_admin'],['category_id'=>$category,'source_url'=>'https://www.fssai.gov.in/upload/uploadfiles/files/Gazette_Notification_Surplus_Food_06_08_2019.pdf']);
reject(fn()=> $research->details($actors['donor'],$experiment),403,'Donor cannot access research datasets');
$pdf=(new ExportService())->pdf('Test report',['collected_kg'=>9]);verify(str_starts_with($pdf,'%PDF-'),'Reports generate real PDFs');
$csv=(new ExportService())->csv([['name'=>'=SUM(1,2)','value'=>9]]);verify(str_contains($csv,"'=SUM"),'CSV export escapes spreadsheet formula injection');
$uneven=(new ExportService())->csv([['donation_id'=>1,'outcome'=>'unassigned'],['donation_id'=>2,'outcome'=>'completed','score'=>0.5]]);
$lines=array_values(array_filter(explode("\n",trim($uneven))));verify(count(str_getcsv($lines[0],',','"',''))===3 && count(str_getcsv($lines[1],',','"',''))===3,'Decision CSV preserves a consistent schema for assigned and unassigned rows');
$prediction=(new \FoodRescue\Services\PredictionMetrics())->evaluate([['score'=>0.9,'actual'=>1],['score'=>0.1,'actual'=>0]]);verify($prediction['precision']===1 && $prediction['roc_auc']===1,'Prediction evaluation recognizes perfectly separated labelled outcomes');
$mail=(new MailService($db))->deliver(1);verify($mail['sent']===1 && $mail['failed']===0,'Local email worker generates a MIME message');
echo "{$count} workflow checks passed.\n";
