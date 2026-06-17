<?php

namespace App\Util;

class Retry
{
    public static function retry(callable $fn, $retries = 2, $delay = 5)
    {
        while (true) {
            try {
                return $fn();
            } catch (\Exception $e) {
                if (!$retries--) {
                    throw $e;
                }
                if ($delay) {
                    sleep($delay);
                }
            }
        }
    }
}