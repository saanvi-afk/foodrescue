<?php
require dirname(__DIR__) . '/config/bootstrap.php';
if (!str_ends_with(FoodRescue\Core\Environment::get('DB_DATABASE'),'_test')) { exit(2); }
try {
    $db=FoodRescue\Core\Database::connection();$user=(new FoodRescue\Models\UserRepository($db))->find((int)$argv[1]);
    $id=(new FoodRescue\Services\MatchingService($db))->accept($user,(int)$argv[2]);
    echo json_encode(['accepted'=>true,'id'=>$id]);
} catch (FoodRescue\Core\HttpException $error) { echo json_encode(['accepted'=>false,'status'=>$error->status]); }
catch (Throwable $error) { fwrite(STDERR,(string)$error);exit(1); }
