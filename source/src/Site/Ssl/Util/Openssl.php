<?php

namespace App\Site\Ssl\Util;

use App\Site\Ssl\PrivateKey;
use App\Site\Ssl\Generator\CsrGenerator;
class Openssl
{
    const DIGEST_ALGORITHM = "sha256";
    public static function createSelfSignedCertificate(PrivateKey $privateKey, string $csr) : ?string
    {
        $privateKeyResource = $privateKey->getResource();
        $selfSignedCertificate = '';
        $sslConfigFile = tempnam(sys_get_temp_dir(), "clp-le-");
        try {
            file_put_contents($sslConfigFile, CsrGenerator::$sslConfigTemplate);
            $config = ["config" => $sslConfigFile, "x509_extensions" => "usr_cert", "digest_alg" => self::DIGEST_ALGORITHM];
            $x509 = openssl_csr_sign($csr, null, $privateKeyResource, $days = 365, $config);
            openssl_x509_export($x509, $certificate);
            openssl_pkey_export($privateKeyResource, $privateKey);
            $selfSignedCertificate = trim($certificate);
        } catch (\Exception $e) {
            throw $e;
        } finally {
            unlink($sslConfigFile);
        }
        return $selfSignedCertificate;
    }
}