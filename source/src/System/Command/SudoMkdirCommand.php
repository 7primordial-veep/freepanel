<?php

namespace App\System\Command;

use App\System\Command;

/**
 * `sudo -n -u <user> mkdir -p <path>` — create a directory as the site's system user.
 */
class SudoMkdirCommand extends Command
{
    private ?string $targetUser = null;
    private ?string $path = null;

    public function setTargetUser(string $user) : void
    {
        $this->targetUser = $user;
    }

    public function setPath(string $path) : void
    {
        $this->path = $path;
    }

    public function getCommand() : string
    {
        if ($this->command) {
            return $this->command;
        }
        if (null === $this->targetUser || null === $this->path) {
            throw new \RuntimeException('SudoMkdirCommand: targetUser and path must be set.');
        }
        $this->command = sprintf(
            '/usr/bin/sudo -n -u %s /bin/mkdir -p %s 2>&1',
            escapeshellarg($this->targetUser),
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
        foreach (['denied', 'error'] as $needle) {
            if (false !== strpos($output, $needle)) {
                return false;
            }
        }
        return true;
    }
}
