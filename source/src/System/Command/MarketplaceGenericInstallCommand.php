<?php

namespace App\System\Command;

use App\System\Command;

/**
 * Downloads a marketplace tarball as the site user, extracts it into the site
 * htdocs directory (stripping the top-level component), then removes the
 * tarball. The entire pipeline runs inside a single non-interactive sudo
 * invocation so the files land owned by the site user (no later chown).
 */
class MarketplaceGenericInstallCommand extends Command
{
    private ?string $targetUser  = null;
    private ?string $tarballUrl  = null;
    private ?string $htdocsDir   = null;

    public function setTargetUser(string $user) : void
    {
        $this->targetUser = $user;
    }

    public function setTarballUrl(string $url) : void
    {
        $this->tarballUrl = $url;
    }

    public function setHtdocsDir(string $dir) : void
    {
        $this->htdocsDir = $dir;
    }

    public function getCommand() : string
    {
        if ($this->command) {
            return $this->command;
        }
        if (null === $this->targetUser || null === $this->tarballUrl || null === $this->htdocsDir) {
            throw new \RuntimeException('MarketplaceGenericInstallCommand: targetUser, tarballUrl and htdocsDir must be set.');
        }
        if (!preg_match('/^[a-z0-9_-]{1,32}$/', $this->targetUser)) {
            throw new \RuntimeException('MarketplaceGenericInstallCommand: invalid targetUser.');
        }
        if (!preg_match('#^https?://#i', $this->tarballUrl)) {
            throw new \RuntimeException('MarketplaceGenericInstallCommand: tarballUrl must be http(s).');
        }

        $tarPath = sprintf('/tmp/clp-mp-%s.tar', $this->targetUser);

        $inner = sprintf(
            '/usr/bin/curl -fsSL %s -o %s && /bin/tar -xf %s --strip-components=1 -C %s && /bin/rm -f %s',
            escapeshellarg($this->tarballUrl),
            escapeshellarg($tarPath),
            escapeshellarg($tarPath),
            escapeshellarg($this->htdocsDir),
            escapeshellarg($tarPath)
        );

        $this->command = sprintf(
            '/usr/bin/sudo -n -u %s /bin/sh -c %s 2>&1',
            escapeshellarg($this->targetUser),
            escapeshellarg($inner)
        );
        return $this->command;
    }

    public function isSuccessful() : bool
    {
        $output = (string) $this->output;
        if ('' === trim($output)) {
            return true;
        }
        $lower = strtolower($output);
        if (false !== strpos($lower, 'error')
            || false !== strpos($lower, 'denied')
            || false !== strpos($lower, 'failed')) {
            return false;
        }
        return true;
    }
}
