<?php

namespace App\Backup\Rclone;

class AmazonS3ConfigTemplate extends ConfigTemplate
{
    private const TYPE = "s3";
    private const PROVIDER = "AWS";
    private const ENV_AUTH = "true";
    private const ACL = "bucket-owner-full-control";
    private const STORAGE_CLASS = "STANDARD";
    private array $defaultSettings = ["type" => self::TYPE, "provider" => self::PROVIDER, "env_auth" => self::ENV_AUTH, "acl" => self::ACL, "storage_class" => self::STORAGE_CLASS];
    public function __construct()
    {
        $this->addSettings($this->defaultSettings);
    }
    public function setRegion(string $region) : void
    {
        $this->setSetting("region", $region);
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