<?php

namespace App\Site\Updater;

use App\Site\Updater as SiteUpdater;
use App\Site\DockerSite as DockerSiteModel;
use App\System\Command\DockerRunCommand;

class DockerSite extends SiteUpdater
{
    public function recreateContainer(): void
    {
        if (!($this->site instanceof DockerSiteModel)) {
            return;
        }

        $containerName = $this->site->getDockerContainerName() ?: sprintf('clp-%s', $this->site->getUser());
        $image = $this->site->getDockerImage();
        $port = $this->site->getDockerPort();

        if (empty($image) || empty($port) || empty($containerName)) {
            return;
        }

        $cmd = new DockerRunCommand();
        $cmd->setContainerName($containerName);
        $cmd->setImage($image);
        $cmd->setHostPort((int) $port);
        $cmd->setEnv($this->site->getDockerEnv());
        $cmd->setVolumes($this->site->getDockerVolumes());

        $this->commandExecutor->execute($cmd, 300);
    }

    public function applyDockerSettings(?string $image, ?int $port, array $env, array $volumes): void
    {
        if (!($this->site instanceof DockerSiteModel)) {
            return;
        }

        if ($image !== null) {
            $this->site->setDockerImage($image);
        }

        if ($port !== null) {
            $this->site->setDockerPort($port);
        }

        $this->site->setDockerEnv($env);
        $this->site->setDockerVolumes($volumes);

        $this->recreateContainer();
    }
}
