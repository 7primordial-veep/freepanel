<?php

namespace App\System\Command;

use App\System\Command;
class TailCommand extends Command
{
    private ?string $file = null;
    private int $numberOfLines = 0;
    public function getCommand() : string
    {
        if (!$this->command) {
            $file = $this->getFile();
            $numberOfLines = $this->getNumberOfLines();
            $this->command = sprintf("/usr/bin/sudo /usr/bin/tail %s -n%s", escapeshellarg($file), escapeshellarg($numberOfLines));
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
    public function setNumberOfLines(int $numberOfLines) : void
    {
        $this->numberOfLines = $numberOfLines;
    }
    public function getNumberOfLines() : int
    {
        return $this->numberOfLines;
    }
}