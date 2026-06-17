<?php

namespace App\System\Command;

use App\System\Command;
class GunzipCommand extends Command
{
    private ?string $file;
    public function getCommand() : string
    {
        if (!$this->command) {
            $file = $this->getFile();
            $this->command = sprintf("/usr/bin/sudo /bin/gunzip %s", escapeshellarg($file));
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