<?php

namespace App\Site;

class DockerSite extends Site
{
    const TYPE = 'docker';
    protected string $type = self::TYPE;

    private ?string $dockerImage = null;
    private ?int $dockerPort = null;
    /** @var array<string,string> */
    private array $dockerEnv = [];
    /** @var array<int,array{host:string,container:string}> */
    private array $dockerVolumes = [];
    private ?string $dockerContainerName = null;

    public function getDockerImage(): ?string
    {
        return $this->dockerImage;
    }

    public function setDockerImage(?string $dockerImage): void
    {
        $this->dockerImage = $dockerImage;
    }

    public function getDockerPort(): ?int
    {
        return $this->dockerPort;
    }

    public function setDockerPort(?int $dockerPort): void
    {
        $this->dockerPort = $dockerPort;
    }

    public function getDockerEnv(): array
    {
        return $this->dockerEnv;
    }

    public function setDockerEnv(array $dockerEnv): void
    {
        $this->dockerEnv = $dockerEnv;
    }

    public function getDockerVolumes(): array
    {
        return $this->dockerVolumes;
    }

    public function setDockerVolumes(array $dockerVolumes): void
    {
        $this->dockerVolumes = $dockerVolumes;
    }

    public function getDockerContainerName(): ?string
    {
        return $this->dockerContainerName;
    }

    public function setDockerContainerName(?string $name): void
    {
        $this->dockerContainerName = $name;
    }

    /**
     * Reuse the reverse-proxy nginx vhost machinery by exposing the
     * container's host port as a 127.0.0.1 upstream.
     */
    public function getReverseProxyUrl(): ?string
    {
        if ($this->dockerPort === null) {
            return null;
        }

        return sprintf('http://127.0.0.1:%d', $this->dockerPort);
    }
}
