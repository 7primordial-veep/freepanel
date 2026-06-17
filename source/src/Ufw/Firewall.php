<?php

namespace App\Ufw;

use App\Ufw\Firewall\AllowTcpRule;
use App\Ufw\Firewall\AllowUdpRule;
use App\Ufw\Command\AllowTcpRule as AllowTcpRuleCommand;
use App\Ufw\Command\AllowUdpRule as AllowUdpRuleCommand;
use App\Ufw\Command\Enable as EnableCommand;
use App\Ufw\Command\Disable as DisableCommand;
use App\Ufw\Command\Reset as ResetCommand;
use App\System\CommandExecutor;
class Firewall
{
    private CommandExecutor $commandExecutor;
    public function __construct()
    {
        $this->commandExecutor = new CommandExecutor();
    }
    public function allowTcpRule($ip, $portRange, $dryRun = false) : void
    {
        $allowTcpRule = new AllowTcpRule();
        $allowTcpRule->setIp($ip);
        $allowTcpRule->setPortRange($portRange);
        $allowTcpRuleCommand = new AllowTcpRuleCommand($allowTcpRule);
        $allowTcpRuleCommand->setDryRun($dryRun);
        $this->commandExecutor->execute($allowTcpRuleCommand);
    }
    public function allowUdpRule($ip, $portRange, $dryRun = false) : void
    {
        $allowUdpRule = new AllowUdpRule();
        $allowUdpRule->setIp($ip);
        $allowUdpRule->setPortRange($portRange);
        $allowUdpRuleCommand = new AllowUdpRuleCommand($allowUdpRule);
        $allowUdpRuleCommand->setDryRun($dryRun);
        $this->commandExecutor->execute($allowUdpRuleCommand);
    }
    public function enable() : void
    {
        $enableCommand = new EnableCommand();
        $this->commandExecutor->execute($enableCommand);
    }
    public function disable() : void
    {
        $disableCommand = new DisableCommand();
        $this->commandExecutor->execute($disableCommand);
    }
    public function reset() : void
    {
        $resetCommand = new ResetCommand();
        $this->commandExecutor->execute($resetCommand);
    }
}