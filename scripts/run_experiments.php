<?php
require_once dirname(__DIR__) . '/config/bootstrap.php';
if ($argc!==5) { fwrite(STDERR,"Usage: php scripts/run_experiments.php researcher_id dataset_id configuration_id seed\n");exit(1); }
$db=FoodRescue\Core\Database::connection();$user=(new FoodRescue\Models\UserRepository($db))->find((int)$argv[1]);
$id=(new FoodRescue\Services\ResearchService($db))->experiment($user ?? [],['name'=>'CLI comparison','dataset_id'=>$argv[2],'configuration_id'=>$argv[3],'seed'=>$argv[4]]);
echo "Experiment {$id} completed.\n";
