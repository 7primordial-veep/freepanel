<?php

namespace App\Backup\Rclone;

class DropboxConfigTemplate extends ConfigTemplate
{
    private const TYPE = "dropbox";
    private array $defaultSettings = ["type" => self::TYPE];
    public function __construct()
    {
        $this->addSettings($this->defaultSettings);
    }
    public function setToken(string $token) : void
    {
        $this->setSetting("token", $token);
    }
}