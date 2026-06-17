<?php

namespace App\System\Command;

use App\System\Command;
class DownloadFileCommand extends Command
{
    private ?string $file = null;
    private ?string $outputFile = null;
    public function getCommand() : string
    {
        if (!$this->command) {
            $file = $this->getFile();
            $outputFile = $this->getOutputFile();
            $this->command = sprintf("/usr/bin/sudo /usr/bin/curl -kLs %s --output %s", escapeshellarg($file), escapeshellarg($outputFile));
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
    public function setOutputFile(string $outputFile) : void
    {
        $this->outputFile = $outputFile;
    }
    public function getOutputFile() : ?string
    {
        return $this->outputFile;
    }
}