<?php

namespace App\System\Command;

use App\System\Command;
class DeleteFileCommand extends Command
{
    private ?string $file = null;
    public function getCommand() : string
    {
        if (!$this->command) {
            $file = $this->getFile();
            $this->command = sprintf("/usr/bin/sudo /bin/bash -c \"/bin/rm -f %s\"", escapeshellarg($file));
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        $output = $this->getOutput();
        $isSuccessful = empty($output);
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