<?php

namespace App\System\Command;

use App\System\Command;
class ChmodCommand extends Command
{
    private ?string $chmod = null;
    private ?string $file = null;
    private bool $recursive = false;
    public function getCommand() : string
    {
        if (!$this->command) {
            $chmod = $this->getChmod();
            $file = $this->getFile();
            $recursive = $this->getRecursive();
            $this->command = sprintf("/usr/bin/sudo /bin/chmod %s %s %s", true === $recursive ? "-R" : '', $chmod, escapeshellarg($file));
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        $output = $this->getOutput();
        $isSuccessful = empty($output);
        return $isSuccessful;
    }
    public function setChmod(string $chmod) : void
    {
        $this->chmod = $chmod;
    }
    public function getChmod() : ?string
    {
        return $this->chmod;
    }
    public function setFile(string $file) : void
    {
        $this->file = $file;
    }
    public function getFile() : ?string
    {
        return $this->file;
    }
    public function setRecursive(bool $flag) : void
    {
        $this->recursive = $flag;
    }
    public function getRecursive() : bool
    {
        return $this->recursive;
    }
}