<?php

declare(strict_types=1);

namespace App\Mail;

use App\System\Command\MailcowComposeStatusCommand;
use App\System\Command\MailcowInstallCommand;
use App\System\CommandExecutor;

class MailcowInstaller
{
    public function __construct(private CommandExecutor $commandExecutor)
    {
    }

    public function isInstalled(): bool
    {
        return is_dir('/opt/mailcow-dockerized') && is_file('/opt/mailcow-dockerized/mailcow.conf');
    }

    public function isRunning(): bool
    {
        $command = new MailcowComposeStatusCommand();

        try {
            $this->commandExecutor->execute($command);
        } catch (\Exception $e) {
            return false;
        }

        $output = trim((string) $command->getOutput());

        if ($output === '') {
            return false;
        }

        return ((int) $output) > 5;
    }

    public function install(string $mailHostname): array
    {
        $mailHostname = trim($mailHostname);

        // FQDN validation. Empty string can't match this pattern, so no separate
        // empty pre-check is needed.
        $fqdnPattern = '/^[a-z0-9]([-a-z0-9]{0,253}[a-z0-9])?(\.[a-z0-9]([-a-z0-9]{0,253}[a-z0-9])?)+$/i';
        if (!preg_match($fqdnPattern, $mailHostname)) {
            return [
                'ok' => false,
                'output' => 'Invalid mail hostname. Please provide a fully-qualified domain name (e.g. mail.example.com).',
            ];
        }

        if ($this->isInstalled()) {
            return [
                'ok' => false,
                'output' => 'Mailcow is already installed in /opt/mailcow-dockerized.',
            ];
        }

        $command = new MailcowInstallCommand($mailHostname);

        try {
            // Mailcow install: git clone + docker compose pull + up -d.
            // Bumped well past Symfony Process default (60s) to cover image pulls.
            $this->commandExecutor->execute($command, 1800);
        } catch (\Exception $e) {
            return [
                'ok' => false,
                'output' => trim($e->getMessage() . "\n" . (string) $command->getOutput()),
            ];
        }

        return [
            'ok' => $this->isInstalled(),
            'output' => (string) $command->getOutput(),
        ];
    }
}
