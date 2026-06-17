<?php

namespace App\Backup\Rclone;

class SftpConfigTemplate extends ConfigTemplate
{
    private const TYPE = "sftp";
    private const SHELL_TYPE = "unix";
    private const DISABLE_HASH_CHECK = "true";
    private array $defaultSettings = ["type" => self::TYPE, "shell_type" => self::SHELL_TYPE, "disable_hashcheck" => self::DISABLE_HASH_CHECK];
    public function __construct()
    {
        $this->addSettings($this->defaultSettings);
    }
}