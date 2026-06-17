<?php

namespace App\Site\Ssl;

class ParsedCertificate
{
    private Certificate $source;
    private array $subject = [];
    private array $issuers = [];
    private ?bool $isSelfSigned = true;
    private ?\DateTime $validFrom = null;
    private ?\DateTime $validTo = null;
    private ?string $serialNumber = null;
    private array $subjectAlternativeNames = [];
    public function __construct(Certificate $source, $subject = [], $issuers = [], $isSelfSigned = true, \DateTime $validFrom = null, \DateTime $validTo = null, $serialNumber = null, array $subjectAlternativeNames = [])
    {
        $this->source = $source;
        $this->subject = $subject;
        $this->issuers = $issuers;
        $this->isSelfSigned = $isSelfSigned;
        $this->validFrom = $validFrom;
        $this->validTo = $validTo;
        $this->serialNumber = $serialNumber;
        $this->subjectAlternativeNames = $subjectAlternativeNames;
    }
    public function getSource() : Certificate
    {
        return $this->source;
    }
    public function getSubject() : array
    {
        return $this->subject;
    }
    public function getIssuers() : array
    {
        return $this->issuers;
    }
    public function getIssuerList() : string
    {
        $issuerParts = [];
        $issuers = $this->getIssuers();
        foreach ($issuers as $key => $value) {
            $issuerParts[] = sprintf("/%s=%s", $key, $value);
        }
        $issuerList = implode(",", $issuerParts);
        return $issuerList;
    }
    public function getSubjectList() : string
    {
        $subjectParts = [];
        $subjects = $this->getSubject();
        foreach ($subjects as $key => $value) {
            if (true === is_array($value)) {
                $value = implode("\n", $value);
            }
            $subjectParts[] = sprintf("/%s=%s", $key, $value);
        }
        $subjectList = implode(",", $subjectParts);
        return $subjectList;
    }
    public function isSelfSigned() : ?bool
    {
        return $this->isSelfSigned;
    }
    public function getValidFrom() : ?\DateTime
    {
        return $this->validFrom;
    }
    public function getValidTo() : ?\DateTime
    {
        return $this->validTo;
    }
    public function isExpired() : bool
    {
        return $this->validTo < new \DateTime();
    }
    public function getSerialNumber() : ?string
    {
        return $this->serialNumber;
    }
    public function getSubjectAlternativeNames() : array
    {
        return $this->subjectAlternativeNames;
    }
}