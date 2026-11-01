<?php
declare(strict_types=1);
namespace FoodRescue\Validators;
use FoodRescue\Core\{HttpException,Input};
final class DatasetValidator
{
    private function keys(array $row,array $allowed): void
    {
        if (array_diff(array_keys($row),$allowed)) { throw new HttpException(422,'Unsupported dataset fields. Remove identifiers, contact details, addresses and free text.'); }
    }
    public function validate(string $json): array
    {
        try { $source=json_decode($json,true,32,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new HttpException(422,'Dataset must contain valid JSON.'); }
        if (!is_array($source)) { throw new HttpException(422,'Dataset must be an object.'); }
        $this->keys($source,['donations','receivers']);
        if (!is_array($source['donations'] ?? null) || !is_array($source['receivers'] ?? null) || !$source['donations'] || !$source['receivers'] || count($source['donations'])>500 || count($source['receivers'])>100) { throw new HttpException(422,'Provide 1–500 donations and 1–100 receivers.'); }
        $out=['donations'=>[],'receivers'=>[]];$ids=[];
        foreach ($source['donations'] as $row) {
            if (!is_array($row)) { throw new HttpException(422,'Invalid donation record.'); }
            $this->keys($row,['id','category_id','quantity_kg','latitude','longitude','created_at','deadline','status']);
            $id=Input::id($row);if (isset($ids[$id])) { throw new HttpException(422,'Duplicate donation id.'); }$ids[$id]=true;
            $created=Input::date($row,'created_at');$deadline=Input::date($row,'deadline');if ($deadline<=$created) { throw new HttpException(422,'Donation deadline must follow creation.'); }
            $out['donations'][]=['id'=>$id,'name'=>'Anonymous donation ' . $id,'category_id'=>Input::id($row,'category_id'),'quantity_kg'=>Input::number($row,'quantity_kg',0.001,100000),'latitude'=>Input::number($row,'latitude',-90,90),'longitude'=>Input::number($row,'longitude',-180,180),'created_at'=>$created,'deadline'=>$deadline,'status'=>Input::choice($row,'status',['available','rejected'])];
        }
        $ids=[];
        foreach ($source['receivers'] as $row) {
            if (!is_array($row)) { throw new HttpException(422,'Invalid receiver record.'); }
            $this->keys($row,['id','capacity_kg','latitude','longitude','collection_available','transport_available','max_distance_km','available_from','available_until','categories','history_count','history_success']);
            $id=Input::id($row);if (isset($ids[$id])) { throw new HttpException(422,'Duplicate receiver id.'); }$ids[$id]=true;
            $from=Input::date($row,'available_from');$until=Input::date($row,'available_until');if ($until<=$from) { throw new HttpException(422,'Invalid receiver window.'); }
            if (!is_array($row['categories'] ?? null) || count($row['categories'])>100 || !$row['categories']) { throw new HttpException(422,'Receiver categories are required.'); }
            $categories=[];foreach ($row['categories'] as $category) { $categories[]=Input::id(['id'=>$category]); }
            $history=(int)Input::number($row,'history_count',0,1000000);$success=(int)Input::number($row,'history_success',0,$history);
            $out['receivers'][]=['id'=>$id,'user_id'=>$id,'name'=>'Anonymous receiver ' . $id,'status'=>'active','capacity_kg'=>Input::number($row,'capacity_kg',0.001,100000),'reserved_kg'=>0,'latitude'=>Input::number($row,'latitude',-90,90),'longitude'=>Input::number($row,'longitude',-180,180),'collection_available'=>Input::flag($row,'collection_available'),'transport_available'=>Input::flag($row,'transport_available'),'max_distance_km'=>Input::number($row,'max_distance_km',0.1,500),'available_from'=>$from,'available_until'=>$until,'categories'=>$categories,'history_count'=>$history,'history_success'=>$success];
        }return $out;
    }
}
