<?php

namespace App\Site\Creator;

use App\Site\Creator as SiteCreator;
use App\Site\Nginx\Vhost\NodejsTemplate;
use App\System\Command\CopyFileCommand;
use App\System\Command\WriteFileCommand;
use App\System\Command\ChownCommand;
use App\System\Command\FindChmodCommand;
use App\System\Command\NvmInstallNodejsCommand;
class NodejsSite extends SiteCreator
{
    public function createNvmDirectory() : void
    {
        $siteUser = $this->site->getUser();
        $nvmDirectory = realpath(dirname(__FILE__) . "/../../../resources/etc/skel/nvm/");
        $siteUserNvmDirectory = sprintf("/home/%s/.nvm", $siteUser);
        $copyNvmDirectoryCommand = new CopyFileCommand();
        $copyNvmDirectoryCommand->setRecursive(true);
        $copyNvmDirectoryCommand->setSourceFile($nvmDirectory);
        $copyNvmDirectoryCommand->setDestinationFile($siteUserNvmDirectory);
        $chownCommand = new ChownCommand();
        $chownCommand->setFile($siteUserNvmDirectory);
        $chownCommand->setRecursive(true);
        $chownCommand->setUser($siteUser);
        $chownCommand->setGroup($siteUser);
        $chmodCommand = new FindChmodCommand();
        $chmodCommand->setFile($siteUserNvmDirectory);
        $chmodCommand->setDirectoryChmod(770);
        $chmodCommand->setFileChmod(770);
        $this->commandExecutor->execute($copyNvmDirectoryCommand);
        $this->commandExecutor->execute($chownCommand);
        $this->commandExecutor->execute($chmodCommand);
    }
    public function installNodejs()
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