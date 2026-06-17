<?php

namespace App\Site\Ssl;

class DistinguishedName
{
    private $commonName;
    private $countryName;
    private $stateOrProvinceName;
    private $localityName;
    private $organizationName;
    private $organizationalUnitName;
    private $emailAddress;
    private $subjectAlternativeNames = [];
    public function __construct($commonName, array $subjectAlternativeNames = [], $countryName = null, $stateOrProvinceName = null, $localityName = null, $organizationName = null, $organizationalUnitName = null, $emailAddress = null)
    {
        $this->commonName = $commonName;
        $this->subjectAlternativeNames = array_diff(array_unique($subjectAlternativeNames), [$commonName]);
        $this->countryName = $countryName;
        $this->stateOrProvinceName = $stateOrProvinceName;
        $this->localityName = $localityName;
        $this->organizationName = $organizationName;
        $this->organizationalUnitName = $organizationalUnitName;
        $this->emailAddress = $emailAddress;
    }
    public function getCommonName()
    {
        return $this->commonName;
    }
    public function getCountryName()
    {
        return $this->countryName;
    }
    public function getStateOrProvinceName()
    {
        return $this->stateOrProvinceName;
    }
    public function getLocalityName()
    {
        return $this->localityName;
    }
    public function getOrganizationName()
    {
        return $this->organizationName;
    }
    public function getOrganizationalUnitName()
    {
        return $this->organizationalUnitName;
    }
    public function getEmailAddress()
    {
        return $this->emailAddress;
    }
    public function getSubjectAlternativeNames()
    {
        return $this->subjectAlternativeNames;
    }
}