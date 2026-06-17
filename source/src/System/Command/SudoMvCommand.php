<?php

namespace App\System\Command;

use App\System\Command;

/**
 * `sudo -n -u <user> mv <from> <to>` — move/rename a path as the site's system user.
 */
class SudoMvCommand extends Command
{
    private ?string $targetUser = null;
    private ?string $fromPath = null;
    private ?string $toPath = null;

    public function setTargetUser(string $user) : void
    {
        $this->targetUser = $user;
    }

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
        if (null === $this->targetUser || null === $this->fromPath || null === $this->toPath) {
            throw new \RuntimeException('SudoMvCommand: targetUser, fromPath and toPath must be set.');
        }
        $this->command = sprintf(
            '/usr/bin/sudo -n -u %s /bin/mv %s %s 2>&1',
            escapeshellarg($this->targetUser),
            escapeshellarg($this->fromPath),
            escapeshellarg($this->toPath)
        );
        return $this->command;
    }

    public function isSuccessful() : bool
    {
        $output = strtolower((string) $this->getOutput());
        if ('' === trim($output)) {
            return true;
        }
        foreach (['denied', 'no such', 'error'] as $needle) {
            if (false !== strpos($output, $needle)) {
                return false;
            }
        }
        return true;
    }
}
