<?php

namespace App\System\Command;

use App\System\Command;
class LsCommand extends Command
{
    protected ?string $directory = null;
    public function getCommand() : string
    {
        if (!$this->command) {
            $directory = $this->getDirectory();
            $this->command = sprintf("/usr/bin/sudo /bin/ls %s", escapeshellarg($directory));
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        return true;
    }
    public function setDirectory(string $directory) : void
    {
        $this->directory = $directory;
    }
    public function getDirectory() : ?string
    {
        return $this->directory;
    }
}