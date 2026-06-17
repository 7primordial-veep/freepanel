<?php

namespace App\Backup\Rclone;

class WasabiConfigTemplate extends ConfigTemplate
{
    private const TYPE = "s3";
    private const PROVIDER = "Wasabi";
    private const ENV_AUTH = "true";
    private const ACL = "private";
    private array $defaultSettings = ["type" => self::TYPE, "provider" => self::PROVIDER, "env_auth" => self::ENV_AUTH, "acl" => self::ACL];
    public function __construct()
    {
        $this->addSettings($this->defaultSettings);
    }
    public function setRegion(string $region) : void
    {
        $this->setEndpoint($region);
    }
    public function setEndpoint(string $region) : void
    {
        $endpoint = sprintf("s3.%s.wasabisys.com", $region);
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