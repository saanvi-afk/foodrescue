<?php
use FoodRescue\Core\Environment as Env;
return [
    'dsn' => sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', Env::get('DB_HOST', '127.0.0.1'), Env::get('DB_PORT', '3306'), Env::get('DB_DATABASE', 'foodrescue')),
    'username' => Env::get('DB_USERNAME', 'foodrescue'),
    'password' => Env::get('DB_PASSWORD'),
];
