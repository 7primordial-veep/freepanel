<?php

namespace App\System\Command;

use App\System\Command;
class DeleteOldFilesRecursiveCommand extends Command
{
    private ?string $directory = null;
    private int $retentionPeriod = 0;
    public function getCommand() : string
    {
        if (!$this->command) {
            $directory = $this->getDirectory();
            $retentionPeriod = $this->getRetentionPeriod();
            $this->command = sprintf("/usr/bin/sudo /usr/bin/find %s -mindepth 1 -type d -mtime +%s -exec rm -rf {} \\; > /dev/null 2>&1", rtrim($directory, "/"), $retentionPeriod);
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        return true;
    }
    public function setDirectory(string $directory) : void
    {
        $this->directory = $directory;
    }
    public function getDirectory() : ?string
    {
        return $this->directory;
    }
    public function getRetentionPeriod() : int
    {
        return $this->retentionPeriod;
    }
    public function setRetentionPeriod(int $retentionPeriod) : void
    {
        $this->retentionPeriod = $retentionPeriod;
    }
}