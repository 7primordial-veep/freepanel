<?php

namespace App\System\Command;

use App\System\Command;
class WriteFileCommand extends Command
{
    private ?string $file = null;
    private ?string $content = null;
    public function getCommand() : string
    {
        if (!$this->command) {
            $file = $this->getFile();
            $content = $this->getContent();
            $this->command = sprintf("echo %s | /usr/bin/sudo /usr/bin/tee %s > /dev/null", escapeshellarg($content), escapeshellarg($file));
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
    public function setContent(string $content) : void
    {
        $this->content = $content;
    }
    public function getContent() : ?string
    {
        return $this->content;
    }
}