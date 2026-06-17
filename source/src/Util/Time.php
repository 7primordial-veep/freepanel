<?php

namespace App\Util;

class Time
{
    public static function isValidTimestamp($timestamp) : bool
    {
        $isValidTimestamp = (string) (int) $timestamp === $timestamp && $timestamp <= PHP_INT_MAX && $timestamp >= ~PHP_INT_MAX;
        return $isValidTimestamp;
    }
    public static function roundToNearestMinuteInterval(\DateTime $dateTime, $minuteInterval = 5) : \DateTime
    {
        $dateTime = $dateTime->setTime($dateTime->format("H"), round($dateTime->format("i") / $minuteInterval) * $minuteInterval, 0);
        return $dateTime;
    }
}