<?php

namespace App\Util;

class HumanFileSize
{
    private static array $units = ["B" => 0, "KB" => 1, "MB" => 2, "GB" => 3, "TB" => 4, "PB" => 5, "EB" => 6, "ZB" => 7, "YB" => 8];
    public static function convert($bytes, $unit = '', $decimals = 2) : string
    {
        $units = self::$units;
        $value = 0;
        if ($bytes > 0) {
            if (!array_key_exists($unit, $units)) {
                $pow = floor(log($bytes) / log(1024));
                $unit = array_search($pow, $units);
            }
            $value = $bytes / pow(1024, floor($units[$unit]));
        }
        if (!is_numeric($decimals) || $decimals < 0) {
            $decimals = 2;
        }
        return sprintf("%." . $decimals . "f " . $unit, $value);
    }
}