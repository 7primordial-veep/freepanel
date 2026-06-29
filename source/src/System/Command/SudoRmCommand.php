<?php

namespace App\System\Command;

/**
 * `sudo -u <user> rm <path>` or `rmdir` for directories.
 */
class SudoRmCommand extends AbstractSudoCommand
{
    private ?string $path = null;
    private bool $isDirectory = false;

    public function setPath(string $path) : void
    {
        $this->path = $path;
    }

    public function setIsDirectory(bool $isDirectory) : void
    {
        $this->isDirectory = $isDirectory;
    }

    public function getCommand() : string
    {
        if ($this->command) {
            return $this->command;
        }
        if (null === $this->path) {
            throw new \RuntimeException('SudoRmCommand: path must be set.');
        }
        $bin = $this->isDirectory ? '/bin/rmdir' : '/bin/rm -f';
        $this->command = sprintf(
            '%s %s %s 2>&1',
            $this->sudoPrefix(),
            $bin,
            escapeshellarg($this->path)
        );
        return $this->command;
    }
}
