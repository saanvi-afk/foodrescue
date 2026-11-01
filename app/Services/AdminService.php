<?php
declare(strict_types=1);
namespace FoodRescue\Services;
use FoodRescue\Core\{HttpException,Input};
use FoodRescue\Middleware\Authorize;
use FoodRescue\Models\Repository;
final class AdminService extends Repository
{
    public function account(array $user,array $data): void
    {
        Authorize::check($user,['super_admin']);$id=Input::id($data);
        if ($id===(int)$user['id']) { throw new HttpException(422,'You cannot suspend or change your own administrator account here.'); }
        $status=Input::choice($data,'status',['active','suspended']);
        $this->transaction(function () use ($id,$status,$user) {
            if (!$this->one('SELECT id FROM users WHERE id=? FOR UPDATE',[$id])) { throw new HttpException(404,'Account not found.'); }
            $this->execute('UPDATE users SET status=?,auth_version=auth_version+1 WHERE id=?',[$status,$id]);
            (new AuthService($this->db))->audit((int)$user['id'],'admin.account_' . $status);
            $this->execute('INSERT INTO audit_logs(user_id,event,context) VALUES (?,?,?)',[$id,'account.status_changed',json_encode(['status'=>$status,'actor'=>$user['id']],JSON_THROW_ON_ERROR)]);
        });
    }
    public function category(array $user,array $data): void
    {
        Authorize::check($user,['super_admin']);$name=Input::text($data,'name',100);
        $this->transaction(function () use ($user,$data,$name) {
            if (!empty($data['id'])) { $id=Input::id($data);$this->execute('UPDATE food_categories SET name=?,active=? WHERE id=?',[$name,(int)Input::flag($data,'active'),$id]); }
            else {
                $id=$this->insert('INSERT INTO food_categories(name) VALUES (?)',[$name]);
                $this->execute("INSERT INTO safety_rules(category_id,version,parameters,source_url,created_by) VALUES (?,'screening-v1','{}',?,?)",[$id,'https://www.fssai.gov.in/upload/uploadfiles/files/Gazette_Notification_Surplus_Food_06_08_2019.pdf',$user['id']]);
            }
            (new AuthService($this->db))->audit((int)$user['id'],'admin.category_saved');
        });
    }
    public function safety(array $user,array $data): void
    {
        Authorize::check($user,['super_admin']);$category=Input::id($data,'category_id');
        if (!$this->one('SELECT id FROM food_categories WHERE id=?',[$category])) { throw new HttpException(422,'Unknown food category.'); }
        $parameters=['require_temperature'=>Input::flag($data,'require_temperature')];
        foreach (['max_age_minutes','max_temperature','min_temperature'] as $key) {
            if (isset($data[$key]) && $data[$key]!=='') { $parameters[$key]=Input::number($data,$key,$key==='max_age_minutes' ? 1 : -100,$key==='max_age_minutes' ? 525600 : 150); }
        }
        if (isset($parameters['max_temperature'],$parameters['min_temperature']) && $parameters['max_temperature']<$parameters['min_temperature']) { throw new HttpException(422,'Minimum temperature cannot exceed maximum temperature.'); }
        $url=Input::text($data,'source_url',500);
        if (!filter_var($url,FILTER_VALIDATE_URL) || parse_url($url,PHP_URL_SCHEME)!=='https') { throw new HttpException(422,'Provide a source guidance URL using HTTPS.'); }
        $this->execute('INSERT INTO safety_rules(category_id,version,parameters,source_url,created_by) VALUES (?,?,?,?,?)',[$category,'screening-' . bin2hex(random_bytes(6)),json_encode($parameters,JSON_THROW_ON_ERROR),$url,$user['id']]);
        (new AuthService($this->db))->audit((int)$user['id'],'admin.safety_rule_versioned');
    }
    public function configuration(array $user,array $data): void
    {
        Authorize::check($user,['super_admin','researcher']);$weights=[];
        foreach (TAMRAService::COMPONENTS as $key) { $weights[$key]=Input::number($data,$key,0,1); }
        $parameters=['speed_kmh'=>Input::number($data,'speed_kmh',1,100),'handling_minutes'=>Input::number($data,'handling_minutes',1,180),'urgency_horizon_minutes'=>Input::number($data,'urgency_horizon_minutes',1,10080),'cold_start_reliability'=>0.5,'minimum_history'=>5];
        (new TAMRAService())->validate($weights,$parameters);
        $this->execute('INSERT INTO algorithm_configurations(version,weights,parameters,created_by) VALUES (?,?,?,?)',[Input::text($data,'version',100),json_encode($weights,JSON_THROW_ON_ERROR),json_encode($parameters,JSON_THROW_ON_ERROR),$user['id']]);
        (new AuthService($this->db))->audit((int)$user['id'],'matching.configuration_versioned');
    }
    public function settings(array $user,array $data): void
    {
        Authorize::check($user,['super_admin']);$mass=Input::number($data,'meal_mass_kg',0.01,10);
        $this->execute("UPDATE platform_settings SET setting_value=?,updated_by=? WHERE setting_key='meal_mass_kg'",[json_encode($mass),$user['id']]);
        (new AuthService($this->db))->audit((int)$user['id'],'admin.settings_updated');
    }
}
