<?php

namespace App\System\Command;

use App\System\Command;

class DuCommand extends Command
{
    private ?string $directory = null;

    public function setDirectory(string $directory) : void
    {
        $this->directory = $directory;
    }

    public function getCommand() : string
    {
        if ($this->command) {
            return $this->command;
        }
        if (null === $this->directory) {
            throw new \RuntimeException('DuCommand: directory must be set.');
        }
        $this->command = sprintf(
            '/usr/bin/sudo /usr/bin/du -sm %s 2>/dev/null',
            escapeshellarg($this->directory)
        );
        return $this->command;
    }

    public function isSuccessful() : bool
    {
        return 1 === preg_match('/^\s*\d+\s/m', (string) $this->getOutput());
    }

    public function getUsedMb() : int
    {
        if (1 === preg_match('/^\s*(\d+)\s/m', (string) $this->getOutput(), $m)) {
            return (int) $m[1];
        }
        return 0;
    }
}
