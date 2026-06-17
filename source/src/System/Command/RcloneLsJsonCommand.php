<?php

namespace App\System\Command;

use App\System\Command;
class RcloneLsJsonCommand extends Command
{
    private ?string $remotePath = null;
    private array $flags = [["flag" => "--log-level", "value" => "ERROR"]];
    public function getCommand() : string
    {
        if (!$this->command) {
            $remotePath = $this->getRemotePath();
            if (true === is_null($remotePath)) {
                $remotePath = '';
            }
            $renderedFlags = $this->getRenderedFlags();
            $this->command = sprintf("/usr/bin/sudo /usr/bin/rclone lsjson remote:%s %s", escapeshellarg($remotePath), $renderedFlags);
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        return true;
    }
    public function setConfigFile(string $configFile) : void
    {
        $this->addFlag("--config", $configFile);
    }
    public function setGoogleDriveEmail(string $email) : void
    {
        $this->addFlag("--drive-impersonate", $email);
    }
    public function getFiles() : array
    {
        $files = [];
        $output = trim($this->getOutput());
        if (false === empty($output)) {
            $files = (array) json_decode($output, true);
        }
        return $files;
    }
    public function setRemotePath(?string $remotePath) : void
    {
        $this->remotePath = $remotePath;
    }
    public function getRemotePath() : ?string
    {
        return $this->remotePath;
    }
    public function setDirectoriesOnly(bool $flag)
    {
        $this->addFlag("--dirs-only", "true");
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