<?php

namespace App\Site\Updater;

use App\Site\Updater as SiteUpdater;
use App\System\Command\NvmInstallNodejsCommand;
class NodejsSite extends SiteUpdater
{
    public function nodejsSettings() : void
    {
        $this->updateNginxVhost();
        $this->reloadNginxService();
    }
    public function installNodejsVersion() : void
    {
        $siteUser = $this->site->getUser();
        $nodejsSettings = $this->site->getNodejsSettings();
        $nodejsVersion = $nodejsSettings->getNodejsVersion();
        $installNodejsCommand = new NvmInstallNodejsCommand();
        $installNodejsCommand->setRunAsUser($siteUser);
        $installNodejsCommand->setNodejsVersion($nodejsVersion);
        if ("dev" != $_ENV["APP_ENV"]) {
            $this->commandExecutor->execute($installNodejsCommand, 180);
        }
    }
}