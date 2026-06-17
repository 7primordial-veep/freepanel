<?php

namespace App\Backup\Rclone;

class ConfigBuilder
{
    public const CONFIG_NAME = "[remote]";
    private ConfigTemplate $configTemplate;
    public function __construct(ConfigTemplate $configTemplate)
    {
        $this->configTemplate = $configTemplate;
    }
    public function build() : string
    {
        $template = self::CONFIG_NAME;
        $configSettings = $this->configTemplate->getSettings();
        foreach ($configSettings as $key => $value) {
            $value = str_replace(["\r", "\n"], '', (string) $value);
            $template .= sprintf("%s%s = %s", PHP_EOL, $key, $value);
        }
        return $template;
    }
}