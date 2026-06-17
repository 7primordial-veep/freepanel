<?php

namespace App\System\Command;

use App\System\Command;
class FindChmodSecureCommand extends Command
{
    private ?string $fileChmod = null;
    private ?string $directoryChmod = null;
    private ?string $file = null;
    public function getCommand() : string
    {
        if (!$this->command) {
            $fileChmod = $this->getFileChmod();
            $directoryChmod = $this->getDirectoryChmod();
            $file = $this->getFile();
            $this->command = sprintf("/usr/bin/sudo /usr/bin/find -P %s -type d -exec /usr/bin/sh -c \"find \"\"\\\"\"\\\$@\"\\\"\"\" -type d -exec chmod %s \\\"\\\$1\\\" \\;\" _ {} \\; && /usr/bin/sudo /usr/bin/find -P %s -type f -exec /usr/bin/sh -c \"/usr/bin/find \"\"\\\"\"\\\$@\"\\\"\"\" -type f -exec chmod %s \\\"\\\$1\\\" \\;\" _ {} \\;", escapeshellarg($file), escapeshellarg($directoryChmod), escapeshellarg($file), escapeshellarg($fileChmod));
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        $output = $this->getOutput();
        $isSuccessful = empty($output);
        return $isSuccessful;
    }
    public function setFileChmod(string $fileChmod) : void
    {
        $this->fileChmod = $fileChmod;
    }
    public function getFileChmod() : ?string
    {
        return $this->fileChmod;
    }
    public function setDirectoryChmod(string $directoryChmod) : void
    {
        $this->directoryChmod = $directoryChmod;
    }
    public function getDirectoryChmod() : ?string
    {
        return $this->directoryChmod;
    }
    public function setFile(string $file) : void
    {
        $this->file = $file;
    }
    public function getFile() : string
    {
        return $this->file;
    }
}