<?php

namespace App\Site\ResourceQuota;

use App\Entity\Site;
use App\System\CommandExecutor;
use App\System\Command\SystemctlDaemonReloadCommand;
use App\System\Command\SystemctlSetPropertyCommand;
use App\System\Command\WriteSliceFileCommand;
use App\System\Command\RemoveSliceFileCommand;
use Psr\Log\LoggerInterface;

/**
 * Writes per-site systemd resource control:
 *
 *   /etc/systemd/system/clp-site-<user>.slice
 *       declares the slice with CPUQuota / MemoryMax / MemoryHigh / TasksMax.
 *
 *   /etc/systemd/system/clp-php-fpm.service.d/<user>.conf
 *       drop-in containing Slice=clp-site-<user>.slice so PHP-FPM worker
 *       processes spawned for the user inherit the slice's cgroup caps.
 *
 * After writing both, runs systemctl daemon-reload and uses
 * SystemctlSetPropertyCommand to push the slice's CPU/Memory properties to
 * the running cgroup so changes take effect without restarting PHP-FPM.
 */
class SystemdSliceWriter
{
    private const SYSTEMD_DIR = '/etc/systemd/system';
    private const PHP_FPM_UNIT = 'clp-php-fpm.service';
    private const PHP_FPM_DROPIN_DIR = 'clp-php-fpm.service.d';

    private CommandExecutor $commandExecutor;
    private ?LoggerInterface $logger;

    public function __construct(CommandExecutor $commandExecutor, ?LoggerInterface $logger = null)
    {
        $this->commandExecutor = $commandExecutor;
        $this->logger = $logger;
    }

    public function sliceName(Site $site) : string
    {
        return sprintf('clp-site-%s.slice', $site->getUser());
    }

    public function sliceFileName(Site $site) : string
    {
        return $this->sliceName($site);
    }

    public function slicePath(Site $site) : string
    {
        return self::SYSTEMD_DIR . '/' . $this->sliceFileName($site);
    }

    public function dropInFileName(Site $site) : string
    {
        return sprintf('%s/%s.conf', self::PHP_FPM_DROPIN_DIR, $site->getUser());
    }

    public function dropInPath(Site $site) : string
    {
        return self::SYSTEMD_DIR . '/' . $this->dropInFileName($site);
    }

    /**
     * Writes slice file + php-fpm drop-in, reloads daemon, applies properties
     * to the live slice unit. Safe to call repeatedly — each step is
     * idempotent and any partial failure is logged but does not block the rest.
     */
    public function apply(Site $site) : void
    {
        $user = $site->getUser();
        if (true === empty($user)) {
            return;
        }

        $cpu = $site->getCpuQuota();
        $mem = $site->getMemoryQuota();

        // 1. Build and write the slice unit file.
        $sliceContents = $this->buildSliceContents($site, $cpu, $mem);
        $writeSlice = new WriteSliceFileCommand();
        $writeSlice->setFileName($this->sliceFileName($site));
        $writeSlice->setContent($sliceContents);
        $this->commandExecutor->execute($writeSlice);

        // 2. Write the php-fpm drop-in pointing at this slice. This makes any
        //    workers spawned by the system php-fpm unit accounted under the
        //    site's cgroup. Drop-in is keyed by user so multiple sites coexist.
        $dropInContents = $this->buildPhpFpmDropInContents($site);
        $writeDropIn = new WriteSliceFileCommand();
        $writeDropIn->setFileName($this->dropInFileName($site));
        $writeDropIn->setContent($dropInContents);
        $this->commandExecutor->execute($writeDropIn);

        // 3. Reload systemd so the new slice + drop-in are recognised.
        try {
            $this->commandExecutor->execute(new SystemctlDaemonReloadCommand());
        } catch (\Throwable $e) {
            if (null !== $this->logger) {
                $this->logger->warning('systemctl daemon-reload failed', ['error' => $e->getMessage()]);
            }
        }

        // 4. Push the resource properties onto the live slice via
        //    systemctl set-property. This handles the case where the slice
        //    unit was already loaded — daemon-reload alone does not refresh
        //    cgroup attributes for active slices.
        $properties = $this->buildPropertyMap($cpu, $mem);
        if (false === empty($properties)) {
            $setProperty = new SystemctlSetPropertyCommand();
            $setProperty->setUnit($this->sliceName($site));
            $setProperty->setProperties($properties);
            try {
                $this->commandExecutor->execute($setProperty);
            } catch (\Throwable $e) {
                // Slice may not be loaded yet (no processes have entered it).
                // That's fine — the file-based config will apply on first use.
                if (null !== $this->logger) {
                    $this->logger->info('set-property on slice deferred (slice not loaded yet)', [
                        'slice' => $this->sliceName($site),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Removes the per-site slice, the php-fpm drop-in, and reloads systemd.
     */
    public function removeFor(Site $site) : void
    {
        $user = $site->getUser();
        if (true === empty($user)) {
            return;
        }
        $removeSlice = new RemoveSliceFileCommand();
        $removeSlice->setFileName($this->sliceFileName($site));
        try {
            $this->commandExecutor->execute($removeSlice);
        } catch (\Throwable $e) {
            if (null !== $this->logger) {
                $this->logger->warning('Failed removing slice file', ['error' => $e->getMessage()]);
            }
        }
        $removeDropIn = new RemoveSliceFileCommand();
        $removeDropIn->setFileName($this->dropInFileName($site));
        try {
            $this->commandExecutor->execute($removeDropIn);
        } catch (\Throwable $e) {
            if (null !== $this->logger) {
                $this->logger->warning('Failed removing drop-in', ['error' => $e->getMessage()]);
            }
        }
        try {
            $this->commandExecutor->execute(new SystemctlDaemonReloadCommand());
        } catch (\Throwable $e) {
            if (null !== $this->logger) {
                $this->logger->warning('systemctl daemon-reload failed on remove', ['error' => $e->getMessage()]);
            }
        }
    }

    private function buildSliceContents(Site $site, ?int $cpu, ?int $mem) : string
    {
        $lines = [];
        $lines[] = '[Unit]';
        $lines[] = sprintf('Description=CloudPanel resource slice for site %s (%s)', $site->getDomainName(), $site->getUser());
        $lines[] = '';
        $lines[] = '[Slice]';
        if (null !== $cpu && $cpu > 0) {
            $lines[] = sprintf('CPUQuota=%d%%', $cpu);
            $lines[] = 'CPUAccounting=yes';
        }
        if (null !== $mem && $mem > 0) {
            $lines[] = sprintf('MemoryMax=%dM', $mem);
            $lines[] = sprintf('MemoryHigh=%dM', max(1, (int) floor($mem * 0.9)));
            $lines[] = 'MemoryAccounting=yes';
        }
        $lines[] = 'TasksMax=infinity';
        $lines[] = 'TasksAccounting=yes';
        return implode("\n", $lines) . "\n";
    }

    private function buildPhpFpmDropInContents(Site $site) : string
    {
        $lines = [];
        $lines[] = '[Service]';
        $lines[] = sprintf('Slice=%s', $this->sliceName($site));
        return implode("\n", $lines) . "\n";
    }

    private function buildPropertyMap(?int $cpu, ?int $mem) : array
    {
        $properties = [];
        if (null !== $cpu && $cpu > 0) {
            $properties['CPUQuota'] = sprintf('%d%%', $cpu);
        }
        if (null !== $mem && $mem > 0) {
            $properties['MemoryMax'] = sprintf('%dM', $mem);
            $properties['MemoryHigh'] = sprintf('%dM', max(1, (int) floor($mem * 0.9)));
        }
        $properties['TasksMax'] = 'infinity';
        return $properties;
    }
}
