<?php

namespace App\System\Command;

use App\System\Command;
class DeleteUserCommand extends Command
{
    private ?string $userName = null;
    private bool $removeHomeDirectory = true;
    protected bool $runInBackground = true;
    public function getCommand() : string
    {
        if (!$this->command) {
            $userName = $this->getUserName();
            $removeHomeDirectory = $this->getRemoveHomeDirectory();
            if (true === $removeHomeDirectory) {
                $this->command = sprintf("/usr/bin/sudo /usr/sbin/userdel -rf %s", escapeshellarg($userName));
            } else {
                $this->command = sprintf("/usr/bin/sudo /usr/sbin/userdel -f %s", escapeshellarg($userName));
            }
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
    public function setRemoveHomeDirectory(bool $flag) : void
    {
        $this->removeHomeDirectory = $flag;
    }
    public function getRemoveHomeDirectory() : bool
    {
        return $this->removeHomeDirectory;
    }
}