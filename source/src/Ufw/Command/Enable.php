<?php

namespace App\Ufw\Command;

use App\System\Command;
class Enable extends Command
{
    public function getCommand() : string
    {
        if (true === is_null($this->command)) {
            $this->command = "/usr/bin/sudo /usr/sbin/ufw --force enable";
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        $output = $this->getOutput();
        $isSuccessful = false === str_contains($output, "ERROR") ? true : false;
        return $isSuccessful;
    }
}