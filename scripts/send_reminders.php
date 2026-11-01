<?php
require_once dirname(__DIR__) . '/config/bootstrap.php';
echo 'Created ' . (new FoodRescue\Services\JobService(FoodRescue\Core\Database::connection()))->reminders() . " reminders.\n";
