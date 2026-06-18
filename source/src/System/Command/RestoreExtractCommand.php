<?php

namespace App\System\Command;

use App\System\Command;

class RestoreExtractCommand extends Command
{
    private ?string $targetUser = null;
    private ?string $archive = null;

    public function setTargetUser(string $u): void
    {
        $this->targetUser = $u;
    }

    public function setArchive(string $p): void
    {
        $this->archive = $p;
    }

    public function getCommand(): string
    {
        if (null === $this->targetUser || null === $this->archive) {
            throw new \RuntimeException('targetUser + archive required');
        }
        $home = '/home/' . $this->targetUser . '/';
        // tar -xf auto-detects gzip vs plain tar.
        return sprintf(
            '/usr/bin/sudo -n -u %s /bin/tar -xf %s -C %s 2>&1',
            escapeshellarg($this->targetUser),
            escapeshellarg($this->archive),
            escapeshellarg($home)
        );
    }

    public function isSuccessful(): bool
    {
        // Rely on exit code via CommandExecutor (throws on non-zero).
        // tar prints benign warnings to stderr/stdout ("Removing leading '/'",
        // "Cannot stat" for excluded paths) that must not be treated as errors.
        return true;
    }
}
