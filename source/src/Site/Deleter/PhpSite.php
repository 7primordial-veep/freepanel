<?php

namespace App\Site\Deleter;

use App\Site\Deleter as SiteDeleter;
use App\System\Command\DeleteFileCommand;
class PhpSite extends SiteDeleter
{
    public function delete() : void
    {
        $this->deletePhpFpmPool();
        parent::delete();
    }
    private function deletePhpFpmPool()
    {
        $domainName = $this->site->getDomainName();
        $phpSettings = $this->site->getPhpSettings();
        $phpVersion = $phpSettings->getPhpVersion();
        $phpFpmPoolFile = sprintf("/etc/php/%s/fpm/pool.d/%s.conf", $phpVersion, $domainName);
        $phpFpmPoolFileDeleteCommand = new DeleteFileCommand();
        $phpFpmPoolFileDeleteCommand->setFile($phpFpmPoolFile);
        $this->commandExecutor->execute($phpFpmPoolFileDeleteCommand);
        $serviceName = sprintf("php%s-fpm", $phpVersion);
        $this->reloadService($serviceName);
    }
}