<?php

namespace App\Site\Ssl;

class PrivateKey extends Key
{
    public function getResource()
    {
        if (!($resource = openssl_pkey_get_private($this->keyPEM))) {
            throw new \Exception(sprintf("Failed to convert key into resource: %s", openssl_error_string()));
        }
        return $resource;
    }
    public function getPublicKey()
    {
        $resource = $this->getResource();
        if (!($details = openssl_pkey_get_details($resource))) {
            throw new \Exception(sprintf("Failed to extract public key: %s", openssl_error_string()));
        }
        openssl_free_key($resource);
        return new PublicKey($details["key"]);
    }
}