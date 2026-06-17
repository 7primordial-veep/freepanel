<?php

namespace App\Site\Ssl;

use App\Site\Ssl\Key;
use App\Site\Ssl\ParsedKey;
class KeyParser
{
    public function parse(Key $key) : ParsedKey
    {
        $resource = $key->getResource();
        $rawData = openssl_pkey_get_details($resource);
        openssl_free_key($resource);
        if (false === is_array($rawData)) {
            throw new \Exception(sprintf("Fail to parse key with error: %s", openssl_error_string()));
        }
        foreach (["type", "key", "bits"] as $requiredKey) {
            if (isset($rawData[$requiredKey])) {
                continue;
            }
            throw new \Exception(sprintf("Missing expected key \"%s\" in OpenSSL key", $requiredKey));
        }
        $details = $rawData["rsa"] ?? [];
        return new ParsedKey($key, $rawData["key"], $rawData["bits"], $rawData["type"], $details);
    }
}