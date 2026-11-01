<?php
declare(strict_types=1);
namespace FoodRescue\Services;
final class FeasibilityService
{
    public function estimate(array $donation,array $receiver,float $distance,int $now,array $parameters): array
    {
        $speed=!empty($receiver['transport_available']) ? (float)$parameters['speed_kmh'] : 4.0;
        $travel=$distance*1.3/$speed*60;
        $handling=(float)$parameters['handling_minutes'];
        $start=max($now,strtotime($receiver['available_from'] ?? '1970-01-01'));
        $arrival=$start+(int)ceil(($travel+$handling)*60);
        $end=min(strtotime($donation['deadline']),strtotime($receiver['available_until'] ?? '1970-01-01'));
        $remaining=max(1,($end-$now)/60);$slack=($end-$arrival)/60;
        $score=max(0,min(1,$slack/$remaining));
        return ['eligible'=>$arrival<=$end,'score'=>$score,'level'=>$score>=0.6 ? 'high' : ($score>=0.25 ? 'medium' : 'low'),
            'travel_minutes'=>round($travel,1),'handling_minutes'=>$handling,'estimated_arrival'=>gmdate('Y-m-d H:i:s',$arrival),
            'slack_minutes'=>round($slack,1),'label'=>'Rule-based feasibility score, not a calibrated probability',
            'factors'=>['Distance is straight-line distance; travel uses a 1.3 detour assumption.','Speed: ' . $speed . ' km/h.', 'Collection begins within the recorded availability window.']];
    }
}
