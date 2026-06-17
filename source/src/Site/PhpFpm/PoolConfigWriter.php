<?php

namespace App\Site\PhpFpm;

use App\System\CommandExecutor;
use App\System\Command\WritePhpFpmPoolDirectiveCommand;
use App\System\Command\ServiceRestartCommand;

class PoolConfigWriter
{
    public function __construct(private CommandExecutor $exec)
    {
    }

    /**
     * @param array{pm: string, pmMaxChildren: int, pmStartServers: int, pmMinSpareServers: int, pmMaxSpareServers: int, pmMaxRequests: int} $settings
     */
    public function apply(string $siteUser, string $phpVersion, array $settings) : void
    {
        if (!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $siteUser)) {
            throw new \RuntimeException('invalid siteUser');
        }
        if (!preg_match('/^\d+\.\d+$/', $phpVersion)) {
            throw new \RuntimeException('invalid phpVersion');
        }

        $poolPath = sprintf('/etc/php/%s/fpm/pool.d/%s.conf', $phpVersion, $siteUser);

        $directives = [
            'pm'                   => (string) $settings['pm'],
            'pm.max_children'      => (string) (int) $settings['pmMaxChildren'],
            'pm.start_servers'     => (string) (int) $settings['pmStartServers'],
            'pm.min_spare_servers' => (string) (int) $settings['pmMinSpareServers'],
            'pm.max_spare_servers' => (string) (int) $settings['pmMaxSpareServers'],
            'pm.max_requests'      => (string) (int) $settings['pmMaxRequests'],
        ];

        foreach ($directives as $key => $value) {
            $cmd = new WritePhpFpmPoolDirectiveCommand();
            $cmd->setPath($poolPath);
            $cmd->setDirective((string) $key);
            $cmd->setValue((string) $value);
            $this->exec->execute($cmd, 30);
        }

        $restart = new ServiceRestartCommand();
        $restart->setServiceName('php' . $phpVersion . '-fpm');
        $this->exec->execute($restart, 60);
    }
}
