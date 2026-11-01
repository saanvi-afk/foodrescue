<?php
require_once dirname(__DIR__) . '/config/bootstrap.php';
echo json_encode((new FoodRescue\Services\MailService(FoodRescue\Core\Database::connection()))->deliver(),JSON_THROW_ON_ERROR) . "\n";
