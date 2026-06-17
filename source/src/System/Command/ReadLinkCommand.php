<?php

namespace App\System\Command;

use App\System\Command;
class ReadLinkCommand extends Command
{
    private ?string $file = null;
    public function getCommand() : string
    {
        if (!$this->command) {
            $file = $this->getFile();
            $this->command = sprintf("/usr/bin/sudo /usr/bin/readlink -f %s", escapeshellarg($file));
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