<?php

namespace App\Backup\Rclone;

class DigitalOceanSpacesConfigTemplate extends ConfigTemplate
{
    private const TYPE = "s3";
    private const PROVIDER = "DigitalOcean";
    private const ACL = "bucket-owner-full-control";
    private array $defaultSettings = ["type" => self::TYPE, "provider" => self::PROVIDER, "acl" => self::ACL];
    public function __construct()
    {
        $this->addSettings($this->defaultSettings);
    }
    public function setEndpoint(string $endpoint) : void
    {
        $this->setSetting("endpoint", $endpoint);
    }
    public function setAccessKeyId(string $accessKeyId) : void
    {
        $this->setSetting("access_key_id", $accessKeyId);
    }
    public function setSecretAccessKey(string $secretAccessKey) : void
    {
        $this->setSetting("secret_access_key", $secretAccessKey);
    }
}