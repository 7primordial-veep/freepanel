<?php

namespace App\System\Command;

/**
 * `sudo -n -u <user> mkdir -p <path>` — create a directory as the site's system user.
 */
class SudoMkdirCommand extends AbstractSudoCommand
{
    private ?string $path = null;

    public function setPath(string $path) : void
    {
        $this->path = $path;
    }

    public function getCommand() : string
    {
        if ($this->command) {
            return $this->command;
        }
        if (null === $this->path) {
            throw new \RuntimeException('SudoMkdirCommand: path must be set.');
        }
        $this->command = sprintf(
            '%s /bin/mkdir -p %s 2>&1',
            $this->sudoPrefix(),
            escapeshellarg($this->path)
        );
        return $this->command;
    }
}
