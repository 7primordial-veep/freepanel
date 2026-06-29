<?php

namespace App\System\Command;

/**
 * `sudo -u <user> sh -c 'printf %s <content> > <path>'` — used to write a file
 * under the site user's identity. The CommandExecutor strips runAsUser
 * support so the command renders the full pipeline itself.
 */
class SudoTeeCommand extends AbstractSudoCommand
{
    private ?string $path = null;
    private ?string $content = null;

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
        if (null === $this->path || null === $this->content) {
            throw new \RuntimeException('SudoTeeCommand: path and content must be set.');
        }
        $this->command = sprintf(
            '%s /bin/sh -c %s',
            $this->sudoPrefix(),
            escapeshellarg(sprintf('printf %%s %s > %s', escapeshellarg($this->content), escapeshellarg($this->path)))
        );
        return $this->command;
    }
}
