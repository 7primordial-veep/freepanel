<?php

namespace App\System\Command;

use App\System\Command;
class CheckIfPortIsInUseCommand extends Command
{
    private ?string $port = null;
    public function getCommand() : string
    {
        if (!$this->command) {
            $port = $this->getPort();
            $this->command = sprintf("/usr/bin/sudo /bin/netstat -tulpn | /bin/grep -w %s || true", escapeshellarg($port));
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        return true;
    }
    public function isPortInUse()
    {
        $output = $this->getOutput();
        $isPortInUse = false === empty($output) ? true : false;
        return $isPortInUse;
    }
    public function setPort(string $port) : void
    {
        $this->port = $port;
    }
    public function getPort() : ?string
    {
        return $this->port;
    }
}