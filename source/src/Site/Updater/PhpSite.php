<?php

namespace App\Site\Updater;

use App\Site\Updater as SiteUpdater;
use App\System\Command\ChownCommand;
use App\System\Command\ChmodCommand;
use App\System\Command\ReadLinkCommand;
use App\System\Command\DeleteFileCommand;
use App\System\Command\WriteFileCommand;
use App\System\Command\ServiceReloadCommand;
use App\Site\PhpFpm\Pool;
use App\Site\PhpFpm\PoolBuilder;
use App\Site\PhpFpm\PoolReader;
class PhpSite extends SiteUpdater
{
    public function phpSettings() : void
    {
        $this->updateNginxVhost();
        $this->reloadNginxService();
    }
    public function reloadPhpFpmService($phpVersion)
    {
        if ("dev" != $_ENV["APP_ENV"]) {
            $serviceName = sprintf("php%s-fpm", $phpVersion);
            $reloadPhpFpmServiceCommand = new ServiceReloadCommand();
            $reloadPhpFpmServiceCommand->setServiceName($serviceName);
            $this->commandExecutor->execute($reloadPhpFpmServiceCommand);
        }
    }
    public function changePhpVersion(string $currentPhpVersion, string $newPhpVersion) : void
    {
        $domainName = $this->site->getDomainName();
        $oldPhpFpmPoolFile = sprintf("/etc/php/%s/fpm/pool.d/%s.conf", $currentPhpVersion, $domainName);
        $phpFpmPoolFileDeleteCommand = new DeleteFileCommand();
        $phpFpmPoolFileDeleteCommand->setFile($oldPhpFpmPoolFile);
        $this->commandExecutor->execute($phpFpmPoolFileDeleteCommand);
        $this->reloadPhpFpmService($currentPhpVersion);
        $this->createPhpFpmPool($newPhpVersion);
        $this->reloadPhpFpmService($newPhpVersion);
    }
    private function createPhpFpmPool(string $phpVersion) : void
    {
        $siteUser = $this->site->getUser();
        $phpSettings = $this->site->getPhpSettings();
        $domainName = $this->site->getDomainName();
        $poolDirectory = sprintf("/etc/php/%s/fpm/pool.d/", $phpVersion);
        $poolReader = new PoolReader($poolDirectory);
        $pools = $poolReader->getPools();
        usort($pools, function ($a, $b) {
            return $a->getPort() < $b->getPort();
        });
        $latestPool = array_shift($pools);
        $poolPort = $latestPool->getPort() + 1;
        $pool = new Pool();
        $pool->setName($domainName);
        $pool->setUser($siteUser);
        $pool->setGroup($siteUser);
        $pool->setPort($poolPort);
        $phpSettings->setPoolPort($poolPort);
        $poolBuilder = new PoolBuilder();
        $poolContent = $poolBuilder->create($pool);
        $poolFile = sprintf("/etc/php/%s/fpm/pool.d/%s.conf", $phpVersion, $domainName);
        $writePoolFileCommand = new WriteFileCommand();
        $writePoolFileCommand->setFile($poolFile);
        $writePoolFileCommand->setContent($poolContent);
        $this->commandExecutor->execute($writePoolFileCommand);
    }
    public function writeVarnishCacheSettingsFile(array $varnishCacheSettings) : void
    {
        $siteUser = $this->site->getUser();
        $settingsFile = sprintf("/home/%s/.varnish-cache/settings.json", $siteUser);
        $readLinkCommand = new ReadLinkCommand();
        $readLinkCommand->setFile($settingsFile);
        $this->commandExecutor->execute($readLinkCommand);
        $readLinkPath = $readLinkCommand->getOutput();
        if (false === empty($readLinkPath) && $readLinkPath == $settingsFile) {
            $settings = json_encode($varnishCacheSettings, JSON_PRETTY_PRINT);
            $writeVarnishCacheSettingsFileCommand = new WriteFileCommand();
            $writeVarnishCacheSettingsFileCommand->setFile($settingsFile);
            $writeVarnishCacheSettingsFileCommand->setContent($settings);
            $chownVarnishCacheSettingsCommand = new ChownCommand();
            $chownVarnishCacheSettingsCommand->setFile($settingsFile);
            $chownVarnishCacheSettingsCommand->setRecursive(false);
            $chownVarnishCacheSettingsCommand->setUser($siteUser);
            $chownVarnishCacheSettingsCommand->setGroup($siteUser);
            $chmodVarnishCacheSettingsCommand = new ChmodCommand();
            $chmodVarnishCacheSettingsCommand->setFile($settingsFile);
            $chmodVarnishCacheSettingsCommand->setChmod(770);
            $this->commandExecutor->execute($writeVarnishCacheSettingsFileCommand);
            $this->commandExecutor->execute($chownVarnishCacheSettingsCommand);
            $this->commandExecutor->execute($chmodVarnishCacheSettingsCommand);
        }
    }
    protected function getRootDirectory() : string
    {
        return parent::getRootDirectory();
    }
}