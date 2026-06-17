<?php

namespace App\System\Command;

use App\System\Command;
class CheckIfFileExistsCommand extends Command
{
    private ?string $file = null;
    public function getCommand() : string
    {
        if (!$this->command) {
            $file = $this->getFile();
            $this->command = sprintf("/usr/bin/sudo /usr/bin/test -e %s && echo 1 || echo 0", escapeshellarg($file));
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        $output = $this->getOutput();
        $isSuccessful = "1" == $output ? true : false;
        return $isSuccessful;
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