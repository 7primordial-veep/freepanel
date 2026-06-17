<?php

namespace App\Site\Ssl;

class PublicKey extends Key
{
    public function getResource()
    {
        if (!($resource = openssl_pkey_get_public($this->keyPEM))) {
            throw new \Exception(sprintf("Failed to convert key into resource: %s", openssl_error_string()));
        }
        return $resource;
    }
}