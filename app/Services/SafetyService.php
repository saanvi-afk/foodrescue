<?php
declare(strict_types=1);
namespace FoodRescue\Services;
final class SafetyService
{
    /** Screening based on declarations; never a food-safety certification. */
    public function screen(array $food,array $rule,int $now): array
    {
        $rejected=[];$review=[];
        foreach (['handling_declared'=>'Handling declaration is required.','unserved'=>'Previously served food is not eligible.','contamination_free'=>'Contamination or spoilage declared.'] as $key=>$message) {
            if (empty($food[$key])) { $rejected[]=$message; }
        }
        if (($food['packaging'] ?? '')==='damaged') { $rejected[]='Packaging is damaged.'; }
        elseif (($food['packaging'] ?? '')!=='intact') { $review[]='Packaging condition requires inspection.'; }
        if (empty($food['allergens']) || empty($food['ingredients'])) { $review[]='Ingredients and allergen information must be recorded.'; }
        $prepared=strtotime($food['prepared_at'] ?? '');$useBy=strtotime($food['use_by'] ?? '');$deadline=strtotime($food['deadline'] ?? '');
        if (!$prepared || $prepared>$now || !$useBy || $useBy<=$now || !$deadline || $deadline<=$now || $deadline>$useBy || $prepared>=$useBy) { $rejected[]='Preparation, pickup deadline or use-by time is invalid or expired.'; }
        $temperature=$food['temperature_c'] ?? null;
        if (!empty($rule['require_temperature']) && ($temperature===null || $temperature==='')) { $review[]='Record a measured storage temperature for this category.'; }
        if ($temperature!==null && $temperature!=='') {
            if (isset($rule['max_temperature']) && $temperature>$rule['max_temperature']) { $rejected[]='Recorded temperature exceeds the configured category limit.'; }
            if (isset($rule['min_temperature']) && $temperature<$rule['min_temperature']) { $rejected[]='Recorded temperature is below the configured category limit.'; }
        }
        if (isset($rule['max_age_minutes']) && ($now-$prepared)/60>$rule['max_age_minutes']) { $rejected[]='Preparation age exceeds the configured category limit.'; }
        if (isset($rule['required_storage']) && !in_array($food['storage_method'] ?? '',$rule['required_storage'],true)) { $rejected[]='Storage method is incompatible with the configured category rule.'; }
        return ['status'=>$rejected ? 'rejected' : ($review ? 'review' : 'available'),'reasons'=>array_merge($rejected,$review)];
    }
}
