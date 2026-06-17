<?php

namespace App\System\Command;

use App\System\Command;
class SedCommand extends Command
{
    private ?string $file = null;
    private ?string $pattern = null;
    public function getCommand() : string
    {
        if (!$this->command) {
            $file = $this->getFile();
            $pattern = $this->getPattern();
            $this->command = sprintf("/usr/bin/sudo /bin/sed -i %s %s", escapeshellarg($pattern), escapeshellarg($file));
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
    public function getFile() : string
    {
        return $this->file;
    }
    public function setPattern(string $pattern) : void
    {
        $this->pattern = $pattern;
    }
    public function getPattern() : string
    {
        return $this->pattern;
    }
}