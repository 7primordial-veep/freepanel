<?php

namespace App\System\Command;

use App\System\Command;
class KillUserProcessesCommand extends Command
{
    private ?string $userName = null;
    public function getCommand() : string
    {
        if (!$this->command) {
            $userName = $this->getUserName();
            $this->command = sprintf("/usr/bin/sudo /usr/bin/pkill -9 -u %s", escapeshellarg($userName));
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        $output = $this->getOutput();
        $isSuccessful = empty($output);
        return $isSuccessful;
    }
    public function setUserName($userName) : void
    {
        $this->userName = $userName;
    }
    public function getUserName() : ?string
    {
        return $this->userName;
    }
}