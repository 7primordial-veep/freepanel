<?php

namespace App\System\Command;

use App\System\Command;

/**
 * `sudo -u <user> rm <path>` or `rmdir` for directories.
 */
class SudoRmCommand extends Command
{
    private ?string $targetUser = null;
    private ?string $path = null;
    private bool $isDirectory = false;

    public function setTargetUser(string $user) : void
    {
        $this->targetUser = $user;
    }

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
        if (null === $this->targetUser || null === $this->path) {
            throw new \RuntimeException('SudoRmCommand: targetUser and path must be set.');
        }
        $bin = $this->isDirectory ? '/bin/rmdir' : '/bin/rm -f';
        $this->command = sprintf(
            '/usr/bin/sudo -n -u %s %s %s 2>&1',
            escapeshellarg($this->targetUser),
            $bin,
            escapeshellarg($this->path)
        );
        return $this->command;
    }

    public function isSuccessful() : bool
    {
        $output = strtolower((string) $this->getOutput());
        if ('' === trim($output)) {
            return true;
        }
        foreach (['denied', 'no such', 'not empty', 'not a directory', 'directory not empty'] as $needle) {
            if (false !== strpos($output, $needle)) {
                return false;
            }
        }
        return true;
    }
}
