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

        // Follows the official CloudPanel mailcow guide
        // (https://www.cloudpanel.io/docs/v2/guides/applications/mailcow/):
        //   1. systemctl stop+disable postfix — frees port 25 (CloudPanel's
        //      Debian/Ubuntu base ships with postfix listening on 25, which
        //      otherwise collides with mailcow-postfix).
        //   2. git clone + ./generate_config.sh (MAILCOW_HOSTNAME env skips
        //      the interactive FQDN prompt).
        //   3. rebind mailcow.conf to 127.0.0.1:7080 / 127.0.0.1:7443 so
        //      mailcow doesn't fight CloudPanel's nginx on 80/443. Admin
        //      then creates a Reverse Proxy site in CloudPanel pointing at
        //      https://127.0.0.1:7443.
        //   4. docker compose pull + up -d.
        $script = sprintf(
            'set -e; '
            . 'systemctl stop postfix 2>/dev/null || true; '
            . 'systemctl disable postfix 2>/dev/null || true; '
            . 'git clone https://github.com/mailcow/mailcow-dockerized /opt/mailcow-dockerized; '
            . 'cd /opt/mailcow-dockerized; '
            . 'MAILCOW_HOSTNAME=%s ./generate_config.sh; '
            . 'sed -i '
            . '-e \'s/^HTTP_PORT=.*/HTTP_PORT=7080/\' '
            . '-e \'s/^HTTP_BIND=.*/HTTP_BIND=127.0.0.1/\' '
            . '-e \'s/^HTTPS_PORT=.*/HTTPS_PORT=7443/\' '
            . '-e \'s/^HTTPS_BIND=.*/HTTPS_BIND=127.0.0.1/\' '
            . 'mailcow.conf; '
            . 'docker compose pull; '
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
