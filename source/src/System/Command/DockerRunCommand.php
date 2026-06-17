<?php

namespace App\System\Command;

use App\System\Command;

class DockerRunCommand extends Command
{
    private string $containerName;
    private string $image;
    private int $hostPort;
    private int $containerPort = 80;
    /** @var array<string,string> */
    private array $env = [];
    /** @var array<int,array{host:string,container:string}> */
    private array $volumes = [];

    public function setContainerName(string $name): void
    {
        $this->containerName = $name;
    }

    public function setImage(string $image): void
    {
        $this->image = $image;
    }

    public function setHostPort(int $port): void
    {
        $this->hostPort = $port;
    }

    public function setContainerPort(int $port): void
    {
        $this->containerPort = $port;
    }

    public function setEnv(array $env): void
    {
        $this->env = $env;
    }

    public function setVolumes(array $volumes): void
    {
        $this->volumes = $volumes;
    }

    public function getCommand(): string
    {
        if ($this->command) {
            return $this->command;
        }
        $parts = [
            '/usr/bin/docker run -d',
            '--restart unless-stopped',
            sprintf('--name %s', escapeshellarg($this->containerName)),
            sprintf('-p 127.0.0.1:%d:%d', $this->hostPort, $this->containerPort),
        ];
        foreach ($this->env as $k => $v) {
            $parts[] = sprintf('-e %s', escapeshellarg($k . '=' . $v));
        }
        foreach ($this->volumes as $vol) {
            if (empty($vol['host']) || empty($vol['container'])) {
                continue;
            }
            $parts[] = sprintf('-v %s:%s', escapeshellarg($vol['host']), escapeshellarg($vol['container']));
        }
        $parts[] = escapeshellarg($this->image);

        // Idempotent: rm -f the prior container (if any) before starting a fresh one.
        $this->command = sprintf(
            '/usr/bin/docker rm -f %s > /dev/null 2>&1 || true; %s',
            escapeshellarg($this->containerName),
            implode(' ', $parts)
        );
        return $this->command;
    }

    public function isSuccessful() : bool
    {
        return true;
    }
}
