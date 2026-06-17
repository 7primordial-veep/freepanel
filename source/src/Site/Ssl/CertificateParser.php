<?php

namespace App\Site\Ssl;

class CertificateParser
{
    public function parse(Certificate $certificate) : ParsedCertificate
    {
        $rawData = openssl_x509_parse($certificate->getCertificate());
        if (false === is_array($rawData)) {
            throw new \Exception(sprintf("Fail to parse certificate with error: %s", openssl_error_string()));
        }
        if (false === isset($rawData["subject"]["CN"])) {
            throw new \Exception("Missing expected key \"subject.cn\" in certificate");
        }
        if (false === isset($rawData["serialNumber"])) {
            throw new \Exception("Missing expected key \"serialNumber\" in certificate");
        }
        if (false === isset($rawData["validFrom_time_t"])) {
            throw new \Exception("Missing expected key \"validFrom_time_t\" in certificate");
        }
        if (false === isset($rawData["validTo_time_t"])) {
            throw new \Exception("Missing expected key \"validTo_time_t\" in certificate");
        }
        $subjectAlternativeNames = [];
        if (isset($rawData["extensions"]["subjectAltName"])) {
            $subjectAlternativeNames = array_map(function ($item) {
                return explode(":", trim($item), 2)[1];
            }, array_filter(explode(",", $rawData["extensions"]["subjectAltName"]), function ($item) {
                return false !== strpos($item, ":");
            }));
        }
        $subject = $rawData["subject"] ?? [];
        $issuer = $rawData["issuer"] ?? [];
        $isSelfSigned = $rawData["subject"] === $rawData["issuer"];
        $validFromTime = new \DateTime("@" . $rawData["validFrom_time_t"]);
        $validFromTo = new \DateTime("@" . $rawData["validTo_time_t"]);
        $serialNumber = $rawData["serialNumber"] ?? null;
        $parsedCertificate = new ParsedCertificate($certificate, $subject, $issuer, $isSelfSigned, $validFromTime, $validFromTo, $serialNumber, $subjectAlternativeNames);
        return $parsedCertificate;
    }
}