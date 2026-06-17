<?php

namespace App\Site\ResourceQuota;

use App\Entity\Site;
use App\System\CommandExecutor;
use App\System\Command\AppendProjectsLineCommand;
use App\System\Command\XfsQuotaCommand;
use Psr\Log\LoggerInterface;

/**
 * Applies an XFS project quota per site.
 *
 *  - Each site gets a project ID (allocated lazily, persisted on Site).
 *  - /etc/projects gets `<id>:<path>` so xfs_quota can resolve the path.
 *  - /etc/projid gets `<name>:<id>` so the project has a human-readable name.
 *  - `xfs_quota project -s <name>` marks the inode tree, and
 *    `xfs_quota limit -p bhard=<N>m <name>` sets the hard byte limit.
 *
 * Requires the filesystem to be mounted with `prjquota` (or `pquota`).
 */
class XfsProjectQuotaApplier
{
    private const PROJECT_ID_BASE = 10000;

    private CommandExecutor $commandExecutor;
    private ?LoggerInterface $logger;

    public function __construct(CommandExecutor $commandExecutor, ?LoggerInterface $logger = null)
    {
        $this->commandExecutor = $commandExecutor;
        $this->logger = $logger;
    }

    public function apply(Site $site) : void
    {
        $user = $site->getUser();
        $quotaMb = $site->getDiskQuotaMb();
        if (empty($user) || null === $quotaMb || $quotaMb <= 0) {
            return;
        }

        $homeDir = '/home/' . $user;
        if (!is_dir($homeDir)) {
            return;
        }

        $projectId = $site->getDiskQuotaProjectId();
        if (null === $projectId || $projectId <= 0) {
            $projectId = $this->allocateProjectId($site);
            $site->setDiskQuotaProjectId($projectId);
        }

        $projectName = $this->projectName($site, $projectId);
        $mountPoint = $this->resolveMountPoint($homeDir);

        // 1. /etc/projects: <id>:<path>
        $projectsLine = sprintf('%d:%s', $projectId, $homeDir);
        $appendProjects = new AppendProjectsLineCommand();
        $appendProjects->setPath('/etc/projects');
        $appendProjects->setLine($projectsLine);
        $this->safeExecute($appendProjects, 'append /etc/projects', ['site' => $site->getDomainName()]);

        // 2. /etc/projid: <name>:<id>
        $projidLine = sprintf('%s:%d', $projectName, $projectId);
        $appendProjid = new AppendProjectsLineCommand();
        $appendProjid->setPath('/etc/projid');
        $appendProjid->setLine($projidLine);
        $this->safeExecute($appendProjid, 'append /etc/projid', ['site' => $site->getDomainName()]);

        // 3. xfs_quota: mark project + set hard byte limit
        $xfs = new XfsQuotaCommand();
        $xfs->setMountPoint($mountPoint);
        $xfs->setExpressions([
            sprintf('project -s %s', $projectName),
            sprintf('limit -p bhard=%dm %s', $quotaMb, $projectName),
        ]);
        $this->safeExecute($xfs, 'apply xfs project quota', [
            'site' => $site->getDomainName(),
            'project' => $projectName,
            'quotaMb' => $quotaMb,
        ]);
    }

    public function remove(Site $site) : void
    {
        $projectId = $site->getDiskQuotaProjectId();
        $user = $site->getUser();
        if (null === $projectId || $projectId <= 0 || empty($user)) {
            return;
        }
        $projectName = $this->projectName($site, $projectId);
        $homeDir = '/home/' . $user;
        $mountPoint = is_dir($homeDir) ? $this->resolveMountPoint($homeDir) : '/home';

        $xfs = new XfsQuotaCommand();
        $xfs->setMountPoint($mountPoint);
        $xfs->setExpressions([
            sprintf('limit -p bhard=0 %s', $projectName),
        ]);
        $this->safeExecute($xfs, 'clear xfs project quota', [
            'site' => $site->getDomainName(),
            'project' => $projectName,
        ]);
    }

    private function allocateProjectId(Site $site) : int
    {
        $id = $site->getId();
        if (null === $id || $id <= 0) {
            // Fallback to a hash of the domain so the ID is stable.
            $id = crc32($site->getDomainName() ?? 'unknown') & 0x7FFFFFFF;
        }
        return self::PROJECT_ID_BASE + (int) $id;
    }

    private function projectName(Site $site, int $projectId) : string
    {
        $user = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $site->getUser());
        return 'clp-' . ($user ?: 'site') . '-' . $projectId;
    }

    /**
     * Walk up until we find a mount point. df is the cheapest reliable way
     * but it lives outside php's tree, so fall back to /home if df is missing.
     */
    private function resolveMountPoint(string $path) : string
    {
        $out = @shell_exec(sprintf('/bin/df --output=target %s 2>/dev/null | tail -1', escapeshellarg($path)));
        $mount = trim((string) $out);
        if ('' !== $mount && '/' === $mount[0]) {
            return $mount;
        }
        return '/home';
    }

    private function safeExecute($command, string $description, array $context = []) : void
    {
        try {
            $this->commandExecutor->execute($command, 60);
        } catch (\Throwable $e) {
            if (null !== $this->logger) {
                $this->logger->warning(
                    sprintf('XfsProjectQuotaApplier: %s failed', $description),
                    $context + ['error' => $e->getMessage()]
                );
            }
        }
    }
}
