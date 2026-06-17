<?php

namespace App\Ufw\Command;

use App\System\Command;
class Reset extends Command
{
    public function getCommand() : string
    {
        if (true === is_null($this->command)) {
            $this->command = "/usr/bin/sudo /usr/sbin/ufw --force reset";
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