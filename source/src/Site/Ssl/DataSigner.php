<?php

namespace App\Site\Ssl;

class DataSigner
{
    public function signData($data, PrivateKey $privateKey, $algorithm = OPENSSL_ALGO_SHA256) : ?string
    {
        $resource = $privateKey->getResource();
        if (!openssl_sign($data, $signature, $resource, $algorithm)) {
            throw new \Exception(sprintf("OpenSSL data signing failed with error: %s", openssl_error_string()));
        }
        return $signature;
    }
}