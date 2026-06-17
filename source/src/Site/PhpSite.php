<?php

namespace App\Site;

use App\Entity\PhpSettings;
use App\System\CommandExecutor;
use App\System\Command\CatFileCommand;
class PhpSite extends Site
{
    private const TYPE = "php";
    protected string $type = self::TYPE;
    private ?PhpSettings $phpSettings = null;
    private bool $varnishCache = false;
    private ?string $varnishCacheSettingsFile = null;
    private array $varnishCacheSettings = [];
    public function setPhpSettings(PhpSettings $phpSettings) : void
    {
        $this->phpSettings = $phpSettings;
    }
    public function getPhpSettings() : ?PhpSettings
    {
        return $this->phpSettings;
    }
    public function setVarnishCache(bool $flag) : void
    {
        $this->varnishCache = $flag;
    }
    public function getVarnishCache() : bool
    {
        return $this->varnishCache;
    }
    public function setVarnishCacheSettings(array $varnishCacheSettings) : void
    {
        $this->varnishCacheSettings = $varnishCacheSettings;
    }
    public function getVarnishCacheSettings() : array
    {
        if (true === empty($this->varnishCacheSettings)) {
            try {
                $varnishCacheSettingsFile = $this->getVarnishCacheSettingsFile();
                $commandExecutor = new CommandExecutor();
                $varnishCacheSettingsFileCatCommand = new CatFileCommand();
                $varnishCacheSettingsFileCatCommand->setFile($varnishCacheSettingsFile);
                $commandExecutor->execute($varnishCacheSettingsFileCatCommand, 10);
                $varnishCacheSettings = trim($varnishCacheSettingsFileCatCommand->getOutput());
                if (false === empty($varnishCacheSettings)) {
                    $varnishCacheSettings = @json_decode($varnishCacheSettings, true);
                    if (false === empty($varnishCacheSettings) && true === is_array($varnishCacheSettings)) {
                        $this->varnishCacheSettings = $varnishCacheSettings;
                    }
                }
            } catch (\Exception $e) {
                // settings file missing/unreadable is non-fatal
            }
        }
        return $this->varnishCacheSettings;
    }
    public function setVarnishCacheSettingsFile(?string $varnishCacheSettingsFile) : void
    {
        $this->varnishCacheSettingsFile = $varnishCacheSettingsFile;
    }
    public function getVarnishCacheSettingsFile() : ?string
    {
        if (true === is_null($this->varnishCacheSettingsFile)) {
            $user = $this->getUser();
            $this->varnishCacheSettingsFile = sprintf("/home/%s/.varnish-cache/settings.json", $user);
        }
        return $this->varnishCacheSettingsFile;
    }
}