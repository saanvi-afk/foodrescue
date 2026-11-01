<?php
declare(strict_types=1);
namespace FoodRescue\Services;
final class Simulator
{
    private int $state;
    private function random(): float { $this->state=(int)(($this->state*1664525+1013904223)%4294967296);return $this->state/4294967296; }
    public function generate(int $donations,int $receivers,int $seed): array
    {
        $this->state=$seed;$start=strtotime('2026-01-01 08:00:00 UTC');$foods=[];$people=[];
        for ($i=1;$i<=$receivers;$i++) {
            $categories=[];for ($c=1;$c<=5;$c++) { if ($this->random()>0.25) { $categories[]=$c; } }if (!$categories) { $categories=[1]; }
            $people[]=['id'=>$i,'user_id'=>$i,'name'=>'Synthetic receiver ' . $i,'status'=>'active','latitude'=>28.6+($this->random()-0.5)*0.2,'longitude'=>77.2+($this->random()-0.5)*0.2,
                'capacity_kg'=>round(10+$this->random()*90,3),'reserved_kg'=>0,'collection_available'=>true,'transport_available'=>$this->random()>0.2,'max_distance_km'=>10+$this->random()*25,
                'categories'=>$categories,'available_from'=>gmdate('Y-m-d H:i:s',$start),'available_until'=>gmdate('Y-m-d H:i:s',$start+(int)(3600*(4+$this->random()*8))),
                'history_count'=>10,'history_success'=>(int)(5+$this->random()*6),'latent_reliability'=>0.55+$this->random()*0.44];
        }
        for ($i=1;$i<=$donations;$i++) {
            $created=$start+(int)($this->random()*180*60);$deadline=$created+(int)(30+$this->random()*330)*60;
            $foods[]=['id'=>$i,'category_id'=>1+(int)($this->random()*5),'name'=>'Synthetic donation ' . $i,'quantity_kg'=>round(2+$this->random()*28,3),
                'latitude'=>28.6+($this->random()-0.5)*0.2,'longitude'=>77.2+($this->random()-0.5)*0.2,'created_at'=>gmdate('Y-m-d H:i:s',$created),
                'deadline'=>gmdate('Y-m-d H:i:s',$deadline),'status'=>$this->random()>0.08 ? 'available' : 'rejected'];
        }
        return ['donations'=>$foods,'receivers'=>$people,'metadata'=>['source'=>'simulated','seed'=>$seed,'collection_period'=>'Synthetic 2026-01-01 UTC','record_count'=>$donations+$receivers,'missing_values'=>'None generated','preprocessing'=>'None','permission_status'=>'Synthetic, no personal records','limitations'=>'Hypothetical inputs and outcome model; no real-world evidence.','outcome_model'=>'Shared hash-derived route factors and independent latent pickup reliability; no learned predictor.']];
    }
    private function uniform(int $seed,int $donation,int $receiver,string $feature): float { return hexdec(substr(hash('sha256',"{$seed}:{$donation}:{$receiver}:{$feature}"),0,8))/4294967296; }
    public function run(array $dataset,array $configuration,string $algorithm,int $seed): array
    {
        if (!in_array($algorithm,['nearest','earliest_deadline','highest_capacity','tamra'],true)) { throw new \InvalidArgumentException('Unknown experiment algorithm.'); }
        $foods=$dataset['donations'];$people=$dataset['receivers'];$decisions=[];$eligible=0;$accepted=0;$success=0;$distances=[];$scores=[];$reserved=[];$used=0;
        usort($foods,fn($a,$b)=>($a[$algorithm==='earliest_deadline' ? 'deadline' : 'created_at']<=>$b[$algorithm==='earliest_deadline' ? 'deadline' : 'created_at']) ?: ($a['id']<=>$b['id']));
        // Snapshot allocation: all assignments reserve capacity for the run; no future capacity information is used.
        foreach ($foods as $food) {
            if ($food['status']!=='available') { continue; }$eligible++;
            $now=strtotime($food['created_at']);
            foreach ($people as &$person) { $person['reserved_kg']=$reserved[$person['id']] ?? 0; }unset($person);
            $ranked=(new TAMRAService())->rank($food,$people,$configuration,$now)['ranked'];
            if (!$ranked) { $decisions[]=['donation_id'=>$food['id'],'receiver_id'=>null,'outcome'=>'unassigned','quantity_kg'=>$food['quantity_kg']];continue; }
            if (in_array($algorithm,['nearest','earliest_deadline'],true)) { usort($ranked,fn($a,$b)=>($a['distance_km']<=>$b['distance_km']) ?: ($a['receiver_id']<=>$b['receiver_id'])); }
            elseif ($algorithm==='highest_capacity') { usort($ranked,fn($a,$b)=>($b['free_capacity_kg']<=>$a['free_capacity_kg']) ?: ($a['receiver_id']<=>$b['receiver_id'])); }
            $match=$ranked[0];$accepted++;$receiver=array_values(array_filter($people,fn($p)=>(int)$p['id']===$match['receiver_id']))[0];
            $reserved[$receiver['id']]=($reserved[$receiver['id']] ?? 0)+$food['quantity_kg'];$used+=$food['quantity_kg'];
            $factor=1+1.2*$this->uniform($seed,(int)$food['id'],(int)$receiver['id'],'route');
            $speed=$receiver['transport_available'] ? 18 : 3.5;
            $actual=max($now,strtotime($receiver['available_from']))+(int)(($match['distance_km']*$factor/$speed*60+15+20*$this->uniform($seed,(int)$food['id'],(int)$receiver['id'],'delay'))*60);
            $ok=$actual<=min(strtotime($food['deadline']),strtotime($receiver['available_until'])) && $this->uniform($seed,(int)$food['id'],(int)$receiver['id'],'success')<($receiver['latent_reliability'] ?? 0.75);
            if ($ok) { $success++;$distances[]=$match['distance_km']; }
            $scores[]=['score'=>$match['feasibility']['score'],'actual'=>(int)$ok];
            $decisions[]=['donation_id'=>$food['id'],'receiver_id'=>$receiver['id'],'outcome'=>$ok ? 'completed' : 'failed','quantity_kg'=>$food['quantity_kg'],'distance_km'=>$match['distance_km'],'score'=>$match['score'],'feasibility_score'=>$match['feasibility']['score'],'components'=>$match['components']];
        }
        $tp=0;$fp=0;$fn=0;$correct=0;$pairs=0;$aucWins=0;
        foreach ($scores as $point) { $prediction=$point['score']>=0.25;if ($prediction && $point['actual']) { $tp++; }elseif ($prediction) { $fp++; }elseif ($point['actual']) { $fn++; }if ((int)$prediction===$point['actual']) { $correct++; } }
        foreach ($scores as $positive) { if (!$positive['actual']) { continue; }foreach ($scores as $negative) { if ($negative['actual']) { continue; }$pairs++;$aucWins+=$positive['score']===$negative['score'] ? 0.5 : (int)($positive['score']>$negative['score']); } }
        $precision=$tp+$fp ? $tp/($tp+$fp) : null;$recall=$tp+$fn ? $tp/($tp+$fn) : null;
        $capacity=array_sum(array_column($people,'capacity_kg'));
        return ['metrics'=>['eligible_donations'=>$eligible,'accepted_assignments'=>$accepted,'successful_pickups'=>$success,'pickup_success_rate'=>$accepted ? $success/$accepted : null,
            'expired_donation_rate'=>$eligible ? ($eligible-$success)/$eligible : null,'average_distance_km'=>$distances ? array_sum($distances)/count($distances) : null,
            'capacity_utilization'=>$capacity ? $used/$capacity : null,'precision'=>$precision,'recall'=>$recall,'f1'=>$precision!==null && $recall!==null && $precision+$recall>0 ? 2*$precision*$recall/($precision+$recall) : null,
            'roc_auc'=>$pairs ? $aucWins/$pairs : null,'prediction_accuracy'=>$scores ? $correct/count($scores) : null], 'decisions'=>$decisions];
    }
}
