<?php

namespace App\Ufw\Command;

use App\System\Command;
use App\Ufw\Firewall\AllowUdpRule as UdpRule;
class AllowUdpRule extends Command
{
    private UdpRule $udpRule;
    private bool $dryRun = false;
    public function __construct(UdpRule $udpRule)
    {
        $this->udpRule = $udpRule;
    }
    public function getCommand() : string
    {
        if (true === is_null($this->command)) {
            $ip = $this->udpRule->getIp();
            $portRange = $this->udpRule->getPortRange();
            $dryRun = $this->getDryRun();
            $this->command = sprintf("/usr/bin/sudo /usr/sbin/ufw %s allow proto udp from %s to any port %s", true === $dryRun ? "--dry-run" : '', $ip, $portRange);
        }
        return $this->command;
    }
    public function setDryRun($flag) : void
    {
        $this->dryRun = (bool) $flag;
    }
    public function getDryRun() : bool
    {
        return $this->dryRun;
    }
    public function isSuccessful() : bool
    {
        $output = $this->getOutput();
        $isSuccessful = false === str_contains($output, "ERROR") ? true : false;
        return $isSuccessful;
    }
}