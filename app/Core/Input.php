<?php
declare(strict_types=1);
namespace FoodRescue\Core;
final class Input
{
    public static function text(array $data, string $key, int $max = 1000, bool $required = true): string
    {
        $value = $data[$key] ?? '';
        if (!is_scalar($value)) { throw new HttpException(422, "Invalid {$key}."); }
        $value = trim((string) $value);
        if (($required && $value === '') || strlen($value)>$max) { throw new HttpException(422, "Enter {$key} (maximum {$max} characters)."); }
        return $value;
    }
    public static function number(array $data,string $key,float $min,float $max): float
    {
        $raw = $data[$key] ?? null;
        if (!is_scalar($raw) || !is_numeric($raw) || !is_finite((float)$raw) || (float)$raw<$min || (float)$raw>$max) { throw new HttpException(422,"{$key} must be between {$min} and {$max}."); }
        return (float)$raw;
    }
    public static function id(array $data,string $key='id'): int { $value=self::number($data,$key,1,PHP_INT_MAX); if (floor($value)!==$value) { throw new HttpException(422,"Invalid {$key}."); } return (int)$value; }
    public static function flag(array $data,string $key): bool { return in_array($data[$key] ?? false,[true,1,'1','true','on'],true); }
    public static function date(array $data,string $key): string
    {
        $raw=self::text($data,$key,40);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})?$/D',$raw)) { throw new HttpException(422,"Use an ISO date and time for {$key}."); }
        try { $date=new \DateTimeImmutable($raw,new \DateTimeZone('UTC')); }
        catch (\Exception) { throw new HttpException(422,"Enter a valid {$key} date."); }
        $errors=\DateTimeImmutable::getLastErrors();
        if ($errors && ($errors['warning_count'] || $errors['error_count'])) { throw new HttpException(422,"Enter a valid {$key} date."); }
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    public static function choice(array $data,string $key,array $choices): string
    {
        $value=self::text($data,$key);
        if (!in_array($value,$choices,true)) { throw new HttpException(422,"Invalid {$key}."); }
        return $value;
    }
}
