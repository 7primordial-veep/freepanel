<?php

namespace App\Ufw\Command;

use App\System\Command;
use App\Ufw\Firewall\AllowTcpRule as TcpRule;
class AllowTcpRule extends Command
{
    private TcpRule $tcpRule;
    private bool $dryRun = false;
    public function __construct(TcpRule $tcpRule)
    {
        $this->tcpRule = $tcpRule;
    }
    public function getCommand() : string
    {
        if (true === is_null($this->command)) {
            $ip = $this->tcpRule->getIp();
            $portRange = $this->tcpRule->getPortRange();
            $dryRun = $this->getDryRun();
            $this->command = sprintf("/usr/bin/sudo /usr/sbin/ufw %s allow proto tcp from %s to any port %s", true === $dryRun ? "--dry-run" : '', escapeshellarg($ip), escapeshellarg($portRange));
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