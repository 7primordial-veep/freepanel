<?php

namespace App\System\Command;

use App\System\Command;

/**
 * `sudo -u <user> tee <path>` fed via a here-doc — used to write a file
 * under the site user's identity. The CommandExecutor strips runAsUser
 * support so the command renders the full pipeline itself.
 */
class SudoTeeCommand extends Command
{
    private ?string $targetUser = null;
    private ?string $path = null;
    private ?string $content = null;

    public function setTargetUser(string $user) : void
    {
        $this->targetUser = $user;
    }

    public function setPath(string $path) : void
    {
        $this->path = $path;
    }

    public function setContent(string $content) : void
    {
        $this->content = $content;
    }

    public function getCommand() : string
    {
        if ($this->command) {
            return $this->command;
        }
        if (null === $this->targetUser || null === $this->path || null === $this->content) {
            throw new \RuntimeException('SudoTeeCommand: targetUser, path and content must be set.');
        }
        $this->command = sprintf(
            '/usr/bin/sudo -n -u %s /bin/sh -c %s',
            escapeshellarg($this->targetUser),
            escapeshellarg(sprintf('printf %%s %s > %s', escapeshellarg($this->content), escapeshellarg($this->path)))
        );
        return $this->command;
    }

    public function isSuccessful() : bool
    {
        $output = trim((string) $this->getOutput());
        if ('' === $output) {
            return true;
        }
        if (false !== stripos($output, 'denied') || false !== stripos($output, 'no such') || false !== stripos($output, 'error')) {
            return false;
        }
        return true;
    }
}
