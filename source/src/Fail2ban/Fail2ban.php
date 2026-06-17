<?php

namespace App\Fail2ban;

use App\System\CommandExecutor;
use App\System\Command\Fail2banClientCommand;

class Fail2ban
{
    private CommandExecutor $commandExecutor;

    public function __construct()
    {
        $this->commandExecutor = new CommandExecutor();
    }

    /**
     * @return array{running:bool, jails:string[], raw:string}
     */
    public function getStatus(): array
    {
        $cmd = new Fail2banClientCommand();
        $cmd->setAction('status');
        $this->commandExecutor->execute($cmd);
        $raw = (string) $cmd->getOutput();
        $running = (bool) preg_match('/Number of jail:/i', $raw);
        $jails = [];
        if (preg_match('/Jail list:\s*(.*)/i', $raw, $m)) {
            foreach (preg_split('/[,\s]+/', trim($m[1])) as $j) {
                $j = trim($j);
                if ($j !== '') {
                    $jails[] = $j;
                }
            }
        }
        return ['running' => $running, 'jails' => $jails, 'raw' => $raw];
    }

    /**
     * @return array{name:string, currentlyFailed:?int, totalFailed:?int, currentlyBanned:?int, totalBanned:?int, bannedIps:string[], raw:string}
     */
    public function getJailStatus(string $jail): array
    {
        $cmd = new Fail2banClientCommand();
        $cmd->setAction('status');
        $cmd->setJail($jail);
        $this->commandExecutor->execute($cmd);
        $raw = (string) $cmd->getOutput();
        $data = [
            'name' => $jail,
            'currentlyFailed' => null,
            'totalFailed' => null,
            'currentlyBanned' => null,
            'totalBanned' => null,
            'bannedIps' => [],
            'raw' => $raw,
        ];
        if (preg_match('/Currently failed:\s*(\d+)/i', $raw, $m)) {
            $data['currentlyFailed'] = (int) $m[1];
        }
        if (preg_match('/Total failed:\s*(\d+)/i', $raw, $m)) {
            $data['totalFailed'] = (int) $m[1];
        }
        if (preg_match('/Currently banned:\s*(\d+)/i', $raw, $m)) {
            $data['currentlyBanned'] = (int) $m[1];
        }
        if (preg_match('/Total banned:\s*(\d+)/i', $raw, $m)) {
            $data['totalBanned'] = (int) $m[1];
        }
        if (preg_match('/Banned IP list:\s*(.*)/i', $raw, $m)) {
            foreach (preg_split('/\s+/', trim($m[1])) as $ip) {
                $ip = trim($ip);
                if ($ip !== '') {
                    $data['bannedIps'][] = $ip;
                }
            }
        }
        return $data;
    }

    public function unban(string $jail, string $ip): string
    {
        $cmd = new Fail2banClientCommand();
        $cmd->setAction('unban');
        $cmd->setJail($jail);
        $cmd->setIp($ip);
        $this->commandExecutor->execute($cmd);
        return (string) $cmd->getOutput();
    }
}
