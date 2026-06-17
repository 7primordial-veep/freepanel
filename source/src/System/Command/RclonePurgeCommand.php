<?php

namespace App\System\Command;

use App\System\Command;
class RclonePurgeCommand extends Command
{
    private ?string $remotePath = null;
    private array $flags = [];
    public function getCommand() : string
    {
        if (!$this->command) {
            $remotePath = $this->getRemotePath();
            $renderedFlags = $this->getRenderedFlags();
            $this->command = trim(sprintf("/usr/bin/sudo /usr/bin/rclone purge remote:%s %s", escapeshellarg($remotePath), $renderedFlags));
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        return true;
    }
    public function setRemotePath(?string $remotePath) : void
    {
        $this->remotePath = $remotePath;
    }
    public function getRemotePath() : ?string
    {
        return $this->remotePath;
    }
    public function setGoogleDriveEmail(string $email) : void
    {
        $this->addFlag("--drive-impersonate", $email);
    }
    public function addFlag(string $flag, string $value)
    {
        $this->flags[] = ["flag" => $flag, "value" => $value];
    }
    public function getFlags() : array
    {
        return $this->flags;
    }
    private function getRenderedFlags() : string
    {
        $renderedFlags = [];
        $flags = $this->getFlags();
        foreach ($flags as $flag) {
            $renderedFlags[] = sprintf("%s=%s", $flag["flag"], escapeshellarg($flag["value"]));
        }
        $renderedFlags = implode(" ", $renderedFlags);
        return $renderedFlags;
    }
}