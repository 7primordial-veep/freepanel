<?php

namespace App\System\Command;

use App\System\Command;
class ChangeUserHomeDirectoryCommand extends Command
{
    private ?string $userName = null;
    private ?string $homeDirectory = null;
    public function getCommand() : string
    {
        if (!$this->command) {
            $userName = $this->getUserName();
            $homeDirectory = $this->getHomeDirectory();
            $this->command = sprintf("/usr/bin/sudo /usr/sbin/usermod -d %s %s", escapeshellarg($homeDirectory), escapeshellarg($userName));
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        return true;
    }
    public function setUserName(string $userName) : void
    {
        $this->userName = $userName;
    }
    public function getUserName() : ?string
    {
        return $this->userName;
    }
    public function setHomeDirectory(string $homeDirectory) : void
    {
        $this->homeDirectory = $homeDirectory;
    }
    public function getHomeDirectory() : ?string
    {
        return $this->homeDirectory;
    }
}