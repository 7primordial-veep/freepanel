<?php

namespace App\Site\ResourceQuota;

use App\System\CommandExecutor;
use App\System\Command\DuCommand;
use Psr\Log\LoggerInterface;

/**
 * Two responsibilities:
 *
 *   isHardQuotaSupported() — true iff /home (or /) is XFS mounted with
 *   prjquota / pquota, the requirement for XfsProjectQuotaApplier.
 *
 *   measure($user) — returns the megabytes currently consumed by
 *   /home/<user>/htdocs via `du -sm`. Called by QuotaEnforceCommand to
 *   detect over-limit sites on whatever filesystem.
 */
class DiskQuotaProbe
{
    private ?CommandExecutor $commandExecutor;
    private ?LoggerInterface $logger;

    public function __construct(?CommandExecutor $commandExecutor = null, ?LoggerInterface $logger = null)
    {
        $this->commandExecutor = $commandExecutor;
        $this->logger = $logger;
    }

    public function isHardQuotaSupported() : bool
    {
        $mounts = @file_get_contents('/proc/mounts');
        if (false === $mounts) {
            return false;
        }
        foreach (explode("\n", $mounts) as $line) {
            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 4) {
                continue;
            }
            [$dev, $mountpoint, $fstype, $opts] = $parts;
            if ('/home' !== $mountpoint && '/' !== $mountpoint) {
                continue;
            }
            if ('xfs' !== $fstype) {
                continue;
            }
            if (false !== strpos($opts, 'prjquota') || false !== strpos($opts, 'pquota')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Disk usage in MB for /home/<user>/htdocs. Returns 0 if the directory
     * does not exist or du fails (the caller decides whether to treat that
     * as zero-used or an error).
     */
    public function measure(string $user) : int
    {
        if ('' === trim($user)) {
            return 0;
        }
        // Defensive: only [a-z0-9_-] is valid for a CloudPanel site user.
        if (1 !== preg_match('/^[a-z][-a-z0-9_]+$/i', $user)) {
            return 0;
        }
        $directory = sprintf('/home/%s/htdocs', $user);
        if (false === is_dir($directory)) {
            return 0;
        }
        if (null === $this->commandExecutor) {
            // No executor wired — fall back to a direct du call but still
            // escape the path.
            $command = sprintf('/usr/bin/du -sm %s 2>/dev/null', escapeshellarg($directory));
            $output = (string) @shell_exec($command);
            if (1 === preg_match('/^\s*(\d+)\s/m', $output, $matches)) {
                return (int) $matches[1];
            }
            return 0;
        }
        $du = new DuCommand();
        $du->setDirectory($directory);
        try {
            $this->commandExecutor->execute($du, 60);
        } catch (\Throwable $e) {
            if (null !== $this->logger) {
                $this->logger->warning('du failed during disk usage probe', [
                    'user' => $user,
                    'error' => $e->getMessage(),
                ]);
            }
            return 0;
        }
        if (false === $du->isSuccessful()) {
            return 0;
        }
        return $du->getUsedMb();
    }
}
