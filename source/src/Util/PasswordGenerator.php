<?php

namespace App\Util;

class PasswordGenerator
{
    public static function generate($length = 16) : string
    {
        $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
        $count = mb_strlen($chars);
        $i = 0;
        $password = '';
        while ($i < $length) {
            $index = random_int(0, $count - 1);
            $password .= mb_substr($chars, $index, 1);
            $i++;
        }
        return $password;
    }
}