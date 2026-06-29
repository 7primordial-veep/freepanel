<?php

namespace App\System\Command;

/**
 * `sudo -n -u <user> mv <from> <to>` — move/rename a path as the site's system user.
 */
class SudoMvCommand extends AbstractSudoCommand
{
    private ?string $fromPath = null;
    private ?string $toPath = null;

    public function setFromPath(string $path) : void
    {
        $this->fromPath = $path;
    }

    public function setToPath(string $path) : void
    {
        $this->toPath = $path;
    }

    public function getCommand() : string
    {
        if ($this->command) {
            return $this->command;
        }
        if (null === $this->fromPath || null === $this->toPath) {
            throw new \RuntimeException('SudoMvCommand: fromPath and toPath must be set.');
        }
        $this->command = sprintf(
            '%s /bin/mv %s %s 2>&1',
            $this->sudoPrefix(),
            escapeshellarg($this->fromPath),
            escapeshellarg($this->toPath)
        );
        return $this->command;
    }
}
