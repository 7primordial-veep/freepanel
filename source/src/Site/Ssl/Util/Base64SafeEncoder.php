<?php

namespace App\Site\Ssl\Util;

class Base64SafeEncoder
{
    public function encode($input) : string
    {
        return str_replace("=", '', strtr(base64_encode($input), "+/", "-_"));
    }
    public function decode($input) : string
    {
        $remainder = \strlen($input) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $input .= str_repeat("=", $padlen);
        }
        return base64_decode(strtr($input, "-_", "+/"));
    }
}