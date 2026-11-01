<?php
declare(strict_types=1);
namespace FoodRescue\Core;

final class Environment
{
    public static function load(string $path): void
    {
        if (!is_file($path)) { return; }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) { continue; }
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $key = trim($key);
            if (getenv($key) === false) { putenv($key . '=' . trim(trim($value), "\"'")); }
        }
    }
    public static function get(string $key, string $default = ''): string
    {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }
}
