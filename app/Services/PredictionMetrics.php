<?php
declare(strict_types=1);
namespace FoodRescue\Services;
final class PredictionMetrics
{
    public function evaluate(array $points): array
    {
        $tp=0;$fp=0;$fn=0;$pairs=0;$wins=0;
        foreach ($points as $p) { $predicted=$p['score']>=0.25;if ($predicted && $p['actual']) { $tp++; }elseif ($predicted) { $fp++; }elseif ($p['actual']) { $fn++; } }
        foreach ($points as $p) { if (!$p['actual']) { continue; }foreach ($points as $n) { if ($n['actual']) { continue; }$pairs++;$wins+=$p['score']===$n['score'] ? 0.5 : (int)($p['score']>$n['score']); } }
        $precision=$tp+$fp ? $tp/($tp+$fp) : null;$recall=$tp+$fn ? $tp/($tp+$fn) : null;
        return ['observation_count'=>count($points),'threshold'=>0.25,'precision'=>$precision,'recall'=>$recall,'f1'=>$precision!==null && $recall!==null && $precision+$recall>0 ? 2*$precision*$recall/($precision+$recall) : null,'roc_auc'=>$pairs ? $wins/$pairs : null];
    }
}
