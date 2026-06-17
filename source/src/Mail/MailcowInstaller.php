<?php

declare(strict_types=1);

namespace App\Mail;

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
        $output = shell_exec('docker compose -f /opt/mailcow-dockerized/docker-compose.yml ps --status running --quiet 2>/dev/null | wc -l');

        if ($output === null) {
            return false;
        }

        return ((int) trim($output)) > 5;
    }

    public function install(string $mailHostname): array
    {
        $mailHostname = trim($mailHostname);

        if ($mailHostname === '') {
            return [
                'ok' => false,
                'output' => 'Mail hostname is required.',
            ];
        }

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

        $script = sprintf(
            'git clone https://github.com/mailcow/mailcow-dockerized /opt/mailcow-dockerized && '
            . 'cd /opt/mailcow-dockerized && '
            . 'MAILCOW_HOSTNAME=%s ./generate_config.sh && '
            . 'docker compose pull && '
            . 'docker compose up -d 2>&1',
            escapeshellarg($mailHostname)
        );

        $command = 'sudo /bin/bash -c ' . escapeshellarg($script);

        $output = shell_exec($command);

        if ($output === null) {
            return [
                'ok' => false,
                'output' => 'Failed to execute mailcow installer (no output returned).',
            ];
        }

        $ok = $this->isInstalled();

        return [
            'ok' => $ok,
            'output' => (string) $output,
        ];
    }
}
