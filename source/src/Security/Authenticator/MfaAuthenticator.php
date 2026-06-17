<?php

namespace App\Security\Authenticator;

class MfaAuthenticator
{
    private int $codeLength = 6;
    public static function createSecret($secretLength = 16) : string
    {
        $validChars = self::getBase32LookupTable();
        $secret = '';
        $rnd = false;
        if (function_exists("random_bytes") || function_exists("mcrypt_create_iv") || function_exists("openssl_random_pseudo_bytes")) {
            $rnd = random_bytes($secretLength);
        }
        if (!($rnd !== false)) {
            throw new \Exception("No source of secure random");
        }
        $i = 0;
        while ($i < $secretLength) {
            $secret .= $validChars[ord($rnd[$i]) & 31];
            ++$i;
        }
        return $secret;
    }
    public function getCode($secret, $timeSlice = null) : string
    {
        if ($timeSlice === null) {
            $timeSlice = floor(time() / 30);
        }
        $secretKey = self::base32Decode($secret);
        $time = chr(0) . chr(0) . chr(0) . chr(0) . pack("N*", $timeSlice);
        $hm = hash_hmac("SHA1", $time, $secretKey, true);
        $offset = ord(substr($hm, -1)) & 0xf;
        $hashpart = substr($hm, $offset, 4);
        $value = unpack("N", $hashpart);
        $value = $value[1];
        $value = $value & 0x7fffffff;
        $modulo = pow(10, $this->codeLength);
        return str_pad($value % $modulo, $this->codeLength, "0", STR_PAD_LEFT);
    }
    public function verifyCode($secret, $code, $discrepancy = 2, $currentTimeSlice = null) : bool
    {
        if ($currentTimeSlice === null) {
            $currentTimeSlice = floor(time() / 30);
        }
        if (strlen($code) != 6) {
            return false;
        }
        $i = -$discrepancy;
        while ($i <= $discrepancy) {
            $calculatedCode = $this->getCode($secret, $currentTimeSlice + $i);
            if (self::timingSafeEquals($calculatedCode, $code)) {
                return true;
            }
            ++$i;
        }
        return false;
    }
    private static function base32Decode(string $secret) : string
    {
        if (true === empty($secret)) {
            return '';
        }
        $base32chars = self::getBase32LookupTable();
        $base32charsFlipped = array_flip($base32chars);
        $paddingCharCount = substr_count($secret, $base32chars[32]);
        $allowedValues = array(6, 4, 3, 1, 0);
        if (!in_array($paddingCharCount, $allowedValues)) {
            return false;
        }
        $i = 0;
        while ($i < 4) {
            if ($paddingCharCount == $allowedValues[$i] && substr($secret, -$allowedValues[$i]) != str_repeat($base32chars[32], $allowedValues[$i])) {
                return false;
            }
            ++$i;
        }
        $secret = str_replace("=", '', $secret);
        $secret = str_split($secret);
        $binaryString = '';
        $i = 0;
        while ($i < count($secret)) {
            $x = '';
            if (!in_array($secret[$i], $base32chars)) {
                return false;
            }
            $j = 0;
            while ($j < 8) {
                $x .= str_pad(base_convert(@$base32charsFlipped[@$secret[$i + $j]], 10, 2), 5, "0", STR_PAD_LEFT);
                ++$j;
            }
            $eightBits = str_split($x, 8);
            $z = 0;
            while ($z < count($eightBits)) {
                $binaryString .= ($y = chr(base_convert($eightBits[$z], 2, 10))) || ord($y) == 48 ? $y : '';
                ++$z;
            }
            $i = $i + 8;
        }
        return $binaryString;
    }
    private static function getBase32LookupTable() : array
    {
        return ["A", "B", "C", "D", "E", "F", "G", "H", "I", "J", "K", "L", "M", "N", "O", "P", "Q", "R", "S", "T", "U", "V", "W", "X", "Y", "Z", "2", "3", "4", "5", "6", "7", "="];
    }
    private static function timingSafeEquals($safeString, $userString) : bool
    {
        if (function_exists("hash_equals")) {
            return hash_equals($safeString, $userString);
        }
        $safeLen = strlen($safeString);
        $userLen = strlen($userString);
        if ($userLen != $safeLen) {
            return false;
        }
        $result = 0;
        $i = 0;
        while ($i < $userLen) {
            $result |= ord($safeString[$i]) ^ ord($userString[$i]);
            ++$i;
        }
        return $result === 0;
    }
    public function getQrCodeLink($secret, $name, $issuer) : string
    {
        $qrCodeLink = sprintf("otpauth://totp/%s?secret=%s&issuer=%s", rawurlencode($name), $secret, rawurlencode($issuer));
        return $qrCodeLink;
    }
}