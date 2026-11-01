<?php
declare(strict_types=1);
namespace FoodRescue\Services;
use FoodRescue\Core\HttpException;
final class Lifecycle
{
    private const TRANSITIONS=[
        'review'=>['available','rejected','withdrawn','expired'], 'rejected'=>['review','available','withdrawn'],
        'available'=>['review','rejected','accepted','withdrawn','expired'], 'accepted'=>['scheduled','cancelled','failed','expired'],
        'scheduled'=>['collected','cancelled','failed','expired'], 'collected'=>['completed'],
        'failed'=>['available','expired'], 'cancelled'=>['available','expired'],
    ];
    public static function assert(string $from,string $to): void
    {
        if (!in_array($to,self::TRANSITIONS[$from] ?? [],true)) { throw new HttpException(409,"Cannot move donation from {$from} to {$to}."); }
    }
}
