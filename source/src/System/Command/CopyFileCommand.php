<?php

namespace App\System\Command;

use App\System\Command;
class CopyFileCommand extends Command
{
    private ?string $sourceFile = null;
    private ?string $destinationFile = null;
    private bool $recursive = false;
    public function getCommand() : string
    {
        if (!$this->command) {
            $sourceFile = $this->getSourceFile();
            $destinationFile = $this->getDestinationFile();
            $recursive = $this->getRecursive();
            $this->command = sprintf("/usr/bin/sudo /bin/cp %s %s %s", true === $recursive ? "-a" : '', escapeshellarg($sourceFile), escapeshellarg($destinationFile));
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        $output = $this->getOutput();
        $isSuccessful = empty($output);
        return $isSuccessful;
    }
    public function setSourceFile(string $sourceFile) : void
    {
        $this->sourceFile = $sourceFile;
    }
    public function getSourceFile() : ?string
    {
        return $this->sourceFile;
    }
    public function setDestinationFile(string $destinationFile) : void
    {
        $this->destinationFile = $destinationFile;
    }
    public function getDestinationFile() : ?string
    {
        return $this->destinationFile;
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