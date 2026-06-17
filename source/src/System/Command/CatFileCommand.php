<?php

namespace App\System\Command;

use App\System\Command;
class CatFileCommand extends Command
{
    private ?string $file = null;
    public function getCommand() : string
    {
        if (!$this->command) {
            $file = $this->getFile();
            $this->command = sprintf("/usr/bin/sudo /bin/cat %s", escapeshellarg($file));
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        return true;
    }
    public function setFile(string $file) : void
    {
        $this->file = $file;
    }
    public function getFile() : ?string
    {
        return $this->file;
    }
}