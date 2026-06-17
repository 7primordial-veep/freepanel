<?php

namespace App\Marketplace;

use App\System\CommandExecutor;
use App\System\Command\MarketplaceGenericInstallCommand;

/**
 * Drives MarketplaceGenericInstallCommand: downloads + extracts a generic PHP
 * marketplace tarball into the site user's htdocs directory under that user's
 * own UID (via sudo -u). Returns a simple success/output array so callers
 * don't have to catch exceptions just to render a flash message.
 */
class GenericPhpInstaller
{
    private CommandExecutor $commandExecutor;

    public function __construct(CommandExecutor $commandExecutor)
    {
        $this->commandExecutor = $commandExecutor;
    }

    /**
     * @return array{ok: bool, output: string}
     */
    public function install(string $siteUser, string $tarballUrl) : array
    {
        $htdocs = sprintf('/home/%s/htdocs', $siteUser);

        $command = new MarketplaceGenericInstallCommand();
        $command->setTargetUser($siteUser);
        $command->setTarballUrl($tarballUrl);
        $command->setHtdocsDir($htdocs);

        try {
            $this->commandExecutor->execute($command, 600);
        } catch (\Throwable $e) {
            return [
                'ok'     => false,
                'output' => $e->getMessage(),
            ];
        }

        return [
            'ok'     => $command->isSuccessful(),
            'output' => (string) $command->getOutput(),
        ];
    }
}
