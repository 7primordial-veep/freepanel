<?php

namespace App\Backup\Rclone;

class GoogleDriveConfigTemplate extends ConfigTemplate
{
    private const TYPE = "drive";
    private const SCOPE = "drive";
    public const SERVICE_ACCOUNT_FILE = "/home/clp/.config/rclone/credentials/service-account-file.json";
    private array $defaultSettings = ["type" => self::TYPE, "scope" => self::SCOPE, "service_account_file" => self::SERVICE_ACCOUNT_FILE];
    public function __construct()
    {
        $this->addSettings($this->defaultSettings);
    }
}