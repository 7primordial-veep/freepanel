<?php

namespace App\System\Command;

use App\System\Command;

/**
 * `docker rm -f <name>` — idempotent. "No such container" is treated as success
 * so the deleter doesn't fail on already-removed containers.
 */
class DockerRmCommand extends Command
{
    private ?string $containerName = null;

    public function setContainerName(string $name) : void
    {
        $this->containerName = $name;
    }

    public function getCommand() : string
    {
        if ($this->command) {
            return $this->command;
        }
        if (null === $this->containerName) {
            throw new \RuntimeException('DockerRmCommand: containerName must be set.');
        }
        // `|| true` swallows "no such container" so this is safe to call on a
        // site whose container was never created or already removed.
        $this->command = sprintf(
            '/usr/bin/docker rm -f %s 2>&1 || true',
            escapeshellarg($this->containerName)
        );
        return $this->command;
    }

    public function isSuccessful() : bool
    {
        return true;
    }
}
