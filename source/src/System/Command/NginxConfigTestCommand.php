<?php

namespace App\System\Command;

use App\System\Command;
class NginxConfigTestCommand extends Command
{
    public function getCommand() : string
    {
        if (!$this->command) {
            $this->command = "/usr/bin/sudo /usr/sbin/nginx -t";
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        $output = $this->getOutput();
        return strpos($output, "failed") ? false : true;
    }
}