<?php
declare(strict_types=1);
namespace FoodRescue\Services;
use FoodRescue\Core\{HttpException,Input};
use FoodRescue\Middleware\Authorize;
use FoodRescue\Models\Repository;
final class ResearchService extends Repository
{
    public function import(array $user,array $data): int
    {
        Authorize::check($user,['researcher']);
        $payload=(new \FoodRescue\Validators\DatasetValidator())->validate(Input::text($data,'payload',2000000));
        $source=Input::choice($data,'source',['anonymized','pilot']);
        $metadata=['source'=>$source,'collection_period'=>Input::text($data,'collection_period',200),'permission_status'=>Input::text($data,'permission_status',500),'limitations'=>Input::text($data,'limitations',2000),'preprocessing'=>Input::text($data,'preprocessing',2000),'record_count'=>count($payload['donations'])+count($payload['receivers']),'missing_values'=>'All required fields validated; optional historical counts must be explicit.','outcome_model'=>'Imported inputs only. Experiment outcomes are simulated, not observed pilot results.'];
        $payload['metadata']=$metadata;$json=json_encode($payload,JSON_THROW_ON_ERROR);
        return $this->insert('INSERT INTO datasets(owner_id,name,source,metadata,payload,content_hash) VALUES (?,?,?,?,?,?)',[$user['id'],Input::text($data,'name',160),$source,json_encode($metadata,JSON_THROW_ON_ERROR),$json,hash('sha256',$json)]);
    }
    public function generate(array $user,array $data): int
    {
        Authorize::check($user,['researcher']);$count=(int)Input::number($data,'donations',1,500);$receivers=(int)Input::number($data,'receivers',1,100);$seed=(int)Input::number($data,'seed',0,2147483647);
        $payload=(new Simulator())->generate($count,$receivers,$seed);$json=json_encode($payload,JSON_THROW_ON_ERROR);
        return $this->insert("INSERT INTO datasets(owner_id,name,source,metadata,payload,content_hash) VALUES (?,?,'simulated',?,?,?)",[$user['id'],Input::text($data,'name',160),json_encode($payload['metadata'],JSON_THROW_ON_ERROR),$json,hash('sha256',$json)]);
    }
    public function experiment(array $user,array $data): int
    {
        Authorize::check($user,['researcher']);$dataset=Input::id($data,'dataset_id');$configuration=Input::id($data,'configuration_id');
        $source=$this->one('SELECT * FROM datasets WHERE id=? AND owner_id=?',[$dataset,$user['id']]) ?? throw new HttpException(403,'Dataset not available to this researcher.');
        $config=(new MatchingService($this->db))->configuration($configuration);$seed=(int)Input::number($data,'seed',0,2147483647);
        $name=Input::text($data,'name',160);$payload=json_decode($source['payload'],true,512,JSON_THROW_ON_ERROR);
        return $this->transaction(function () use ($user,$dataset,$configuration,$seed,$name,$payload,$config) {
            $id=$this->insert('INSERT INTO experiments(owner_id,dataset_id,configuration_id,name,seed) VALUES (?,?,?,?,?)',[$user['id'],$dataset,$configuration,$name,$seed]);
            foreach (['nearest','earliest_deadline','highest_capacity','tamra'] as $algorithm) {
                $start=hrtime(true);$result=(new Simulator())->run($payload,$config,$algorithm,$seed);$elapsed=(hrtime(true)-$start)/1e6;
                $result['metrics']['average_matching_ms']=$result['metrics']['eligible_donations'] ? $elapsed/$result['metrics']['eligible_donations'] : null;
                $run=$this->insert('INSERT INTO experiment_runs(experiment_id,algorithm,metrics,decisions,execution_ms) VALUES (?,?,?,?,?)',[$id,$algorithm,json_encode($result['metrics'],JSON_THROW_ON_ERROR),json_encode($result['decisions'],JSON_THROW_ON_ERROR),$elapsed]);
                foreach ($result['metrics'] as $metric=>$value) { $this->execute('INSERT INTO experiment_results(run_id,metric,value) VALUES (?,?,?)',[$run,$metric,$value]); }
            }
            return $id;
        });
    }
    public function details(array $user,int $id): array
    {
        Authorize::check($user,['researcher']);
        $experiment=$this->one('SELECT e.*,d.name AS dataset_name,d.metadata,d.content_hash,d.source,d.payload,c.version,c.weights,c.parameters FROM experiments e JOIN datasets d ON d.id=e.dataset_id JOIN algorithm_configurations c ON c.id=e.configuration_id WHERE e.id=? AND e.owner_id=?',[$id,$user['id']]) ?? throw new HttpException(404,'Experiment not found.');
        $experiment['runs']=$this->all('SELECT * FROM experiment_runs WHERE experiment_id=? ORDER BY id',[$id]);
        foreach (['metadata','payload','weights','parameters'] as $key) { $experiment[$key]=json_decode($experiment[$key],true,512,JSON_THROW_ON_ERROR); }
        foreach ($experiment['runs'] as &$run) { $run['metrics']=json_decode($run['metrics'],true,512,JSON_THROW_ON_ERROR);$run['decisions']=json_decode($run['decisions'],true,512,JSON_THROW_ON_ERROR); }unset($run);
        $groups=[];
        foreach ($this->all('SELECT r.algorithm,r.metrics,e.seed FROM experiment_runs r JOIN experiments e ON e.id=r.experiment_id WHERE e.dataset_id=? AND e.configuration_id=? AND e.owner_id=?',[$experiment['dataset_id'],$experiment['configuration_id'],$user['id']]) as $historical) {
            $groups[$historical['algorithm']]['seeds'][]=(int)$historical['seed'];
            foreach (json_decode($historical['metrics'],true) as $metric=>$value) { if ($value!==null) { $groups[$historical['algorithm']]['metrics'][$metric][]=(float)$value; } }
        }
        $experiment['replicate_summary']=[];
        foreach ($groups as $algorithm=>$group) {
            $summary=['algorithm'=>$algorithm,'runs'=>count($group['seeds']),'distinct_seeds'=>count(array_unique($group['seeds'])),'metrics'=>[]];
            foreach ($group['metrics'] as $metric=>$values) { $mean=array_sum($values)/count($values);$variance=0;foreach ($values as $value) { $variance+=($value-$mean)**2; }$summary['metrics'][$metric]=['n'=>count($values),'mean'=>$mean,'sample_stddev'=>count($values)>1 ? sqrt($variance/(count($values)-1)) : null]; }
            $experiment['replicate_summary'][]=$summary;
        }
        return $experiment;
    }
}
