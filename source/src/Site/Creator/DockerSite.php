<?php

namespace App\Site\Creator;

use App\Site\Creator as SiteCreator;
use App\Site\DockerSite as DockerSiteModel;
use App\System\Command\DockerRunCommand;

class DockerSite extends SiteCreator
{
    /**
     * Boot the container for a freshly created Docker site.
     *
     * ponytail: scaffold — just `docker run -d`. No pre-pull, no rm-if-exists,
     * no health check, no logging hook. See TODOs in build plan.
     */
    public function createContainer(): void
    {
        if (!($this->site instanceof DockerSiteModel)) {
            return;
        }
        $image = $this->site->getDockerImage();
        $port = $this->site->getDockerPort();
        if (empty($image) || empty($port)) {
            return;
        }

        $containerName = $this->site->getDockerContainerName()
            ?: sprintf('clp-%s', $this->site->getUser());

        $cmd = new DockerRunCommand();
        $cmd->setContainerName($containerName);
        $cmd->setImage($image);
        $cmd->setHostPort((int) $port);
        $cmd->setEnv($this->site->getDockerEnv());
        $cmd->setVolumes($this->site->getDockerVolumes());

        if (($_ENV['APP_ENV'] ?? 'prod') !== 'dev') {
            $this->commandExecutor->execute($cmd, 300);
        }
    }
}
