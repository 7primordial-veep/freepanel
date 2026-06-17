<?php

namespace App\Service;

use Defuse\Crypto\Crypto as DefuseCrypto;
class Crypto
{
    public static function encrypt(string $text)
    {
        $secret = self::getSecret();
        $encryptedText = DefuseCrypto::encryptWithPassword($text, $secret, false);
        return $encryptedText;
    }
    public static function decrypt(string $encryptedText)
    {
        $secret = self::getSecret();
        $decryptedText = DefuseCrypto::decryptWithPassword($encryptedText, $secret, false);
        return $decryptedText;
    }
    private static function getSecret() : ?string
    {
        $secret = $_ENV["APP_SECRET"] ?? '';
        return $secret;
    }
}