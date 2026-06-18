<?php

declare(strict_types=1);

namespace App\System\Command;

use App\System\Command;

/**
 * Installs mailcow-dockerized following the official CloudPanel guide
 * (https://www.cloudpanel.io/docs/v2/guides/applications/mailcow/):
 *
 *   1. systemctl stop+disable postfix — frees port 25 (CloudPanel's
 *      Debian/Ubuntu base ships with postfix listening on 25, which would
 *      otherwise collide with mailcow-postfix).
 *   2. git clone (pinned tag, shallow) + ./generate_config.sh
 *      (MAILCOW_HOSTNAME env skips the interactive FQDN prompt).
 *   3. Rebind mailcow.conf to 127.0.0.1:7080 / 127.0.0.1:7443 so mailcow
 *      doesn't fight CloudPanel's nginx on 80/443. Admin then creates a
 *      Reverse Proxy site in CloudPanel pointing at https://127.0.0.1:7443.
 *   4. docker compose pull + up -d.
 *
 * The git clone is pinned to a known mailcow release tag with --depth 1
 * (supply-chain hardening: no floating master, no full history).
 */
class MailcowInstallCommand extends Command
{
    /**
     * Pinned mailcow-dockerized release tag. Bump this when validating a
     * newer mailcow release against CloudPanel.
     */
    private const MAILCOW_RELEASE_TAG = '2024-04';

    public function __construct(private string $mailHostname)
    {
    }

    public function getCommand(): string
    {
        if ($this->command) {
            return $this->command;
        }

        $script = sprintf(
            'set -e; '
            . 'systemctl stop postfix 2>/dev/null || true; '
            . 'systemctl disable postfix 2>/dev/null || true; '
            . 'git clone --branch %s --depth 1 https://github.com/mailcow/mailcow-dockerized /opt/mailcow-dockerized; '
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
            escapeshellarg(self::MAILCOW_RELEASE_TAG),
            escapeshellarg($this->mailHostname)
        );

        $this->command = '/usr/bin/sudo /bin/bash -c ' . escapeshellarg($script);

        return $this->command;
    }

    public function isSuccessful(): bool
    {
        return true;
    }
}
