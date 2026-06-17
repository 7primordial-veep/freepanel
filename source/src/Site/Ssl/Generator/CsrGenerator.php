<?php

namespace App\Site\Ssl\Generator;

use App\Site\Ssl\DistinguishedName;
use App\Site\Ssl\PrivateKey;
class CsrGenerator
{
    const DIGEST_ALGORITHM = "sha256";
    private $privateKey;
    private $distinguishedName;
    public static $sslConfigTemplate = "[ req ]\ndistinguished_name = req_distinguished_name\nreq_extensions = v3_req\n[ req_distinguished_name ]\n[ v3_req ]\nbasicConstraints = CA:FALSE\nkeyUsage = nonRepudiation, digitalSignature, keyEncipherment\nextendedKeyUsage = serverAuth, clientAuth, codeSigning, emailProtection\nsubjectAltName = \${ENV::PHP_PASS_SUBJECTALTNAME}\n[ usr_cert ]\nbasicConstraints=CA:FALSE\nnsComment = \"OpenSSL Generated Certificate\"\nkeyUsage = nonRepudiation, digitalSignature, keyEncipherment\nextendedKeyUsage = serverAuth, clientAuth, codeSigning, emailProtection\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid,issuer\nsubjectAltName = \${ENV::PHP_PASS_SUBJECTALTNAME_FROM_CSR}";
    public function __construct(PrivateKey $privateKey, DistinguishedName $distinguishedName)
    {
        $this->privateKey = $privateKey;
        $this->distinguishedName = $distinguishedName;
    }
    public function generate()
    {
        $domains = array_merge([$this->distinguishedName->getCommonName()], $this->distinguishedName->getSubjectAlternativeNames());
        $sslConfigFile = tempnam(sys_get_temp_dir(), "clp-le-");
        try {
            file_put_contents($sslConfigFile, self::$sslConfigTemplate);
            $resource = $this->privateKey->getResource();
            $csrData = $this->getCSRData();
            $sanDomainPrefixed = array_map(function ($value) {
                return sprintf("DNS: %s", $value);
            }, $domains);
            putenv("PHP_PASS_SUBJECTALTNAME_FROM_CSR=" . implode(",", $sanDomainPrefixed));
            putenv("PHP_PASS_SUBJECTALTNAME=" . implode(",", $sanDomainPrefixed));
            $csr = openssl_csr_new($csrData, $resource, ["digest_alg" => self::DIGEST_ALGORITHM, "config" => $sslConfigFile]);
            if (false === $csr) {
                throw new \Exception(sprintf("OpenSSL CSR signing failed with error: %s", openssl_error_string()));
            }
            if (!openssl_csr_export($csr, $csrExport)) {
                throw new \Exception(sprintf("OpenSSL CSR signing failed with error: %s", openssl_error_string()));
            }
            return $csrExport;
        } finally {
            unlink($sslConfigFile);
        }
    }
    private function getCSRData()
    {
        $data = [];
        if (false === is_null($this->distinguishedName->getCountryName())) {
            $data["countryName"] = $this->distinguishedName->getCountryName();
        }
        if (false === is_null($this->distinguishedName->getStateOrProvinceName())) {
            $data["stateOrProvinceName"] = $this->distinguishedName->getStateOrProvinceName();
        }
        if (false === is_null($this->distinguishedName->getLocalityName())) {
            $data["localityName"] = $this->distinguishedName->getLocalityName();
        }
        if (false === is_null($this->distinguishedName->getOrganizationName())) {
            $data["organizationName"] = $this->distinguishedName->getOrganizationName();
        }
        if (false === is_null($this->distinguishedName->getOrganizationalUnitName())) {
            $data["organizationalUnitName"] = $this->distinguishedName->getOrganizationalUnitName();
        }
        if (false === is_null($this->distinguishedName->getCommonName())) {
            $data["commonName"] = $this->distinguishedName->getCommonName();
        }
        if (false === is_null($this->distinguishedName->getEmailAddress())) {
            $data["emailAddress"] = $this->distinguishedName->getEmailAddress();
        }
        return $data;
    }
}