<?php

namespace App\Site\Ssl\Generator;

use App\Site\Ssl\PrivateKey;
class RsaKeyGenerator
{
    const PRIVATE_KEY_BITS = 4096;
    public function generatePrivateKey() : PrivateKey
    {
        $resource = openssl_pkey_new(["private_key_type" => OPENSSL_KEYTYPE_RSA, "private_key_bits" => self::PRIVATE_KEY_BITS]);
        openssl_pkey_export($resource, $privateKey);
        $privateKey = new PrivateKey($privateKey);
        return $privateKey;
    }
}