<?php
declare(strict_types=1);
namespace FoodRescue\Services;
use FoodRescue\Core\HttpException;
final class TAMRAService
{
    public const COMPONENTS=['urgency','distance','capacity','compatibility','feasibility','reliability'];
    public function validate(array $weights,array $parameters): void
    {
        if (array_diff(self::COMPONENTS,array_keys($weights)) || array_diff(array_keys($weights),self::COMPONENTS)) { throw new HttpException(422,'Provide all six TAMRA weights.'); }
        foreach ($weights as $weight) { if (!is_numeric($weight) || !is_finite((float)$weight) || $weight<0 || $weight>1) { throw new HttpException(422,'Weights must be finite numbers between zero and one.'); } }
        if (abs(array_sum($weights)-1)>0.000001) { throw new HttpException(422,'TAMRA weights must sum to one.'); }
        foreach (['speed_kmh','handling_minutes','urgency_horizon_minutes','minimum_history'] as $key) {
            if (!isset($parameters[$key]) || !is_numeric($parameters[$key]) || !is_finite((float)$parameters[$key]) || $parameters[$key]<=0) { throw new HttpException(422,"Provide a positive {$key}."); }
        }
        if (!isset($parameters['cold_start_reliability']) || !is_numeric($parameters['cold_start_reliability']) || !is_finite((float)$parameters['cold_start_reliability']) || $parameters['cold_start_reliability']<0 || $parameters['cold_start_reliability']>1) { throw new HttpException(422,'Invalid cold-start reliability.'); }
    }
    public static function distance(float $a,float $b,float $c,float $d): float
    {
        $lat=deg2rad($c-$a);$lng=deg2rad($d-$b);
        $h=sin($lat/2)**2+cos(deg2rad($a))*cos(deg2rad($c))*sin($lng/2)**2;
        return 6371*2*asin(min(1,sqrt($h)));
    }
    public function rank(array $donation,array $receivers,array $configuration,int $now): array
    {
        $weights=$configuration['weights'];$p=$configuration['parameters'];$this->validate($weights,$p);$ranked=[];$excluded=[];
        if ($donation['status']!=='available' || strtotime($donation['deadline'])<=$now) { return ['ranked'=>[],'excluded'=>[['reason'=>'Donation is not available or its deadline has passed.']]]; }
        foreach ($receivers as $receiver) {
            $reason=null;
            foreach (['latitude','longitude','capacity_kg','reserved_kg','available_from','available_until','max_distance_km'] as $field) {
                if (!isset($receiver[$field]) || $receiver[$field]==='') { $reason='Required receiver information is missing.'; }
            }
            if (($receiver['status'] ?? '')!=='active' || empty($receiver['collection_available'])) { $reason='Receiver is inactive or unavailable.'; }
            if (!in_array((int)$donation['category_id'],array_map('intval',$receiver['categories'] ?? []),true)) { $reason='Food category is not accepted.'; }
            $free=(float)($receiver['capacity_kg'] ?? 0)-(float)($receiver['reserved_kg'] ?? 0);
            if (round($free,3)<(float)$donation['quantity_kg']) { $reason='Insufficient unreserved capacity.'; }
            if ($reason) { $excluded[]=['receiver_id'=>$receiver['id'],'reason'=>$reason];continue; }
            $distance=self::distance((float)$donation['latitude'],(float)$donation['longitude'],(float)$receiver['latitude'],(float)$receiver['longitude']);
            if ($distance>(float)$receiver['max_distance_km']) { $excluded[]=['receiver_id'=>$receiver['id'],'reason'=>'Outside receiver collection radius.'];continue; }
            $feasibility=(new FeasibilityService())->estimate($donation,$receiver,$distance,$now,$p);
            if (!$feasibility['eligible']) { $excluded[]=['receiver_id'=>$receiver['id'],'reason'=>'Estimated pickup falls outside availability or the deadline.'];continue; }
            $history=(int)($receiver['history_count'] ?? 0);
            $reliability=$history>=(int)$p['minimum_history'] ? min(1,(int)$receiver['history_success']/$history) : (float)$p['cold_start_reliability'];
            $components=[
                'urgency'=>max(0,min(1,1-(strtotime($donation['deadline'])-$now)/60/$p['urgency_horizon_minutes'])),
                'distance'=>max(0,1-$distance/max(0.001,(float)$receiver['max_distance_km'])),
                'capacity'=>min(1,(float)$donation['quantity_kg']/max(0.001,$free)),
                'compatibility'=>1.0,'feasibility'=>$feasibility['score'],'reliability'=>$reliability,
            ];
            $score=0;foreach ($components as $name=>$component) { $score+=$weights[$name]*$component; }
            $ranked[]=['receiver_id'=>(int)$receiver['id'],'user_id'=>(int)$receiver['user_id'],'name'=>$receiver['name'],
                'score'=>$score,'components'=>$components,'distance_km'=>round($distance,3),'free_capacity_kg'=>$free,'feasibility'=>$feasibility,
                'explanation'=>['All mandatory category, capacity and collection-time constraints pass.',
                    $history<(int)$p['minimum_history'] ? 'Neutral reliability assigned because fewer than ' . $p['minimum_history'] . ' completed outcomes are available.' : 'Reliability uses only previous completed, failed and expired pickups.',
                    'Capacity score favors a closer fit to the currently free capacity.']];
        }
        usort($ranked,fn($a,$b)=>($b['score']<=>$a['score']) ?: ($a['receiver_id']<=>$b['receiver_id']));
        return ['ranked'=>$ranked,'excluded'=>$excluded];
    }
}
