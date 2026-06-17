<?php

namespace App\System\Command;

use App\System\Command;
class CreateDirectoryCommand extends Command
{
    private ?string $directory = null;
    public function getCommand() : string
    {
        if (!$this->command) {
            $directory = $this->getDirectory();
            $this->command = sprintf("/usr/bin/sudo /bin/mkdir -p %s", escapeshellarg($directory));
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        $output = $this->getOutput();
        $isSuccessful = empty($output);
        return $isSuccessful;
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