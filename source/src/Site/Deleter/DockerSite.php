<?php

namespace App\Site\Deleter;

use App\Site\Deleter as SiteDeleter;
use App\Site\DockerSite as DockerSiteModel;
use App\System\Command\DockerRmCommand;

class DockerSite extends SiteDeleter
{
    public function delete() : void
    {
        $this->removeContainer();
        parent::delete();
    }

    private function removeContainer() : void
    {
        if (!($this->site instanceof DockerSiteModel)) {
            return;
        }
        $containerName = $this->site->getDockerContainerName()
            ?: sprintf('clp-%s', $this->site->getUser());
        if (empty($containerName)) {
            return;
        }
        $cmd = new DockerRmCommand();
        $cmd->setContainerName($containerName);
        $this->commandExecutor->execute($cmd, 60);
    }
}
