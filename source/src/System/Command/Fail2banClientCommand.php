<?php

namespace App\System\Command;

use App\System\Command;

class Fail2banClientCommand extends Command
{
    private string $action = 'status';
    private ?string $jail = null;
    private ?string $ip = null;

    public function setAction(string $action): void
    {
        $this->action = $action;
    }

    public function setJail(?string $jail): void
    {
        $this->jail = $jail;
    }

    public function setIp(?string $ip): void
    {
        $this->ip = $ip;
    }

    public function getCommand(): string
    {
        if (!$this->command) {
            switch ($this->action) {
                case 'status':
                    if ($this->jail !== null) {
                        $this->command = sprintf('/usr/bin/sudo /usr/bin/fail2ban-client status %s 2>&1', escapeshellarg($this->jail));
                    } else {
                        $this->command = '/usr/bin/sudo /usr/bin/fail2ban-client status 2>&1';
                    }
                    break;
                case 'unban':
                    $this->command = sprintf(
                        '/usr/bin/sudo /usr/bin/fail2ban-client set %s unbanip %s 2>&1',
                        escapeshellarg((string) $this->jail),
                        escapeshellarg((string) $this->ip)
                    );
                    break;
                case 'ping':
                    $this->command = '/usr/bin/sudo /usr/bin/fail2ban-client ping 2>&1';
                    break;
                default:
                    $this->command = '/usr/bin/sudo /usr/bin/fail2ban-client status 2>&1';
            }
        }
        return $this->command;
    }

    public function isSuccessful(): bool
    {
        // fail2ban-client returns non-zero when the service is down; we still want to render the page.
        return true;
    }
}
