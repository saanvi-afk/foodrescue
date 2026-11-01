<?php
require_once dirname(__DIR__) . '/config/bootstrap.php';
echo 'Expired ' . (new FoodRescue\Services\JobService(FoodRescue\Core\Database::connection()))->expire() . " donations.\n";
