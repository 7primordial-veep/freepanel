<?php

namespace App\System\Command;

use App\System\Command;
class ServiceRestartCommand extends Command
{
    private ?string $serviceName = null;
    public function getCommand() : string
    {
        if (!$this->command) {
            $serviceName = $this->getServiceName();
            $this->command = sprintf("/usr/bin/sudo /bin/systemctl restart %s &", escapeshellarg($serviceName));
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        return true;
    }
    public function setServiceName(string $serviceName) : void
    {
        $this->serviceName = $serviceName;
    }
    public function getServiceName() : ?string
    {
        return $this->serviceName;
    }
}